<?php
declare(strict_types=1);

/**
 * Les bundles du Coach Vente (17/11/2026, Sam : « le but est de créer des bundles pour augmenter la marge : coupler des
 * produits qui ont une faible marge avec des produits à grande valeur ajoutée, pour augmenter la marge de base » ; maquette
 * docs/maquettes/promo-coach, puis « push it » : le dashboard crée la promotion au panel, l'équipe vient d'une liste des
 * Paramètres). L'onglet Promotions du dashboard magasin, à la place de la liste des promotions : 1 le constat, 2 les
 * couples, 3 le prix, 4 la fiche pré-encodée, 5 le lancement (public/dashboard/bundles.js).
 *
 *   GET  /assistant/magasin/bundles?shop=                                    le constat, les couples, les bundles lancés, l'équipe
 *   POST /assistant/magasin/bundles/apercu   {shop, x, y, prix, nom, duree, canal}            la fiche de l'équipe en PDF (rien ne se crée)
 *   POST /assistant/magasin/bundles/lancer   {shop, x, y, prix, nom, duree, canal, envoyer}   la promotion créée au panel, la fiche envoyée
 *   GET  /assistant/magasin/bundles/fiche?shop=&id=                          la fiche PDF d'un bundle lancé
 *   POST /assistant/magasin/bundles/renvoyer {shop, id}                      la fiche renvoyée à l'équipe
 *
 * LA MARGE. Par produit : (prix HT − coût de recette) ÷ prix HT, prix et coûts du magasin (sinon du réseau) au panel
 * (aivProduits). Ses ventes hors TVA : les ventes des tickets × prix HT ÷ prix TTC (hvProduits, BND_JOURS jours jusqu'à
 * hier). La MARGE DE BASE : somme des marges ÷ somme des ventes hors TVA, sur les produits chiffrés.
 * UN COUPLE : le produit qui attire (X : sous la marge de base, gros volume, BND_PRIX_X € au plus) et le produit qui
 * rapporte (Y : BND_MARGE_HAUTE % de marge et plus, au plus le double du prix de X, d'une autre famille que X : deux
 * produits qui se complètent, pas deux viennoiseries qui se remplacent), vendus au même moment de la journée
 * (le matin, le midi, l'après-midi : la part des pièces de chacun, BND_CRENEAUX). Le prix du bundle : la somme des deux
 * moins BND_REMISE, à 10 centimes près. La marge en plus d'un ticket : marge du bundle − marge de X vendu seul (le
 * client qui ne prenait que X) ; par mois : × la prise (BND_PRISE, un client de X sur dix) × les pièces de X.
 *
 * AU PANEL : une « Buy X Get Y » du magasin (pmEnregistrer, src/promos_magasin.php, envoyée : active), X et Y une pièce
 * chacun, Y à prix fixe (prix du bundle − prix de X), répétable, du lundi qui suit la validation (00:00) à la fin de la
 * durée choisie (1 mois par défaut, la veille du même quantième, 23:59). Elle se garde dans aia_bundle.
 * LA FICHE de l'équipe (A4) : le bundle, les dates, la caisse, les phrases pour le proposer, la mise en place — ni marge
 * ni coefficient. Elle part depuis la boîte mail du magasin (bmEnvoyer) aux adresses de l'équipe (Paramètres › Mails et
 * automatisations : pil_equipe_mail, amEquipe) ; une boîte non reliée ou une équipe vide : la promotion est créée, la
 * fiche se télécharge.
 */

const BND_JOURS = 30;
const BND_MARGE_HAUTE = 78.0;     // % : le produit qui rapporte
const BND_PRIX_X = 8.0;           // € TTC au plus : le produit qui attire (pas une tarte entière)
const BND_REMISE = 0.10;          // la remise du prix conseillé
const BND_REMISE_CONSEIL = [8, 12];
const BND_REMISE_TROP = 15;
const BND_PRISE = 0.10;           // un client de X sur dix prend le bundle (hypothèse, réglable à l'écran)
const BND_COUPLES = 6;
const BND_Y_MAX_FOIS = 2;         // un même produit qui rapporte dans deux couples au plus
const BND_CRENEAUX = ['matin' => [null, 11, 'le matin'], 'midi' => [11, 14, 'le midi'], 'apres' => [14, null, 'l’après-midi']];
const BND_DUREES = ['2s' => '2 semaines', '1m' => '1 mois', '2m' => '2 mois'];
const BND_CANAUX = ['tous' => 'au comptoir et sur le webshop', 'comptoir' => 'au comptoir', 'webshop' => 'sur le webshop'];

