<?php
declare(strict_types=1);

/**
 * Le dashboard magasin › Les chiffres › Frais (16/11/2026, maquette docs/maquettes/frais-tva) : les frais du mois tels
 * que le panel les rend, et pour chaque poste « TVA comprise ? » et le taux. Le calcul (lecture des frais, choix, part
 * hors TVA, P&L hors TVA) est dans src/pnl_ht.php, partagé avec le cockpit du siège ; ici, les deux routes de l'onglet.
 *
 *   GET  /exploitation/frais?shop=4[&mois=2026-11]         l'onglet Frais : les frais du mois au panel, le choix de chaque poste
 *   POST /exploitation/frais/tva {shop, poste, tva, taux}   le choix d'un poste ; `tva` : true, false, ou null (à indiquer)
 *   POST /exploitation/frais/ajout {shop, nom, type, montant, base, mois, repeter, tva, taux}
 *                                                           un frais ajouté au panel, ce mois seulement ou mois après mois
 *   POST /exploitation/frais/annuler {shop, lot}            un ajout défait : ses lignes supprimées au panel
 *
 * Tables : `panel_frais_tva` (ensureFraisTva, src/pnl_ht.php) ; `panel_frais_ajout` (ensureFraisAjout, ci-dessous), les
 * lignes que le dashboard a créées au panel, pour pouvoir les défaire. Le panel n'est modifié que par « Ajouter un frais »
 * et « Annuler cet ajout ».
 */

/* --- les routes ------------------------------------------------------------------------------------------------------- */

/** Le mois demandé (AAAA-MM, pas après le mois en cours), sinon le mois en cours. */
function phMoisDemande(mixed $v): string
{
    $m = is_string($v) ? trim($v) : '';
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) && $m <= date('Y-m') ? $m : date('Y-m');
}

/** GET /exploitation/frais?shop=4[&mois=AAAA-MM][&rafraichir=1] — l'onglet Frais du dashboard. */
function ep_exploitation_frais(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    if ($shop <= 0) { http_response_code(400); return ['error' => 'magasin attendu (shop)']; }
    $ym = phMoisDemande($_GET['mois'] ?? null);
    $base = ['shop' => (string) $shop, 'mois' => $ym, 'taux' => PH_TAUX, 'tauxOui' => PH_TAUX_OUI,
        'source' => 'le panel : frais du magasin du mois (/shops/{id}/owner/costs?period=' . $ym . ')'];
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return $base + ['indispo' => true, 'motif' => 'compte du panel non configuré']; }
    $P = phFraisPanel([$shop], $ym, !empty($_GET['rafraichir']))[$shop] ?? ['lu' => false, 'le' => null, 'lignes' => []];
    $F = phFraisMois($P['lignes'], phChoix([$shop])[$shop] ?? []);
    // Ce que ça change dans le P&L du jour : les frais que le panel alloue à aujourd'hui (daily-summary.shop_cost).
    $jour = null;
    if ($ym === date('Y-m') && $F['part'] !== null) {
        $ds = PanelApi::get('/shops/' . $shop . '/statistics/daily-summary?date=' . date('Y-m-d'));
        $oh = is_array($ds) ? nombreOuNull($ds, ['shop_cost']) : null;
        if ($oh !== null && $oh > 0) { $jour = ['date' => date('Y-m-d'), 'saisi' => round($oh, 2), 'ht' => round($oh * $F['part'], 2)]; }
    }
    return $base + ['lu' => $P['lu'], 'luLe' => $P['le'] ? date('c', (int) $P['le']) : null, 'jour' => $jour,
        'types' => phTypesFrais(), 'ajouts' => phAjoutsRecents($shop)] + $F;
}

/** POST /exploitation/frais/tva {shop, poste, tva: true|false|null, taux} — le choix d'un poste, gardé d'un mois à l'autre. */
function wr_exploitation_frais_tva(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    $lib = trim((string) ($b['poste'] ?? ''));
    if ($shop <= 0 || $lib === '') { http_response_code(400); return ['error' => 'magasin et poste attendus']; }
    $tva = $b['tva'] ?? null;
    if ($tva !== null && !is_bool($tva)) { http_response_code(422); return ['error' => 'tva : true, false ou null']; }
    $taux = $b['taux'] ?? null;
    if ($taux !== null && (!is_numeric($taux) || (float) $taux < 0 || (float) $taux > 30)) { http_response_code(422); return ['error' => 'taux : entre 0 et 30 %']; }
    $taux = $tva === null ? null : ($taux !== null ? round((float) $taux, 2) : ($tva ? PH_TAUX_OUI : 0.0));
    $cle = phEnregistrerChoix($shop, $lib, $tva, $taux);
    phPerimerResultat();
    return ['ok' => true, 'poste' => $cle, 'tva' => $tva, 'taux' => $taux];
}

