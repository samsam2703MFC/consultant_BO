<?php
declare(strict_types=1);

/**
 * Cockpit CEO — les agences.
 *
 * Le réseau travaille avec PLUSIEURS agences : une pour la création, une pour
 * l'achat média, une pour le shooting. Le réglage unique — un nom, un logo,
 * une adresse — ne pouvait en porter qu'une, et la note signait toujours la
 * même quelle que soit la campagne.
 *
 * Le référentiel vit dans `mar_agency`, la table du module marketing : c'est
 * elle que les canaux d'une campagne désignent déjà (`mar_campaign_channel
 * .agency_id`). En créer une seconde aurait donné deux listes d'agences, et un
 * canal pointant vers l'une pendant que la note signe l'autre.
 *
 * Les colonnes qui manquaient — adresse, site, logo, agence par défaut — sont
 * ajoutées à la table existante, jamais supposées : le module les ignore, et
 * il continue de fonctionner sans elles.
 */

/** Les colonnes réellement présentes sur `mar_agency`. */
function agenceColonnes(): array
{
    static $cols = null;
    if ($cols !== null) { return $cols; }
    $cols = [];
    try {
        foreach (Db::rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mar_agency'") as $r) {
            $v = is_array($r) ? (string) reset($r) : (string) $r;
            if ($v !== '') { $cols[] = strtolower($v); }
        }
    } catch (Throwable $e) { /* introspection muette : on tentera l'ALTER */ }
    return $cols;
}

/**
 * Ajoute ce qui manque à `mar_agency`, une fois par requête.
 *
 * Tolérant et idempotent : une colonne déjà là fait échouer l'ALTER, ce qui
 * n'est pas une erreur — deux requêtes concurrentes peuvent la créer en même
 * temps.
 */
function agenceTable(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    $cols = agenceColonnes();
    foreach (['email' => 'VARCHAR(190) NULL',
              'site' => 'VARCHAR(190) NULL',
              'logo_uri' => 'MEDIUMTEXT NULL',
              'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0'] as $col => $type) {
        if ($cols !== [] && in_array($col, $cols, true)) { continue; }
        try { Db::exec("ALTER TABLE mar_agency ADD COLUMN `$col` $type"); }
        catch (Throwable $e) { /* déjà ajoutée, ou table absente */ }
    }

    // Reprise du réglage unique : l'agence saisie avant ce référentiel entre
    // dans la table plutôt que de disparaître de l'écran.
    try {
        $n = Db::row('SELECT COUNT(*) n FROM mar_agency');
        if ((int) ($n['n'] ?? 0) === 0) {
            $c = mktBriefConfig();
            $ag = $c['agence'] ?? [];
            if (trim((string) ($ag['nom'] ?? '')) !== '') {
                Db::exec('INSERT INTO mar_agency (name, email, site, logo_uri, is_default)
                          VALUES (?,?,?,?,1)',
                    [mb_substr(trim((string) $ag['nom']), 0, 160),
                     mktBriefAdresse($ag['email'] ?? ''),
                     mb_substr(trim((string) ($ag['site'] ?? '')), 0, 190),
                     (string) ($ag['logo'] ?? '')]);
            }
        }
    } catch (Throwable $e) { /* table absente : l'écran le dira */ }
}

/** Une agence, telle que les écrans la lisent. */
function agenceLigne(array $a): array
{
    return [
        'id' => (int) $a['id'],
        'nom' => (string) $a['name'],
        'email' => mktBriefAdresse($a['email'] ?? '') ?: mktBriefAdresse($a['site'] ?? ''),
        'site' => (string) ($a['site'] ?? ''),
        'logo' => (string) ($a['logo_uri'] ?? ''),
        'defaut' => (bool) ($a['is_default'] ?? false),
        'campagnes' => (int) ($a['campagnes'] ?? 0),
    ];
}

