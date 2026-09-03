<?php
declare(strict_types=1);

/**
 * Cockpit CEO — les annexes d'une campagne.
 *
 * Une campagne réseaux sociaux ne se résume pas à des chiffres : le franchisé
 * a besoin du plan de publication, de la liste des produits mis en avant, du
 * bon de commande PLV. Ces documents existaient — dans une boîte mail, dans un
 * dossier partagé — mais pas avec la campagne, et jamais dans la note.
 *
 * Ils vivent donc ici : déposés dans l'assistant, rangés par type, et joints à
 * la note envoyée aux franchisés quand la case est cochée. Le TYPE est un
 * référentiel ouvert : on ajoute « Plan de publication » une fois, il est
 * proposé aux campagnes suivantes.
 *
 * Les fichiers sont écrits sous `public/assistant/uploads/annexes/`, le dossier
 * que le déploiement préserve — un rsync --delete ne doit pas emporter ce que
 * le réseau attend.
 */

/** Le dossier des annexes, créé au besoin. */
function annexeDossier(): string
{
    $d = __DIR__ . '/../public/assistant/uploads/annexes';
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    return $d;
}

/**
 * Les deux tables, créées à la première utilisation.
 *
 * Le cockpit n'a pas de migration : les tables `mar_*` viennent du module et
 * les siennes se créent là où elles servent. `IF NOT EXISTS` rend l'appel
 * idempotent — il coûte une requête et évite un déploiement en deux temps.
 */
function annexeTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_mkt_annexe_type (
        id INT AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(120) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY u_label (label)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_mkt_annexe (
        id INT AUTO_INCREMENT PRIMARY KEY,
        campaign_id INT NOT NULL,
        type_id INT NULL,
        label VARCHAR(190) NOT NULL,
        file_name VARCHAR(190) NOT NULL,
        size_bytes INT NOT NULL DEFAULT 0,
        in_mail TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        KEY k_campagne (campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Le référentiel de départ : ce que la maison joint déjà à ses campagnes.
    // Ajouté une seule fois — la table vide est le signal, pas un drapeau.
    $n = Db::row('SELECT COUNT(*) n FROM ceo_mkt_annexe_type');
    if ((int) ($n['n'] ?? 0) === 0) {
        foreach (['Plan de publication', 'Liste des produits', 'Visuels & PLV',
                  'Bon de commande', 'Argumentaire vendeur'] as $i => $label) {
            Db::exec('INSERT INTO ceo_mkt_annexe_type (label, sort_order) VALUES (?, ?)',
                [$label, $i + 1]);
        }
    }
}

/** Les types, tels que la liste déroulante les propose. */
function annexeTypes(): array
{
    annexeTables();
    return array_map(static fn ($t) => [
        'id' => (int) $t['id'], 'nom' => (string) $t['label'],
        'utilise' => (int) $t['utilise'],
    ], Db::rows('SELECT t.id, t.label, t.sort_order,
                        (SELECT COUNT(*) FROM ceo_mkt_annexe a WHERE a.type_id = t.id) AS utilise
                   FROM ceo_mkt_annexe_type t
                  WHERE t.is_active = 1
                  ORDER BY t.sort_order, t.label'));
}

/** Les annexes d'une campagne. */
function annexeListe(int $campagne): array
{
    annexeTables();
    return array_map(static fn ($a) => [
        'id' => (int) $a['id'],
        'nom' => (string) $a['label'],
        'type' => (string) ($a['type_label'] ?? ''),
        'typeId' => $a['type_id'] !== null ? (int) $a['type_id'] : null,
        'fichier' => (string) $a['file_name'],
        'taille' => (int) $a['size_bytes'],
        'tailleTxt' => annexeTaille((int) $a['size_bytes']),
        'enMail' => (bool) $a['in_mail'],
        'depuis' => substr((string) $a['created_at'], 0, 10),
        'existe' => is_file(annexeDossier() . '/' . basename((string) $a['file_name'])),
    ], Db::rows('SELECT a.*, t.label AS type_label
                   FROM ceo_mkt_annexe a
                   LEFT JOIN ceo_mkt_annexe_type t ON t.id = a.type_id
                  WHERE a.campaign_id = ?
                  ORDER BY a.id', [$campagne]));
}

/** `1,4 Mo` — une taille se lit, elle ne se compte pas en octets. */
function annexeTaille(int $octets): string
{
    if ($octets >= 1048576) { return number_format($octets / 1048576, 1, ',', ' ') . ' Mo'; }
    return max(1, (int) round($octets / 1024)) . ' Ko';
}

/** Le contenu d'une annexe, ou null si le fichier a disparu du disque. */
function annexeOctets(array $a): ?string
{
    $chemin = annexeDossier() . '/' . basename($a['fichier']);
    return is_file($chemin) ? (string) file_get_contents($chemin) : null;
}

/** GET /marketing/campagne/{id}/annexes. */
function ep_mkt_annexes(int $id): array
{
    if (Db::row('SELECT 1 FROM mar_campaign WHERE id = ?', [$id]) === null) {
        http_response_code(404); return ['error' => 'campagne inconnue'];
    }
    return ['annexes' => annexeListe($id), 'types' => annexeTypes(),
        'maxOctets' => 8 * 1024 * 1024];
}

/**
 * POST /marketing/campagne/{id}/annexe — dépôt d'un PDF.
 *
 * Corps : `{ nom, typeId | typeNom, fichier: "data:application/pdf;base64,…",
 * enMail }`. Un `typeNom` inconnu crée le type : c'est la liste « intelligente »
 * — on écrit ce qu'on veut, et ce qu'on a écrit est proposé la fois d'après.
 */
function wr_mkt_annexe(int $id): array
{
    if (Db::row('SELECT 1 FROM mar_campaign WHERE id = ?', [$id]) === null) {
        http_response_code(404); return ['error' => 'campagne inconnue'];
    }
    annexeTables();
    $b = body();

    $data = (string) ($b['fichier'] ?? '');
    if (!preg_match('#^data:application/pdf;base64,#', $data)) {
        http_response_code(422);
        return ['error' => 'seuls les PDF sont acceptés en annexe : le franchisé doit pouvoir l’ouvrir et l’imprimer partout'];
    }
    $octets = base64_decode(substr($data, strpos($data, ',') + 1) ?: '', true);
    if ($octets === false || !str_starts_with($octets, '%PDF')) {
        http_response_code(422);
        return ['error' => 'ce fichier ne commence pas par un en-tête PDF : renommer une image en .pdf ne suffit pas'];
    }
    if (strlen($octets) > 8 * 1024 * 1024) {
        http_response_code(422);
        return ['error' => 'annexe trop lourde (' . annexeTaille(strlen($octets)) . ') : 8 Mo au plus, sinon le courrier est refusé par les boîtes'];
    }

    $typeId = annexeTypeResolu($b);
    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 190);
    if ($nom === '') { $nom = 'Document'; }

    $fichier = 'a' . $id . '-' . bin2hex(random_bytes(8)) . '.pdf';
    if (@file_put_contents(annexeDossier() . '/' . $fichier, $octets) === false) {
        http_response_code(500);
        return ['error' => 'le dossier des annexes n’est pas accessible en écriture sur le serveur'];
    }

    Db::exec('INSERT INTO ceo_mkt_annexe (campaign_id, type_id, label, file_name, size_bytes, in_mail, created_at)
              VALUES (?,?,?,?,?,?,?)',
        [$id, $typeId, $nom, $fichier, strlen($octets),
         array_key_exists('enMail', $b) ? (int) (bool) $b['enMail'] : 1, date('Y-m-d H:i:s')]);

    journalAdd('CEO', 'Campagne', $nom, 'Annexe ajoutée à la campagne #' . $id);
    return ['ok' => true, 'annexes' => annexeListe($id), 'types' => annexeTypes()];
}

/**
 * Le type demandé : un identifiant existant, ou un libellé qui crée le type.
 *
 * Écrire un type qui existe déjà ne le duplique pas — la comparaison ignore la
 * casse et les espaces, sinon « Plan de publication » et « plan de
 * publication  » auraient fini par cohabiter dans la liste.
 */
function annexeTypeResolu(array $b): ?int
{
    $id = (int) ($b['typeId'] ?? 0);
    if ($id > 0 && Db::row('SELECT 1 FROM ceo_mkt_annexe_type WHERE id = ?', [$id]) !== null) {
        return $id;
    }
    $nom = mb_substr(trim((string) ($b['typeNom'] ?? '')), 0, 120);
    if ($nom === '') { return null; }

    $dej = Db::row('SELECT id FROM ceo_mkt_annexe_type WHERE LOWER(label) = LOWER(?)', [$nom]);
    if ($dej !== null) {
        // Un type désactivé qu'on redemande revient : le retirer n'est pas le
        // détruire, et le retaper doit le ramener plutôt que d'échouer.
        Db::exec('UPDATE ceo_mkt_annexe_type SET is_active = 1 WHERE id = ?', [(int) $dej['id']]);
        return (int) $dej['id'];
    }
    $rang = (int) (Db::row('SELECT COALESCE(MAX(sort_order), 0) + 1 r FROM ceo_mkt_annexe_type')['r'] ?? 1);
    Db::exec('INSERT INTO ceo_mkt_annexe_type (label, sort_order) VALUES (?, ?)', [$nom, $rang]);
    return (int) Db::pdo()->lastInsertId();
}

/** PATCH /marketing/annexe/{id} — la case « en annexe du mail », le nom, le type. */
function wr_mkt_annexe_maj(int $annexe): array
{
    annexeTables();
    $a = Db::row('SELECT * FROM ceo_mkt_annexe WHERE id = ?', [$annexe]);
    if ($a === null) { http_response_code(404); return ['error' => 'annexe inconnue']; }
    $b = body();

    if (array_key_exists('enMail', $b)) {
        Db::exec('UPDATE ceo_mkt_annexe SET in_mail = ? WHERE id = ?', [(int) (bool) $b['enMail'], $annexe]);
    }
    if (array_key_exists('nom', $b)) {
        $nom = mb_substr(trim((string) $b['nom']), 0, 190);
        if ($nom !== '') { Db::exec('UPDATE ceo_mkt_annexe SET label = ? WHERE id = ?', [$nom, $annexe]); }
    }
    if (array_key_exists('typeId', $b) || array_key_exists('typeNom', $b)) {
        Db::exec('UPDATE ceo_mkt_annexe SET type_id = ? WHERE id = ?', [annexeTypeResolu($b), $annexe]);
    }
    return ['ok' => true, 'annexes' => annexeListe((int) $a['campaign_id']), 'types' => annexeTypes()];
}

/** DELETE /marketing/annexe/{id} — la ligne ET le fichier. */
function wr_mkt_annexe_suppr(int $annexe): array
{
    annexeTables();
    $a = Db::row('SELECT * FROM ceo_mkt_annexe WHERE id = ?', [$annexe]);
    if ($a === null) { http_response_code(404); return ['error' => 'annexe inconnue']; }
    @unlink(annexeDossier() . '/' . basename((string) $a['file_name']));
    Db::exec('DELETE FROM ceo_mkt_annexe WHERE id = ?', [$annexe]);
    journalAdd('CEO', 'Campagne', (string) $a['label'], 'Annexe retirée de la campagne #' . (int) $a['campaign_id']);
    return ['ok' => true, 'annexes' => annexeListe((int) $a['campaign_id']), 'types' => annexeTypes()];
}

/**
 * DELETE /marketing/annexe-type/{id} — retirer un type de la liste.
 *
 * Désactivé, jamais supprimé : les annexes déjà rangées dessous garderaient
 * sinon un type orphelin, et l'historique perdrait ce qu'on avait classé.
 */
function wr_mkt_annexe_type_suppr(int $type): array
{
    annexeTables();
    if (Db::row('SELECT 1 FROM ceo_mkt_annexe_type WHERE id = ?', [$type]) === null) {
        http_response_code(404); return ['error' => 'type inconnu'];
    }
    Db::exec('UPDATE ceo_mkt_annexe_type SET is_active = 0 WHERE id = ?', [$type]);
    return ['ok' => true, 'types' => annexeTypes()];
}

/** GET /marketing/annexe/{id}/fichier — le PDF lui-même. */
function ep_mkt_annexe_fichier(int $annexe): array
{
    annexeTables();
    $a = Db::row('SELECT * FROM ceo_mkt_annexe WHERE id = ?', [$annexe]);
    if ($a === null) { http_response_code(404); return ['error' => 'annexe inconnue']; }
    $chemin = annexeDossier() . '/' . basename((string) $a['file_name']);
    if (!is_file($chemin)) {
        http_response_code(410);
        return ['error' => 'le fichier de cette annexe n’est plus sur le serveur — déposez-le à nouveau'];
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . annexeNomFichier((string) $a['label']) . '"');
    readfile($chemin);
    exit;
}

/** `plan-de-publication.pdf` — lisible dans une pièce jointe. */
function annexeNomFichier(string $libelle): string
{
    $base = mktSlug($libelle);
    return ($base !== '' ? str_replace('_', '-', $base) : 'annexe') . '.pdf';
}