function bndSchema(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS aia_bundle (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        shop_id INT NOT NULL,
        cree_le DATETIME NOT NULL,
        nom VARCHAR(80) NOT NULL,
        produit_x BIGINT NOT NULL,
        produit_y BIGINT NOT NULL,
        prix DECIMAL(8,2) NOT NULL,
        du DATE NOT NULL,
        au DATE NOT NULL,
        canal VARCHAR(12) NOT NULL,
        panel_id BIGINT NULL,
        fiche MEDIUMTEXT NOT NULL,
        envoi TEXT NULL,
        KEY k_shop (shop_id, cree_le)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/* ---------------------------------------------------------------------------
 * Les chiffres
 * ------------------------------------------------------------------------- */
/**
 * Les produits vendus et chiffrés (fonction pure, testée) : $ventes : hvProduits()['produits'] (toutes portions) ; $prod :
 * aivProduits ; $creneaux : [créneau => hvProduits()['produits']] ou []. [pid => fiche], par pièces décroissantes.
 */
function bndProduits(array $ventes, array $prod, array $creneaux = []): array
{
    $P = [];
    foreach ($ventes as $x) {
        $pid = (int) $x[0];
        $f = $prod[$pid] ?? null;
        if ($f === null || !is_numeric($f['prixHt'] ?? null) || !is_numeric($f['cout'] ?? null) || !is_numeric($f['prixTtc'] ?? null) || (float) $f['prixHt'] <= 0 || (float) $f['prixTtc'] <= 0) { continue; }
        $P[$pid] ??= ['id' => $pid, 'nom' => (string) $f['nom'], 'cat' => (string) ($f['categorie'] ?? ''), 'q' => 0.0, 'v' => 0.0,
            'ttc' => (float) $f['prixTtc'], 'ht' => (float) $f['prixHt'], 'cout' => (float) $f['cout'], 'creneaux' => []];
        $P[$pid]['q'] += (float) $x[3];
        $P[$pid]['v'] += (float) $x[4];
    }
    foreach ($creneaux as $k => $L) {
        foreach ($L as $x) { if (isset($P[(int) $x[0]])) { $P[(int) $x[0]]['creneaux'][$k] = ($P[(int) $x[0]]['creneaux'][$k] ?? 0.0) + (float) $x[3]; } }
    }
    foreach ($P as &$p) {
        $p['m'] = round(100 * ($p['ht'] - $p['cout']) / $p['ht'], 1);          // la marge, en %
        $p['me'] = round($p['ht'] - $p['cout'], 4);                           // la marge d'une pièce, en € HT
        $p['vht'] = $p['v'] * $p['ht'] / $p['ttc'];                           // les ventes hors TVA
        $t = array_sum($p['creneaux']);
        $p['parts'] = $t > 0 ? array_map(static fn ($n) => $n / $t, $p['creneaux']) : [];
        $p['q'] = round($p['q'], 1);
    }
    unset($p);
    uasort($P, static fn ($a, $b) => $b['q'] <=> $a['q']);
    return $P;
}

/** La marge de base et ce qui la compose (fonction pure, testée). */
function bndBase(array $P): array
{
    $ht = 0.0; $mg = 0.0; $q = 0.0;
    foreach ($P as $p) { $ht += $p['vht']; $mg += $p['vht'] * $p['m'] / 100; $q += $p['q']; }
    $base = $ht > 0 ? 100 * $mg / $ht : 0.0;
    $sous = 0.0; $haut = 0.0;
    foreach ($P as $p) { if ($p['m'] < $base) { $sous += $p['q']; } if ($p['m'] >= BND_MARGE_HAUTE) { $haut += $p['q']; } }
    return ['base' => round($base, 1), 'ventesHt' => round($ht, 2), 'marge' => round($mg, 2), 'pieces' => (int) round($q), 'produits' => count($P),
        'sousPct' => $q > 0 ? round(100 * $sous / $q, 1) : 0.0, 'hautPct' => $q > 0 ? round(100 * $haut / $q, 1) : 0.0];
}

/** Le prix conseillé d'un bundle : la somme moins BND_REMISE, à 10 centimes près. */
function bndPrixConseille(float $separes): float
{
    return round(round($separes * (1 - BND_REMISE) * 10) / 10, 2);
}

/** Un couple à un prix (fonction pure, testée) : la marge du bundle, la marge en plus, par mois. */
function bndCouple(array $X, array $Y, float $prix, float $prise = BND_PRISE): array
{
    $sep = round($X['ttc'] + $Y['ttc'], 2);
    $htB = $prix * ($X['ht'] + $Y['ht']) / ($X['ttc'] + $Y['ttc']);   // les TVA des deux produits, au prorata
    $mB = $htB - $X['cout'] - $Y['cout'];
    $gain = $mB - $X['me'];
    return ['x' => $X['id'], 'y' => $Y['id'], 'separes' => $sep, 'prix' => round($prix, 2), 'remise' => round($sep - $prix, 2),
        'remisePct' => $sep > 0 ? round(100 * ($sep - $prix) / $sep, 1) : 0.0, 'prixY' => round($prix - $X['ttc'], 2),
        'margeBundle' => round($mB, 2), 'margeBundlePct' => $htB > 0 ? round(100 * $mB / $htB, 1) : 0.0,
        'margeXSeul' => round($X['me'], 2), 'margeXSeulPct' => $X['m'], 'gainTicket' => round($gain, 2),
        'bundlesMois' => (int) round($prise * $X['q'] * 30 / BND_JOURS), 'gainMois' => round($prise * $X['q'] * 30 / BND_JOURS * $gain, 2)];
}

/** La famille d'une catégorie : son premier mot, sans accent (« Viennoiserie Ind. » et « Viennoiserie réduction » : viennoiserie). */
function bndFamille(string $cat): string
{
    $m = strtolower(strtr(trim($cat), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ç' => 'c', 'É' => 'e']));
    return (string) (preg_split('/[^a-z0-9]+/', $m, -1, PREG_SPLIT_NO_EMPTY)[0] ?? '');
}

/** Le moment où deux produits se vendent ensemble : la somme des plus petites parts par créneau, et le créneau le plus fort. */
function bndMoment(array $X, array $Y): array
{
    $ov = 0.0; $meilleur = null; $max = -1.0;
    foreach (BND_CRENEAUX as $k => $c) {
        $a = $X['parts'][$k] ?? 0.0; $b = $Y['parts'][$k] ?? 0.0;
        $ov += min($a, $b);
        if ($a + $b > $max) { $max = $a + $b; $meilleur = $k; }
    }
    return [$X['parts'] === [] || $Y['parts'] === [] ? 0.5 : $ov, $meilleur];
}

/**
 * Les couples qui montent la marge (fonction pure, testée) : les produits qui attirent (sous la marge de base, gros volume)
 * et les produits qui rapportent ; chaque couple à son prix conseillé, classé par la marge en plus du mois, pondérée par
 * le moment partagé ; un produit qui attire une fois, un produit qui rapporte BND_Y_MAX_FOIS fois au plus.
 */
function bndCouples(array $P, float $base, int $n = BND_COUPLES): array
{
    $q = array_sum(array_column($P, 'q'));
    $qMin = max(20.0, 0.01 * $q);
    $X = array_slice(array_values(array_filter($P, static fn ($p) => $p['m'] < $base && $p['q'] >= $qMin && $p['ttc'] <= BND_PRIX_X)), 0, 10);
    $Y = array_values(array_filter($P, static fn ($p) => $p['m'] >= BND_MARGE_HAUTE && $p['q'] >= 1 && $p['ttc'] >= 1.0));
    $cands = [];
    foreach ($X as $x) {
        foreach ($Y as $y) {
            // Deux produits qui se complètent : pas deux de la même famille (deux viennoiseries se remplacent l'une l'autre).
            if ($x['id'] === $y['id'] || $y['ttc'] > max(3.5, 2 * $x['ttc']) || bndFamille($x['cat']) === bndFamille($y['cat'])) { continue; }
            $c = bndCouple($x, $y, bndPrixConseille($x['ttc'] + $y['ttc']));
            if ($c['gainTicket'] <= 0 || $c['margeBundlePct'] <= $x['m']) { continue; }
            [$ov, $moment] = bndMoment($x, $y);
            $c['moment'] = $moment !== null ? BND_CRENEAUX[$moment][2] : null;
            $c['score'] = $c['gainMois'] * (0.5 + $ov);
            $cands[] = $c;
        }
    }
    usort($cands, static fn ($a, $b) => $b['score'] <=> $a['score']);
    $out = []; $xs = []; $ys = [];
    foreach ($cands as $c) {
        if (isset($xs[$c['x']]) || ($ys[$c['y']] ?? 0) >= BND_Y_MAX_FOIS) { continue; }
        $xs[$c['x']] = true; $ys[$c['y']] = ($ys[$c['y']] ?? 0) + 1;
        unset($c['score']);
        $out[] = $c;
        if (count($out) >= $n) { break; }
    }
    usort($out, static fn ($a, $b) => $b['gainMois'] <=> $a['gainMois']);
    return $out;
}

/** Les dates d'un bundle validé aujourd'hui : du lundi qui suit, pour la durée choisie (la veille du même quantième). */
function bndDates(string $duree, ?string $auj = null): array
{
    $auj ??= date('Y-m-d');
    $du = date('Y-m-d', strtotime($auj . ' next monday'));
    $au = match ($duree) {
        '2s' => date('Y-m-d', strtotime($du . ' +13 days')),
        '2m' => date('Y-m-d', strtotime($du . ' +2 months -1 day')),
        default => date('Y-m-d', strtotime($du . ' +1 month -1 day')),
    };
    return [$du, $au];
}

/** Le nom de la durée et des dates, en mots. */
function bndJour(string $d, bool $annee = false): string
{
    $j = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int) date('w', strtotime($d))];
    $m = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int) date('n', strtotime($d)) - 1];
    return $j . ' ' . (int) date('j', strtotime($d)) . ' ' . $m . ($annee ? ' ' . date('Y', strtotime($d)) : '');
}

