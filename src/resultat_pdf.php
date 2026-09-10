<?php
declare(strict_types=1);

/**
 * LE RÉSULTAT DU MOIS EN PDF — une page réseau, puis une page par magasin.
 *
 * Le même document que l'onglet Mois de « Résultat », mais pensé pour être
 * lu sur papier, magasin par magasin : le budget et où l'on en est, le
 * compte de résultat, puis le jour par jour avec l'objectif, l'écart en
 * clients et le résultat net. Chaque chiffre vient des mêmes endpoints que
 * l'écran ; le papier ne dit rien que l'écran ne dise.
 */

/** GET /exploitation/mois.pdf[?date=YYYY-MM-DD] */
function ep_exploitation_mois_pdf(): array
{
    $_GET['vue'] = 'mois';
    $p = ep_exploitation_periode();
    if (!empty($p['indispo']) || empty($p['magasins'])) {
        http_response_code(422);
        return ['error' => $p['motif'] ?? 'aucun magasin à imprimer'];
    }
    // N-1, tickets et panier du mois : la même source que l'écran.
    $_GET['periode'] = 'mois';
    $reseau = ep_exploitation_reseau();
    $n1 = [];
    foreach ((array) ($reseau['magasins'] ?? []) as $m) { $n1[(string) $m['shopId']] = $m; }
    // Le résultat net jour par jour : le panel ne le sert que pour le mois
    // courant — sur un mois passé, la colonne reste vide et la note le dit.
    $rent = [];
    $moisCourant = substr($p['aujourdhui'], 0, 7) === substr($p['du'], 0, 7);
    if ($moisCourant) {
        $r = ep_exploitation_rentabilite();
        foreach ((array) ($r['magasins'] ?? []) as $m) {
            foreach ((array) ($m['jours'] ?? []) as $j) { $rent[(string) $m['id']][$j['date']] = $j; }
        }
    }
    $titreMois = resPdfMois($p['du']);
    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>'
        . htmlspecialchars('Résultat du mois — ' . $titreMois, ENT_QUOTES) . '</title></head><body>'
        . resPdfHtml($p, $n1, $rent, $titreMois) . '</body></html>';
    $pdf = rapPdfRendu($doc, [
        'magasin' => 'Réseau',
        'rapport' => 'Résultat du mois — ' . $titreMois,
        'genere' => date('d/m/Y à H:i'),
        'envoye' => '',
    ]);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="resultat-' . substr($p['du'], 0, 7) . '.pdf"');
    echo $pdf;
    exit;
}

/** « septembre 2026 » à partir d'une date. */
function resPdfMois(string $date): string
{
    $noms = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $noms[(int) substr($date, 5, 2)] . ' ' . substr($date, 0, 4);
}