/** Le choix « TVA comprise » et le taux d'un poste, gardé pour le magasin ; rend la clé du poste. */
function phEnregistrerChoix(int $shop, string $lib, ?bool $tva, ?float $taux): string
{
    $cle = phCle($lib);
    ensureFraisTva();
    Db::exec('DELETE FROM panel_frais_tva WHERE shop_id = ? AND poste = ?', [(string) $shop, $cle]);
    Db::exec('INSERT INTO panel_frais_tva (shop_id, poste, libelle, tva_comprise, taux, maj_le, maj_par) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [(string) $shop, $cle, mb_substr($lib, 0, 190), $tva === null ? null : ($tva ? 1 : 0), $taux, date('Y-m-d H:i:s'), phQui()]);
    return $cle;
}

/** Le nom de qui écrit (la session), pour les journaux ; null sans session. */
function phQui(): ?string
{
    $qui = function_exists('accesIdentite') ? accesIdentite() : null;
    return is_array($qui) && isset($qui['nom']) ? mb_substr((string) $qui['nom'], 0, 120) : null;
}

/**
 * Après un choix : le Résultat gardé d'aujourd'hui (jour, semaine, mois) part périmé — il reste servi et se refait en
 * arrière-plan, avec les nouveaux frais hors TVA.
 */
function phPerimerResultat(): void
{
    $auj = date('Y-m-d');
    foreach (['exJour:' . $auj, 'exPer:semaine:' . $auj, 'exPer:mois:' . $auj] as $cle) {
        try {
            $c = setting($cle);
            if (!is_array($c) || !isset($c['le'])) { continue; }
            $c['le'] = time() - (int) ($c['ttl'] ?? 0) - 1;
            Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode($c, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) { /* il expirera de lui-même */ }
    }
}

/* --- ajouter un frais au panel (21/11/2026) ----------------------------------------------------------------------------
 * Demande : « rajouter un frais (faire un formulaire) et que ce frais se reporte sur toute l'année ». Le formulaire de
 * l'onglet Frais crée la ligne au panel (POST /shops/{id}/costs) pour chaque mois choisi : ce mois seulement, de ce mois
 * à décembre, ou toute l'année (janvier → décembre). Le montant se saisit par mois, ou en total réparti sur les mois.
 *
 * La forme du corps n'est pas documentée (le Swagger du panel ne décrit ni corps ni réponse des routes de frais) : le
 * corps reprend les noms des champs d'une ligne déjà saisie au magasin (/shops/{id}/costs?period=, le gabarit), sinon
 * des noms usuels ; un refus du panel remonte tel quel, avec son message, et les mois suivants ne partent pas.
 *
 * Le panel reporte peut-être lui-même un frais d'un mois à l'autre : le mois qui suit le premier est relu avant et après
 * sa création (/shops/{id}/owner/costs?period=, la lecture de l'onglet). S'il porte le frais une fois de plus, rien
 * d'autre n'est créé — sinon le frais compterait deux fois. À la fin, chaque mois est relu : `vu` dit si le panel le
 * montre. Chaque ligne créée est gardée (`panel_frais_ajout`) : « Annuler cet ajout » les supprime au panel.
 */

const PH_REPETER = ['mois', 'fin', 'annee'];   // ce mois seulement · de ce mois à décembre · janvier → décembre
const PH_TYPES_TTL = 86400;                    // les types de frais du panel (/costs) : relus chaque jour
const PH_MONTANT_MAX = 1000000.0;
const PH_CLES_LIBELLE = ['name', 'label', 'title', 'cost_name', 'description'];

function ensureFraisAjout(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS panel_frais_ajout ('
        . 'shop_id VARCHAR(16) NOT NULL,'
        . 'lot VARCHAR(32) NOT NULL,'             // un envoi du formulaire : une ligne par mois
        . 'mois CHAR(7) NOT NULL,'
        . 'poste VARCHAR(190) NOT NULL,'
        . 'montant DECIMAL(12,2) NOT NULL,'
        . 'panel_id VARCHAR(64) NULL,'            // l'identifiant de la ligne au panel, s'il l'a rendu
        . 'cree_le DATETIME NOT NULL,'
        . 'cree_par VARCHAR(120) NULL,'
        . 'annule_le DATETIME NULL,'
        . 'PRIMARY KEY (shop_id, lot, mois)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/** Les types de frais du panel (/costs) : [['id' => '3', 'nom' => 'Loyer']], gardés un jour dans `phTypes`. */
function phTypesFrais(bool $relire = false): array
{
    $g = null;
    try { $g = setting('phTypes'); } catch (Throwable $e) { /* sans réglages */ }
    $vieux = is_array($g) && is_array($g['t'] ?? null) ? $g['t'] : null;
    if (!$relire && $vieux !== null && (int) ($g['q'] ?? 0) > time() - PH_TYPES_TTL) { return $vieux; }
    $r = class_exists('PanelApi') && PanelApi::configured() ? PanelApi::get('/costs') : null;
    if (!is_array($r)) {
        // Un panel muet : la dernière liste reste servie, et la lecture se retente dans dix minutes, pas à chaque appel.
        try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['phTypes', json_encode(['q' => time() - PH_TYPES_TTL + 600, 't' => $vieux ?? []], JSON_UNESCAPED_UNICODE)]); }
        catch (Throwable $e) { /* relu la prochaine fois */ }
        return $vieux ?? [];
    }
    $t = [];
    foreach (phListePanel($r) as $x) {
        if (!is_array($x) || !isset($x['id']) || !is_scalar($x['id']) || (string) $x['id'] === '') { continue; }
        $nom = '';
        foreach (array_merge(PH_CLES_LIBELLE, ['type_name']) as $c) { if (is_string($x[$c] ?? null) && trim($x[$c]) !== '') { $nom = trim($x[$c]); break; } }
        $t[] = ['id' => (string) $x['id'], 'nom' => $nom !== '' ? mb_substr($nom, 0, 120) : 'Type ' . $x['id']];
    }
    try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['phTypes', json_encode(['q' => time(), 't' => $t], JSON_UNESCAPED_UNICODE)]); }
    catch (Throwable $e) { /* relu la prochaine fois */ }
    return $t;
}

