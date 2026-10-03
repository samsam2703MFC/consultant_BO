<?php
declare(strict_types=1);

/*
 * LES REMARQUES DES CLIENTS — saisies au comptoir sur la tablette des vendeuses.
 *
 * Une vendeuse note ce qu'un client vient de dire (compliment, suggestion,
 * réclamation) ; la tablette l'envoie ici (POST /tablette/remarques), et l'écran
 * « Tablette vendeuses » du BO les montre magasin par magasin, chacune avec sa
 * case « Traitée » (GET, puis PATCH /tablette/remarques/{id}).
 * Contrat figé avec la tablette : docs/contrat-api.md.
 *
 * La tablette garde une remarque tant qu'elle n'a reçu ni 2xx ni 4xx : elle en
 * renvoie donc parfois une déjà reçue. Son `id` (uuid tiré sur la tablette) est
 * la clé primaire — un renvoi rend 200 « doublon », jamais une deuxième ligne.
 * Un 400 est définitif (la tablette jette la remarque) : il ne sort que pour une
 * remarque qui ne sera jamais valable (id, type, texte). Ce qui se corrige sans
 * rien inventer (une date de saisie illisible ou dans le futur, une langue
 * absente, un magasin inconnu — gardé sans magasin, la valeur reçue à côté) est
 * corrigé plutôt que refusé.
 *
 * L'API est ouverte (auth désactivée en production, comme tout le cockpit) : le
 * plafond de 200 remarques par magasin et par jour borne ce qu'un appel abusif
 * pourrait remplir. Table : ceo_tablette_remarque (installer.php,
 * ensureTabletteRemarques).
 */

const TRM_TYPES = ['compliment', 'suggestion', 'reclamation'];
const TRM_LIBELLES = ['compliment' => 'Compliment', 'suggestion' => 'Suggestion', 'reclamation' => 'Réclamation'];
const TRM_TEXTE_MAX = 1000;     // caractères, après les espaces de bord retirés
const TRM_PAR_JOUR = 200;       // par magasin (« sans magasin » compte pour un) et par jour de réception
const TRM_JOURS_DEFAUT = 30;
const TRM_JOURS_MAX = 366;
const TRM_LISTE_MAX = 1000;     // remarques rendues au plus par lecture ; `total` et `nonTraitees` comptent tout

/**
 * POST /tablette/remarques — {id, shop, type, texte, langue, saisieLe}.
 * 201 {ok, id} ; déjà reçue → 200 {ok, id, doublon: true} ; invalide → 400 ;
 * plafond du jour atteint → 429 ; base injoignable → 503 (la tablette réessaie).
 */