/** @return list<array<string,mixed>> */
function agenceListe(): array
{
    agenceTable();
    try {
        return array_map('agenceLigne', Db::rows(
            'SELECT a.*, (SELECT COUNT(DISTINCT cc.campaign_id) FROM mar_campaign_channel cc
                            WHERE cc.agency_id = a.id) AS campagnes
               FROM mar_agency a ORDER BY a.is_default DESC, a.name'));
    } catch (PDOException $e) { return []; }
}

/** L'agence qui signe : celle de la campagne, sinon celle par défaut. */
function agenceDeCampagne(int $campagne): ?array
{
    agenceTable();
    try {
        $a = Db::row('SELECT a.*, 0 AS campagnes
                        FROM mar_campaign_channel cc
                        JOIN mar_agency a ON a.id = cc.agency_id
                       WHERE cc.campaign_id = ?
                       ORDER BY cc.budget_amount DESC
                       LIMIT 1', [$campagne]);
        if ($a !== null) { return agenceLigne($a); }
        $d = Db::row('SELECT a.*, 0 AS campagnes FROM mar_agency a WHERE a.is_default = 1 LIMIT 1');
        if ($d !== null) { return agenceLigne($d); }
    } catch (PDOException $e) { /* pas de référentiel : le réglage fera foi */ }
    return null;
}

/** GET /marketing/agences. */
function ep_mkt_agences(): array
{
    return ['agences' => agenceListe()];
}

/** POST /marketing/agence — ou PATCH /marketing/agence/{id}. */
function wr_mkt_agence(?int $id): array
{
    agenceTable();
    $b = body();

    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 160);
    if ($id === null && $nom === '') {
        http_response_code(422); return ['error' => 'Le nom de l’agence est obligatoire.'];
    }

    $logo = (string) ($b['logo'] ?? '');
    // Un logo est une IMAGE embarquée : une URL ferait un cadre vide chez le
    // destinataire et rien du tout à l'impression.
    if ($logo !== '' && !preg_match('#^data:image/(png|jpeg|gif|webp|svg\+xml);base64,#', $logo)) { $logo = ''; }
    if (strlen($logo) > 700000) { $logo = ''; }

    try {
        if ($id === null) {
            Db::exec('INSERT INTO mar_agency (name, email, site, logo_uri, is_default) VALUES (?,?,?,?,?)',
                [$nom, mktBriefAdresse($b['email'] ?? ''),
                 mb_substr(trim((string) ($b['site'] ?? '')), 0, 190), $logo,
                 empty($b['defaut']) ? 0 : 1]);
            $id = (int) Db::pdo()->lastInsertId();
            journalAdd('CEO', 'Paramètre', $nom, 'Agence ajoutée');
        } else {
            $dej = Db::row('SELECT * FROM mar_agency WHERE id = ?', [$id]);
            if ($dej === null) { http_response_code(404); return ['error' => 'agence inconnue']; }
            Db::exec('UPDATE mar_agency SET name = ?, email = ?, site = ?, logo_uri = ?, is_default = ? WHERE id = ?',
                [$nom !== '' ? $nom : (string) $dej['name'],
                 array_key_exists('email', $b) ? mktBriefAdresse($b['email']) : mktBriefAdresse($dej['email'] ?? ''),
                 array_key_exists('site', $b) ? mb_substr(trim((string) $b['site']), 0, 190) : (string) ($dej['site'] ?? ''),
                 array_key_exists('logo', $b) ? $logo : (string) ($dej['logo_uri'] ?? ''),
                 array_key_exists('defaut', $b) ? (empty($b['defaut']) ? 0 : 1) : (int) ($dej['is_default'] ?? 0),
                 $id]);
        }
        // Une seule agence par défaut : c'est elle qui signe quand la campagne
        // n'en désigne aucune, et deux « par défaut » ne veut rien dire.
        if (!empty($b['defaut'])) {
            Db::exec('UPDATE mar_agency SET is_default = 0 WHERE id <> ?', [$id]);
        }
    } catch (PDOException $e) {
        http_response_code(503);
        return ['error' => 'le référentiel des agences est indisponible : ' . $e->getMessage()];
    }

    return ['ok' => true, 'agences' => agenceListe()];
}

/** DELETE /marketing/agence/{id}. */
function wr_mkt_agence_suppr(int $id): array
{
    agenceTable();
    try {
        $dej = Db::row('SELECT name FROM mar_agency WHERE id = ?', [$id]);
        if ($dej === null) { http_response_code(404); return ['error' => 'agence inconnue']; }
        // Une agence qui porte des canaux ne s'efface pas : les campagnes
        // passées perdraient qui les a faites.
        $n = Db::row('SELECT COUNT(*) n FROM mar_campaign_channel WHERE agency_id = ?', [$id]);
        if ((int) ($n['n'] ?? 0) > 0) {
            http_response_code(409);
            return ['error' => (int) $n['n'] . ' canal(aux) de campagne la désignent — elle ne peut pas être retirée'];
        }
        Db::exec('DELETE FROM mar_agency WHERE id = ?', [$id]);
        journalAdd('CEO', 'Paramètre', (string) $dej['name'], 'Agence retirée');
    } catch (PDOException $e) {
        http_response_code(503);
        return ['error' => 'le référentiel des agences est indisponible'];
    }
    return ['ok' => true, 'agences' => agenceListe()];
}