/** Les mois d'un ajout : ce mois seulement, de ce mois à décembre, ou janvier → décembre de son année. */
function phMoisCibles(string $ym, string $repeter): array
{
    $y = substr($ym, 0, 4);
    $de = $repeter === 'annee' ? 1 : (int) substr($ym, 5, 2);
    $a = $repeter === 'mois' ? (int) substr($ym, 5, 2) : 12;
    $out = [];
    for ($m = $de; $m <= $a; $m++) { $out[] = $y . '-' . str_pad((string) $m, 2, '0', STR_PAD_LEFT); }
    return $out;
}

/** Le montant de chaque mois : le même chaque mois, ou un total réparti au centime (le dernier mois prend l'arrondi). */
function phMontants(float $montant, string $base, int $n): array
{
    $n = max(1, $n);
    if ($base !== 'total') { return array_fill(0, $n, round($montant, 2)); }
    $c = (int) round($montant * 100);
    $u = intdiv($c, $n);
    $out = array_fill(0, $n, $u / 100.0);
    $out[$n - 1] = ($c - $u * ($n - 1)) / 100.0;
    return $out;
}

/** Le champ du type de frais d'une ligne du panel (id_cost, cost_id, cost_type_id, type_id, category_id…) ? */
function phCleType(string $k): bool
{
    return (bool) preg_match('/^(id_?cost(_?type)?|cost(_?type)?_?id|id_?type|type_?id|id_?category|category_?id)$/i', $k);
}

/**
 * Une ligne de frais déjà saisie au magasin, pour reprendre ses noms de champs : le mois de l'ajout, sinon les trois
 * d'avant ; la liste des frais du magasin (/shops/{id}/costs) d'abord, celle de l'onglet ensuite. Lues de front.
 */