/** Le nom d'un produit pour l'équipe et le client : sans la précision entre parenthèses (« Cappuccino (à emporter) »). */
function bndNomCourt(string $n): string
{
    return trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $n)) ?: $n;
}

/** Le magasin : son nom (la table des magasins). */
function bndNomMagasin(int $sid): string
{
    try { $r = Db::row('SELECT name FROM shops WHERE id = ?', [$sid]); } catch (Throwable $e) { $r = null; }
    $n = trim((string) ($r['name'] ?? ''));
    return $n !== '' ? trim((string) preg_replace('/^Atelier by\s*-?\s*/i', '', $n)) : 'Magasin ' . $sid;
}

/** Les produits chiffrés du magasin sur la période, avec leurs moments ; gardés une heure. */
function bndLire(int $sid): array
{
    $au = date('Y-m-d', strtotime('-1 day'));
    $du = date('Y-m-d', strtotime($au . ' -' . (BND_JOURS - 1) . ' days'));
    $r = aiaCache('bundles:' . $sid . ':' . $au, 3600, static function () use ($sid, $du, $au) {
        $ventes = hvProduits([$sid], $du, $au)['produits'];
        $cr = [];
        foreach (BND_CRENEAUX as $k => [$hd, $hf]) { $cr[$k] = hvProduits([$sid], $du, $au, [], $hd, $hf)['produits']; }
        return ['du' => $du, 'au' => $au, 'produits' => array_values(bndProduits($ventes, aivProduits($sid), $cr))];
    });
    $P = [];
    foreach ((array) ($r['produits'] ?? []) as $p) { $P[(int) $p['id']] = $p; }
    return ['du' => (string) ($r['du'] ?? $du), 'au' => (string) ($r['au'] ?? $au), 'produits' => $P];
}

