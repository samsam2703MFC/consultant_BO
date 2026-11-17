<?php
declare(strict_types=1);

/**
 * L'assistant IA du dashboard magasin : ses tables, le manuel opératoire, le Professeur, les tableaux et graphiques
 * (17/11/2026, Sam : « rajouter l'accès à l'entièreté du manuel opératoire ; mettre un agent rapide ; rajouter un avatar
 * type professeur pour le manuel ; en fonction de la question du franchisé, l'agent lui propose tableau et/ou graphique ;
 * créer toutes les tables db pour l'assistant avec le préfixe aia_ »). Le cœur — la fiche de faits, les coachs, les
 * outils de l'historique des ventes, la boucle d'appels — reste dans src/assistant_magasin.php, qui charge ce fichier.
 *
 * LES TABLES (aia_, créées au premier appel) :
 *   aia_reglage         les réglages de l'assistant (coachs, modèle, effort, Professeur, sel des références anonymes) ;
 *                       repris de ceo_app_setting clé par clé, la première fois qu'on les lit
 *   aia_fiche           la fiche de faits d'un magasin, gardée dix minutes (avant : ceo_app_setting « assistantFaits|… »)
 *   aia_conversation    une conversation : le magasin, l'agent, la première question, le nombre de questions
 *   aia_question        chaque question et sa réponse, ses jetons, sa durée, ses outils (avant : ceo_assistant_question,
 *                       reprise une fois)
 *   aia_visuel          chaque graphique ou tableau proposé : la demande du modèle et les chiffres relus
 *   aia_modele          les graphiques qu'un magasin garde comme modèles : la demande seulement, rejouée à l'ouverture
 *                       sur une période qui glisse avec le temps
 *   aia_manuel_passage  le manuel opératoire découpé en passages (en-tête, étapes, points critiques, quiz) pour la
 *                       recherche ; refait quand le manuel change (signature des versions)
 *
 * LE MANUEL OPÉRATOIRE : tous les documents de ceo_sop (SOP, checklists, mémos, fiches, articles — publiés, à valider et
 * brouillons, signalés), à leur version en vigueur, en français (repli sur le néerlandais). Trois outils, pour tous les
 * agents : manuel_sommaire, manuel_chercher, manuel_lire (par pages de 12 000 caractères : rien n'est coupé en silence).
 *
 * LE PROFESSEUR : le quatrième personnage de l'assistant, le formateur du réseau. Il répond sur le manuel, avec le
 * sommaire en contexte au lieu de la fiche de faits (aucune lecture de l'ERP avant la question : il démarre tout de
 * suite). C'est l'agent rapide : Claude Haiku 5.5, effort `low`, quatre appels au plus — réglage `assistantProfesseur`
 * {modele, effort} pour en changer.
 *
 * LES TABLEAUX ET GRAPHIQUES : le modèle ne recopie aucun chiffre. Il décrit dans sa réponse (`visuels`) ce qu'il faut
 * montrer — les données (ventes par heure, par jour, par semaine, par mois, produits, catégories, classement du réseau),
 * la mesure, la période, la comparaison (l'an passé, le réseau) et la forme (colonnes, courbe, barres, tableau) — et le
 * serveur relit les chiffres dans l'historique des ventes (src/historique_ventes.php). Seule exception : un tableau tiré
 * du manuel (`donnees = manuel`), recopié d'un document lu. Chaque visuel est gardé (aia_visuel), téléchargeable (CSV,
 * PNG) et peut devenir un modèle.
 *
 * Routes (préfixe /assistant/magasin : ouvert à la session d'un magasin, le `shop` vérifié par src/acces.php) :
 *   GET  /assistant/magasin/modeles?shop=                    les modèles du magasin
 *   POST /assistant/magasin/modeles {shop, visuel, nom}      garder un visuel comme modèle
 *   GET  /assistant/magasin/modeles/{id}?shop=               le rejouer, chiffres du jour
 *   POST /assistant/magasin/modeles/{id}/supprimer {shop}    le retirer
 */

const AIA_PROF_MODELE = 'claude-haiku-5-5';   // l'agent rapide (Sam, 17/11/2026 : « mettre un agent rapide »)
const AIA_PROF_EFFORT = 'low';
const AIA_PROF_TOURS = 4;                     // appels au modèle au plus pour une question du Professeur
const AIA_PROF_SECONDES = 40;
const AIA_PROF = [
    'nom' => 'Le Professeur',
    'dit' => 'le manuel opératoire : gestes, recettes, standards',
];
const AIA_REGLAGES = ['assistantCoachs', 'assistantMagasin', 'assistantProfesseur', 'assistantSel'];
const AIA_FORMES = ['colonnes', 'courbe', 'barres', 'tableau'];
const AIA_DONNEES = [
    'ventes_par_heure' => 'les ventes heure par heure (moyenne par jour ouvert)',
    'ventes_par_jour' => 'les ventes jour par jour',
    'ventes_par_semaine' => 'les ventes semaine par semaine',
    'ventes_par_mois' => 'les ventes mois par mois',
    'produits' => 'les produits les plus vendus',
    'categories' => 'les catégories vendues',
    'classement_reseau' => 'le classement des magasins du réseau (anonyme)',
    'manuel' => 'un tableau tiré du manuel opératoire',
];
const AIA_MESURES = ['ca' => ['Chiffre d’affaires', '€'], 'tickets' => ['Clients', 'clients'], 'panier' => ['Panier moyen', '€'],
    'quantite' => ['Quantité vendue', 'pièces'], 'part' => ['Part des ventes', '%']];
const AIA_COMPARER = ['aucune', 'an_passe', 'reseau'];
const AIA_VISUELS_MAX = 2;
const AIA_POINTS_MAX = ['ventes_par_jour' => 62, 'ventes_par_semaine' => 78, 'ventes_par_mois' => 18];
const AIA_MODELES_MAX = 30;
const AIA_PAGE = 12000;                       // caractères d'une page de manuel_lire
const AIA_MOTS_VIDES = ['les', 'des', 'une', 'pour', 'par', 'avec', 'dans', 'sur', 'est', 'que', 'qui', 'quoi', 'quel', 'quelle',
    'quels', 'quelles', 'comment', 'faire', 'fait', 'mon', 'mes', 'son', 'ses', 'leur', 'aux', 'pas', 'plus', 'tout', 'tous', 'doit',
    'dois', 'faut', 'il', 'elle', 'on', 'nous', 'vous', 'je', 'au', 'du', 'de', 'la', 'le', 'et', 'en', 'un', 'ou', 'ce', 'cette',
    'ces', 'quand', 'sont', 'avoir', 'etre', 'bien', 'peut', 'peux'];

/* ---------------------------------------------------------------------------
 * Les tables
 * ------------------------------------------------------------------------- */