function phGabarit(int $shop, string $ym): ?array
{
    $ch = [];
    for ($i = 0; $i < 4; $i++) {
        $m = date('Y-m', strtotime($ym . '-01 -' . $i . ' month'));
        $ch['s' . $i] = '/shops/' . $shop . '/costs?period=' . $m;
        $ch['o' . $i] = '/shops/' . $shop . '/owner/costs?period=' . $m;
    }
    $r = PanelApi::getParallele($ch, 4, 15);
    foreach (array_keys($ch) as $k) {
        foreach (phListePanel($r[$k] ?? null) as $x) { if (is_array($x) && $x !== [] && !array_is_list($x)) { return $x; } }
    }
    return null;
}

/**
 * Le corps de la ligne au panel. Avec un gabarit : ses champs, remplis — magasin, type, montant, libellé, mois ou dates
 * au format de la ligne lue (AAAA-MM, AAAA-MM-JJ, avec l'heure) ; l'identifiant, les horodatages, les objets imbriqués
 * et les champs inconnus ne se reprennent pas. Ce que le gabarit ne dit pas prend un nom usuel : amount, name, period,
 * id_cost. Chaque ligne couvre son mois, jamais plus : deux lignes ne se chevauchent pas.
 * @param array{shop: int, type: ?string, nom: string, montant: float, mois: string} $v
 */
function phCorpsFrais(?array $g, array $v): array
{
    $ym = $v['mois']; $du = $ym . '-01'; $au = date('Y-m-t', strtotime($du));
    $date = static function (mixed $modele, string $jour, string $heure): string|int {
        if (is_int($modele) || (is_string($modele) && preg_match('/^\d{1,2}$/', $modele))) { return (int) substr($jour, 5, 2); }
        if (is_string($modele) && preg_match('/^\d{4}-\d{2}$/', $modele)) { return substr($jour, 0, 7); }
        if (is_string($modele) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $modele)) { return $jour . ' ' . $heure; }
        return $jour;
    };
    $c = []; $montant = false; $nom = false; $periode = false; $type = false;
    foreach ($g ?? [] as $k => $x) {
        if (!is_string($k) || is_array($x)) { continue; }
        $lk = strtolower($k);
        if ($lk === 'id' || preg_match('/created|updated|deleted|insert|modif|timestamp/', $lk)) { continue; }
        if (preg_match('/^(id_?shop|shop_?id|shop)$/', $lk)) { $c[$k] = $v['shop']; }
        elseif (phCleType($lk)) { $type = true; if ($v['type'] !== null) { $c[$k] = is_numeric($v['type']) ? (int) $v['type'] : $v['type']; } }
        elseif (!$montant && in_array($lk, PH_CLES_MONTANT, true)) { $c[$k] = $v['montant']; $montant = true; }
        elseif (!$nom && in_array($lk, PH_CLES_LIBELLE, true)) { $c[$k] = $v['nom']; $nom = true; }
        elseif (in_array($lk, ['period', 'month'], true)) { $c[$k] = $date($x, $du, '00:00:00'); $periode = true; }
        elseif (in_array($lk, ['date', 'date_from', 'from_date', 'start_date', 'valid_from', 'from', 'start'], true)) { $c[$k] = $date($x, $du, '00:00:00'); $periode = true; }
        elseif (in_array($lk, ['date_to', 'to_date', 'end_date', 'valid_to', 'to', 'end'], true)) { $c[$k] = $date($x, $au, '23:59:59'); }
        elseif ($lk === 'year') { $c[$k] = (int) substr($ym, 0, 4); }
    }
    if (!$montant) { $c['amount'] = $v['montant']; }
    if (!$nom) { $c['name'] = $v['nom']; }
    if (!$periode) { $c['period'] = $ym; }
    if (!$type && $v['type'] !== null) { $c['id_cost'] = is_numeric($v['type']) ? (int) $v['type'] : $v['type']; }
    return $c;
}

/** L'identifiant de la ligne créée, d'après la réponse du panel (à la racine, sous data, item, cost…), sinon null. */
function phIdCree(mixed $res): ?string
{
    if (!is_array($res)) { return null; }
    $d = is_array($res['data'] ?? null) ? $res['data'] : [];
    foreach ([$res, $d, $res['item'] ?? null, $res['cost'] ?? null, $res['shop_cost'] ?? null, $d['item'] ?? null, $d['cost'] ?? null, $d['shop_cost'] ?? null] as $x) {
        if (is_array($x) && isset($x['id']) && is_scalar($x['id']) && (string) $x['id'] !== '') { return (string) $x['id']; }
    }
    return isset($res['data']) && is_int($res['data']) ? (string) $res['data'] : null;
}