/* ---------------------------------------------------------------------------
 * La fiche de l'équipe
 * ------------------------------------------------------------------------- */
/** Les phrases pour proposer le bundle, selon le moment. */
function bndPhrases(string $x, string $y, string $prix, string $au): array
{
    $xl = mb_strtolower($x); $yl = mb_strtolower($y);
    return ['« ' . ucfirst($yl) . ' avec votre ' . $xl . ' ? Les deux sont à ' . $prix . '. »',
        'À un client qui prend un ' . $yl . ' : « Avec un ' . $xl . ', c’est ' . $prix . ' les deux. »',
        '« C’est notre offre du moment, jusqu’au ' . $au . '. »'];
}

/** La fiche de l'équipe (A4, fonction pure, testée) : ni marge ni coefficient. $f : bndPreparer. */
function bndFicheHtml(array $f): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eur = static fn (float $v) => number_format($v, 2, ',', "\u{00A0}") . "\u{00A0}€";
    $ruby = defined('DOC_RUBY') ? DOC_RUBY : '#8D1D2C'; $mut = defined('DOC_MUTED') ? DOC_MUTED : '#7a736a'; $line = defined('DOC_LINE') ? DOC_LINE : '#e6e0d8';
    $au = bndJour($f['au']);
    $h = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' . $e('Fiche promotion · ' . $f['nom']) . '</title><style>'
        . (function_exists('docCss') ? docCss() : '')
        . 'h1{font-size:30px;font-weight:700;margin:16px 0 8px;letter-spacing:-.01em}'
        . '.dates{display:inline-block;background:' . $ruby . ';color:#fff;border-radius:6px;padding:6px 12px;font-size:13px}'
        . '.offre{width:100%;border-collapse:collapse;margin:22px 0 6px;background:#fbf6f2;border:1px solid ' . $line . '}'
        . '.offre td{padding:18px 20px;vertical-align:middle}.offre .k{font-size:9px;letter-spacing:.14em;text-transform:uppercase;color:' . $mut . ';margin-bottom:5px}'
        . '.offre .q{font-size:19px;line-height:1.35}.offre .fl{font-size:24px;color:' . $ruby . ';text-align:center;width:40px}'
        . '.offre .px{font-size:36px;color:' . $ruby . ';font-weight:700}.offre .px s{font-size:15px;color:' . $mut . ';font-weight:400;margin-left:8px}'
        . 'h2{font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:' . $ruby . ';font-weight:400;margin:22px 0 8px;border-bottom:1px solid ' . $line . ';padding-bottom:4px}'
        . 'p{font-size:12.5px;line-height:1.55;margin:0}'
        . '.dire div{font-size:13.5px;line-height:1.45;padding:8px 14px;border-left:3px solid ' . $ruby . ';background:#faf7f2;margin:0 0 7px}'
        . 'ol{margin:0;padding-left:18px;font-size:12.5px;line-height:1.7}'
        . '.pied{margin-top:26px;padding-top:10px;border-top:1px solid ' . $line . ';font-size:9px;color:' . $mut . '}'
        . '</style></head><body>';
    $h .= function_exists('docEntete') ? docEntete('Fiche promotion · ' . $f['magasin'], 'pour l’équipe · ' . date('d/m/Y', strtotime($f['le'] ?? 'now'))) : '';
    $h .= '<h1>' . $e($f['nom']) . '</h1><span class="dates">du ' . $e(bndJour($f['du'])) . ' au ' . $e(bndJour($f['au'], true)) . '</span>';
    $h .= '<table class="offre"><tr><td><div class="k">Le client prend</div><div class="q">1 ' . $e(mb_strtolower($f['xNom'])) . '<br>+ 1 ' . $e(mb_strtolower($f['yNom'])) . '</div></td>'
        . '<td class="fl">→</td><td><div class="k">Il paie</div><div class="px">' . $e($eur($f['prix'])) . '<s>' . $e($eur($f['separes'])) . '</s></div></td></tr></table>';
    $h .= '<h2>En caisse</h2><p>Rien à faire : la remise s’applique toute seule quand le ' . $e(mb_strtolower($f['xNom'])) . ' et le ' . $e(mb_strtolower($f['yNom']))
        . ' sont sur le même ticket' . ($f['canal'] === 'comptoir' ? ', au comptoir.' : ($f['canal'] === 'webshop' ? ', sur le webshop seulement.' : '. Sur le webshop aussi, en click & collect et en livraison.')) . '</p>';
    $h .= '<h2>Comment le proposer</h2><div class="dire">' . implode('', array_map(static fn ($t) => '<div>' . $e($t) . '</div>', bndPhrases($f['xNom'], $f['yNom'], $eur($f['prix']), $au))) . '</div>';
    $h .= '<h2>La mise en place</h2><ol><li>L’affiche du bundle au comptoir, à côté de la caisse.</li>'
        . '<li>Le ' . $e(mb_strtolower($f['xNom'])) . ' au premier rang de la vitrine' . ($f['moment'] ? ', ' . $e($f['moment']) : '') . '.</li>'
        . '<li>Le bundle proposé à chaque client qui prend un ' . $e(mb_strtolower($f['xNom'])) . ' ou un ' . $e(mb_strtolower($f['yNom'])) . '.</li></ol>';
    $h .= '<div class="pied">Préparée par le Coach Vente, validée par le magasin le ' . $e(date('d/m/Y à H:i', strtotime($f['le'] ?? 'now'))) . '. Une question : la Demande au bureau, dans le dashboard.</div>';
    return $h . '</body></html>';
}