function resPdfHtml(array $p, array $n1, array $rent, string $titreMois): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eur = static fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', ' ') . ' €';
    $sig = static fn ($v) => $v === null ? '—' : (($v >= 0 ? '+ ' : '− ') . number_format(abs((float) $v), 0, ',', ' ') . ' €');
    $cli = static fn ($v) => $v === null ? '—' : (($v > 0 ? '− ' : '+ ') . number_format(abs((int) $v), 0, ',', ' '));
    $pct = static fn ($v, int $d = 1) => $v === null ? '—' : number_format((float) $v, $d, ',', ' ') . ' %';
    $px2 = static fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ') . ' €';
    $court = static fn (string $nom) => trim((string) array_reverse(explode(' - ', $nom))[0]);
    $cls = static fn ($v) => $v === null ? 'mut' : ($v >= 0 ? 'ok' : 'acc');
    $feu = static fn ($v, float $s) => $v === null ? 'mut' : ($v <= $s ? 'ok' : ($v <= $s * 1.3 ? 'wa' : 'acc'));
    $feuRes = static fn ($v) => $v === null ? 'mut' : ($v >= 15 ? 'ok' : ($v >= 5 ? 'wa' : 'acc'));
    $seuils = $p['seuils'] ?? ['food' => 32, 'labour' => 33, 'overhead' => 13.5];
    $logo = rapLogoDataUri();
    $moisCourant = substr($p['aujourdhui'], 0, 7) === substr($p['du'], 0, 7);
    $jours = ['', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'];

    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A;font-size:9pt}
      .serif{font-family:Georgia,"DejaVu Serif","Times New Roman",serif}
      .k{font-size:7.2pt;letter-spacing:.09em;text-transform:uppercase;color:#7a736a;font-weight:normal}
      .mut{color:#7a736a}.acc{color:#8D1D2C}.ok{color:#2d7a3e}.wa{color:#B26A00}
      .h1{font-size:20pt;letter-spacing:-.01em;margin:4mm 0 1mm}
      .soustitre{font-size:9pt;color:#7a736a;margin:0 0 5mm}
      .sec{font-family:Georgia,"DejaVu Serif",serif;font-size:12pt;margin:0 0 2.5mm;padding-bottom:1.2mm;border-bottom:1.4pt solid #8D1D2C}
      .tile{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm}
      .tile .v{font-family:Georgia,"DejaVu Serif",serif;font-size:15pt;margin-top:1mm;white-space:nowrap}
      .tile .s{font-size:7.5pt;color:#7a736a;margin-top:.8mm;line-height:1.45}
      table.grille{width:100%;border-collapse:separate;border-spacing:1.6mm 0;margin:0 -1.6mm 5mm}
      table.t{width:100%;border-collapse:collapse;margin-bottom:5mm}
      .t th{font-size:6.8pt;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;font-weight:normal;
            text-align:right;padding:1.5mm 2mm;border-bottom:1pt solid #221E1A}
      .t td{font-size:8.6pt;text-align:right;padding:1.4mm 2mm;border-bottom:.5pt solid #EAE3D8;white-space:nowrap}
      .t .l{text-align:left;white-space:normal}
      .t tr.tot td{border-top:1pt solid #221E1A;border-bottom:none;font-weight:bold;background:#FCFAF7}
      .t tr.auj td{background:#FBEFE0}
      .t td b{font-weight:bold}
      .sep{border-left:.6pt solid #c9c1b5}
      .barre{display:inline-block;position:relative;width:30mm;height:2.6mm;border-radius:2mm;background:#EFE9DF;vertical-align:middle}
      .barre i{position:absolute;left:0;top:0;height:2.6mm;border-radius:2mm}
      .methode{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm;font-size:7.6pt;color:#7a736a;line-height:1.6}
      .cadre{border:1px solid #8D1D2C;border-radius:8px;padding:2.5mm 3.5mm;margin-bottom:4mm}
      .cadre .k{color:#8D1D2C;font-weight:bold}
      .cadre .lg{display:block;font-size:8.6pt;padding:1.2mm 0;border-bottom:.5pt solid #EAE3D8}
      .cadre .lg:last-child{border-bottom:none}
    </style>';

    $entete = static fn (string $droite) => '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #8D1D2C;padding-bottom:2.6mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" alt="L’Atelier by" style="height:34px">' : '<strong style="font-size:12pt">L’Atelier by</strong>') . '</td>'
        . '<td align="right" style="font-size:7.5pt;color:#7a736a;line-height:1.6">' . $droite . '</td></tr></table>';
    $tuile = static fn (string $lbl, string $val, string $sub, string $cl = '', string $w = '25%') =>
        '<td width="' . $w . '" valign="top" class="tile"><div class="k">' . $lbl . '</div>'
        . '<div class="v ' . $cl . '">' . $val . '</div><div class="s">' . $sub . '</div></td>';

    $R = $p['reseau'];
    $enCours = !empty($p['enCours']);
    $etat = $enCours ? 'au ' . date('d/m', strtotime($p['jusqua'])) . ' — mois en cours' : 'mois clos';
    $droite = 'Résultat du mois<br>' . $e($titreMois) . ' · ' . $e($etat);

    // ── Page réseau.
    $h = $css . '<div class="doc">' . $entete($droite)
        . '<div class="serif h1">Le réseau — ' . $e($titreMois) . '</div>'
        . '<p class="soustitre">Le budget du mois et où l’on en est, magasin par magasin. L’objectif de chaque jour vient du budget mensuel réparti par la pondération réseau des jours ; l’écart réalisé − attendu, divisé par le panier moyen, se lit en clients manquants.</p>';
    $h .= '<table class="grille" cellpadding="0" cellspacing="0"><tr>'
        . $tuile('Budget du mois', $eur($R['objectif']), (int) $R['magasinsAvecObjectif'] . ' magasin(s) avec budget')
        . $tuile('Réalisé', $eur($R['realise']), $R['attendu'] !== null ? 'attendu ' . $eur($R['attendu']) . ' à ce stade' : number_format((int) $R['tickets'], 0, ',', ' ') . ' tickets')
        . $tuile('Écart', $sig($R['ecart']), $R['clientsManquants'] === null ? '' : ($R['clientsManquants'] > 0 ? 'il manque ' . number_format((int) $R['clientsManquants'], 0, ',', ' ') . ' clients' : number_format(-(int) $R['clientsManquants'], 0, ',', ' ') . ' clients d’avance'), $cls($R['ecart']))
        . $tuile('Reste à faire', $eur($R['reste']), $R['objectif'] ? 'soit ' . (int) round(100 * $R['reste'] / $R['objectif']) . ' % du mois' : '')
        . '</tr></table>';
    $h .= '<div class="sec">Magasin par magasin</div><table class="t" cellpadding="0" cellspacing="0"><tr>'
        . '<th class="l">Magasin</th><th>Budget</th><th>Ventes</th><th>Attendu</th><th>Écart</th><th>Clients</th><th>vs N-1</th>'
        . '<th class="sep">Matière</th><th>Main-d’œuvre</th><th>Frais gén.</th><th>Résultat</th></tr>';
    $ligne = function (array $m, bool $tot) use ($e, $eur, $sig, $cli, $pct, $cls, $feu, $feuRes, $seuils, $n1, $court): string {
        $x = $tot ? null : ($n1[(string) $m['shopId']] ?? null);
        $ec = $x && $x['ecart'] !== null ? (($x['ecart'] >= 0 ? '+ ' : '− ') . number_format(abs((float) $x['ecart']), 1, ',', ' ') . ' %') : '—';
        return '<tr' . ($tot ? ' class="tot"' : '') . '><td class="l"><b>' . $e($tot ? 'Réseau' : $court($m['magasin'])) . '</b></td>'
            . '<td>' . $eur($m['objectif']) . '</td><td><b>' . $eur($m['realise']) . '</b></td><td class="mut">' . $eur($m['attendu']) . '</td>'
            . '<td class="' . $cls($m['ecart']) . '"><b>' . $sig($m['ecart']) . '</b></td>'
            . '<td class="' . $cls($m['ecart']) . '"><b>' . $cli($m['clientsManquants']) . '</b></td>'
            . '<td class="' . ($x && $x['ecart'] !== null ? $cls($x['ecart']) : 'mut') . '">' . $ec . '</td>'
            . '<td class="sep ' . $feu($m['coutMatierePct'], (float) $seuils['food']) . '">' . $pct($m['coutMatierePct']) . '</td>'
            . '<td class="' . $feu($m['labourPct'], (float) $seuils['labour']) . '">' . $pct($m['labourPct']) . '</td>'
            . '<td class="' . $feu($m['overheadPct'], (float) $seuils['overhead']) . '">' . $pct($m['overheadPct']) . '</td>'
            . '<td class="' . $feuRes($m['netPct']) . '"><b>' . $eur($m['net']) . '</b> <span class="mut">' . $pct($m['netPct']) . '</span></td></tr>';
    };
    foreach ($p['magasins'] as $m) {
        if (empty($m['ouvert'])) {
            $h .= '<tr><td class="l"><b>' . $e($court($m['magasin'])) . '</b></td><td colspan="10" class="mut" style="text-align:left">' . $e($m['motif'] ?? 'aucun chiffre') . '</td></tr>';
            continue;
        }
        $h .= $ligne($m, false);
    }
    $h .= $ligne($R, true) . '</table>';
    $h .= '<div class="methode"><b style="color:#221E1A">Comment lire.</b> Attendu = la part du budget que la pondération des jours place sur les jours déjà passés, journée en cours comprise. '
        . 'Clients = écart ÷ panier moyen du magasin — négatif, il en manque ; positif, ils sont d’avance. '
        . 'Matière, main-d’œuvre et frais généraux en pourcentage des ventes, colorés contre les seuils du réseau ('
        . $pct($seuils['food'], 0) . ' · ' . $pct($seuils['labour'], 0) . ' · ' . $pct($seuils['overhead']) . '). '
        . $e($p['source'] ?? '') . '</div>';

    // ── Une page par magasin.
    foreach ($p['magasins'] as $m) {
        if (empty($m['ouvert'])) { continue; }
        $x = $n1[(string) $m['shopId']] ?? null;
        $h .= '<div style="page-break-after:always"></div><div>' . $entete($droite)
            . '<div class="serif h1">' . $e($court($m['magasin'])) . '</div>'
            . '<p class="soustitre">' . $e($m['magasin']) . ' — ' . $e($titreMois) . ' · ' . $e($etat)
            . (($m['sansBudget'] ?? []) !== [] ? ' · sans budget encodé' : ($m['objectifSource'] === 'theorique' ? ' · objectif = CA théorique de l’étude, faute de budget validé' : '')) . '</p>';
        $h .= '<table class="grille" cellpadding="0" cellspacing="0"><tr>'
            . $tuile('Budget du mois', $eur($m['objectif']), $m['attendu'] !== null ? 'attendu ' . $eur($m['attendu']) . ' à ce stade' : 'aucun budget encodé')
            . $tuile('Ventes', $eur($m['realise']), number_format((int) $m['tickets'], 0, ',', ' ') . ' tickets · panier ' . $px2($m['panier']))
            . $tuile('Écart', $sig($m['ecart']), $m['clientsManquants'] === null ? '' : ($m['clientsManquants'] > 0 ? 'il manque <b>' . number_format((int) $m['clientsManquants'], 0, ',', ' ') . ' clients</b>' : '<b>' . number_format(-(int) $m['clientsManquants'], 0, ',', ' ') . ' clients</b> d’avance'), $cls($m['ecart']))
            . $tuile('vs N-1', $x && $x['ecart'] !== null ? (($x['ecart'] >= 0 ? '+ ' : '− ') . number_format(abs((float) $x['ecart']), 1, ',', ' ') . ' %') : '—',
                $x && $x['n1'] !== null ? 'N-1 : ' . $eur($x['n1']) . ' sur la même période' : 'pas de N-1', $x && $x['ecart'] !== null ? $cls($x['ecart']) : 'mut')
            . '</tr></table>';

        // Ce qu'il manque, et ce qu'il faut pour tenir.
        if ($m['objectif'] !== null) {
            $eff = $m['reste'] - $m['prevu'];
            $effCli = $m['panier'] > 0 ? (int) round($eff / $m['panier']) : null;
            $h .= '<table width="100%" cellpadding="0" cellspacing="0"><tr><td width="49%" valign="top">'
                . '<div class="cadre"><div class="k">' . ($m['clientsManquants'] > 0 ? 'Il manque ' . number_format((int) $m['clientsManquants'], 0, ',', ' ') . ' clients sur le mois' : ($m['clientsManquants'] < 0 ? number_format(-(int) $m['clientsManquants'], 0, ',', ' ') . ' clients d’avance' : 'Dans la cible')) . '</div>'
                . '<span class="lg">Écart à l’attendu <b style="float:right" class="' . $cls($m['ecart']) . '">' . $sig($m['ecart']) . '</b></span>'
                . '<span class="lg">Panier moyen du magasin <b style="float:right">' . $px2($m['panier']) . '</b></span>'
                . '<span class="lg">Clients manquants <b style="float:right" class="' . $cls($m['ecart']) . '">' . $cli($m['clientsManquants']) . '</b></span></div></td>'
                . '<td width="2%"></td><td width="49%" valign="top">'
                . '<div class="cadre" style="border-color:#c9c1b5"><div class="k" style="color:#7a736a">Pour tenir le budget</div>'
                . '<span class="lg">Reste à faire <b style="float:right">' . $eur($m['reste']) . '</b></span>'
                . ($enCours ? '<span class="lg">Ce que la pondération prévoyait <b style="float:right">' . $eur($m['prevu']) . ($m['objectif'] ? ' · ' . (int) round(100 * $m['prevu'] / $m['objectif']) . ' %' : '') . '</b></span>'
                    . '<span class="lg">Effort supplémentaire <b style="float:right" class="' . ($eff > 0 ? 'acc' : 'ok') . '">' . $sig($eff) . ($effCli !== null ? ' · ' . ($effCli > 0 ? '+' : '') . $effCli . ' clients' : '') . '</b></span>'
                    : '<span class="lg mut">Mois clos.</span>')
                . '</div></td></tr></table>';
        }

        // Le compte de résultat du mois.
        $casc = [
            ['Ventes TTC', $m['realise'], null, '', true],
            ['− Coût matière', $m['coutMatiere'] === null ? null : -$m['coutMatiere'], $m['coutMatierePct'], $feu($m['coutMatierePct'], (float) $seuils['food']), false],
            ['− Main-d’œuvre', $m['labour'] === null ? null : -$m['labour'], $m['labourPct'], $feu($m['labourPct'], (float) $seuils['labour']), false],
            ['− Frais généraux', $m['overhead'] === null ? null : -$m['overhead'], $m['overheadPct'], $feu($m['overheadPct'], (float) $seuils['overhead']), false],
            ['Résultat', $m['net'], $m['netPct'], $feuRes($m['netPct']), true],
        ];
        $h .= '<div class="sec">Le compte de résultat du mois</div><table class="t" cellpadding="0" cellspacing="0">';
        foreach ($casc as [$lib, $v, $pc, $cl, $fort]) {
            $w = $pc === null ? 0 : (int) min(100, abs((float) $pc));
            $coul = $cl === 'ok' ? '#2d7a3e' : ($cl === 'wa' ? '#B26A00' : ($cl === 'acc' ? '#8D1D2C' : '#7a736a'));
            $h .= '<tr' . ($fort ? ' style="font-weight:bold"' : '') . '><td class="l" width="34%">' . $e($lib) . '</td>'
                . '<td class="l"><span class="barre"><i style="width:' . ($lib === 'Ventes TTC' ? 100 : $w) . '%;background:' . ($lib === 'Ventes TTC' ? '#221E1A' : $coul) . '"></i></span></td>'
                . '<td width="22%">' . ($v === null ? '—' : ($v < 0 ? '− ' : '') . number_format(abs((float) $v), 0, ',', ' ') . ' €') . '</td>'
                . '<td width="12%" class="' . $cl . '">' . ($pc === null ? '' : $pct($pc)) . '</td></tr>';
        }
        $h .= '</table>';
        if (!empty($m['motifNet'])) { $h .= '<div class="methode" style="margin-bottom:4mm">' . $e($m['motifNet']) . '</div>'; }

        // Le jour par jour.
        $rj = $rent[(string) $m['shopId']] ?? [];
        $h .= '<div class="sec">Le jour par jour</div><table class="t" cellpadding="0" cellspacing="0"><tr>'
            . '<th class="l">Jour</th><th>Objectif</th><th>Ventes</th><th>Écart</th><th>Clients</th><th>Tickets</th><th>Panier</th>'
            . '<th class="sep">Marge brute</th><th>Résultat net</th></tr>';
        $cumObj = 0.0; $cumCa = 0.0;
        foreach ($m['jours'] as $j) {
            if (!$j['passe']) { continue; }
            $d = $j['date'];
            $lib = $jours[(int) $j['jour']] . ' ' . (int) substr($d, 8, 2);
            if ($j['ferme'] || $j['ca'] === null) {
                $h .= '<tr><td class="l mut">' . $e($lib) . '</td><td class="mut">' . ($j['objectif'] === null ? '—' : $eur($j['objectif'])) . '</td>'
                    . '<td colspan="7" class="mut" style="text-align:left">' . ($j['ferme'] ? 'fermé' : 'aucune vente') . '</td></tr>';
                continue;
            }
            $ec = $j['objectif'] === null ? null : $j['ca'] - $j['objectif'];
            $c = ($ec !== null && $m['panier'] > 0) ? (int) round(-$ec / $m['panier']) : null;
            $r = $rj[$d] ?? null;
            $cumObj += (float) ($j['objectif'] ?? 0); $cumCa += (float) $j['ca'];
            $h .= '<tr' . ($j['aujourdhui'] ? ' class="auj"' : '') . '><td class="l"><b>' . $e($lib) . '</b></td>'
                . '<td class="mut">' . ($j['objectif'] === null ? '—' : $eur($j['objectif'])) . '</td>'
                . '<td><b>' . $eur($j['ca']) . '</b></td>'
                . '<td class="' . $cls($ec) . '">' . $sig($ec) . '</td>'
                . '<td class="' . $cls($ec) . '"><b>' . $cli($c) . '</b></td>'
                . '<td>' . ($j['tickets'] === null ? '—' : number_format((int) $j['tickets'], 0, ',', ' ')) . '</td>'
                . '<td>' . ($j['tickets'] ? $px2($j['ca'] / $j['tickets']) : '—') . '</td>'
                . '<td class="sep">' . ($r && $r['margePct'] !== null ? $pct($r['margePct']) : '—') . '</td>'
                . '<td class="' . ($r && $r['netPct'] !== null ? $feuRes($r['netPct']) : 'mut') . '"><b>' . ($r && $r['net'] !== null ? $eur($r['net']) : '—') . '</b>'
                . ($r && $r['netPct'] !== null ? ' <span class="mut">' . $pct($r['netPct']) . '</span>' : '') . '</td></tr>';
        }
        $h .= '<tr class="tot"><td class="l">Cumul</td><td>' . ($m['attendu'] === null ? '—' : $eur($m['attendu'])) . '</td><td>' . $eur($m['realise']) . '</td>'
            . '<td class="' . $cls($m['ecart']) . '">' . $sig($m['ecart']) . '</td><td class="' . $cls($m['ecart']) . '">' . $cli($m['clientsManquants']) . '</td>'
            . '<td>' . number_format((int) $m['tickets'], 0, ',', ' ') . '</td><td>' . $px2($m['panier']) . '</td>'
            . '<td class="sep">' . $pct($m['margeBrutePct']) . '</td><td class="' . $feuRes($m['netPct']) . '">' . $eur($m['net']) . ' <span class="mut">' . $pct($m['netPct']) . '</span></td></tr>'
            . '</table>';
        $h .= '<div class="methode">Objectif du jour = budget du mois × poids réseau du jour de semaine, sur les jours d’ouverture du magasin. '
            . 'Clients = écart ÷ panier moyen du mois. '
            . ($moisCourant ? 'Résultat net du jour : P&L quotidien du panel quand il le sert, sinon marge brute moins main-d’œuvre et frais généraux du mois répartis par jour d’ouverture.'
                : 'Résultat net du jour : le panel ne le sert que pour le mois en cours.')
            . '</div></div>';
    }
    return $h . '</div>';
}