function wr_tablette_remarque_creer(): array
{
    $b = body();
    $id = trmUuid($b['id'] ?? null);
    if ($id === null) { return trmRefus(400, 'id manquant ou mal formé (uuid attendu)'); }

    // Déjà reçue : la tablette renvoie ce qu'elle n'a pas vu acquitté. Vérifié
    // avant le plafond — une remarque gardée ne doit pas se faire refuser.
    if (Db::row('SELECT id FROM ceo_tablette_remarque WHERE id = ?', [$id]) !== null) {
        return ['ok' => true, 'id' => $id, 'doublon' => true];
    }

    $type = $b['type'] ?? null;
    if (!is_string($type) || !in_array($type, TRM_TYPES, true)) {
        return trmRefus(400, 'type inconnu (compliment, suggestion ou reclamation)');
    }
    $texte = trmTexte($b['texte'] ?? null);
    $n = mb_strlen($texte);
    if ($n === 0) { return trmRefus(400, 'texte vide'); }
    if ($n > TRM_TEXTE_MAX) { return trmRefus(400, 'texte trop long (' . $n . ' caractères, ' . TRM_TEXTE_MAX . ' au plus)'); }

    // Le magasin : un magasin connu, ou aucun (tablette sans magasin, vue réseau).
    // Un magasin inconnu ne fait jamais perdre la remarque : elle est gardée sans
    // magasin, avec la valeur reçue (`shop_brut`), que l'écran BO affiche.
    $shop = null;
    $brut = null;
    $nom = null;
    $s = $b['shop'] ?? null;
    if ($s !== null) {
        $v = trmTexte(is_scalar($s) ? (string) $s : (string) json_encode($s, JSON_UNESCAPED_UNICODE));
        $noms = preg_match('/^[A-Za-z0-9_-]{1,32}$/', $v) ? trmMagasins([$v]) : [];
        if (isset($noms[$v])) {
            $shop = $v;
            $nom = $noms[$v];
        } elseif ($v !== '') {
            $brut = mb_substr($v, 0, 32);
            $nom = 'magasin inconnu (' . $brut . ')';
        }
    }
    $langue = ($b['langue'] ?? null) === 'nl' ? 'nl' : 'fr';

    $recu = new DateTimeImmutable('now');
    $saisie = trmSaisie($b['saisieLe'] ?? null, $recu);

    $deja = (int) (Db::row('SELECT COUNT(*) AS n FROM ceo_tablette_remarque WHERE shop_id <=> ? AND recu_le >= ?',
        [$shop, $recu->format('Y-m-d 00:00:00')])['n'] ?? 0);
    if ($deja >= TRM_PAR_JOUR) {
        return trmRefus(429, 'trop de remarques pour ce magasin aujourd’hui (' . TRM_PAR_JOUR . ' au plus par jour)');
    }

    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    try {
        Db::exec('INSERT INTO ceo_tablette_remarque (id, shop_id, shop_brut, type, texte, langue, saisie_le, recu_le, traitee, traitee_le, ip)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, ?)',
            [$id, $shop, $brut, $type, $texte, $langue, $saisie->format('Y-m-d H:i:s'), $recu->format('Y-m-d H:i:s'), $ip !== '' ? $ip : null]);
    } catch (PDOException $e) {
        // Deux envois croisés du même id : le second trouve la ligne du premier.
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) { return ['ok' => true, 'id' => $id, 'doublon' => true]; }
        throw $e;
    }
    // Le journal ne dit que le fait, pas le texte du client (qui reste dans sa table).
    try { journalAdd('Tablette', 'Remarque client', $nom, 'Remarque « ' . TRM_LIBELLES[$type] . ' » reçue de la tablette (' . $n . ' caractères, ' . $langue . ')'); }
    catch (Throwable $e) { /* la remarque est enregistrée : le journal ne la fait pas renvoyer */ }

    http_response_code(201);
    return ['ok' => true, 'id' => $id];
}

/**
 * GET /tablette/remarques?shop=<id>&jours=30 — les remarques saisies depuis
 * `jours` jours (minuit), les plus récentes d'abord ; sans `shop`, tous les
 * magasins (et celles des tablettes sans magasin). `total` et `nonTraitees`
 * comptent toute la période ; `remarques` en porte au plus 1 000.
 */
function ep_tablette_remarques(): array
{
    $s = trim((string) ($_GET['shop'] ?? ''));
    if ($s !== '' && !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $s)) { return trmRefus(400, 'magasin mal formé'); }
    $jours = (int) ($_GET['jours'] ?? TRM_JOURS_DEFAUT);
    $jours = $jours < 1 ? TRM_JOURS_DEFAUT : min($jours, TRM_JOURS_MAX);
    $depuis = (new DateTimeImmutable('today'))->modify('-' . $jours . ' days')->format('Y-m-d H:i:s');

    $where = 'saisie_le >= ?' . ($s !== '' ? ' AND shop_id = ?' : '');
    $p = $s !== '' ? [$depuis, $s] : [$depuis];
    $c = Db::row('SELECT COUNT(*) AS n, COALESCE(SUM(traitee = 0), 0) AS nt FROM ceo_tablette_remarque WHERE ' . $where, $p);
    $rows = Db::rows('SELECT id, shop_id, shop_brut, type, texte, langue, saisie_le, recu_le, traitee, traitee_le
                      FROM ceo_tablette_remarque WHERE ' . $where . '
                      ORDER BY saisie_le DESC, recu_le DESC LIMIT ' . TRM_LISTE_MAX, $p);

    $noms = trmMagasins(array_filter(array_column($rows, 'shop_id'), static fn ($v) => $v !== null));
    $out = [];
    foreach ($rows as $r) {
        $sid = $r['shop_id'] === null ? null : (string) $r['shop_id'];
        $out[] = [
            'id' => (string) $r['id'],
            'shop' => $sid === null ? null : ['id' => $sid, 'nom' => $noms[$sid] ?? $sid],
            // Le magasin envoyé par la tablette quand il n'était pas connu (`shop` est alors null).
            'shopBrut' => $r['shop_brut'] === null ? null : (string) $r['shop_brut'],
            'type' => (string) $r['type'],
            'texte' => (string) $r['texte'],
            'langue' => (string) $r['langue'],
            'saisieLe' => trmIso($r['saisie_le']),
            'recuLe' => trmIso($r['recu_le']),
            'traitee' => (int) $r['traitee'] === 1,
            'traiteeLe' => trmIso($r['traitee_le']),
        ];
    }
    return ['remarques' => $out, 'total' => (int) ($c['n'] ?? 0), 'nonTraitees' => (int) ($c['nt'] ?? 0)];
}