function aiaSchema(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS aia_reglage (
        cle VARCHAR(64) NOT NULL PRIMARY KEY,
        valeur MEDIUMTEXT NOT NULL,
        maj_le DATETIME NOT NULL,
        maj_par VARCHAR(120) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS aia_fiche (
        shop_id INT NOT NULL PRIMARY KEY,
        lu_le DATETIME NOT NULL,
        faits MEDIUMTEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS aia_conversation (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cle CHAR(32) NOT NULL,
        shop_id INT NOT NULL,
        agent VARCHAR(20) NOT NULL,
        ouverte_le DATETIME NOT NULL,
        derniere_le DATETIME NOT NULL,
        questions SMALLINT NOT NULL DEFAULT 0,
        premiere VARCHAR(500) NOT NULL,
        UNIQUE KEY u_cle (cle, shop_id), KEY k_shop (shop_id, derniere_le)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS aia_question (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        conversation_id BIGINT UNSIGNED NULL,
        cree_le DATETIME NOT NULL,
        shop_id INT NOT NULL,
        coach VARCHAR(20) NOT NULL,
        origine VARCHAR(10) NOT NULL,
        rang SMALLINT NOT NULL DEFAULT 0,
        question VARCHAR(500) NOT NULL,
        reponse MEDIUMTEXT NULL,
        modele VARCHAR(60) NULL,
        jetons_entree INT NULL,
        jetons_cache_lus INT NULL,
        jetons_sortie INT NULL,
        duree_ms INT NULL,
        outils VARCHAR(500) NULL,
        visuels TINYINT NOT NULL DEFAULT 0,
        erreur VARCHAR(300) NULL,
        theme VARCHAR(40) NULL,
        KEY k_date (cree_le), KEY k_shop (shop_id, cree_le), KEY k_conv (conversation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS aia_visuel (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        question_id BIGINT UNSIGNED NULL,
        shop_id INT NOT NULL,
        cree_le DATETIME NOT NULL,
        forme VARCHAR(12) NOT NULL,
        donnees VARCHAR(24) NOT NULL,
        titre VARCHAR(190) NOT NULL,
        demande TEXT NOT NULL,
        resultat MEDIUMTEXT NULL,
        erreur VARCHAR(300) NULL,
        KEY k_question (question_id), KEY k_shop (shop_id, cree_le)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS aia_modele (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        shop_id INT NOT NULL,
        cree_le DATETIME NOT NULL,
        nom VARCHAR(120) NOT NULL,
        forme VARCHAR(12) NOT NULL,
        donnees VARCHAR(24) NOT NULL,
        demande TEXT NOT NULL,
        visuel_id BIGINT UNSIGNED NULL,
        ouvert_le DATETIME NULL,
        ouvertures INT NOT NULL DEFAULT 0,
        KEY k_shop (shop_id, cree_le)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    Db::exec('CREATE TABLE IF NOT EXISTS aia_manuel_passage (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sop_id VARCHAR(32) NOT NULL,
        version VARCHAR(12) NOT NULL,
        ordre INT NOT NULL,
        section VARCHAR(190) NULL,
        numero VARCHAR(16) NULL,
        texte TEXT NOT NULL,
        cle TEXT NOT NULL,
        KEY k_sop (sop_id, ordre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    aiaReprendreQuestions();
}

/** Les questions déjà posées (ceo_assistant_question, 12/11/2026) passent une fois dans aia_question. */
function aiaReprendreQuestions(): void
{
    if (aiaReglageBrut('repriseQuestions') !== null) { return; }
    try {
        Db::exec('INSERT INTO aia_question (cree_le, shop_id, coach, origine, rang, question, reponse, modele, jetons_entree, jetons_sortie, duree_ms, erreur, theme)
                  SELECT cree_le, shop_id, coach, origine, rang, question, reponse, modele, jetons_entree, jetons_sortie, duree_ms, erreur, theme
                  FROM ceo_assistant_question ORDER BY id');
    } catch (Throwable $e) { /* pas de table d'avant : rien à reprendre */ }
    aiaReglageEcrire('repriseQuestions', date('Y-m-d H:i:s'), 'Reprise');
}

/* ---------------------------------------------------------------------------
 * Les réglages
 * ------------------------------------------------------------------------- */
/** La valeur brute (JSON décodé) d'un réglage d'aia_reglage ; null s'il n'y est pas. */
function aiaReglageBrut(string $cle): mixed
{
    $r = Db::row('SELECT valeur FROM aia_reglage WHERE cle = ?', [$cle]);
    return $r === null ? null : json_decode((string) $r['valeur'], true);
}

/** Un réglage de l'assistant ; repris de ceo_app_setting la première fois (même clé). */
function aiaReglage(string $cle, mixed $defaut = null): mixed
{
    aiaSchema();
    $v = aiaReglageBrut($cle);
    if ($v !== null) { return $v; }
    if (in_array($cle, AIA_REGLAGES, true) && function_exists('setting')) {
        $ancien = setting($cle, null);
        if ($ancien !== null) {
            try { Db::exec('INSERT IGNORE INTO aia_reglage (cle, valeur, maj_le, maj_par) VALUES (?, ?, NOW(), ?)', [$cle, json_encode($ancien, JSON_UNESCAPED_UNICODE), 'Reprise']); }
            catch (Throwable $e) { /* relu la prochaine fois */ }
            return $ancien;
        }
    }
    return $defaut;
}

function aiaReglageEcrire(string $cle, mixed $valeur, string $par = ''): void
{
    aiaSchema();
    Db::exec('INSERT INTO aia_reglage (cle, valeur, maj_le, maj_par) VALUES (?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), maj_le = NOW(), maj_par = VALUES(maj_par)',
        [$cle, json_encode($valeur, JSON_UNESCAPED_UNICODE), $par !== '' ? mb_substr($par, 0, 120) : null]);
}

/** Le modèle et l'effort du Professeur : le réglage `assistantProfesseur`, sinon l'agent rapide. */
function aiaProfesseurModele(): array
{
    $r = aiaReglage('assistantProfesseur', []);
    $r = is_array($r) ? $r : [];
    $m = trim((string) ($r['modele'] ?? '')) ?: AIA_PROF_MODELE;
    $e = in_array($r['effort'] ?? '', ['low', 'medium', 'high'], true) ? (string) $r['effort'] : AIA_PROF_EFFORT;
    return ['modele' => $m, 'effort' => $e];
}

/* ---------------------------------------------------------------------------
 * La fiche de faits, gardée dix minutes
 * ------------------------------------------------------------------------- */
function aiaFicheLire(int $sid, int $ttl = 600): ?array
{
    aiaSchema();
    $r = Db::row('SELECT faits FROM aia_fiche WHERE shop_id = ? AND lu_le > ?', [$sid, date('Y-m-d H:i:s', time() - $ttl)]);
    $f = $r !== null ? json_decode((string) $r['faits'], true) : null;
    return is_array($f) ? $f : null;
}

function aiaFicheEcrire(int $sid, array $f): void
{
    try {
        aiaSchema();
        Db::exec('INSERT INTO aia_fiche (shop_id, lu_le, faits) VALUES (?, NOW(), ?) ON DUPLICATE KEY UPDATE lu_le = NOW(), faits = VALUES(faits)',
            [$sid, json_encode($f, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { error_log('aia_fiche : ' . $e->getMessage()); }
}

/* ---------------------------------------------------------------------------
 * Les conversations, les questions, les visuels
 * ------------------------------------------------------------------------- */
/** La conversation d'une question : la clé tirée par le navigateur (une par fil ouvert) ; null sans clé valable. */
function aiaConversation(int $sid, string $agent, string $cle, string $q): ?int
{
    if (!preg_match('/^[a-f0-9]{16,32}$/', $cle)) { return null; }
    try {
        aiaSchema();
        Db::exec('INSERT INTO aia_conversation (cle, shop_id, agent, ouverte_le, derniere_le, questions, premiere) VALUES (?, ?, ?, NOW(), NOW(), 1, ?)
                  ON DUPLICATE KEY UPDATE derniere_le = NOW(), questions = questions + 1, agent = VALUES(agent)', [$cle, $sid, $agent, mb_substr($q, 0, 500)]);
        $r = Db::row('SELECT id FROM aia_conversation WHERE cle = ? AND shop_id = ?', [$cle, $sid]);
        return $r !== null ? (int) $r['id'] : null;
    } catch (Throwable $e) { error_log('aia_conversation : ' . $e->getMessage()); return null; }
}

/** Garde une question et ce qu'elle a donné ; rend son id. Une panne d'écriture ne coûte jamais la réponse. */
function aiaGarderQuestion(?int $conv, int $sid, string $coach, string $orig, string $q, int $rang, array $r, int $ms, int $visuels = 0): ?int
{
    try {
        aiaSchema();
        $u = is_array($r['usage'] ?? null) ? $r['usage'] : [];
        $entree = isset($u['input_tokens']) ? (int) $u['input_tokens'] + (int) ($u['cache_creation_input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0) : null;
        $outils = !empty($r['outils']) ? mb_substr(implode(',', array_unique(array_map('strval', $r['outils']))), 0, 500) : null;
        Db::exec('INSERT INTO aia_question (conversation_id, cree_le, shop_id, coach, origine, rang, question, reponse, modele, jetons_entree, jetons_cache_lus, jetons_sortie, duree_ms, outils, visuels, erreur)
                  VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$conv, $sid, $coach, $orig, $rang, $q, isset($r['reponse']) ? json_encode($r['reponse'], JSON_UNESCAPED_UNICODE) : null, $r['modele'] ?? null,
             $entree, isset($u['cache_read_input_tokens']) ? (int) $u['cache_read_input_tokens'] : null, isset($u['output_tokens']) ? (int) $u['output_tokens'] : null,
             $ms, $outils, $visuels, isset($r['erreur']) ? mb_substr((string) $r['erreur'], 0, 300) : null]);
        return (int) Db::pdo()->lastInsertId() ?: null;
    } catch (Throwable $e) { error_log('aia_question : ' . $e->getMessage()); return null; }
}

function aiaGarderVisuel(?int $qid, int $sid, array $demande, array|string $res): ?int
{
    try {
        aiaSchema();
        Db::exec('INSERT INTO aia_visuel (question_id, shop_id, cree_le, forme, donnees, titre, demande, resultat, erreur) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?)',
            [$qid, $sid, (string) ($demande['forme'] ?? ''), (string) ($demande['donnees'] ?? ''), mb_substr((string) ($demande['titre'] ?? ''), 0, 190),
             json_encode($demande, JSON_UNESCAPED_UNICODE), is_array($res) ? json_encode($res, JSON_UNESCAPED_UNICODE) : null, is_string($res) ? mb_substr($res, 0, 300) : null]);
        return (int) Db::pdo()->lastInsertId() ?: null;
    } catch (Throwable $e) { error_log('aia_visuel : ' . $e->getMessage()); return null; }
}

/* ---------------------------------------------------------------------------
 * Le manuel opératoire
 * ------------------------------------------------------------------------- */
/** Un texte sans accents ni ponctuation, en minuscules : la clé de recherche. */
function aiaNorm(string $s): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae', '’' => ' ', '\'' => ' ']);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/** Les mots qui comptent d'une question : trois lettres au moins, sans les mots vides, le pluriel ôté. */
function aiaMots(string $s): array
{
    $out = [];
    foreach (explode(' ', aiaNorm($s)) as $m) {
        if (strlen($m) < 3 && !ctype_digit($m)) { continue; }
        if (in_array($m, AIA_MOTS_VIDES, true)) { continue; }
        if (strlen($m) > 4 && in_array(substr($m, -1), ['s', 'x'], true)) { $m = substr($m, 0, -1); }
        $out[$m] = true;
    }
    return array_keys($out);
}

/** La signature du manuel : chaque document, sa version, son statut, sa dernière modification. */
function aiaManuelSignature(): string
{
    $L = Db::rows('SELECT id, version, statut, maj_le FROM ceo_sop ORDER BY id');
    return md5(json_encode($L));
}

/** Le contenu d'un document, en français (repli sur le néerlandais), quiz compris ; null s'il n'existe pas. */
function aiaManuelContenu(array $r): array
{
    $c = foContenuVersion((string) $r['id'], (string) $r['version']);
    return foContenuLangue($c, 'fr', true);
}

/** Refait aia_manuel_passage quand le manuel a changé. Rend le nombre de passages (ou -1 : rien à refaire). */
function aiaManuelIndexer(bool $forcer = false): int
{
    aiaSchema();
    if (function_exists('ensureSop')) { ensureSop(); }
    $sig = aiaManuelSignature();
    if (!$forcer && aiaReglageBrut('manuelSignature') === $sig) { return -1; }
    $lignes = [];
    foreach (Db::rows('SELECT * FROM ceo_sop ORDER BY dossier, ordre, id') as $r) {
        $c = aiaManuelContenu($r);
        $id = (string) $r['id']; $v = (string) $r['version']; $o = 0;
        $tete = trim(implode(' — ', array_filter([(string) $c['titre'], (string) $c['objectif'], (string) $c['pour'], (string) $c['duree']])));
        $lignes[] = [$id, $v, $o++, null, null, $tete];
        foreach ($c['sections'] as $sec) {
            foreach ($sec['etapes'] as $e) {
                $t = trim(($e['titre'] !== '' ? $e['titre'] . ' : ' : '') . $e['texte']);
                if ($t === '') { continue; }
                $lignes[] = [$id, $v, $o++, $sec['titre'] !== '' ? mb_substr($sec['titre'], 0, 190) : null, mb_substr((string) $e['numero'], 0, 16), ($e['critique'] ? 'POINT CRITIQUE. ' : '') . $t];
            }
        }
        if ($c['points_critiques']) { $lignes[] = [$id, $v, $o++, 'Points critiques', null, implode(' · ', $c['points_critiques'])]; }
        foreach ($c['quiz'] ?? [] as $q) {
            $bonne = $q['choix'][$q['bonne']] ?? '';
            if ($q['question'] !== '') { $lignes[] = [$id, $v, $o++, 'Quiz', null, $q['question'] . ' Réponse : ' . $bonne]; }
        }
    }
    Db::exec('DELETE FROM aia_manuel_passage');
    foreach (array_chunk($lignes, 100) as $lot) {
        $vals = []; $a = [];
        foreach ($lot as [$id, $v, $o, $sec, $num, $t]) {
            $vals[] = '(?, ?, ?, ?, ?, ?, ?)';
            array_push($a, $id, $v, $o, $sec, $num, $t, aiaNorm(($sec ?? '') . ' ' . $t));
        }
        Db::exec('INSERT INTO aia_manuel_passage (sop_id, version, ordre, section, numero, texte, cle) VALUES ' . implode(',', $vals), $a);
    }
    aiaReglageEcrire('manuelSignature', $sig, 'Manuel');
    return count($lignes);
}

/** Le statut d'un document, en clair. */
function aiaStatut(string $s): string
{
    return ['publiee' => 'publié', 'a_valider' => 'à valider', 'brouillon' => 'brouillon (pas encore relu)'][$s] ?? $s;
}

/** Les documents du manuel, dans l'ordre des familles : ce que voit le sommaire. */
function aiaManuelDocuments(): array
{
    if (function_exists('ensureSop')) { ensureSop(); }
    $out = [];
    foreach (Db::rows('SELECT * FROM ceo_sop ORDER BY dossier, ordre, id') as $r) {
        $out[] = ['id' => (string) $r['id'], 'code' => $r['code'] !== null ? (string) $r['code'] : null, 'type' => (string) $r['type'],
            'famille' => (string) (FO_DOSSIERS[(string) $r['dossier']]['fr'] ?? $r['dossier']), 'dossier' => (string) $r['dossier'],
            'partie' => function_exists('foPartie') ? foPartie($r) : null, 'poste' => (string) $r['poste'], 'moment' => $r['moment'],
            'statut' => aiaStatut((string) $r['statut']), 'version' => (string) $r['version'],
            'titre' => (string) ($r['titre_fr'] !== '' ? $r['titre_fr'] : $r['titre_nl']),
            'objectif' => mb_substr(trim((string) ($r['objectif_fr'] ?: $r['objectif_nl'])), 0, 160), 'etapes' => (int) $r['nb_etapes']];
    }
    return $out;
}

/** Le sommaire en texte : une ligne par document, rangé par famille (ce que le Professeur a sous les yeux). */
function aiaManuelSommaireTexte(): string
{
    $t = ''; $fam = null;
    foreach (aiaManuelDocuments() as $d) {
        if ($d['famille'] !== $fam) { $fam = $d['famille']; $t .= "\n## " . $fam . "\n"; }
        $t .= '- ' . $d['id'] . ' · ' . ($d['code'] ? $d['code'] . ' · ' : '') . $d['type'] . ' · ' . $d['titre']
            . ($d['objectif'] !== '' ? ' — ' . $d['objectif'] : '') . ' (' . $d['poste'] . ($d['moment'] ? ', ' . $d['moment'] : '') . ', ' . $d['statut'] . ')' . "\n";
    }
    return trim($t) === '' ? 'Le manuel est vide.' : trim($t);
}

/** L'outil manuel_chercher : les passages qui répondent le mieux, le document de chacun. */
function aiaManuelChercher(string $q, int $n = 8): array
{
    aiaManuelIndexer();
    $mots = aiaMots($q);
    if ($mots === []) { return ['erreur' => 'question vide : donnez des mots-clés (« fermeture », « pâte à choux »)']; }
    $P = Db::rows('SELECT sop_id, ordre, section, numero, texte, cle FROM aia_manuel_passage');
    if ($P === []) { return ['passages' => [], 'note' => 'le manuel est vide']; }
    $docs = []; $titres = [];
    foreach (aiaManuelDocuments() as $d) { $docs[$d['id']] = $d; $titres[$d['id']] = aiaNorm($d['titre']); }
    // Le poids d'un mot : rare dans le manuel, il compte plus (idf). Un mot de six lettres et plus vaut aussi par sa racine.
    $cle = static fn (string $m) => strlen($m) >= 6 ? substr($m, 0, 5) : $m;
    $df = [];
    foreach ($P as $p) { foreach ($mots as $m) { if (str_contains(' ' . $p['cle'], ' ' . $cle($m))) { $df[$m] = ($df[$m] ?? 0) + 1; } } }
    $N = count($P);
    $scores = [];
    foreach ($P as $i => $p) {
        $s = 0.0; $vus = 0;
        foreach ($mots as $m) {
            if (!str_contains(' ' . $p['cle'], ' ' . $cle($m))) { continue; }
            $vus++;
            $s += log(1 + $N / max(1, $df[$m] ?? 1));
            if (str_contains($titres[$p['sop_id']] ?? '', $cle($m))) { $s += 1.0; }   // le mot est dans le titre
        }
        if ($vus > 0) { $scores[$i] = $s * (1 + 0.3 * ($vus - 1)); }
    }
    arsort($scores);
    $out = []; $parDoc = [];
    foreach (array_keys($scores) as $i) {
        $p = $P[$i]; $d = $docs[$p['sop_id']] ?? null;
        if ($d === null || ($parDoc[$p['sop_id']] = ($parDoc[$p['sop_id']] ?? 0) + 1) > 3) { continue; }   // trois passages par document au plus
        $out[] = ['id' => $d['id'], 'document' => $d['titre'], 'code' => $d['code'], 'type' => $d['type'], 'statut' => $d['statut'],
            'section' => $p['section'], 'etape' => $p['numero'], 'extrait' => mb_strlen($p['texte']) > 420 ? mb_substr($p['texte'], 0, 420) . '…' : $p['texte']];
        if (count($out) >= max(1, min(15, $n))) { break; }
    }
    return ['question' => implode(' ', $mots), 'passages' => $out, 'conseil' => $out ? 'lis le document entier (manuel_lire) avant de répondre' : 'rien trouvé : essaie d’autres mots, ou parcours le sommaire'];
}

/** L'outil manuel_lire : un document entier, par pages de AIA_PAGE caractères. */
function aiaManuelLire(string $id, int $page = 1): array
{
    if (function_exists('ensureSop')) { ensureSop(); }
    $r = function_exists('foSopRow') ? foSopRow($id) : null;
    if ($r === null) { return ['erreur' => 'document inconnu : prends l’id dans le sommaire ou dans manuel_chercher']; }
    $c = aiaManuelContenu($r);
    $blocs = [];
    foreach ($c['sections'] as $sec) {
        $t = $sec['titre'] !== '' ? '### ' . $sec['titre'] . "\n" : '';
        foreach ($sec['etapes'] as $e) {
            $t .= $e['numero'] . '. ' . ($e['critique'] ? '[POINT CRITIQUE] ' : '') . ($e['titre'] !== '' ? $e['titre'] . ' : ' : '') . $e['texte'] . ($e['photo'] ? ' (photo)' : '') . "\n";
        }
        $blocs[] = $t;
    }
    // Les pages : des sections entières, une section trop longue coupée entre deux étapes.
    $pages = [''];
    foreach ($blocs as $b) {
        foreach (mb_strlen($b) > AIA_PAGE ? explode("\n", $b) : [$b] as $morceau) {
            if (mb_strlen($pages[count($pages) - 1]) + mb_strlen($morceau) > AIA_PAGE && $pages[count($pages) - 1] !== '') { $pages[] = ''; }
            $pages[count($pages) - 1] .= $morceau . (str_ends_with($morceau, "\n") ? '' : "\n");
        }
    }
    $page = max(1, min(count($pages), $page));
    $out = ['id' => (string) $r['id'], 'code' => $r['code'], 'type' => (string) $r['type'], 'titre' => $c['titre'],
        'famille' => (string) (FO_DOSSIERS[(string) $r['dossier']]['fr'] ?? $r['dossier']), 'statut' => aiaStatut((string) $r['statut']),
        'version' => (string) $r['version'], 'majLe' => substr((string) $r['maj_le'], 0, 10), 'poste' => (string) $r['poste'], 'moment' => $r['moment'],
        'objectif' => $c['objectif'], 'pour' => $c['pour'], 'duree' => $c['duree'], 'page' => $page, 'pages' => count($pages), 'etapes' => trim($pages[$page - 1])];
    if ($page === count($pages)) {
        $out['pointsCritiques'] = $c['points_critiques'];
        $out['quiz'] = array_map(static fn ($q) => ['question' => $q['question'], 'bonneReponse' => $q['choix'][$q['bonne']] ?? null], $c['quiz'] ?? []);
    } else { $out['suite'] = 'la suite : manuel_lire avec page = ' . ($page + 1); }
    return $out;
}

/** Les trois outils du manuel, tels que le modèle les voit (texte fixe : il fait partie du préfixe mis en cache). */
function aiaOutilsManuel(): array
{
    return [
        ['name' => 'manuel_sommaire',
         'description' => 'Le sommaire du manuel opératoire du réseau : chaque document (SOP, checklist, mémo, fiche, article) avec son id, son code, sa famille, son titre, son objectif et son statut. '
            . 'À appeler pour savoir ce que le manuel couvre, avant de chercher ou de lire.',
         'input_schema' => ['type' => 'object', 'properties' => new stdClass()]],
        ['name' => 'manuel_chercher',
         'description' => 'Cherche dans tout le manuel opératoire (titres, étapes, points critiques, quiz) les passages qui répondent à une question, avec le document de chacun. '
            . 'À appeler pour toute question sur un geste, une recette, une règle d’hygiène, la vitrine, l’ouverture, la fermeture, un produit ou une procédure du réseau.',
         'input_schema' => ['type' => 'object', 'properties' => [
             'question' => ['type' => 'string', 'description' => 'Les mots-clés ou la question (« température four croissant », « fermeture caisse »).'],
             'limite' => ['type' => 'integer', 'description' => 'Combien de passages rendre : de 1 à 15, 8 par défaut.'],
         ], 'required' => ['question']]],
        ['name' => 'manuel_lire',
         'description' => 'Lit un document entier du manuel opératoire par son id : l’objectif, toutes les étapes dans l’ordre (les points critiques marqués), les points critiques et le quiz. '
            . 'Un long document se lit par pages : la réponse dit s’il y a une suite.',
         'input_schema' => ['type' => 'object', 'properties' => [
             'id' => ['type' => 'string', 'description' => 'L’id du document, pris dans le sommaire ou dans manuel_chercher.'],
             'page' => ['type' => 'integer', 'description' => 'La page à lire, 1 par défaut.'],
         ], 'required' => ['id']]],
    ];
}

/** Exécute un outil du manuel ; null si ce n'en est pas un. */
function aiaOutil(string $nom, array $in): ?array
{
    return match ($nom) {
        'manuel_sommaire' => ['documents' => array_map(static fn ($d) => array_diff_key($d, ['dossier' => 1]), aiaManuelDocuments())],
        'manuel_chercher' => aiaManuelChercher((string) ($in['question'] ?? ''), (int) ($in['limite'] ?? 8) ?: 8),
        'manuel_lire' => aiaManuelLire((string) ($in['id'] ?? ''), (int) ($in['page'] ?? 1) ?: 1),
        default => null,
    };
}

/** Les documents cités par une réponse (`sources`) : ceux qui existent, avec leur titre, leur code et leur version. */
function aiaSources(mixed $ids): array
{
    $out = []; $vus = [];
    foreach (is_array($ids) ? array_slice($ids, 0, 6) : [] as $id) {
        $r = function_exists('foSopRow') && is_string($id) ? foSopRow($id) : null;
        if ($r === null || isset($vus[$r['id']])) { continue; }
        $vus[$r['id']] = true;
        $out[] = ['id' => (string) $r['id'], 'code' => $r['code'], 'type' => (string) $r['type'], 'titre' => (string) ($r['titre_fr'] ?: $r['titre_nl']),
            'version' => (string) $r['version'], 'statut' => aiaStatut((string) $r['statut'])];
    }
    return $out;
}

/* ---------------------------------------------------------------------------
 * Le Professeur
 * ------------------------------------------------------------------------- */
const AIA_SYSTEME_PROF = <<<'TXT'
Tu es Le Professeur, le formateur du réseau L'Atelier by (boulangerie, pâtisserie, traiteur ; Belgique). Tu réponds au franchisé et à son équipe, en français, sur le MANUEL OPÉRATOIRE du réseau : les standards (SOP), les checklists, les mémos, les fiches et les articles. Tu vouvoies. Tu es un professeur patient et précis : tu donnes le geste juste et, en une phrase, pourquoi il compte.

Le sommaire du manuel est joint (chaque document : son id, son code, son type, son titre, son objectif, le poste, le moment, son statut). Règles :
- Avant de répondre, lis le document qui répond (manuel_lire, par son id). Si le sommaire ne suffit pas à le trouver, cherche (manuel_chercher). Lance les deux en parallèle quand c'est utile ; un seul tour d'outils suffit le plus souvent.
- Ne réponds jamais de mémoire : tout ce que tu dis vient d'un document lu. Ce que le manuel ne dit pas, dis-le en une phrase et propose d'envoyer la question au bureau (onglet « Demande au bureau »).
- Respecte le document : les étapes dans l'ordre, les points critiques tels quels, les chiffres (températures, durées, doses, poids, fréquences) recopiés exactement, avec leur unité.
- Un document « brouillon » n'est pas encore relu par le réseau, un document « à valider » attend sa validation : dis-le en une phrase si tu t'appuies dessus.
- Une question sur les chiffres du magasin (ventes, clients, produits) se règle avec les outils des ventes, comme le font les coachs ; le reste du temps, tu n'en as pas besoin.
- La forme :
  · `titre` : le sujet, en quelques mots ;
  · `resume` : UNE phrase, 30 mots au plus : le geste ou la règle essentielle ;
  · `points` : 2 à 6 puces, les étapes ou les points clés dans l'ordre du document ; un titre de 2 à 4 mots et UNE phrase ;
  · `chiffres` : 0 à 3 tuiles, les valeurs à retenir (« 180 °C », « 12 min », « 3 jours ») ;
  · `priorites` : vide, sauf une erreur fréquente à corriger (écran « aucun ») ;
  · `conclusion` : le point critique à ne pas oublier, en une phrase ;
  · `suites` : 2 ou 3 questions courtes sur le manuel, que le sommaire permet de traiter ;
  · `sources` : les id des documents lus.
TXT;

/** Le bloc commun à tous les agents : le manuel, les tableaux et graphiques, les sources. */
const AIA_CONSIGNES = <<<'TXT'
Le manuel opératoire et les visuels — règles communes :
- Le manuel opératoire du réseau entier se lit avec manuel_sommaire, manuel_chercher et manuel_lire. Une question sur un geste, une recette, l'hygiène, la vitrine, l'ouverture, la fermeture ou une procédure du réseau se règle dans le manuel, jamais de mémoire ; nomme le document sur lequel tu t'appuies.
- `sources` : les id des documents du manuel que tu as lus pour répondre ; vide si tu n'en as lu aucun.
- `visuels` (0 à 2) : propose un tableau ou un graphique quand il aide à voir ce que tu dis, selon la question :
  · une évolution dans le temps → `courbe` (ventes_par_jour, ventes_par_semaine, ventes_par_mois ; `colonnes` si 14 points ou moins) ;
  · un moment de la journée → `colonnes` (ventes_par_heure) ;
  · un classement → `barres` (produits, categories, classement_reseau) ;
  · un détail à lire ligne à ligne → `tableau` (toutes les données s'y prêtent) ;
  · une liste de valeurs d'un document du manuel (températures, durées, doses, produits et rayons) → `tableau` avec `donnees` = manuel.
  Pas de visuel pour une réponse qui tient en une phrase, ni deux visuels qui disent la même chose.
- Dans un visuel, tu ne recopies AUCUN chiffre de vente : tu décris la demande, le dashboard relit les chiffres dans l'historique (18 mois et la journée en cours). `mesure` : ca (chiffre d'affaires), tickets (clients), panier (panier moyen hors clients pro), quantite (pièces, pour les produits), part (part des ventes en %, pour les catégories). `du` et `au` en AAAA-MM-JJ. `comparer` : aucune, an_passe (la même période un an plus tôt) ou reseau (la moyenne des magasins, sans nom ; pour les catégories, compare les parts). `jours_semaine` (1 = lundi … 7 = dimanche, vide = tous), `heure_debut` et `heure_fin` (fin non comprise, null = toute la journée), `categorie` (un mot du nom, vide = toutes), `limite` (0 = par défaut) filtrent comme les outils. `entetes` et `lignes` restent vides.
- Seule exception : `donnees` = manuel. Tu remplis alors `entetes` et `lignes` en recopiant exactement un document lu (pas de chiffre inventé, pas de calcul), `forme` = tableau, `mesure` = ca, `comparer` = aucune, les autres champs vides ou nuls.
- `titre` du visuel : ce qu'il montre, en 4 à 8 mots (« Mes samedis depuis un an », « Températures de cuisson »).
TXT;

/** Les questions toutes prêtes du Professeur : quatre tuiles, puis trois documents par famille du manuel. */
function aiaQuestionsProfesseur(): array
{
    $groupes = [];
    try {
        foreach (aiaManuelDocuments() as $d) {
            if (str_starts_with($d['statut'], 'brouillon')) { continue; }
            $g = $d['famille'];
            if (count($groupes[$g] ?? []) >= 3) { continue; }
            $groupes[$g][] = 'Explique-moi « ' . mb_substr($d['titre'], 0, 70) . ' »';
        }
    } catch (Throwable $e) { /* sans manuel lisible : les tuiles seules */ }
    return [
        'tuiles' => [
            ['Comment ouvrir le magasin ?', 'les étapes, dans l’ordre du manuel'],
            ['Que faut-il vérifier à la fermeture ?', 'la checklist et ses points critiques'],
            ['Quelles règles d’hygiène au comptoir ?', 'les gestes à ne jamais oublier'],
            ['Comment présenter la vitrine ?', 'le standard du réseau'],
        ],
        'groupes' => array_map(static fn ($g, $L) => [$g, $L], array_keys($groupes), array_values($groupes)),
    ];
}

/* ---------------------------------------------------------------------------
 * Les tableaux et graphiques
 * ------------------------------------------------------------------------- */
/** Le schéma des visuels dans la réponse du modèle : la DEMANDE (aucun chiffre de vente). */
function aiaSchemaVisuels(): array
{
    $txt = ['type' => 'string'];
    $heure = ['anyOf' => [['type' => 'integer'], ['type' => 'null']]];
    return ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
        'required' => ['forme', 'titre', 'donnees', 'mesure', 'du', 'au', 'comparer', 'jours_semaine', 'heure_debut', 'heure_fin', 'categorie', 'limite', 'entetes', 'lignes'],
        'properties' => [
            'forme' => ['type' => 'string', 'enum' => AIA_FORMES],
            'titre' => $txt,
            'donnees' => ['type' => 'string', 'enum' => array_keys(AIA_DONNEES)],
            'mesure' => ['type' => 'string', 'enum' => array_keys(AIA_MESURES)],
            'du' => $txt, 'au' => $txt,
            'comparer' => ['type' => 'string', 'enum' => AIA_COMPARER],
            'jours_semaine' => ['type' => 'array', 'items' => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5, 6, 7]]],
            'heure_debut' => $heure, 'heure_fin' => $heure,
            'categorie' => $txt,
            'limite' => ['type' => 'integer'],
            'entetes' => ['type' => 'array', 'items' => $txt],
            'lignes' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => $txt]],
        ]]];
}

/** La demande d'un visuel, propre : formes, données et mesures connues ; ce qui manque, par défaut. */
function aiaDemande(array $v): ?array
{
    $d = (string) ($v['donnees'] ?? '');
    if (!isset(AIA_DONNEES[$d])) { return null; }
    $f = in_array($v['forme'] ?? '', AIA_FORMES, true) ? (string) $v['forme'] : 'tableau';
    $m = isset(AIA_MESURES[$v['mesure'] ?? '']) ? (string) $v['mesure'] : 'ca';
    if ($d === 'manuel') { $f = 'tableau'; }
    if (in_array($d, ['produits', 'categories', 'classement_reseau'], true) && $f !== 'tableau') { $f = 'barres'; }
    if ($d === 'produits' && !in_array($m, ['ca', 'quantite'], true)) { $m = 'ca'; }
    if ($d === 'categories' && !in_array($m, ['ca', 'part'], true)) { $m = 'ca'; }
    if (in_array($d, ['ventes_par_heure', 'ventes_par_jour', 'ventes_par_semaine', 'ventes_par_mois', 'classement_reseau'], true) && !in_array($m, ['ca', 'tickets', 'panier'], true)) { $m = 'ca'; }
    $c = in_array($v['comparer'] ?? '', AIA_COMPARER, true) ? (string) $v['comparer'] : 'aucune';
    if ($d === 'produits' || $d === 'classement_reseau' || $d === 'manuel') { $c = 'aucune'; }
    if ($d === 'categories' && $c === 'an_passe') { $c = 'aucune'; }
    if ($d === 'categories' && $c === 'reseau') { $m = 'part'; }
    $h = static fn ($x) => is_int($x) || (is_string($x) && ctype_digit($x)) ? (int) $x : null;
    return [
        'forme' => $f, 'titre' => mb_substr(trim((string) ($v['titre'] ?? '')) ?: AIA_DONNEES[$d], 0, 120), 'donnees' => $d, 'mesure' => $m,
        'du' => (string) ($v['du'] ?? ''), 'au' => (string) ($v['au'] ?? ''), 'comparer' => $c,
        'jours_semaine' => array_values(array_unique(array_filter(array_map('intval', is_array($v['jours_semaine'] ?? null) ? $v['jours_semaine'] : []), static fn ($x) => $x >= 1 && $x <= 7))),
        'heure_debut' => $h($v['heure_debut'] ?? null), 'heure_fin' => $h($v['heure_fin'] ?? null),
        'categorie' => mb_substr(trim((string) ($v['categorie'] ?? '')), 0, 60), 'limite' => max(0, min(20, (int) ($v['limite'] ?? 0))),
        'entetes' => $d === 'manuel' ? array_map(static fn ($x) => mb_substr((string) $x, 0, 80), array_slice(is_array($v['entetes'] ?? null) ? $v['entetes'] : [], 0, 8)) : [],
        'lignes' => $d === 'manuel' ? array_map(static fn ($l) => array_map(static fn ($x) => mb_substr((string) $x, 0, 160), array_slice(is_array($l) ? $l : [], 0, 8)),
            array_slice(is_array($v['lignes'] ?? null) ? $v['lignes'] : [], 0, 40)) : [],
    ];
}

/** La période d'un visuel, au format des outils (amPeriode) ; une chaîne quand elle est illisible. */
function aiaPeriode(array $D): array|string
{
    return amPeriode(['du' => $D['du'], 'au' => $D['au'], 'jours_semaine' => $D['jours_semaine'], 'heure_debut' => $D['heure_debut'], 'heure_fin' => $D['heure_fin']]);
}

/** Une date décalée d'un an, au même jour de semaine (364 jours) ; un mois, au même mois. */
function aiaAnPasse(string $d, bool $mois = false): string
{
    return date('Y-m-d', strtotime($d . ($mois ? ' -1 year' : ' -364 days')));
}

/** Tickets, CA, pro… des jours d'une période, magasin(s) donnés : {jour: [t, ca, tp, cp, ouverts]}, aujourd'hui en direct. */
function aiaJours(array $shops, string $du, string $au, array $jours): array
{
    hvSchema();
    $out = [];
    $hier = date('Y-m-d', strtotime('-1 day'));
    if (min($au, $hier) >= $du) {
        [$w, $a] = hvFiltre($shops, $du, min($au, $hier), $jours);
        foreach (Db::rows('SELECT jour, SUM(tickets) t, SUM(ca) ca, SUM(tickets_pro) tp, SUM(ca_pro) cp, SUM(tickets > 0) o FROM ceo_hist_jour WHERE ' . $w . ' GROUP BY jour ORDER BY jour', $a) as $r) {
            $out[(string) $r['jour']] = [(int) $r['t'], (float) $r['ca'], (int) $r['tp'], (float) $r['cp'], (int) $r['o']];
        }
    }
    $auj = date('Y-m-d');
    if ($du <= $auj && $au >= $auj && hvJourGarde($auj, $jours)) {
        $x = [0, 0.0, 0, 0.0, 0];
        foreach ($shops as $sid) {
            $t = hvAujourdhui((int) $sid);
            if (!$t) { continue; }
            $y = hvTotaux($t);
            $x = [$x[0] + $y[0], $x[1] + $y[1], $x[2] + $y[2], $x[3] + $y[3], $x[4] + 1];
        }
        if ($x[0] > 0) { $out[$auj] = $x; }
    }
    return $out;
}

/** La valeur d'une mesure pour un total [t, ca, tp, cp, ouverts] ; `parMagasin` : la moyenne par magasin ouvert. */
function aiaValeur(?array $x, string $m, bool $parMagasin = false): ?float
{
    if ($x === null || $x[0] <= 0) { return null; }
    $n = $parMagasin ? max(1, (int) ($x[4] ?? 1)) : 1;
    return match ($m) {
        'tickets' => round($x[0] / $n, 1),
        'panier' => $x[0] - $x[2] > 0 ? round(($x[1] - $x[3]) / ($x[0] - $x[2]), 2) : null,
        default => round($x[1] / $n, 2),
    };
}

/** Les ventes par jour, semaine ou mois : [libellés, valeurs] d'une période, regroupées. */
function aiaTemps(array $shops, array $P, string $pas, string $m, bool $parMagasin): array
{
    $J = aiaJours($shops, $P['du'], $P['au'], $P['jours']);
    $G = []; $L = [];
    $noms = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
    for ($d = $P['du']; $d <= $P['au']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if (!hvJourGarde($d, $P['jours'])) { continue; }
        $ts = strtotime($d);
        [$k, $lib] = match ($pas) {
            'semaine' => [date('o-W', $ts), 'S' . date('W', $ts)],
            'mois' => [date('Y-m', $ts), ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'][(int) date('n', $ts) - 1] . (date('Y', $ts) !== date('Y') ? ' ' . date('y', $ts) : '')],
            default => [$d, $noms[(int) date('N', $ts) - 1] . ' ' . date('d/m', $ts)],
        };
        $L[$k] = $lib;
        $x = $J[$d] ?? null;
        if ($x === null) { $G[$k] ??= null; continue; }
        $y = $G[$k] ?? [0, 0.0, 0, 0.0, 0];
        // Par magasin ouvert : on garde le plus grand nombre de magasins ouverts d'un jour de la période (une semaine, un mois).
        $G[$k] = [$y[0] + $x[0], $y[1] + $x[1], $y[2] + $x[2], $y[3] + $x[3], max($y[4], $x[4])];
    }
    return [array_values($L), array_map(static fn ($x) => aiaValeur($x, $m, $parMagasin), array_values($G))];
}

/** Construit un visuel : les chiffres relus pour la demande ; une chaîne d'erreur quand la demande ne se lit pas. */
function aiaVisuel(array $D, int $sid): array|string
{
    [$nomM, $unite] = AIA_MESURES[$D['mesure']];
    $base = ['forme' => $D['forme'], 'titre' => $D['titre'], 'donnees' => $D['donnees'], 'mesure' => $D['mesure'], 'mesureNom' => $nomM, 'unite' => $unite, 'demande' => $D];
    if ($D['donnees'] === 'manuel') {
        if ($D['entetes'] === [] || $D['lignes'] === []) { return 'tableau du manuel vide'; }
        $n = count($D['entetes']);
        return $base + ['periode' => null, 'libelles' => [], 'series' => [], 'source' => 'le manuel opératoire du réseau',
            'tableau' => ['entetes' => $D['entetes'], 'lignes' => array_map(static fn ($l) => array_pad(array_slice($l, 0, $n), $n, ''), $D['lignes'])]];
    }
    $P = aiaPeriode($D);
    if (is_string($P)) { return $P; }
    $per = amPeriodeTexte($P);
    $periode = 'du ' . date('d/m/Y', strtotime($P['du'])) . ' au ' . date('d/m/Y', strtotime($P['au'])) . (isset($per['jours']) ? ' · ' . $per['jours'] : '') . (isset($per['heures']) ? ' · ' . $per['heures'] : '');
    $moi = ['nom' => 'Mon magasin', 'role' => 'moi'];
    $ref = null; $surligne = null; $note = $P['note'];
    $source = 'les tickets du panel (historique des ventes, 18 mois, et la journée en cours)';
    switch ($D['donnees']) {
        case 'ventes_par_heure':
            $lire = static function (array $shops, array $P2) use ($D): array {
                $H = hvHeures($shops, $P2['du'], $P2['au'], $P2['jours'], $P2['hd'], $P2['hf']);
                $jo = max(1, $H['couverture']['ouverts'] + (int) ($H['aujourdhui']['ouverts'] ?? 0));
                $v = [];
                foreach ($H['heures'] as $h => $x) { $v[$h] = aiaValeur([$x[0] / ($D['mesure'] === 'panier' ? 1 : $jo), $x[1] / ($D['mesure'] === 'panier' ? 1 : $jo), $x[2], $x[3], 1], $D['mesure']); }
                return $v;
            };
            $V = $lire([$sid], $P);
            $R = match ($D['comparer']) {
                'an_passe' => $lire([$sid], ['du' => aiaAnPasse($P['du']), 'au' => aiaAnPasse($P['au'])] + $P),
                'reseau' => $lire(amMagasinsActifs(), $P),
                default => null,
            };
            $hs = array_keys($V + ($R ?? []));
            sort($hs);
            if ($hs === []) { return 'aucune vente lue sur la période'; }
            $libs = array_map(static fn ($h) => $h . ' h', $hs);
            $moi['valeurs'] = array_map(static fn ($h) => $V[$h] ?? null, $hs);
            if ($R !== null) { $ref = ['nom' => $D['comparer'] === 'reseau' ? 'Le réseau (moyenne par magasin)' : 'Un an plus tôt', 'role' => 'ref', 'valeurs' => array_map(static fn ($h) => $R[$h] ?? null, $hs)]; }
            $periode .= ' · moyenne par jour ouvert';
            break;
        case 'ventes_par_jour': case 'ventes_par_semaine': case 'ventes_par_mois':
            $pas = ['ventes_par_jour' => 'jour', 'ventes_par_semaine' => 'semaine', 'ventes_par_mois' => 'mois'][$D['donnees']];
            if ($D['heure_debut'] !== null || $D['heure_fin'] !== null) { $note = trim(($note ?? '') . ' Les heures ne filtrent pas les ventes jour par jour : la journée entière est comptée.'); }
            [$libs, $vals] = aiaTemps([$sid], $P, $pas, $D['mesure'], false);
            $max = AIA_POINTS_MAX[$D['donnees']];
            if (count($libs) > $max) {
                // Trop de points : on garde les derniers, et on le dit.
                $libs = array_slice($libs, -$max); $vals = array_slice($vals, -$max);
                $note = trim(($note ?? '') . ' Les ' . $max . ' derniers points seulement.');
            }
            $moi['valeurs'] = $vals;
            if ($D['comparer'] === 'an_passe') {
                // Un an plus tôt : 364 jours (mêmes jours de semaine, autant de semaines) ou douze mois ; avant l'historique, des trous.
                $P2 = ['du' => aiaAnPasse($P['du'], $pas === 'mois'), 'au' => aiaAnPasse($P['au'], $pas === 'mois')] + $P;
                [, $v2] = aiaTemps([$sid], $P2, $pas, $D['mesure'], false);
                $ref = ['nom' => 'Un an plus tôt', 'role' => 'ref', 'valeurs' => array_pad(array_slice($v2, -count($vals)), count($vals), null)];
            } elseif ($D['comparer'] === 'reseau') {
                [, $v2] = aiaTemps(amMagasinsActifs(), $P, $pas, $D['mesure'], true);
                $ref = ['nom' => 'Le réseau (moyenne par magasin)', 'role' => 'ref', 'valeurs' => array_slice($v2, -count($vals))];
            }
            if (count(array_filter($vals, static fn ($x) => $x !== null)) === 0) { return 'l’historique ne couvre pas encore cette période'; }
            if (in_array($D['forme'], ['colonnes', 'courbe'], true)) { $base['forme'] = count($libs) > 31 ? 'courbe' : $D['forme']; }
            break;
        case 'produits':
            $R = hvProduits([$sid], $P['du'], $P['au'], $P['jours'], $P['hd'], $P['hf']);
            $cat = function_exists('svCatalogue') ? svCatalogue() : [];
            $filtre = aiaNorm($D['categorie']);
            $L = [];
            foreach ($R['produits'] as [$pid, , $nom, $q, $v]) {
                $c = trim((string) ($cat[$pid]['cat'] ?? ''));
                if ($filtre !== '' && !str_contains(aiaNorm($c), $filtre)) { continue; }
                $L[] = [$nom, $D['mesure'] === 'quantite' ? round($q, 1) : round($v, 2)];
            }
            usort($L, static fn ($a, $b) => $b[1] <=> $a[1]);
            $L = array_slice($L, 0, $D['limite'] ?: 10);
            if ($L === []) { return 'aucun produit vendu sur la période'; }
            $libs = array_column($L, 0); $moi['valeurs'] = array_column($L, 1);
            break;
        case 'categories':
            $lire = static function (array $shops) use ($P): array {
                $R = hvProduits($shops, $P['du'], $P['au'], $P['jours'], $P['hd'], $P['hf']);
                $cat = function_exists('svCatalogue') ? svCatalogue() : [];
                $C = []; $tot = 0.0;
                foreach ($R['produits'] as [$pid, , , , $v]) { $c = trim((string) ($cat[$pid]['cat'] ?? '')) ?: 'sans catégorie'; $C[$c] = ($C[$c] ?? 0.0) + $v; $tot += $v; }
                return [$C, $tot];
            };
            [$C, $tot] = $lire([$sid]);
            if ($tot <= 0) { return 'aucune vente lue sur la période'; }
            arsort($C);
            $C = array_slice($C, 0, $D['limite'] ?: 8, true);
            $libs = array_keys($C);
            $moi['valeurs'] = array_map(static fn ($v) => $D['mesure'] === 'part' ? round(100 * $v / $tot, 1) : round($v, 2), array_values($C));
            if ($D['comparer'] === 'reseau') {
                [$C2, $t2] = $lire(amMagasinsActifs());
                $ref = ['nom' => 'Le réseau', 'role' => 'ref', 'valeurs' => array_map(static fn ($c) => $t2 > 0 ? round(100 * ($C2[$c] ?? 0) / $t2, 1) : null, $libs)];
            }
            break;
        case 'classement_reseau':
            $res = amOutilClassement($sid, ['du' => $P['du'], 'au' => $P['au'], 'indicateur' => $D['mesure'], 'jours_semaine' => $P['jours'], 'heure_fin' => $P['hf']]);
            if (isset($res['erreur'])) { return (string) $res['erreur']; }
            if (($res['magasins'] ?? []) === []) { return 'aucun magasin n’a de ticket sur la période'; }
            $libs = []; $vals = [];
            foreach ($res['magasins'] as $i => $x) {
                $libs[] = $x['ref'] === 'moi' ? 'Mon magasin' : 'Magasin n° ' . $x['rang'];
                $vals[] = $x[$D['mesure']] ?? null;
                if ($x['ref'] === 'moi') { $surligne = $i; }
            }
            $moi = ['nom' => $nomM, 'role' => 'moi', 'valeurs' => $vals];
            $note = trim(($note ?? '') . ' Les autres magasins ne sont pas nommés.' . ($D['mesure'] === 'panier' ? ' Panier hors clients pro.' : ''));
            $source = 'les tickets du panel, tous les magasins actifs du réseau';
            break;
        default:
            return 'données inconnues';
    }
    $series = array_values(array_filter([$moi, $ref]));
    $ent = array_merge([$D['donnees'] === 'produits' ? 'Produit' : ($D['donnees'] === 'categories' ? 'Catégorie' : ($D['donnees'] === 'classement_reseau' ? 'Magasin' : 'Période'))],
        array_map(static fn ($s) => $s['nom'], $series));
    $lignes = [];
    foreach ($libs as $i => $l) { $lignes[] = array_merge([$l], array_map(static fn ($s) => $s['valeurs'][$i] ?? null, $series)); }
    return $base + ['periode' => $periode, 'libelles' => array_values($libs), 'series' => $series, 'surligne' => $surligne,
        'tableau' => ['entetes' => $ent, 'lignes' => $lignes], 'source' => $source, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null];
}

/** Les visuels d'une réponse : chacun construit, gardé (aia_visuel) ; ceux qui ne se lisent pas sont écartés. */
function aiaVisuels(mixed $demandes, int $sid, ?int $qid): array
{
    $out = [];
    foreach (is_array($demandes) ? array_slice($demandes, 0, AIA_VISUELS_MAX) : [] as $v) {
        $D = is_array($v) ? aiaDemande($v) : null;
        if ($D === null) { continue; }
        try { $res = aiaVisuel($D, $sid); }
        catch (Throwable $e) { error_log('aia visuel : ' . $e->getMessage()); $res = 'lecture impossible pour le moment'; }
        $id = aiaGarderVisuel($qid, $sid, $D, $res);
        if (is_array($res)) { $out[] = ['id' => $id] + $res; }
    }
    return $out;
}

/** La demande d'un visuel, gardée dans le fil de la conversation à la place des chiffres (le modèle n'a pas à les relire). */
function aiaVisuelsPourFil(mixed $vis): array
{
    return array_map(static fn ($v) => is_array($v['demande'] ?? null) ? $v['demande'] : (is_array($v) ? array_diff_key($v, ['series' => 1, 'tableau' => 1, 'libelles' => 1]) : []),
        is_array($vis) ? $vis : []);
}

/* ---------------------------------------------------------------------------
 * Les modèles
 * ------------------------------------------------------------------------- */
/** La demande d'un modèle rejouée aujourd'hui : la période glisse d'autant de jours (ou de mois) qu'il s'en est écoulé. */
function aiaGlisser(array $D, string $enregistreLe, ?string $auj = null): array
{
    $auj ??= date('Y-m-d');
    if ($D['donnees'] === 'manuel' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $D['du']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $D['au'])) { return $D; }
    $e = substr($enregistreLe, 0, 10);
    if ($D['donnees'] === 'ventes_par_mois') {
        $n = ((int) substr($auj, 0, 4) - (int) substr($e, 0, 4)) * 12 + ((int) substr($auj, 5, 2) - (int) substr($e, 5, 2));
        if ($n > 0) {
            $D['du'] = date('Y-m-01', strtotime(substr($D['du'], 0, 7) . '-01 +' . $n . ' months'));
            $fin = date('Y-m-01', strtotime(substr($D['au'], 0, 7) . '-01 +' . $n . ' months'));
            $D['au'] = min($auj, date('Y-m-t', strtotime($fin)));
        }
        return $D;
    }
    $n = (int) round((strtotime($auj) - strtotime($e)) / 86400);
    if ($n > 0) {
        // Une période sur des semaines entières glisse de semaines entières : les jours de semaine restent alignés.
        $n = in_array($D['donnees'], ['ventes_par_semaine'], true) || $D['jours_semaine'] !== [] ? intdiv($n, 7) * 7 : $n;
        $D['du'] = date('Y-m-d', strtotime($D['du'] . ' +' . $n . ' days'));
        $D['au'] = min($auj, date('Y-m-d', strtotime($D['au'] . ' +' . $n . ' days')));
    }
    return $D;
}

function aiaShop(): int
{
    $b = function_exists('body') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? body() : [];
    $s = (string) ($b['shop'] ?? ($_GET['shop'] ?? ''));
    return ctype_digit($s) ? (int) $s : 0;
}

/** GET /assistant/magasin/modeles?shop= */
function ep_aia_modeles(): array
{
    $sid = aiaShop();
    if ($sid <= 0) { http_response_code(422); return ['error' => 'magasin manquant']; }
    aiaSchema();
    $L = Db::rows('SELECT id, cree_le, nom, forme, donnees, ouvertures, ouvert_le FROM aia_modele WHERE shop_id = ? ORDER BY cree_le DESC LIMIT ' . AIA_MODELES_MAX, [$sid]);
    return ['modeles' => array_map(static fn ($r) => ['id' => (int) $r['id'], 'nom' => $r['nom'], 'forme' => $r['forme'], 'donnees' => $r['donnees'],
        'creeLe' => substr((string) $r['cree_le'], 0, 10), 'ouvertures' => (int) $r['ouvertures']], $L)];
}

/** POST /assistant/magasin/modeles {shop, visuel, nom} : garde la demande d'un visuel de ce magasin. */
function wr_aia_modele_enregistrer(): array
{
    $b = body();
    $sid = ctype_digit((string) ($b['shop'] ?? '')) ? (int) $b['shop'] : 0;
    $vid = ctype_digit((string) ($b['visuel'] ?? '')) ? (int) $b['visuel'] : 0;
    if ($sid <= 0 || $vid <= 0) { http_response_code(422); return ['error' => 'magasin ou visuel manquant']; }
    aiaSchema();
    $v = Db::row('SELECT id, forme, donnees, titre, demande, cree_le FROM aia_visuel WHERE id = ? AND shop_id = ?', [$vid, $sid]);
    if ($v === null) { http_response_code(404); return ['error' => 'visuel inconnu pour ce magasin']; }
    $n = (int) (Db::row('SELECT COUNT(*) n FROM aia_modele WHERE shop_id = ?', [$sid])['n'] ?? 0);
    if ($n >= AIA_MODELES_MAX) { http_response_code(409); return ['error' => AIA_MODELES_MAX . ' modèles au plus : retirez-en un d’abord']; }
    $deja = Db::row('SELECT id FROM aia_modele WHERE shop_id = ? AND visuel_id = ?', [$sid, $vid]);
    if ($deja !== null) { return ['ok' => true, 'id' => (int) $deja['id'], 'deja' => true]; }
    $nom = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($b['nom'] ?? ''))) ?: (string) $v['titre'], 0, 120);
    // La demande garde sa date d'enregistrement : c'est d'elle que la période glissera.
    $D = json_decode((string) $v['demande'], true);
    Db::exec('INSERT INTO aia_modele (shop_id, cree_le, nom, forme, donnees, demande, visuel_id) VALUES (?, NOW(), ?, ?, ?, ?, ?)',
        [$sid, $nom, (string) $v['forme'], (string) $v['donnees'], json_encode(['demande' => $D, 'le' => substr((string) $v['cree_le'], 0, 10)], JSON_UNESCAPED_UNICODE), $vid]);
    $id = (int) Db::pdo()->lastInsertId();
    if (function_exists('journalAdd')) { journalAdd('Dashboard', 'Assistant', null, 'Magasin ' . $sid . ' : modèle « ' . $nom . ' » enregistré'); }
    return ['ok' => true, 'id' => $id];
}

/** GET /assistant/magasin/modeles/{id}?shop= : le modèle rejoué sur la période glissée, chiffres du jour. */
function ep_aia_modele_ouvrir(int $id): array
{
    $sid = aiaShop();
    aiaSchema();
    $m = $sid > 0 ? Db::row('SELECT id, nom, demande FROM aia_modele WHERE id = ? AND shop_id = ?', [$id, $sid]) : null;
    if ($m === null) { http_response_code(404); return ['error' => 'modèle inconnu pour ce magasin']; }
    $j = json_decode((string) $m['demande'], true);
    $D = is_array($j['demande'] ?? null) ? aiaDemande($j['demande']) : null;
    if ($D === null) { http_response_code(422); return ['error' => 'modèle illisible']; }
    $D = aiaGlisser($D, (string) ($j['le'] ?? date('Y-m-d')));
    try { $res = aiaVisuel($D, $sid); } catch (Throwable $e) { $res = 'lecture impossible pour le moment'; }
    Db::exec('UPDATE aia_modele SET ouvertures = ouvertures + 1, ouvert_le = NOW() WHERE id = ?', [$id]);
    if (is_string($res)) { http_response_code(409); return ['error' => $res]; }
    $vid = aiaGarderVisuel(null, $sid, $D, $res);
    return ['modele' => ['id' => (int) $m['id'], 'nom' => (string) $m['nom']], 'visuel' => ['id' => $vid] + $res];
}

/** POST /assistant/magasin/modeles/{id}/supprimer {shop} */
function wr_aia_modele_supprimer(int $id): array
{
    $b = body();
    $sid = ctype_digit((string) ($b['shop'] ?? '')) ? (int) $b['shop'] : 0;
    aiaSchema();
    $n = $sid > 0 ? Db::exec('DELETE FROM aia_modele WHERE id = ? AND shop_id = ?', [$id, $sid]) : 0;
    if ($n === 0) { http_response_code(404); return ['error' => 'modèle inconnu pour ce magasin']; }
    return ['ok' => true];
}

/** Les routes des modèles (le reste de l'assistant est routé dans public/api/index.php). */
function aiaRoute(string $method, string $path): ?array
{
    if (!str_starts_with($path, '/assistant/magasin/modeles')) { return null; }
    if ($method === 'GET' && $path === '/assistant/magasin/modeles') { return ep_aia_modeles(); }
    if ($method === 'POST' && $path === '/assistant/magasin/modeles') { return wr_aia_modele_enregistrer(); }
    if ($method === 'GET' && preg_match('#^/assistant/magasin/modeles/(\d+)$#', $path, $m)) { return ep_aia_modele_ouvrir((int) $m[1]); }
    if ($method === 'POST' && preg_match('#^/assistant/magasin/modeles/(\d+)/supprimer$#', $path, $m)) { return wr_aia_modele_supprimer((int) $m[1]); }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