/** Le PDF de la fiche ; null sans moteur PDF. */
function bndFichePdf(array $f): ?string
{
    if (!function_exists('rapPdfRendu')) { return null; }
    return rapPdfRendu(bndFicheHtml($f), ['magasin' => $f['magasin'], 'rapport' => 'Fiche promotion — ' . $f['nom'], 'genere' => date('d/m/Y à H:i'), 'envoye' => '']);
}

/* ---------------------------------------------------------------------------
 * Les routes
 * ------------------------------------------------------------------------- */
/** Un bundle demandé, vérifié et chiffré : [fiche, erreur|null]. */
function bndPreparer(int $sid, array $b): array
{
    $L = bndLire($sid);
    $P = $L['produits'];
    $prod = aivProduits($sid);
    $fiche = static function (int $id) use ($P, $prod): ?array {
        if (isset($P[$id])) { return $P[$id]; }
        $f = $prod[$id] ?? null;
        if ($f === null || !is_numeric($f['prixHt'] ?? null) || !is_numeric($f['cout'] ?? null) || (float) $f['prixHt'] <= 0) { return null; }
        $p = bndProduits([[$id, 0, $f['nom'], 0, 0]], [$id => $f]);
        return $p[$id] ?? null;
    };
    $X = $fiche((int) ($b['x'] ?? 0)); $Y = $fiche((int) ($b['y'] ?? 0));
    if ($X === null || $Y === null) { return [null, 'Les deux produits doivent avoir un prix et un coût de recette au panel.']; }
    if ($X['id'] === $Y['id']) { return [null, 'Choisissez deux produits différents.']; }
    $sep = round($X['ttc'] + $Y['ttc'], 2);
    $prix = is_numeric($b['prix'] ?? null) ? round((float) $b['prix'], 2) : bndPrixConseille($sep);
    if ($prix <= $X['ttc'] || $prix >= $sep) { return [null, 'Le prix du bundle se place entre le prix du ' . mb_strtolower($X['nom']) . ' seul (' . number_format($X['ttc'], 2, ',', ' ') . ' €) et celui des deux séparés (' . number_format($sep, 2, ',', ' ') . ' €).']; }
    $c = bndCouple($X, $Y, $prix);
    if ($c['margeBundle'] <= 0) { return [null, 'À ce prix, le bundle ne couvre pas ses coûts de recette.']; }
    $duree = isset(BND_DUREES[$b['duree'] ?? '']) ? (string) $b['duree'] : '1m';
    $canal = isset(BND_CANAUX[$b['canal'] ?? '']) ? (string) $b['canal'] : 'tous';
    [$du, $au] = bndDates($duree);
    $nom = mb_substr(trim((string) ($b['nom'] ?? '')), 0, 80) ?: (bndNomCourt($X['nom']) . ' + ' . bndNomCourt($Y['nom']));
    [, $moment] = bndMoment($X, $Y);
    return [['magasin' => bndNomMagasin($sid), 'nom' => $nom, 'x' => $X['id'], 'xNom' => bndNomCourt($X['nom']), 'y' => $Y['id'], 'yNom' => bndNomCourt($Y['nom']),
        'prix' => $prix, 'separes' => $sep, 'duree' => $duree, 'canal' => $canal, 'du' => $du, 'au' => $au,
        'moment' => $moment !== null ? BND_CRENEAUX[$moment][2] : null, 'couple' => $c, 'le' => date('Y-m-d H:i:s')], null];
}