/** PATCH /tablette/remarques/{id} — {traitee: true|false} → 200 {ok: true}. */
function wr_tablette_remarque_traiter(string $id): array
{
    $id = trmUuid($id);
    if ($id === null) { return trmRefus(404, 'remarque inconnue'); }
    $b = body();
    $t = $b['traitee'] ?? null;
    if (!in_array($t, [true, false, 0, 1], true)) { return trmRefus(400, 'traitee attendu : true ou false'); }
    $t = (bool) $t;
    $r = Db::row('SELECT shop_id, shop_brut, type, traitee FROM ceo_tablette_remarque WHERE id = ?', [$id]);
    if ($r === null) { return trmRefus(404, 'remarque inconnue'); }
    if (((int) $r['traitee'] === 1) !== $t) {
        Db::exec('UPDATE ceo_tablette_remarque SET traitee = ?, traitee_le = ? WHERE id = ?',
            [$t ? 1 : 0, $t ? date('Y-m-d H:i:s') : null, $id]);
        $sid = $r['shop_id'] === null ? null : (string) $r['shop_id'];
        $nom = $sid !== null ? (trmMagasins([$sid])[$sid] ?? $sid)
            : ($r['shop_brut'] !== null ? 'magasin inconnu (' . $r['shop_brut'] . ')' : null);
        try { journalAdd('CEO', 'Remarque client', $nom, 'Remarque « ' . (TRM_LIBELLES[(string) $r['type']] ?? (string) $r['type']) . ' » ' . ($t ? 'marquée traitée' : 'rouverte')); }
        catch (Throwable $e) { /* le geste est fait : le journal ne le défait pas */ }
    }
    return ['ok' => true];
}

/* --- Les aides --------------------------------------------------------------------------------- */

/** Un refus : le statut HTTP fait foi, le corps dit pourquoi. */
function trmRefus(int $code, string $msg): array
{
    http_response_code($code);
    return ['erreur' => $msg];
}

/** L'uuid en minuscules (8-4-4-4-12 hexadécimal), ou null. */
function trmUuid(mixed $v): ?string
{
    if (!is_string($v)) { return null; }
    $v = strtolower(trim($v));
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) ? $v : null;
}

/** Le texte saisi, sans caractères de contrôle (hors retours à la ligne et tabulations) ni espaces de bord. */
function trmTexte(mixed $v): string
{
    if (!is_string($v)) { return ''; }
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $v)) ?? '';
    return trim($v);
}

/**
 * L'heure de saisie sur la tablette, ramenée au fuseau du serveur. Absente,
 * illisible, d'avant 2024 (horloge remise à zéro) ou dans le futur (horloge en
 * avance) : l'heure de réception, plutôt que de perdre la remarque.
 */
function trmSaisie(mixed $v, DateTimeImmutable $recu): DateTimeImmutable
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $v)) { return $recu; }
    try { $d = new DateTimeImmutable($v); } catch (Exception $e) { return $recu; }
    $d = $d->setTimezone($recu->getTimezone());
    return ((int) $d->format('Y') < 2024 || $d > $recu) ? $recu : $d;
}

/** Une date de la table, en ISO 8601 avec le décalage du serveur ; null reste null. */
function trmIso(mixed $dt): ?string
{
    if ($dt === null || $dt === '') { return null; }
    try { return (new DateTimeImmutable((string) $dt))->format('c'); } catch (Exception $e) { return null; }
}

/**
 * Les noms courts des magasins demandés, [id => nom] ; un id absent n'est pas un
 * magasin connu. `shops` (la table partagée du panel), sinon `ceo_shop` — le même
 * repli que /stores.
 */
function trmMagasins(array $ids): array
{
    $ids = array_values(array_unique(array_map('strval', $ids)));
    if (!$ids) { return []; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $rows = Db::rows('SELECT id, name FROM shops WHERE id IN (' . $in . ')', $ids);
    } catch (PDOException $e) {
        try { $rows = Db::rows('SELECT id, name FROM ceo_shop WHERE id IN (' . $in . ')', $ids); } catch (PDOException $e2) { $rows = []; }
    }
    $out = [];
    foreach ($rows as $r) { $out[(string) $r['id']] = psCourt((string) $r['name']); }
    return $out;
}
