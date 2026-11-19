<?php
declare(strict_types=1);

/**
 * LE P&L HORS TVA (16/11/2026, Sam : « le P&L refaire sans TVA », puis « un onglet dans le rail avec leurs frais, et
 * marquer si oui ou non c'est avec ou sans TVA, et le taux » ; maquette docs/maquettes/frais-tva).
 *
 * Le P&L court (Résultat du jour, de la semaine et du mois : /exploitation/jour, /exploitation/periode) rapportait tout au
 * CA TTC de la caisse (margin-heatmap, « taxe comprise »), face à des coûts hors TVA, et prenait les frais généraux tels
 * que saisis au panel. Il se lit désormais hors TVA de bout en bout :
 *  - CA hors TVA = CA encaissé × (1 − TVA ÷ TTC des tickets du jour). La TVA vient de la caisse, ticket par ticket
 *    (`tax_amount_after_discount` de /shops/{id}/transactions?date=, la liste que le dashboard lit déjà toutes les dix
 *    minutes pour le split pro, gravée sous `b` dans le relevé du jour : vpDuListe) ; à défaut la base quotidienne du CA
 *    (con_verif_ca, écrite chaque heure par le cron de la Vérification) ; à défaut le taux habituel du magasin sur huit
 *    semaines ; à défaut 6 %, le taux de la vente à emporter alimentaire. La source est toujours dite (`tvaSource`) ;
 *  - frais généraux hors TVA = frais du jour × (hors TVA ÷ saisi) des frais du mois, d'après l'onglet Frais du
 *    dashboard : pour chaque poste, « TVA comprise ? » (oui, non, à indiquer) et le taux. Un poste à indiquer compte tel
 *    que saisi et se signale (`fraisAIndiquer`) ;
 *  - coût matière (coût de recette), invendus (coût de production net) et main-d'œuvre n'ont pas de TVA : inchangés.
 * Restent TTC : l'objectif, le CA des tuiles, le panier et la valeur du magasin (le budget est posé en TTC).
 *
 * Les routes de l'onglet Frais (GET /exploitation/frais, POST /exploitation/frais/tva) vivent dans src/franchise/frais.php,
 * avec le dashboard ; ce fichier-ci est partagé avec le cockpit du siège (Résultat, dossier PDF, tablette).
 *
 * Table : `panel_frais_tva` (préfixe du portail Pilotage, docs/PORTAILS.md) : un choix par magasin et par poste (le libellé
 * du frais au panel), gardé d'un mois à l'autre. Le panel n'est pas modifié.
 */

const PH_TVA_DEFAUT = 6.0;           // % : la vente à emporter alimentaire, quand la caisse n'a rien dit
const PH_TVA_PART_MAX = 0.25;        // une TVA au-delà du quart du TTC est une lecture fausse
const PH_HABITUEL_JOURS = 56;        // le taux habituel : huit semaines de caisse
const PH_TAUX = [0.0, 6.0, 12.0, 21.0];   // les taux belges, proposés à l'écran
const PH_TAUX_OUI = 21.0;            // « TVA comprise : oui » sans taux : le taux normal
const PH_FRAIS_TTL = 3600;           // les frais du mois en cours au panel : relus passée l'heure
const PH_FRAIS_TTL_CLOS = 21600;     // un mois passé : six heures

/* --- la TVA du chiffre d'affaires --------------------------------------------------------------------------------- */

/** Une part de TVA plausible (TVA ÷ TTC), sinon null. */
function phPartValide(?float $p): ?float
{
    return $p !== null && is_finite($p) && $p >= 0 && $p <= PH_TVA_PART_MAX ? $p : null;
}

/** La part de TVA d'un relevé de tickets (vpDuListe : `tva`, `caT` = le TTC des tickets dont la TVA est lue), sinon null. */
function phPartReleve(mixed $b): ?float
{
    if (!is_array($b) || !isset($b['tva'], $b['caT']) || !is_numeric($b['tva']) || !is_numeric($b['caT']) || (float) $b['caT'] <= 0) { return null; }
    return phPartValide((float) $b['tva'] / (float) $b['caT']);
}