/** Le corps de la promotion au panel (pmEnregistrer) : X une pièce, Y une pièce à prix fixe, répétable (fonction pure, testée). */
function bndCorpsPanel(int $sid, array $f): array
{
    return ['shop' => $sid, 'action' => 'envoyer', 'nom' => $f['nom'],
        'description' => '1 ' . $f['xNom'] . ' + 1 ' . $f['yNom'] . ' : ' . number_format($f['prix'], 2, ',', ' ') . ' € au lieu de ' . number_format($f['separes'], 2, ',', ' ') . ' € (bundle préparé par le Coach Vente).',
        'x' => ['q' => 1, 'prods' => [['id' => $f['x'], 'nom' => $f['xNom']]]],
        'y' => ['q' => 1, 'prods' => [['id' => $f['y'], 'nom' => $f['yNom']]], 'type' => 'FIXED_PRICE', 'valeur' => $f['couple']['prixY']],
        'du' => $f['du'] . ' 00:00', 'au' => $f['au'] . ' 23:59', 'creneaux' => [], 'repetable' => true, 'maxParTicket' => null, 'canal' => $f['canal']];
}

/** Une adresse masquée : la première lettre, le domaine. */
function bndMasquer(string $a): string
{
    $p = strpos($a, '@');
    return $p === false ? '•••' : mb_substr($a, 0, 1) . '•••' . substr($a, $p);
}

/** La fiche envoyée à l'équipe depuis la boîte du magasin : ['le', 'a' => [{nom, adresse masquée}], 'erreur', 'avertissement']. */
function bndEnvoyer(int $sid, array $f): array
{
    $shop = (string) $sid;
    $out = ['le' => date('Y-m-d H:i:s'), 'a' => [], 'erreur' => null, 'avertissement' => null];
    $c = function_exists('bmCompte') ? bmCompte($shop) : null;
    if ($c === null) { $out['erreur'] = 'La boîte mail du magasin n’est pas reliée : la fiche n’est pas partie (téléchargez-la, ou reliez la boîte dans Boîte mail).'; return $out; }
    $E = function_exists('amEquipe') ? amEquipe($shop) : [];
    if ($E === []) { $out['erreur'] = 'Aucune adresse d’équipe : ajoutez-les dans Paramètres › Mails et automatisations, puis renvoyez la fiche.'; return $out; }
    $pdf = bndFichePdf($f);
    if ($pdf === null) { $out['erreur'] = 'Aucun moteur PDF sur ce serveur : la fiche n’est pas partie.'; return $out; }
    $eur = static fn (float $v) => number_format($v, 2, ',', ' ') . ' €';
    $objet = 'Nouvelle promotion dès ' . bndJour($f['du']) . ' : ' . $f['nom'];
    $texte = "Bonjour à toute l’équipe,\n\nDès " . bndJour($f['du']) . ', nous lançons « ' . $f['nom'] . ' » : 1 ' . mb_strtolower($f['xNom']) . ' + 1 ' . mb_strtolower($f['yNom'])
        . ' pour ' . $eur($f['prix']) . ' au lieu de ' . $eur($f['separes']) . ', jusqu’au ' . bndJour($f['au'], true) . ', ' . BND_CANAUX[$f['canal']] . ".\n\n"
        . "La fiche jointe dit tout : ce que le client reçoit, comment la caisse l’applique et les phrases pour le proposer. Merci de la lire avant le premier jour.\n\nBonne vente !\n" . $c['nom'];
    $a = array_map(static fn ($x) => ['nom' => (string) $x['nom'], 'adresse' => (string) $x['adresse']], $E);
    try {
        [, $avert] = bmEnvoyer($shop, $c, $a, [], $objet, $texte, ['pieces' => [['nom' => 'Fiche promotion - ' . preg_replace('/[\\\\\/:*?"<>|]+/', '-', $f['nom']) . '.pdf', 'type' => 'application/pdf', 'contenu' => $pdf]]]);
        $out['avertissement'] = $avert;
        $out['a'] = array_map(static fn ($x) => ['nom' => $x['nom'], 'adresse' => bndMasquer($x['adresse'])], $a);
    } catch (Throwable $e) {
        $out['erreur'] = 'La fiche n’est pas partie : ' . $e->getMessage();
    }
    return $out;
}