/** Combien de lignes d'une lecture du panel portent ce frais : l'identifiant créé, ou le même montant et le même libellé ou type. */
function phCompterFrais(mixed $r, string $nom, float $montant, ?string $type, ?string $id = null): int
{
    $n = 0; $cle = phCle($nom);
    foreach (phListePanel($r) as $x) {
        if (!is_array($x)) { continue; }
        if ($id !== null && isset($x['id']) && is_scalar($x['id']) && (string) $x['id'] === $id) { $n++; continue; }
        $m = null;
        foreach (PH_CLES_MONTANT as $c) { if (isset($x[$c]) && is_numeric($x[$c])) { $m = (float) $x[$c]; break; } }
        if ($m === null || abs($m - $montant) > 0.005) { continue; }
        $ok = false;
        foreach (PH_CLES_LIBELLE as $c) { if (is_string($x[$c] ?? null) && phCle($x[$c]) === $cle) { $ok = true; break; } }
        if (!$ok && $type !== null) { foreach ($x as $k => $val) { if (is_string($k) && phCleType($k) && is_scalar($val) && (string) $val === $type) { $ok = true; break; } } }
        if ($ok) { $n++; }
    }
    return $n;
}

/** Ce que le panel a dit d'un refus : le code, son message, et le détail des champs s'il le donne. */
function phMotifPanel(array $r): string
{
    $m = (string) ($r['erreur'] ?? ('HTTP ' . (int) ($r['code'] ?? 0)));
    $e = is_array($r['data'] ?? null) ? ($r['data']['errors'] ?? null) : null;
    if (is_array($e) && $e !== []) { $m .= ' · ' . mb_substr((string) json_encode($e, JSON_UNESCAPED_UNICODE), 0, 300); }
    return $m;
}

/**
 * Après un ajout ou une annulation : les frais gardés des mois touchés (onglet Frais), et pour les mois passés les frais
 * gravés jour par jour (dsJ, dsM) et le P&L mensuel du panel (pnlM) se relisent ; le Résultat d'aujourd'hui part périmé.
 */
function phOublierFrais(int $shop, array $mois): void
{
    $ce = date('Y-m');
    try {
        foreach ($mois as $m) {
            Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', ['phF:' . $shop . ':' . $m]);
            if ($m > $ce) { continue; }
            Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', ['dsM:' . $shop . ':' . $m]);
            Db::exec('DELETE FROM ceo_app_setting WHERE `key` LIKE ?', ['dsJ:' . $shop . ':' . $m . '-%']);
        }
        Db::exec('DELETE FROM ceo_app_setting WHERE `key` = ?', ['pnlM:' . $shop]);
    } catch (Throwable $e) { /* les gardes expireront d'elles-mêmes */ }
    phPerimerResultat();
}