/** La part de TVA par défaut : 6 % du hors TVA, soit 6 ÷ 106 du TTC. */
function phPartDefaut(): float
{
    return PH_TVA_DEFAUT / (100 + PH_TVA_DEFAUT);
}

/** Le taux habituel d'un magasin : TVA ÷ TTC de sa caisse sur les huit semaines qui finissent à $jusqua ; null sans base. */
function phTvaHabituelle(int $sid, string $jusqua): ?float
{
    static $memo = [];
    $k = $sid . '|' . $jusqua;
    if (array_key_exists($k, $memo)) { return $memo[$k]; }
    $p = null;
    try {
        $r = Db::row('SELECT SUM(caisse_ttc) AS ttc, SUM(caisse_tva) AS tva FROM con_verif_ca'
            . ' WHERE shop_id = ? AND jour > ? AND jour <= ? AND caisse_ttc > 0 AND caisse_tva IS NOT NULL',
            [$sid, date('Y-m-d', strtotime($jusqua . ' -' . PH_HABITUEL_JOURS . ' days')), $jusqua]);
        if ($r !== null && (float) ($r['ttc'] ?? 0) > 0) { $p = phPartValide((float) $r['tva'] / (float) $r['ttc']); }
    } catch (Throwable $e) { /* sans base quotidienne : le taux par défaut */ }
    return $memo[$k] = $p;
}

/**
 * La part de TVA du CA de chaque jour d'un magasin : [jour => ['part' => 0…0,25, 'src' => tickets|caisse|habituel|defaut]].
 * $releves : [jour => b], les relevés de tickets déjà en main (le pro du jour : vpLu, vpJoursLus) ; les autres jours se
 * lisent dans con_verif_ca en une requête.
 */
function phTvaJours(int $sid, array $jours, array $releves = []): array
{
    $jours = array_values(array_unique(array_map('strval', $jours)));
    $out = []; $manque = [];
    foreach ($jours as $j) {
        $p = phPartReleve($releves[$j] ?? null);
        if ($p !== null) { $out[$j] = ['part' => $p, 'src' => 'tickets']; } else { $manque[] = $j; }
    }
    if ($manque !== []) {
        try {
            $ph = implode(',', array_fill(0, count($manque), '?'));
            foreach (Db::rows('SELECT jour, caisse_ttc, caisse_tva FROM con_verif_ca WHERE shop_id = ? AND jour IN (' . $ph . ')', array_merge([$sid], $manque)) as $r) {
                $ttc = (float) ($r['caisse_ttc'] ?? 0);
                $p = ($ttc > 0 && is_numeric($r['caisse_tva'] ?? null)) ? phPartValide((float) $r['caisse_tva'] / $ttc) : null;
                if ($p !== null) { $out[substr((string) $r['jour'], 0, 10)] = ['part' => $p, 'src' => 'caisse']; }
            }
        } catch (Throwable $e) { /* sans base quotidienne : le taux habituel */ }
    }
    $fin = $jours === [] ? date('Y-m-d') : max($jours);
    foreach ($jours as $j) {
        if (isset($out[$j])) { continue; }
        $h = phTvaHabituelle($sid, $fin);
        $out[$j] = $h !== null ? ['part' => $h, 'src' => 'habituel'] : ['part' => phPartDefaut(), 'src' => 'defaut'];
    }
    return $out;
}

/** Ce que dit la source de la TVA d'un jour ou d'une étendue ($n jours lus sur $sur). */
function phTvaSourceLib(array $srcs): string
{
    $sur = count($srcs);
    if ($sur === 0) { return 'aucun jour vendu'; }
    $c = array_count_values($srcs);
    $lus = ($c['tickets'] ?? 0) + ($c['caisse'] ?? 0);
    if ($lus === $sur) { return 'lue ticket par ticket'; }
    $reste = ($c['habituel'] ?? 0) > 0 ? 'taux habituel du magasin' : 'taux de ' . str_replace('.', ',', (string) PH_TVA_DEFAUT) . ' % par défaut';
    if ($sur === 1) { return $reste . ' (tickets du jour pas encore lus)'; }
    return 'lue ticket par ticket sur ' . $lus . ' jour' . ($lus > 1 ? 's' : '') . ' sur ' . $sur . ', les autres au ' . $reste;
}

