<?php
declare(strict_types=1);

/**
 * Les connecteurs du cockpit, et ce qu'ils ont donné la dernière fois.
 *
 * Le cockpit dépend de quatre systèmes extérieurs — le panel consultant, l'ERP,
 * Google et Anthropic. Leurs secrets vivaient déjà chacun dans son coin de
 * `ceo_app_setting`, et c'est très bien ainsi : chaque client sait lire le
 * sien, avec ses règles (« un champ vide n'efface rien »). Ce qui manquait
 * n'était pas un endroit pour les clés, c'était une VUE : lequel est
 * configuré, quand a-t-il tourné pour la dernière fois, et qu'a-t-il répondu.
 *
 * D'où la séparation tenue ici :
 *
 *  - les SECRETS restent où ils sont. Les déplacer aurait touché quatre
 *    chemins qui fonctionnent, dont deux en production, pour ne rien gagner
 *    que du rangement ;
 *  - l'ÉTAT vient dans cette table. Il ne se déduit d'aucun réglage : il
 *    s'écrit quand un appel a lieu.
 *
 * Ce qui est enregistré est un GESTE, pas une lecture : la synchronisation
 * Google, un test de compte, une proposition de note. Écrire une ligne à
 * chaque appel amont coûterait une écriture par requête HTTP — le panel en
 * fait des dizaines en parallèle sur un seul écran.
 *
 * `configure` n'est jamais stocké : il est calculé à la lecture en interrogeant
 * le client. Une clé retirée doit se voir immédiatement, pas au prochain appel.
 */

/** Le référentiel des connecteurs : leur nom, et ce dont ils sont responsables. */
const CONNECTEURS = [
    'panel'     => ['nom' => 'Panel consultant',  'quoi' => 'Boutiques, ventes, checklists, réquisitions matière'],
    'erp'       => ['nom' => 'ERP TFBuddy',       'quoi' => 'Redevances, gammes saisonnières, facturation magasins'],
    'google'    => ['nom' => 'Google Places',     'quoi' => 'Notes et avis des fiches magasin (Réputation digitale)'],
    'anthropic' => ['nom' => 'Assistance IA',     'quoi' => 'Proposition de note sur photo de comptoir'],
];

function connecteurTable(): void
{
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_connecteur ('
        . 'code VARCHAR(40) PRIMARY KEY,'
        . 'label VARCHAR(80) NOT NULL,'
        . 'last_run_at DATETIME NULL,'
        . 'last_ok_at DATETIME NULL,'
        . 'last_error VARCHAR(400) NULL,'
        . 'last_error_at DATETIME NULL,'
        . 'runs INT NOT NULL DEFAULT 0,'
        . 'items INT NOT NULL DEFAULT 0,'
        . 'detail VARCHAR(400) NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Une ligne par connecteur, posée d'avance : l'écran doit pouvoir dire
    // « jamais appelé » plutôt que de ne rien montrer du tout.
    foreach (CONNECTEURS as $code => $c) {
        Db::exec('INSERT INTO ceo_connecteur (code, label) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE label = VALUES(label)', [$code, $c['nom']]);
    }
}

/**
 * Enregistre le résultat d'un geste.
 *
 * En échec, `last_ok_at` n'est PAS touché : savoir que ça marchait encore
 * hier matin est ce qui distingue une panne d'une configuration jamais faite.
 * En succès, l'erreur précédente est effacée — sinon un message vieux de trois
 * semaines reste affiché à côté d'un connecteur qui va bien.
 */
function connecteurNote(string $code, bool $ok, string $detail = '', int $items = 0): void
{
    if (!isset(CONNECTEURS[$code])) { return; }
    $now = date('Y-m-d H:i:s');
    $detail = $detail !== '' ? mb_substr($detail, 0, 400) : null;
    try {
        if ($ok) {
            Db::exec('UPDATE ceo_connecteur SET last_run_at = ?, last_ok_at = ?, last_error = NULL,
                        last_error_at = NULL, runs = runs + 1, items = ?, detail = ? WHERE code = ?',
                [$now, $now, $items, $detail, $code]);
        } else {
            Db::exec('UPDATE ceo_connecteur SET last_run_at = ?, last_error = ?, last_error_at = ?,
                        runs = runs + 1, detail = ? WHERE code = ?',
                [$now, $detail ?? 'échec', $now, $detail, $code]);
        }
    } catch (PDOException $e) {
        // La table peut manquer sur une base qui n'a pas encore redémarré :
        // tracer l'état ne doit jamais faire échouer le geste lui-même.
    }
}

/** Un connecteur est-il configuré ? Demandé au client, jamais stocké. */
function connecteurConfigure(string $code): bool
{
    return match ($code) {
        'panel'     => class_exists('PanelApi') && PanelApi::configured(),
        'erp'       => class_exists('ErpApi') && ErpApi::configured(),
        'google'    => class_exists('GoogleApi') && GoogleApi::configured(),
        'anthropic' => class_exists('Anthropic') && Anthropic::configured(),
        default     => false,
    };
}

/**
 * GET /connecteurs — l'état des quatre, pour l'écran Diagnostic.
 *
 * `etat` résume en un mot ce qu'il faut faire : « à configurer » appelle un
 * réglage, « en échec » appelle une vérification, « jamais appelé » ne dit
 * rien de mauvais — le connecteur attend simplement son premier geste.
 */
function ep_connecteurs(): array
{
    try {
        connecteurTable();
        $rows = array_column(Db::rows('SELECT * FROM ceo_connecteur'), null, 'code');
    } catch (PDOException $e) {
        $rows = [];
    }
    $out = [];
    foreach (CONNECTEURS as $code => $c) {
        $r = $rows[$code] ?? [];
        $conf = connecteurConfigure($code);
        $enEchec = ($r['last_error'] ?? null) !== null;
        $out[] = [
            'code' => $code, 'nom' => $c['nom'], 'quoi' => $c['quoi'],
            'configure' => $conf,
            'etat' => !$conf ? 'a-configurer' : ($enEchec ? 'en-echec'
                : (($r['last_ok_at'] ?? null) !== null ? 'ok' : 'jamais')),
            'dernierAppel' => isset($r['last_run_at']) && $r['last_run_at'] !== null ? substr((string) $r['last_run_at'], 0, 16) : null,
            'dernierSucces' => isset($r['last_ok_at']) && $r['last_ok_at'] !== null ? substr((string) $r['last_ok_at'], 0, 16) : null,
            'erreur' => $r['last_error'] ?? null,
            'erreurLe' => isset($r['last_error_at']) && $r['last_error_at'] !== null ? substr((string) $r['last_error_at'], 0, 16) : null,
            'passages' => (int) ($r['runs'] ?? 0),
            'elements' => (int) ($r['items'] ?? 0),
            'detail' => $r['detail'] ?? null,
        ];
    }
    return ['connecteurs' => $out];
}

/** L'état d'un seul connecteur — pour l'écran qui le concerne. */
function connecteurEtat(string $code): ?array
{
    foreach (ep_connecteurs()['connecteurs'] as $c) {
        if ($c['code'] === $code) { return $c; }
    }
    return null;
}