/** Un bundle lancé, tel que l'écran le montre. */
function bndLigne(array $r): array
{
    $f = json_decode((string) $r['fiche'], true) ?: [];
    $env = $r['envoi'] !== null ? json_decode((string) $r['envoi'], true) : null;
    $auj = date('Y-m-d');
    return ['id' => (int) $r['id'], 'nom' => (string) $r['nom'], 'le' => (string) $r['cree_le'], 'xNom' => $f['xNom'] ?? '', 'yNom' => $f['yNom'] ?? '',
        'prix' => (float) $r['prix'], 'separes' => (float) ($f['separes'] ?? 0), 'du' => (string) $r['du'], 'au' => (string) $r['au'], 'canal' => (string) $r['canal'],
        'panelId' => $r['panel_id'] !== null ? (int) $r['panel_id'] : null,
        'statut' => $auj < $r['du'] ? 'a_venir' : ($auj <= $r['au'] ? 'en_caisse' : 'terminee'),
        'margeBundle' => $f['couple']['margeBundle'] ?? null, 'gainTicket' => $f['couple']['gainTicket'] ?? null, 'envoi' => is_array($env) ? $env : null];
}

/** GET /assistant/magasin/bundles?shop= */
function ep_bnd(): array
{
    $sid = aiaShop();
    if ($sid <= 0) { http_response_code(422); return ['error' => 'magasin manquant']; }
    bndSchema();
    $L = bndLire($sid);
    $P = $L['produits'];
    $B = bndBase($P);
    $couples = bndCouples($P, $B['base']);
    $nom = static fn (int $id) => $P[$id]['nom'] ?? ('Produit ' . $id);
    foreach ($couples as &$c) { $c['xNom'] = $nom($c['x']); $c['yNom'] = $nom($c['y']); }
    unset($c);
    $prods = array_map(static fn ($p) => ['id' => $p['id'], 'nom' => $p['nom'], 'cat' => $p['cat'], 'q' => $p['q'], 'ttc' => $p['ttc'], 'ht' => round($p['ht'], 4),
        'cout' => round($p['cout'], 4), 'm' => $p['m'], 'me' => $p['me']], array_values($P));
    $lances = array_map('bndLigne', Db::rows('SELECT * FROM aia_bundle WHERE shop_id = ? ORDER BY cree_le DESC LIMIT 20', [$sid]));
    [$du, $au] = bndDates('1m');
    $boite = function_exists('bmCompte') ? bmCompte((string) $sid) !== null : false;
    return ['shop' => $sid, 'magasin' => bndNomMagasin($sid), 'periode' => ['du' => $L['du'], 'au' => $L['au'], 'jours' => BND_JOURS], 'base' => $B,
        'produits' => $prods, 'couples' => $couples, 'lances' => $lances,
        'regles' => ['margeHaute' => BND_MARGE_HAUTE, 'remise' => BND_REMISE * 100, 'remiseConseil' => BND_REMISE_CONSEIL, 'remiseTrop' => BND_REMISE_TROP, 'prise' => BND_PRISE,
            'durees' => BND_DUREES, 'canaux' => BND_CANAUX, 'lundi' => $du, 'finMois' => $au],
        'equipe' => ['n' => function_exists('amEquipe') ? count(amEquipe((string) $sid)) : 0, 'boite' => $boite]];
}

/** POST /assistant/magasin/bundles/apercu : la fiche en PDF, rien ne se crée. */
function wr_bnd_apercu(): array
{
    $sid = aiaShop();
    if ($sid <= 0) { http_response_code(422); return ['error' => 'magasin manquant']; }
    [$f, $err] = bndPreparer($sid, body());
    if ($err !== null) { http_response_code(422); return ['error' => $err]; }
    return bndPdfSortir($f);
}