/** POST /exploitation/frais/ajout — un frais ajouté au panel, pour un mois ou mois après mois (voir plus haut). */
function wr_exploitation_frais_ajout(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    $nom = trim((string) preg_replace('/\s+/u', ' ', is_string($b['nom'] ?? null) ? $b['nom'] : ''));
    $type = isset($b['type']) && is_scalar($b['type']) && trim((string) $b['type']) !== '' ? trim((string) $b['type']) : null;
    $montant = $b['montant'] ?? null;
    $base = ($b['base'] ?? 'mois') === 'total' ? 'total' : 'mois';
    $repeter = (string) ($b['repeter'] ?? 'mois');
    $ym = is_string($b['mois'] ?? null) ? trim($b['mois']) : '';
    $tva = $b['tva'] ?? null;
    $taux = $b['taux'] ?? null;
    if ($shop <= 0 || $nom === '') { http_response_code(400); return ['error' => 'magasin et libellé attendus']; }
    if (mb_strlen($nom) > 120) { http_response_code(422); return ['error' => 'libellé : 120 caractères au plus']; }
    if (!is_numeric($montant) || (float) $montant <= 0 || (float) $montant > PH_MONTANT_MAX) { http_response_code(422); return ['error' => 'montant : un nombre positif']; }
    if (!in_array($repeter, PH_REPETER, true)) { http_response_code(422); return ['error' => 'repeter : mois, fin ou annee']; }
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) || abs((int) substr($ym, 0, 4) - (int) date('Y')) > 1) { http_response_code(422); return ['error' => 'mois : AAAA-MM, de l’année passée à l’année prochaine']; }
    if ($tva !== null && !is_bool($tva)) { http_response_code(422); return ['error' => 'tva : true, false ou null']; }
    if ($taux !== null && (!is_numeric($taux) || (float) $taux < 0 || (float) $taux > 30)) { http_response_code(422); return ['error' => 'taux : entre 0 et 30 %']; }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { http_response_code(503); return ['error' => 'compte du panel non configuré']; }
    if ($type !== null) {
        $types = phTypesFrais();
        if ($types !== [] && !in_array($type, array_column($types, 'id'), true)) { http_response_code(422); return ['error' => 'type de frais inconnu du panel']; }
    }
    @set_time_limit(120);
    $mois = phMoisCibles($ym, $repeter);
    $montants = phMontants((float) $montant, $base, count($mois));
    $g = phGabarit($shop, $ym);
    $lot = bin2hex(random_bytes(8));
    $qui = phQui();
    ensureFraisAjout();
    $lire = static fn (string $m): mixed => PanelApi::get('/shops/' . $shop . '/owner/costs?period=' . $m);
    // Le mois qui suit le premier, avant : combien de fois il porte déjà ce frais (le panel le reporte-t-il de lui-même ?).
    $avant = count($mois) > 1 ? phCompterFrais($lire($mois[1]), $nom, $montants[0], $type) : 0;
    $res = []; $reporte = false; $champs = null;
    foreach ($mois as $i => $m) {
        $corps = phCorpsFrais($g, ['shop' => $shop, 'type' => $type, 'nom' => $nom, 'montant' => $montants[$i], 'mois' => $m]);
        $champs ??= array_keys($corps);
        $r = PanelApi::requete('POST', '/shops/' . $shop . '/costs', $corps);
        $ok = $r['code'] >= 200 && $r['code'] < 300;
        $id = $ok ? phIdCree($r['data']) : null;
        $res[] = ['mois' => $m, 'montant' => $montants[$i], 'ok' => $ok, 'id' => $id, 'erreur' => $ok ? null : phMotifPanel($r)];
        if (!$ok) { break; }   // un refus : le même corps le serait aux mois suivants
        Db::exec('INSERT INTO panel_frais_ajout (shop_id, lot, mois, poste, montant, panel_id, cree_le, cree_par) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [(string) $shop, $lot, $m, mb_substr($nom, 0, 190), $montants[$i], $id, date('Y-m-d H:i:s'), $qui]);
        if ($i === 0 && count($mois) > 1 && phCompterFrais($lire($mois[1]), $nom, $montants[0], $type, $id) > $avant) { $reporte = true; break; }
    }
    $crees = array_values(array_filter($res, static fn ($x) => $x['ok']));
    // Chaque mois relu au panel : y voit-on le frais ?
    if ($crees !== []) {
        $ch = [];
        foreach ($mois as $m) { $ch[$m] = '/shops/' . $shop . '/owner/costs?period=' . $m; }
        $vus = PanelApi::getParallele($ch, 4, 15);
        foreach ($res as $k => $x) { $res[$k]['vu'] = $x['ok'] ? phCompterFrais($vus[$x['mois']] ?? null, $nom, $x['montant'], $type, $x['id']) > 0 : false; }
        if ($reporte) {
            foreach (array_slice($mois, 1) as $m) { $res[] = ['mois' => $m, 'montant' => $montants[0], 'ok' => true, 'id' => null, 'erreur' => null, 'parLePanel' => true, 'vu' => phCompterFrais($vus[$m] ?? null, $nom, $montants[0], $type) > 0]; }
        }
        if ($tva !== null) { phEnregistrerChoix($shop, $nom, $tva, $taux !== null ? round((float) $taux, 2) : ($tva ? PH_TAUX_OUI : 0.0)); }
        phOublierFrais($shop, $mois);
    }
    $refus = array_values(array_filter($res, static fn ($x) => !$x['ok']));
    if ($crees === []) { http_response_code(502); }
    return ['ok' => $crees !== [] && $refus === [], 'lot' => $crees !== [] ? $lot : null, 'poste' => $nom, 'mois' => $res,
        'crees' => count($crees), 'reporteParLePanel' => $reporte, 'annulable' => count(array_filter($crees, static fn ($x) => $x['id'] !== null)) === count($crees),
        'champs' => $champs, 'gabarit' => $g !== null,
        'error' => $crees === [] ? 'le panel a refusé le frais : ' . (string) ($refus[0]['erreur'] ?? 'sans réponse') : null];
}