/* --- le hors TVA d'une vente de produit (Ventes par catégorie) ----------------------------------------------------- */

/**
 * Le taux de TVA de chaque produit au catalogue du magasin : [pid => %] (`tax_value_percent` de
 * /shops/{id}/products/available), gardé six heures dans `phTvaP:{shop}` ; un panel muet ressert la dernière lecture.
 */
function phTvaProduits(int $sid): array
{
    static $memo = [];
    if (isset($memo[$sid])) { return $memo[$sid]; }
    $cle = 'phTvaP:' . $sid;
    $g = null;
    try { $g = setting($cle); } catch (Throwable $e) { /* sans réglages */ }
    if (is_array($g) && isset($g['q'], $g['t']) && is_array($g['t']) && (int) $g['q'] > time() - 21600) { return $memo[$sid] = $g['t']; }
    $out = [];
    if (class_exists('PanelApi') && PanelApi::configured()) {
        try { $lignes = PanelApi::produitsDisponibles($sid); } catch (Throwable $e) { $lignes = []; }
        foreach ($lignes as $l) {
            if (!is_array($l)) { continue; }
            $pid = 0;
            foreach (['id', 'product_id', 'id_product'] as $k) { if (isset($l[$k]) && is_numeric($l[$k])) { $pid = (int) $l[$k]; break; } }
            $t = null;
            foreach (['tax_value_percent', 'tax_val_perc'] as $k) { if (isset($l[$k]) && is_numeric($l[$k])) { $t = (float) $l[$k]; break; } }
            if ($pid > 0 && $t !== null && $t >= 0 && $t <= 30) { $out[$pid] = $t; }
        }
    }
    if ($out !== []) {
        try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['q' => time(), 't' => $out])]); }
        catch (Throwable $e) { /* relu la prochaine fois */ }
        return $memo[$sid] = $out;
    }
    return $memo[$sid] = (is_array($g) && isset($g['t']) && is_array($g['t'])) ? $g['t'] : [];
}

/**
 * Le hors TVA d'une vente de produit (16/11/2026, « le coefficient doit être calculé sur le net, pas le brut ») : une
 * fonction (identifiant du produit — « 123 », ou « 123:portion » —, montant TTC) → montant hors TVA. Le taux du produit
 * au catalogue ; à défaut la part habituelle de TVA du magasin, sinon 6 %.
 */
function phHtProduit(int $sid): Closure
{
    $taux = phTvaProduits($sid);
    $repli = phTvaHabituelle($sid, date('Y-m-d')) ?? phPartDefaut();
    return static function (int|string $id, float $v) use ($taux, $repli): float {
        $pid = is_int($id) ? $id : (int) explode(':', $id)[0];
        $t = $taux[$pid] ?? null;
        return $t !== null ? $v / (1 + (float) $t / 100) : $v * (1 - $repli);
    };
}

/* --- les frais et leur TVA ------------------------------------------------------------------------------------------ */

function ensureFraisTva(): void
{
    static $fait = false;
    if ($fait) { return; }
    Db::exec('CREATE TABLE IF NOT EXISTS panel_frais_tva ('
        . 'shop_id VARCHAR(16) NOT NULL,'
        . 'poste VARCHAR(190) NOT NULL,'          // la clé : le libellé du frais au panel, en minuscules
        . 'libelle VARCHAR(190) NOT NULL,'        // le libellé tel que le panel l'écrit
        . 'tva_comprise TINYINT NULL,'            // 1 : saisi TVA comprise ; 0 : hors TVA ou sans TVA ; NULL : à indiquer
        . 'taux DECIMAL(5,2) NULL,'
        . 'maj_le DATETIME NOT NULL,'
        . 'maj_par VARCHAR(120) NULL,'
        . 'PRIMARY KEY (shop_id, poste)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $fait = true;
}

/** La clé d'un poste : son libellé, espaces réduits, en minuscules (le même poste d'un mois à l'autre). */
function phCle(string $libelle): string
{
    return mb_substr(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $libelle))), 0, 190);
}

