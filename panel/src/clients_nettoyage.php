<?php
declare(strict_types=1);

/**
 * Dashboard magasin › Vendre › Clients › « Nettoyer la base » (28/11/2026). Sam, sur la liste des particuliers de Halle :
 * « rajoute un bouton (nettoyage de la base : majuscule des Nom et Prénom etc.) ». Les fiches viennent de la caisse, à
 * Halle 607 sur 680 en MAJUSCULES : le nettoyage les met en forme dans TF Buddy, après un aperçu « avant → après » où
 * chaque fiche peut être décochée, et il se défait (« Annuler ce nettoyage »).
 *
 * Les règles (pcnNomPropre, pcnEmail) :
 *   - un prénom ou un nom TOUT en majuscules ou TOUT en minuscules passe en Nom Propre (« GABRIELLE » → « Gabrielle »,
 *     « JEAN-PIERRE » → « Jean-Pierre », « D'HONDT » → « D'Hondt ») ; une casse mêlée (« McDonald », « DeSmet ») se respecte ;
 *   - dans un nom de famille, les particules qui suivent le premier mot restent en minuscules (« VAN DEN BERGHE » →
 *     « Van den Berghe », « DE SMET » → « De Smet ») ;
 *   - les espaces en trop partent ; l'e-mail passe en minuscules, sans espace (s'il reste un e-mail valable) ;
 *   - « ... », « ---- » (un nom incomplet, la caisse ne savait pas) restent tels quels : ils se signalent dans la liste
 *     (filtre « Nom incomplet », clients_panel.php) et ne s'effacent jamais d'ici (Sam : « aucune, juste les signaler »).
 *   Le téléphone ne change pas : la caisse retrouve ses clients par lui.
 *
 *   GET  /dashboard/clients/nettoyage?shop=4                          l'aperçu : {propositions: [{id, nom, nomApres, avant,
 *                                                                     apres, incomplet}], regles: {noms, emails}, total, dernier}
 *   POST /dashboard/clients/nettoyage {shop, action: appliquer, ids: [≤ 25], lot?}   PATCH /clients/{id} fiche par fiche
 *   POST /dashboard/clients/nettoyage {shop, action: annuler, lot}                   l'avant revient (25 par appel, `reste`)
 *
 * Le serveur recalcule chaque « après » depuis la liste du magasin relue dans TF Buddy : l'écran n'envoie que des numéros,
 * et un numéro qui n'est pas un particulier de ce magasin ne s'écrit pas. Table panel_clients_nettoyage : l'avant et
 * l'après de chaque fiche écrite, par lot, gardés 90 jours pour pouvoir annuler. Une fiche modifiée depuis (son nom n'est
 * plus l'« après ») ne s'écrase pas à l'annulation : elle est laissée.
 */

const PCN_LOT_MAX = 25;          // fiches écrites par appel (l'écran enchaîne les appels et montre l'avancement)
const PCN_GARDE_JOURS = 90;
/** Les particules d'un nom de famille belge ou néerlandais, en minuscules quand elles ne sont pas le premier mot. */
const PCN_PARTICULES = ['van', 'de', 'den', 'der', 'du', 'des', 'la', 'le', 'von', 'het', 'ter', 'ten', 'op', 'in', "'t", 'te'];