/** POST /exploitation/frais/annuler {shop, lot} — un ajout défait : chaque ligne créée au panel supprimée (DELETE /shops/costs/{id}). */
function wr_exploitation_frais_annuler(): array
{
    $b = body();
    $shop = (int) ($b['shop'] ?? 0);
    $lot = is_string($b['lot'] ?? null) ? $b['lot'] : '';
    if ($shop <= 0 || !preg_match('/^[a-f0-9]{16}$/', $lot)) { http_response_code(400); return ['error' => 'magasin et ajout attendus']; }
    ensureFraisAjout();
    $rows = Db::rows('SELECT mois, panel_id FROM panel_frais_ajout WHERE shop_id = ? AND lot = ? AND annule_le IS NULL ORDER BY mois', [(string) $shop, $lot]);
    if ($rows === []) { http_response_code(404); return ['error' => 'ajout inconnu pour ce magasin, ou déjà annulé']; }
    if (!class_exists('PanelApi') || !PanelApi::configured()) { http_response_code(503); return ['error' => 'compte du panel non configuré']; }
    $faits = []; $sansId = []; $refus = [];
    foreach ($rows as $r) {
        $id = (string) ($r['panel_id'] ?? '');
        if ($id === '') { $sansId[] = (string) $r['mois']; continue; }
        $x = PanelApi::requete('DELETE', '/shops/costs/' . rawurlencode($id));
        if ($x['code'] >= 200 && $x['code'] < 300) {
            Db::exec('UPDATE panel_frais_ajout SET annule_le = ? WHERE shop_id = ? AND lot = ? AND mois = ?', [date('Y-m-d H:i:s'), (string) $shop, $lot, (string) $r['mois']]);
            $faits[] = (string) $r['mois'];
        } else {
            $refus[] = ['mois' => (string) $r['mois'], 'erreur' => phMotifPanel($x)];
        }
    }
    if ($faits !== []) { phOublierFrais($shop, $faits); }
    if ($faits === []) { http_response_code(502); }
    return ['ok' => $sansId === [] && $refus === [], 'annules' => $faits, 'sansId' => $sansId, 'refus' => $refus,
        'error' => $faits === [] ? ($refus !== [] ? 'le panel a refusé la suppression : ' . $refus[0]['erreur'] : 'le panel n’a pas rendu l’identifiant des lignes : à supprimer au panel') : null];
}

/** Les ajouts du magasin des 90 derniers jours, non annulés, du plus récent : [{lot, poste, du, au, n, montant, le, par, annulable}]. */
function phAjoutsRecents(int $shop): array
{
    try {
        ensureFraisAjout();
        $rows = Db::rows('SELECT lot, mois, poste, montant, panel_id, cree_le, cree_par FROM panel_frais_ajout WHERE shop_id = ? AND annule_le IS NULL AND cree_le >= ? ORDER BY cree_le DESC, mois',
            [(string) $shop, date('Y-m-d H:i:s', strtotime('-90 days'))]);
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $l = (string) $r['lot'];
        if (!isset($out[$l])) {
            if (count($out) >= 8) { continue; }
            $out[$l] = ['lot' => $l, 'poste' => (string) $r['poste'], 'du' => (string) $r['mois'], 'au' => (string) $r['mois'], 'n' => 0, 'montant' => 0.0,
                'le' => (string) $r['cree_le'], 'par' => $r['cree_par'] !== null ? (string) $r['cree_par'] : null, 'annulable' => true];
        }
        $o = &$out[$l];
        $o['du'] = min($o['du'], (string) $r['mois']); $o['au'] = max($o['au'], (string) $r['mois']);
        $o['n']++; $o['montant'] = round($o['montant'] + (float) $r['montant'], 2);
        if ($r['panel_id'] === null || (string) $r['panel_id'] === '') { $o['annulable'] = false; }
        unset($o);
    }
    return array_values($out);
}