/** Sort un PDF de fiche (et s'arrête). */
function bndPdfSortir(array $f): array
{
    $pdf = bndFichePdf($f);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="fiche-promotion.pdf"');
    echo $pdf;
    exit;
}

/** POST /assistant/magasin/bundles/lancer */
function wr_bnd_lancer(): array
{
    $b = body();
    $sid = aiaShop();
    if ($sid <= 0) { http_response_code(422); return ['error' => 'magasin manquant']; }
    bndSchema();
    [$f, $err] = bndPreparer($sid, $b);
    if ($err !== null) { http_response_code(422); return ['error' => $err]; }
    if (!function_exists('pmEnregistrer')) { http_response_code(501); return ['error' => 'les promotions ne s’écrivent pas ici']; }
    $r = pmEnregistrer(bndCorpsPanel($sid, $f));
    if (empty($r['ok'])) {
        if (http_response_code() < 400) { http_response_code(502); }
        return ['error' => $r['error'] ?? 'le panel refuse la promotion', 'erreurs' => $r['erreurs'] ?? null];
    }
    $panelId = (int) ($r['demande']['panelId'] ?? 0) ?: null;
    Db::exec('INSERT INTO aia_bundle (shop_id, cree_le, nom, produit_x, produit_y, prix, du, au, canal, panel_id, fiche) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$sid, $f['nom'], $f['x'], $f['y'], $f['prix'], $f['du'], $f['au'], $f['canal'], $panelId, json_encode($f, JSON_UNESCAPED_UNICODE)]);
    $id = (int) Db::pdo()->lastInsertId();
    journalAdd('Magasin ' . $sid, 'Promotions', null, 'Bundle « ' . $f['nom'] . ' » lancé avec le Coach Vente : ' . number_format($f['prix'], 2, ',', ' ') . ' €, du ' . date('d/m', strtotime($f['du'])) . ' au ' . date('d/m', strtotime($f['au'])));
    $envoi = null;
    if (!empty($b['envoyer'])) {
        $envoi = bndEnvoyer($sid, $f);
        Db::exec('UPDATE aia_bundle SET envoi = ? WHERE id = ?', [json_encode($envoi, JSON_UNESCAPED_UNICODE), $id]);
    }
    $row = Db::row('SELECT * FROM aia_bundle WHERE id = ?', [$id]);
    return ['ok' => true, 'bundle' => $row !== null ? bndLigne($row) : null, 'panelErreur' => $r['panelErreur'] ?? null, 'exclusAuto' => $r['exclusAuto'] ?? []];
}

/** GET /assistant/magasin/bundles/fiche?shop=&id= */
function ep_bnd_fiche(): array
{
    $sid = aiaShop();
    $id = (int) ($_GET['id'] ?? 0);
    bndSchema();
    $r = Db::row('SELECT fiche FROM aia_bundle WHERE id = ? AND shop_id = ?', [$id, $sid]);
    $f = $r !== null ? json_decode((string) $r['fiche'], true) : null;
    if (!is_array($f)) { http_response_code(404); return ['error' => 'bundle inconnu pour ce magasin']; }
    return bndPdfSortir($f);
}

/** POST /assistant/magasin/bundles/renvoyer {shop, id} */
function wr_bnd_renvoyer(): array
{
    $b = body();
    $sid = aiaShop();
    bndSchema();
    $r = Db::row('SELECT * FROM aia_bundle WHERE id = ? AND shop_id = ?', [(int) ($b['id'] ?? 0), $sid]);
    $f = $r !== null ? json_decode((string) $r['fiche'], true) : null;
    if (!is_array($f)) { http_response_code(404); return ['error' => 'bundle inconnu pour ce magasin']; }
    $envoi = bndEnvoyer($sid, $f);
    Db::exec('UPDATE aia_bundle SET envoi = ? WHERE id = ?', [json_encode($envoi, JSON_UNESCAPED_UNICODE), (int) $r['id']]);
    return ['ok' => $envoi['erreur'] === null, 'bundle' => bndLigne(Db::row('SELECT * FROM aia_bundle WHERE id = ?', [(int) $r['id']]))];
}

function bndRoute(string $method, string $path): ?array
{
    if ($path !== '/assistant/magasin/bundles' && !str_starts_with($path, '/assistant/magasin/bundles/')) { return null; }
    if ($method === 'GET' && $path === '/assistant/magasin/bundles') { return ep_bnd(); }
    if ($method === 'POST' && $path === '/assistant/magasin/bundles/apercu') { return wr_bnd_apercu(); }
    if ($method === 'POST' && $path === '/assistant/magasin/bundles/lancer') { return wr_bnd_lancer(); }
    if ($method === 'GET' && $path === '/assistant/magasin/bundles/fiche') { return ep_bnd_fiche(); }
    if ($method === 'POST' && $path === '/assistant/magasin/bundles/renvoyer') { return wr_bnd_renvoyer(); }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}