/**
 * Les lignes de frais d'une réponse du panel (/shops/{id}/owner/costs?period=AAAA-MM) : [['poste', 'cle', 'type',
 * 'montant']], un poste par libellé (deux lignes du même libellé s'additionnent). La forme de la réponse n'est pas
 * documentée : liste à la racine ou sous items, costs, lines, data, rows ; libellé, type et montant sous leurs noms usuels.
 */
function phLignesPanel(mixed $r): array
{
    $liste = [];
    if (is_array($r)) {
        if (array_is_list($r)) { $liste = $r; }
        else {
            foreach (['items', 'costs', 'lines', 'data', 'rows', 'results', 'details'] as $k) {
                if (isset($r[$k]) && is_array($r[$k])) {
                    $v = $r[$k];
                    if (!array_is_list($v)) { foreach (['items', 'costs', 'lines', 'data'] as $k2) { if (isset($v[$k2]) && is_array($v[$k2]) && array_is_list($v[$k2])) { $v = $v[$k2]; break; } } }
                    if (array_is_list($v)) { $liste = $v; break; }
                }
            }
        }
    }
    $txt = static function (array $x, array $cles): string {
        foreach ($cles as $c) {
            $v = $x;
            foreach (explode('.', $c) as $p) { $v = is_array($v) ? ($v[$p] ?? null) : null; }
            if (is_string($v) && trim($v) !== '' && !is_numeric($v)) { return trim($v); }
        }
        return '';
    };
    $out = [];
    foreach ($liste as $x) {
        if (!is_array($x)) { continue; }
        $m = null;
        foreach (['amount', 'value', 'total', 'total_amount', 'monthly_amount', 'amount_gross', 'gross_amount', 'price', 'cost', 'sum'] as $c) {
            if (isset($x[$c]) && is_numeric($x[$c])) { $m = (float) $x[$c]; break; }
        }
        if ($m === null) { continue; }
        $type = $txt($x, ['type_name', 'cost_type_name', 'type.name', 'cost_type.name', 'category_name', 'category.name', 'category', 'cost_type', 'type']);
        $nom = $txt($x, ['name', 'label', 'title', 'cost_name', 'description', 'cost.name']);
        if ($nom === '') { $nom = $type !== '' ? $type : 'Frais ' . (string) ($x['id'] ?? (count($out) + 1)); }
        $cle = phCle($nom);
        if (isset($out[$cle])) { $out[$cle]['montant'] += $m; continue; }
        $out[$cle] = ['poste' => mb_substr($nom, 0, 190), 'cle' => $cle, 'type' => $type !== $nom ? $type : '', 'montant' => $m];
    }
    foreach ($out as $k => $l) { $out[$k]['montant'] = round($l['montant'], 2); }
    return array_values($out);
}

/**
 * Les frais du mois $ym de plusieurs magasins tels que le panel les rend : [sid => ['lu' => bool, 'le' => ts|null,
 * 'lignes' => […]]]. Gardés dans `phF:{shop}:{AAAA-MM}` une heure (six pour un mois passé) ; les manquants se lisent en
 * parallèle. Un panel muet garde la dernière lecture, sinon `lu` = false.
 */
function phFraisPanel(array $sids, string $ym, bool $relire = false): array
{
    $ttl = $ym >= date('Y-m') ? PH_FRAIS_TTL : PH_FRAIS_TTL_CLOS;
    $out = []; $ch = []; $vieux = [];
    foreach ($sids as $sid) {
        $sid = (int) $sid;
        $g = null;
        try { $g = setting('phF:' . $sid . ':' . $ym); } catch (Throwable $e) { /* sans réglages */ }
        if (is_array($g) && isset($g['l']) && is_array($g['l'])) {
            $vieux[$sid] = $g;
            if (!$relire && (int) ($g['q'] ?? 0) > time() - $ttl) { $out[$sid] = ['lu' => true, 'le' => (int) $g['q'], 'lignes' => $g['l']]; continue; }
        }
        $ch[(string) $sid] = '/shops/' . $sid . '/owner/costs?period=' . $ym;
    }
    $res = ($ch !== [] && class_exists('PanelApi') && PanelApi::configured()) ? PanelApi::getParallele($ch, 6) : [];
    foreach ($ch as $k => $_) {
        $sid = (int) $k;
        $r = $res[$k] ?? null;
        if (is_array($r)) {
            $l = phLignesPanel($r);
            try { Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['phF:' . $sid . ':' . $ym, json_encode(['q' => time(), 'l' => $l], JSON_UNESCAPED_UNICODE)]); }
            catch (Throwable $e) { /* relu la prochaine fois */ }
            $out[$sid] = ['lu' => true, 'le' => time(), 'lignes' => $l];
        } elseif (isset($vieux[$sid])) {
            $out[$sid] = ['lu' => true, 'le' => (int) ($vieux[$sid]['q'] ?? 0), 'lignes' => $vieux[$sid]['l']];
        } else {
            $out[$sid] = ['lu' => false, 'le' => null, 'lignes' => []];
        }
    }
    return $out;
}