function ensureClientsNettoyage(): void
{
    static $fait = false;
    if ($fait || !class_exists('Db')) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS panel_clients_nettoyage ('
        . 'id INT AUTO_INCREMENT PRIMARY KEY, shop_id VARCHAR(16) NOT NULL, lot CHAR(12) NOT NULL, client_id INT NOT NULL,'
        . 'avant TEXT NOT NULL, apres TEXT NOT NULL, etat VARCHAR(10) NOT NULL DEFAULT "fait",'   // fait | annule | laisse
        . 'fait_le DATETIME NOT NULL, annule_le DATETIME NULL, KEY k_lot (shop_id, lot)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/* ── Les règles (testé : panel/bin/tests_clients.php) ───────────────────────────────────────────────────────────── */

function pcnEspaces(string $v): string
{
    return trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
}

/** Un prénom ($famille = false) ou un nom de famille en Nom Propre, s'il était tout en majuscules ou tout en minuscules. */
function pcnNomPropre(string $v, bool $famille): string
{
    $v = pcnEspaces($v);
    if (preg_match('/\p{L}/u', $v) !== 1) { return $v; }
    if (preg_match('/\p{Lu}/u', $v) === 1 && preg_match('/\p{Ll}/u', $v) === 1) { return $v; }
    $mots = explode(' ', mb_strtolower($v, 'UTF-8'));
    foreach ($mots as $i => $m) {
        if ($famille && $i > 0 && in_array($m, PCN_PARTICULES, true)) { continue; }
        $mots[$i] = preg_replace_callback("/(^|[-'’])(\p{Ll})/u", static fn ($x) => $x[1] . mb_strtoupper($x[2], 'UTF-8'), $m) ?? $m;
    }
    return implode(' ', $mots);
}

/** L'e-mail en minuscules, sans espace ; s'il n'en sort pas un e-mail valable, il ne bouge pas. */
function pcnEmail(string $v): string
{
    if (trim($v) === '') { return $v; }
    $e = mb_strtolower(preg_replace('/\s+/u', '', $v) ?? $v, 'UTF-8');
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : $v;
}

/**
 * Ce que le nettoyage changerait sur une fiche de TF Buddy : {id, nom, avant, apres, incomplet}, avant et après limités
 * aux champs qui changent ; null si la fiche est propre. Un champ qui deviendrait vide (des espaces seulement) ne bouge pas.
 */
function pcnProposer(array $c): ?array
{
    $avant = ['name' => pcnTexte($c['name'] ?? ''), 'surname' => pcnTexte($c['surname'] ?? ''), 'email' => pcnTexte($c['email'] ?? '')];
    $apres = ['name' => pcnNomPropre($avant['name'], false), 'surname' => pcnNomPropre($avant['surname'], true), 'email' => pcnEmail($avant['email'])];
    $av = []; $ap = [];
    foreach ($apres as $k => $v) {
        if ($v === $avant[$k] || $v === '') { continue; }
        $av[$k] = $avant[$k]; $ap[$k] = $v;
    }
    if (!$ap) { return null; }
    return ['id' => (int) ($c['id'] ?? 0), 'nom' => pcnEspaces($avant['name'] . ' ' . $avant['surname']),
        'nomApres' => pcnEspaces(($ap['name'] ?? $avant['name']) . ' ' . ($ap['surname'] ?? $avant['surname'])), 'avant' => $av, 'apres' => $ap,
        'incomplet' => !pclNomComplet($avant['name'], $avant['surname'])];
}

/** Un champ tel que TF Buddy le rend (espaces compris : le nettoyage les voit), '' pour ce qui n'est pas un texte. */
function pcnTexte($v): string
{
    return is_scalar($v) ? (string) $v : '';
}

/** Combien de lignes de la liste gardée (pclCourt) le nettoyage changerait — le résumé de l'onglet Particuliers. */
function pcnAFormer(array $courts): int
{
    $n = 0;
    foreach ($courts as $c) {
        if (pcnProposer(['id' => $c['id'] ?? 0, 'name' => $c['prenom'] ?? '', 'surname' => $c['nomFam'] ?? '', 'email' => $c['email'] ?? '']) !== null) { $n++; }
    }
    return $n;
}

/* ── TF Buddy et la table ─────────────────────────────────────────────────────────────────────────────────────── */

/** Les particuliers du magasin tels que TF Buddy les rend, relus à chaque fois : [id => fiche], ou null s'ils ne se lisent pas. */
function pcnBrut(int $shop): ?array
{
    $out = [];
    $ajouter = static function ($corps) use (&$out): int {
        $l = pclItems($corps);
        foreach ($l as $x) {
            if (!is_array($x) || (int) ($x['id'] ?? 0) <= 0 || !empty($x['is_b2b']) || !empty($x['merged_into_id']) || !empty($x['blocked']) || (isset($x['active']) && !$x['active'])) { continue; }
            $out[(int) $x['id']] = $x;
        }
        return count($l);
    };
    $r = pclTf('GET', '/shops/' . $shop . '/clients?type=b2c', null, 40);
    $n = $r['code'] >= 200 && $r['code'] < 300 && is_array($r['corps']) ? $ajouter($r['corps']) : -1;
    if ($n < 0 || ($n > 0 && $n % 50 === 0)) {   // le repli par pages, comme pclListe
        $out = [];
        for ($page = 0; $page < PCL_PAGES_MAX; $page++) {
            $p = pclTf('GET', '/shops/' . $shop . '/clients?type=b2c&limit=' . PCL_PAGE . '&offset=' . ($page * PCL_PAGE));
            if ($p['code'] < 200 || $p['code'] >= 300) { if ($page === 0) { return null; } break; }
            if ($ajouter($p['corps']) < PCL_PAGE) { break; }
        }
    }
    return $out;
}

/** Le dernier nettoyage du magasin : {lot, n, restants (encore annulables), le} ou null. */
function pcnDernier(int $shop): ?array
{
    if (!class_exists('Db')) { return null; }
    try {
        ensureClientsNettoyage();
        $r = Db::row('SELECT lot, COUNT(*) AS n, SUM(etat = "fait") AS restants, MAX(fait_le) AS le FROM panel_clients_nettoyage WHERE shop_id = ? GROUP BY lot ORDER BY le DESC LIMIT 1', [(string) $shop]);
    } catch (Throwable $e) { return null; }
    return $r ? ['lot' => (string) $r['lot'], 'n' => (int) $r['n'], 'restants' => (int) $r['restants'], 'le' => (string) $r['le']] : null;
}

/* ── Les routes ───────────────────────────────────────────────────────────────────────────────────────────────── */

function ep_clients_nettoyage(): array
{
    $shop = pclShop();
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $l = pcnBrut($shop);
    if ($l === null) { http_response_code(502); return ['error' => 'les particuliers du magasin ne se lisent pas dans TF Buddy']; }
    $props = [];
    foreach ($l as $c) { $p = pcnProposer($c); if ($p !== null) { $props[] = $p; } }
    usort($props, static fn ($a, $b) => strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom'])) ?: $a['id'] <=> $b['id']);
    return ['shop' => $shop, 'total' => count($l), 'propositions' => $props, 'lotMax' => PCN_LOT_MAX,
        'regles' => ['noms' => count(array_filter($props, static fn ($p) => isset($p['apres']['name']) || isset($p['apres']['surname']))),
            'emails' => count(array_filter($props, static fn ($p) => isset($p['apres']['email'])))],
        'dernier' => pcnDernier($shop)];
}

function wr_clients_nettoyage(): array
{
    $b = body();
    $shop = pclShop($b);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'shop manquant']; }
    $action = pclTexte($b['action'] ?? '');
    $lot = pclTexte($b['lot'] ?? '');
    if ($lot !== '' && preg_match('/^[a-z0-9]{12}$/', $lot) !== 1) { http_response_code(422); return ['error' => 'lot illisible']; }
    if ($action === 'appliquer') {
        $ids = array_values(array_unique(array_map('intval', is_array($b['ids'] ?? null) ? $b['ids'] : [])));
        if (count(is_array($b['ids'] ?? null) ? $b['ids'] : []) > PCN_LOT_MAX) { http_response_code(422); return ['error' => PCN_LOT_MAX . ' fiches au plus par appel']; }
        if (!$ids) { http_response_code(422); return ['error' => 'aucune fiche choisie']; }
        $l = pcnBrut($shop);
        if ($l === null) { http_response_code(502); return ['error' => 'les particuliers du magasin ne se lisent pas dans TF Buddy']; }
        if ($lot === '') { $lot = substr(bin2hex(random_bytes(8)), 0, 12); }
        if (class_exists('Db')) {
            try { ensureClientsNettoyage(); Db::exec('DELETE FROM panel_clients_nettoyage WHERE fait_le < NOW() - INTERVAL ' . PCN_GARDE_JOURS . ' DAY'); } catch (Throwable $e) { /* la table se nettoiera au prochain lot */ }
        }
        $faits = []; $echecs = []; $ignores = 0;
        foreach ($ids as $id) {
            $p = isset($l[$id]) ? pcnProposer($l[$id]) : null;
            if ($p === null) { $ignores++; continue; }   // pas un particulier de ce magasin, ou déjà propre
            try { $corps = pclCoordonnees($p['apres'], ['name', 'surname', 'email']); } catch (InvalidArgumentException $e) { $echecs[] = ['id' => $id, 'nom' => $p['nom'], 'motif' => $e->getMessage()]; continue; }
            $r = pclTf('PATCH', '/clients/' . $id, $corps);
            if ($r['code'] < 200 || $r['code'] >= 300) { $echecs[] = ['id' => $id, 'nom' => $p['nom'], 'motif' => pclMotif($r)]; continue; }
            $faits[] = $id;
            if (class_exists('Db')) {
                try {
                    Db::exec('INSERT INTO panel_clients_nettoyage (shop_id, lot, client_id, avant, apres, fait_le) VALUES (?, ?, ?, ?, ?, NOW())',
                        [(string) $shop, $lot, $id, json_encode($p['avant'], JSON_UNESCAPED_UNICODE), json_encode($p['apres'], JSON_UNESCAPED_UNICODE)]);
                } catch (Throwable $e) { /* écrit dans TF Buddy ; seule l'annulation de cette fiche manquera */ }
            }
        }
        if ($faits) { pclOublier($shop); }
        if (function_exists('journalAdd') && ($faits || $echecs)) {
            journalAdd('dashboard', 'clients', null, 'Magasin ' . $shop . ' : nettoyage de la base, ' . count($faits) . ' fiche(s) mises en forme dans TF Buddy' . ($echecs ? ', ' . count($echecs) . ' refusée(s)' : '') . ' (lot ' . $lot . ')');
        }
        return ['ok' => true, 'lot' => $lot, 'faits' => $faits, 'echecs' => $echecs, 'ignores' => $ignores];
    }
    if ($action === 'annuler') {
        if ($lot === '') { http_response_code(422); return ['error' => 'quel nettoyage annuler ?']; }
        if (!class_exists('Db')) { http_response_code(503); return ['error' => 'pas de base : rien à annuler']; }
        ensureClientsNettoyage();
        $L = Db::rows('SELECT id, client_id, avant, apres FROM panel_clients_nettoyage WHERE shop_id = ? AND lot = ? AND etat = "fait" ORDER BY id LIMIT ' . PCN_LOT_MAX, [(string) $shop, $lot]);
        if (!$L) { return ['ok' => true, 'lot' => $lot, 'annules' => 0, 'laisses' => 0, 'echecs' => [], 'reste' => 0]; }
        $l = pcnBrut($shop);
        if ($l === null) { http_response_code(502); return ['error' => 'les particuliers du magasin ne se lisent pas dans TF Buddy']; }
        $annules = 0; $laisses = 0; $echecs = [];
        foreach ($L as $x) {
            $id = (int) $x['client_id'];
            $avant = json_decode((string) $x['avant'], true) ?: [];
            $apres = json_decode((string) $x['apres'], true) ?: [];
            $c = $l[$id] ?? null;
            $intact = $c !== null;
            foreach ($apres as $k => $v) { if ($intact && pcnTexte($c[$k] ?? '') !== $v) { $intact = false; } }
            if (!$intact) {   // modifiée depuis (ou plus dans le magasin) : on ne l'écrase pas
                $laisses++;
                Db::exec('UPDATE panel_clients_nettoyage SET etat = "laisse", annule_le = NOW() WHERE id = ?', [(int) $x['id']]);
                continue;
            }
            try { $corps = pclCoordonnees($avant, ['name', 'surname', 'email']); } catch (InvalidArgumentException $e) { $corps = pclCoordonnees(array_diff_key($avant, ['email' => 1]), ['name', 'surname']); }
            $r = $corps ? pclTf('PATCH', '/clients/' . $id, $corps) : ['code' => 200];
            if ($r['code'] < 200 || $r['code'] >= 300) { $echecs[] = ['id' => $id, 'motif' => pclMotif($r)]; continue; }
            $annules++;
            Db::exec('UPDATE panel_clients_nettoyage SET etat = "annule", annule_le = NOW() WHERE id = ?', [(int) $x['id']]);
        }
        pclOublier($shop);
        $reste = Db::row('SELECT COUNT(*) AS n FROM panel_clients_nettoyage WHERE shop_id = ? AND lot = ? AND etat = "fait"', [(string) $shop, $lot]);
        if (function_exists('journalAdd')) { journalAdd('dashboard', 'clients', null, 'Magasin ' . $shop . ' : nettoyage annulé, ' . $annules . ' fiche(s) remises comme avant, ' . $laisses . ' laissée(s) (modifiées depuis) (lot ' . $lot . ')'); }
        return ['ok' => true, 'lot' => $lot, 'annules' => $annules, 'laisses' => $laisses, 'echecs' => $echecs, 'reste' => $echecs ? 0 : (int) ($reste['n'] ?? 0)];
    }
    http_response_code(400);
    return ['error' => 'action inconnue'];
}