/** Les choix « TVA comprise » et taux de plusieurs magasins : [sid => [clé => ['tva' => ?bool, 'taux' => ?float, 'le', 'par']]]. */
function phChoix(array $sids): array
{
    $out = [];
    if ($sids === []) { return $out; }
    try {
        ensureFraisTva();
        $ph = implode(',', array_fill(0, count($sids), '?'));
        foreach (Db::rows('SELECT shop_id, poste, tva_comprise, taux, maj_le, maj_par FROM panel_frais_tva WHERE shop_id IN (' . $ph . ')', array_map('strval', $sids)) as $r) {
            $out[(int) $r['shop_id']][(string) $r['poste']] = [
                'tva' => $r['tva_comprise'] === null ? null : ((int) $r['tva_comprise'] === 1),
                'taux' => $r['taux'] === null ? null : (float) $r['taux'],
                'le' => $r['maj_le'] !== null ? (string) $r['maj_le'] : null,
                'par' => $r['maj_par'] !== null ? (string) $r['maj_par'] : null];
        }
    } catch (Throwable $e) { /* sans table : tout est à indiquer */ }
    return $out;
}

/** Le hors TVA d'un montant saisi : TVA comprise, montant ÷ (1 + taux) ; sinon tel quel. */
function phHt(float $montant, ?bool $tva, ?float $taux): float
{
    return $tva === true ? $montant / (1 + (float) ($taux ?? PH_TAUX_OUI) / 100) : $montant;
}

/**
 * Les frais d'un mois face aux choix du magasin : chaque ligne avec son choix, son hors TVA et sa TVA ; les totaux ;
 * `part` = hors TVA ÷ saisi (ce que les frais généraux du P&L gardent), null sans frais saisis.
 */
function phFraisMois(array $lignes, array $choix): array
{
    $L = []; $saisi = 0.0; $ht = 0.0; $ai = 0; $aiEur = 0.0;
    foreach ($lignes as $l) {
        $c = $choix[$l['cle']] ?? null;
        $tva = $c['tva'] ?? null;
        $taux = $c['taux'] ?? null;
        $m = (float) $l['montant'];
        $h = phHt($m, $tva, $taux);
        $saisi += $m; $ht += $h;
        if ($tva === null) { $ai++; $aiEur += $m; }
        $L[] = $l + ['tva' => $tva, 'taux' => $tva === null ? null : ($taux ?? ($tva ? PH_TAUX_OUI : 0.0)),
            'ht' => round($h, 2), 'tvaMontant' => round($m - $h, 2), 'aIndiquer' => $tva === null,
            'majLe' => $c['le'] ?? null, 'majPar' => $c['par'] ?? null];
    }
    usort($L, static fn ($a, $b) => $b['montant'] <=> $a['montant']);
    return ['lignes' => $L, 'saisi' => round($saisi, 2), 'ht' => round($ht, 2), 'tva' => round($saisi - $ht, 2),
        'part' => $saisi > 0 ? round($ht / $saisi, 6) : null, 'postes' => count($L), 'aIndiquer' => $ai, 'aIndiquerEur' => round($aiEur, 2)];
}

/**
 * La part hors TVA des frais de chaque (magasin, mois) : [sid => [AAAA-MM => ['part' => ?float, 'aIndiquer' => int,
 * 'lu' => bool]]]. `part` null : frais non lus, ou aucun frais saisi — les frais généraux restent tels que saisis.
 */
function phFraisParts(array $sids, array $mois): array
{
    $sids = array_values(array_unique(array_map('intval', $sids)));
    $choix = phChoix($sids);
    $out = [];
    foreach (array_unique($mois) as $ym) {
        foreach (phFraisPanel($sids, (string) $ym) as $sid => $P) {
            $F = phFraisMois($P['lignes'], $choix[$sid] ?? []);
            $out[$sid][$ym] = ['part' => $P['lu'] ? $F['part'] : null, 'aIndiquer' => $P['lu'] ? $F['aIndiquer'] : null, 'lu' => $P['lu']];
        }
    }
    return $out;
}

/** Ce que dit la ligne « Frais généraux hors TVA » d'après l'état des frais du mois. */
function phFraisSourceLib(?array $f): string
{
    if ($f === null || empty($f['lu'])) { return 'frais du panel non lus : comptés tels que saisis'; }
    if ($f['part'] === null) { return 'aucun frais saisi au panel ce mois-ci'; }
    $n = (int) ($f['aIndiquer'] ?? 0);
    return 'TVA retirée d’après l’onglet Frais' . ($n > 0 ? ' · ' . $n . ' poste' . ($n > 1 ? 's' : '') . ' à indiquer, compté' . ($n > 1 ? 's' : '') . ' tel' . ($n > 1 ? 's' : '') . ' que saisi' . ($n > 1 ? 's' : '') : '');
}

/* --- le P&L hors TVA d'une ligne -------------------------------------------------------------------------------------- */

/**
 * Les champs du P&L d'une ligne (un jour, une semaine, un mois), hors TVA : du CA encaissé (TTC) et de sa part de TVA,
 * du coût matière, des invendus, de la main-d'œuvre et des frais tels que saisis (avec la part hors TVA des frais).
 * Les pourcentages se rapportent au CA hors TVA. Le résultat n'existe que si matière, main-d'œuvre et frais sont connus.
 */
function phPnl(float $ttc, float $partTva, ?float $fc, ?float $inv, ?float $labour, ?float $ohSaisi, ?float $partFrais): array
{
    $ht = $ttc * (1 - $partTva);
    $oh = $ohSaisi === null ? null : $ohSaisi * ($partFrais ?? 1.0);
    $mb = $fc !== null ? $ht - $fc : null;
    $net = ($mb !== null && $labour !== null && $oh !== null) ? $mb - ($inv ?? 0.0) - $labour - $oh : null;
    $pct = static fn (?float $v): ?float => ($v !== null && $ht > 0) ? round($v / $ht * 100, 1) : null;
    return ['caHt' => round($ht, 2), 'tva' => round($ttc - $ht, 2), 'tvaPart' => round($partTva * 100, 2),
        'coutMatiere' => $fc !== null ? round($fc, 2) : null, 'coutMatierePct' => $pct($fc),
        'margeBrute' => $mb !== null ? round($mb, 2) : null, 'margeBrutePct' => $pct($mb),
        'invendusPct' => $inv !== null ? $pct($inv) : null,
        'labour' => $labour !== null ? round($labour, 2) : null, 'labourPct' => $pct($labour),
        'overhead' => $oh !== null ? round($oh, 2) : null, 'overheadPct' => $pct($oh),
        'overheadSaisi' => $ohSaisi !== null ? round($ohSaisi, 2) : null,
        'overheadTva' => ($ohSaisi !== null && $oh !== null) ? round($ohSaisi - $oh, 2) : null,
        'net' => $net !== null ? round($net, 2) : null, 'netPct' => $pct($net)];
}

/** Le résultat hors TVA d'un jour de série : marge brute TTC (panel) − TVA − main-d'œuvre − frais hors TVA ; null s'il manque un chiffre. */
function phNetJour(?float $mbTtc, float $ca, float $partTva, ?float $labour, ?float $ohSaisi, ?float $partFrais): ?float
{
    if ($mbTtc === null || $labour === null || $ohSaisi === null) { return null; }
    return $mbTtc - $ca * $partTva - $labour - $ohSaisi * ($partFrais ?? 1.0);
}
