<?php
declare(strict_types=1);

/**
 * LE PLAN DE DÉVELOPPEMENT EN PDF — trois pages, plus le tableau de tous les
 * trimestres.
 *
 *  1. La rampe à 5 ans et l'engagement de l'année : ses raisons, ses actions,
 *     ses signatures.
 *  2. Tous les trimestres depuis le début du plan — objectif, réalisé, écart,
 *     clients, ratios — puis les quatre derniers avec leurs trois voix.
 *  3. Le prochain trimestre : objectif, effort, ce qui est mis en route.
 */

/** GET /plan.pdf?shop=&exercice=&trimestre= */
function ep_plan_pdf(): array
{
    $d = ep_plan();
    if (isset($d['error'])) { return $d; }
    $q = (int) ($_GET['trimestre'] ?? $d['trimestreCourant']);
    $q = max(1, min(4, $q));
    $titre = 'Plan de développement — ' . $d['magasin'] . ' · revue T' . $q . ' ' . $d['exercice'];
    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>' . htmlspecialchars($titre, ENT_QUOTES) . '</title></head><body>'
        . planPdfHtml($d, $q) . '</body></html>';
    $pdf = rapPdfRendu($doc, ['magasin' => $d['magasin'], 'rapport' => 'Plan de développement — revue T' . $q . ' ' . $d['exercice'],
        'genere' => date('d/m/Y à H:i'), 'envoye' => '']);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="plan-' . mktSlug($d['magasin']) . '-' . $d['exercice'] . '-T' . $q . '.pdf"');
    echo $pdf;
    exit;
}

function planPdfHtml(array $d, int $q): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $k = static fn ($v) => $v === null ? '—' : number_format((float) $v / 1000, 0, ',', ' ') . ' k€';
    $eur = static fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', ' ') . ' €';
    $sig = static fn ($v) => $v === null ? '—' : (($v >= 0 ? '+ ' : '− ') . number_format(abs((float) $v), 0, ',', ' ') . ' €');
    $sigK = static fn ($v) => $v === null ? '—' : (($v >= 0 ? '+ ' : '− ') . number_format(abs((float) $v) / 1000, 0, ',', ' ') . ' k€');
    $pct = static fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', ' ') . ' %';
    $sigP = static fn ($v) => $v === null ? '—' : (($v >= 0 ? '+ ' : '− ') . number_format(abs((float) $v), 1, ',', ' ') . ' %');
    $cli = static fn ($v) => $v === null ? '—' : (($v > 0 ? '− ' : '+ ') . number_format(abs((int) $v), 0, ',', ' '));
    $cls = static fn ($v) => $v === null ? 'mut' : ($v >= 0 ? 'ok' : 'acc');
    $dt = static fn ($v) => $v ? date('d/m/Y', strtotime((string) $v)) : '';
    $court = static fn (string $nom) => trim((string) array_reverse(explode(' - ', $nom))[0]);
    $logo = rapLogoDataUri();
    $ex = (int) $d['exercice'];
    $voixNom = ['franchise' => 'Franchisé', 'consultant' => 'Consultant', 'marque' => 'Marque'];
    $stNom = ['alancer' => 'à lancer', 'encours' => 'en cours', 'fait' => 'fait', 'abandonne' => 'abandonné'];
    $stCls = ['alancer' => 'st-alanc', 'encours' => 'st-enc', 'fait' => 'st-fait', 'abandonne' => 'st-aband'];
    $trimNom = [1 => 'janvier – mars', 2 => 'avril – juin', 3 => 'juillet – septembre', 4 => 'octobre – décembre'];

    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A;font-size:9pt}
      .serif{font-family:Georgia,"DejaVu Serif","Times New Roman",serif}
      .k{font-size:7.2pt;letter-spacing:.09em;text-transform:uppercase;color:#7a736a;font-weight:normal}
      .mut{color:#7a736a}.acc{color:#8D1D2C}.ok{color:#2d7a3e}.wa{color:#B26A00}
      .h1{font-size:20pt;letter-spacing:-.01em;margin:4mm 0 1mm}
      .soustitre{font-size:9pt;color:#7a736a;margin:0 0 5mm}
      .sec{font-family:Georgia,"DejaVu Serif",serif;font-size:12pt;margin:5mm 0 2.5mm;padding-bottom:1.2mm;border-bottom:1.4pt solid #8D1D2C}
      .tile{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm}
      .tile .v{font-family:Georgia,"DejaVu Serif",serif;font-size:15pt;margin-top:1mm;white-space:nowrap}
      .tile .s{font-size:7.5pt;color:#7a736a;margin-top:.8mm;line-height:1.45}
      table.grille{width:100%;border-collapse:separate;border-spacing:1.6mm 0;margin:0 -1.6mm 5mm}
      table.t{width:100%;border-collapse:collapse;margin-bottom:4mm}
      .t th{font-size:6.8pt;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;font-weight:normal;text-align:right;padding:1.5mm 2mm;border-bottom:1pt solid #221E1A}
      .t td{font-size:8.6pt;text-align:right;padding:1.4mm 2mm;border-bottom:.5pt solid #EAE3D8;white-space:nowrap;vertical-align:top}
      .t .l{text-align:left;white-space:normal}
      .t tr.cur td{background:#FBEFE0}
      .t tr.tot td{border-top:1pt solid #221E1A;border-bottom:none;font-weight:bold;background:#FCFAF7}
      .t td b{font-weight:bold}
      .rampe{width:100%;border-collapse:separate;border-spacing:3mm 0;margin:0 -3mm 2mm}
      .rampe td{vertical-align:bottom;text-align:center;width:20%}
      .rampe .v{font-size:8pt;font-weight:bold;margin-bottom:1mm}
      .rampe .y{font-size:7.5pt;color:#7a736a;margin-top:1.2mm}
      .barre{display:block;width:100%;border-radius:2mm 2mm 0 0;background:#EFE9DF;border:1px solid #d9d1c4;position:relative}
      .barre.cur{background:#8D1D2C;border-color:#8D1D2C}
      .barre .fait{position:absolute;left:0;right:0;bottom:0;background:#C17A2A}
      .cadre{border:1px solid #8D1D2C;border-radius:8px;padding:2.5mm 3.5mm;margin-bottom:4mm}
      .cadre .k{color:#8D1D2C;font-weight:bold}
      .methode{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm;font-size:7.6pt;color:#7a736a;line-height:1.6}
      .voix{margin-top:1.5mm;font-size:8.2pt;line-height:1.45}
      .voix b{display:inline-block;width:20mm;font-size:6.8pt;letter-spacing:.06em;text-transform:uppercase;vertical-align:top;padding-top:.6mm}
      .voix.fr b{color:#8D1D2C}.voix.co b{color:#B26A00}.voix.ma b{color:#2d7a3e}
      .voix span{display:inline-block;width:150mm;background:#fbf9f5;border-radius:2mm;padding:1.4mm 2.4mm;vertical-align:top}
      .voix span.vide{color:#b5aea4;border:.5pt dashed #c9c1b5;background:#fff}
      .trim{border:1px solid #e6e0d8;border-radius:8px;padding:2.5mm 3.5mm;margin-bottom:3mm;page-break-inside:avoid}
      .trim.cur{border-color:#8D1D2C}
      .trim .tt{font-family:Georgia,"DejaVu Serif",serif;font-size:11pt}
      .st{display:inline-block;font-size:6.8pt;font-weight:bold;border-radius:3mm;padding:.5mm 2mm;white-space:nowrap}
      .st-fait{background:#E3EFE6;color:#2d7a3e}.st-enc{background:#FBEFE0;color:#8a5a1c}.st-alanc{background:#EFE9DF;color:#7a736a}.st-aband{background:#F6E4E7;color:#8D1D2C}
      table.sig{width:100%;border-collapse:separate;border-spacing:3mm 0;margin:4mm -3mm 0}
      .sig td{border-top:.6pt solid #221E1A;padding-top:1.5mm;font-size:7.5pt;color:#7a736a;width:33%;vertical-align:top;height:12mm}
    </style>';
    $entete = static fn (string $droite) => '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #8D1D2C;padding-bottom:2.6mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" alt="L’Atelier by" style="height:34px">' : '<strong style="font-size:12pt">L’Atelier by</strong>') . '</td>'
        . '<td align="right" style="font-size:7.5pt;color:#7a736a;line-height:1.6">' . $droite . '</td></tr></table>';
    $tuile = static fn (string $lbl, string $val, string $sub, string $cl = '') =>
        '<td width="25%" valign="top" class="tile"><div class="k">' . $lbl . '</div><div class="v ' . $cl . '">' . $val . '</div><div class="s">' . $sub . '</div></td>';
    $droite = 'Plan de développement<br>' . $e($court($d['magasin'])) . ' · revue T' . $q . ' ' . $ex . ' · au ' . $dt($d['aujourdhui']);

    $R = $d['rampe']; $A = $d['annee'];
    $engs = (array) $d['engagements'];
    $eng = $engs[(string) $ex] ?? null;
    $trims = $d['trimestres'];
    $tq = null; foreach ($trims as $t) { if ($t['t'] === $q) { $tq = $t; } }
    $suiv = $q < 4 ? $trims[$q] : null;   // le trimestre suivant, dans l'année
    $anneeSuiv = $q < 4 ? $ex : $ex + 1;
    $qSuiv = $q < 4 ? $q + 1 : 1;
    $actions = $d['actions'];

    // ── Page 1 : la rampe et l'engagement.
    $h = $css . '<div class="doc">' . $entete($droite)
        . '<div class="serif h1">' . $e($d['magasin']) . '</div>'
        . '<p class="soustitre">Plan de développement · exercice ' . $ex
        . ($R['potentiel'] ? ' · étude de marché : potentiel à maturité ' . $eur($R['potentiel']) . ', année ' . (int) $R['anneeExploitation'] . ' d’exploitation en ' . $ex : ' · aucune étude de marché encodée')
        . '</p>';
    $h .= '<div class="sec">1 · La rampe à 5 ans, et l’engagement de l’année</div>';
    if ($R['annees'] !== []) {
        $maxCa = max(array_map(static fn ($a) => (float) $a['ca'], $R['annees'])) ?: 1.0;
        $h .= '<table class="rampe" cellpadding="0" cellspacing="0"><tr>';
        foreach ($R['annees'] as $a) {
            $hb = (int) round(38 * $a['ca'] / $maxCa);
            $cur = $a['an'] === $ex;
            $fait = ($cur && $A['realise'] > 0 && $a['ca'] > 0) ? (int) round($hb * min(1.0, $A['realise'] / $a['ca'])) : 0;
            $h .= '<td><div class="v">' . $k($a['ca']) . '</div><div class="barre' . ($cur ? ' cur' : '') . '" style="height:' . $hb . 'mm">'
                . ($fait > 0 ? '<div class="fait" style="height:' . $fait . 'mm"></div>' : '') . '</div>'
                . '<div class="y">' . $a['an'] . ' · ' . ($a['maturite'] ? 'maturité' : 'année ' . $a['anneeExploitation'] . ' · ' . (int) $a['coef'] . ' %') . '</div></td>';
        }
        $h .= '</tr></table><div class="mut" style="font-size:7.5pt;margin-bottom:4mm">Barres : la rampe de l’étude (potentiel × montée en régime). En orange dans ' . $ex . ' : le réalisé à ce jour, ' . $k($A['realise']) . '.</div>';
    }
    $h .= '<table class="t"><tr><th class="l">Année</th><th>Rampe (étude)</th><th>Engagement du franchisé</th><th>Réalisé</th><th>Écart à l’attendu</th><th class="l">Statut</th></tr>';
    $annees = $R['annees'] !== [] ? $R['annees'] : array_map(static fn ($i) => ['an' => $ex + $i, 'ca' => null, 'anneeExploitation' => null, 'maturite' => false], range(0, 4));
    foreach ($annees as $a) {
        $en = $engs[(string) $a['an']] ?? null; $cur = $a['an'] === $ex;
        $statut = $en === null || $en['statut'] === 'brouillon' ? ($a['an'] > $ex ? 'à engager en janvier ' . $a['an'] : ($en ? 'brouillon' : '—'))
            : ($en['statut'] === 'valide' ? 'engagé le ' . $dt($en['engageLe']) . ', validé le ' . $dt($en['valideLe']) : 'engagé le ' . $dt($en['engageLe']) . ' — à valider');
        $h .= '<tr' . ($cur ? ' class="cur"' : '') . '><td class="l">' . ($cur ? '<b>' : '') . $a['an'] . ($cur ? '</b>' : '') . ($a['anneeExploitation'] ? ' · ' . ($a['maturite'] ? 'maturité' : 'année ' . $a['anneeExploitation']) : '') . '</td>'
            . '<td>' . $k($a['ca']) . '</td><td>' . ($en && $en['objectif'] ? '<b>' . $k($en['objectif']) . '</b>' : ($cur && ($A['objectifSource'] ?? null) === 'rampe' ? '<span class="mut">rampe, à engager</span>' : '<span class="mut">—</span>')) . '</td>'
            . '<td>' . ($cur ? '<b>' . $k($A['realise']) . '</b> <span class="mut">au ' . $dt($d['aujourdhui']) . '</span>' : '<span class="mut">—</span>') . '</td>'
            . '<td class="' . ($cur ? $cls($A['ecart']) : 'mut') . '">' . ($cur && $A['ecart'] !== null ? $sigK($A['ecart']) . ($A['projection'] ? ' · proj. ' . $k($A['projection']) : '') : '—') . '</td>'
            . '<td class="l mut">' . $e($statut) . '</td></tr>';
    }
    $h .= '</table>';
    if ($eng && $eng['objectif']) {
        $ecartRampe = $A['rampe'] ? $eng['objectif'] - $A['rampe'] : null;
        $h .= '<div class="cadre"><div class="k">Engagement ' . $ex . ' — ' . $k($eng['objectif'])
            . ($ecartRampe !== null && abs($ecartRampe) >= 1000 ? ', soit ' . $k(abs($ecartRampe)) . ($ecartRampe < 0 ? ' sous' : ' au-dessus de') . ' la rampe' : '') . '</div>'
            . ($eng['motif'] !== '' ? '<div style="font-size:8.6pt;margin-top:1.2mm;line-height:1.5"><b>Pourquoi :</b> ' . $e($eng['motif']) . '</div>' : '');
        if ($actions !== []) {
            $tot = 0.0;
            $h .= '<div style="font-size:8.6pt;margin-top:1.5mm"><b>Comment j’y arrive :</b></div><table class="t" style="margin:1.5mm 0 0"><tr><th class="l">Action</th><th>Lancement</th><th>Effet attendu / an</th><th>Statut au ' . $dt($d['aujourdhui']) . '</th><th class="l">Effet constaté</th></tr>';
            foreach ($actions as $a) {
                if ($a['statut'] !== 'abandonne' && $a['effetAn']) { $tot += $a['effetAn']; }
                $h .= '<tr><td class="l">' . $e($a['libelle']) . ($a['responsable'] !== '' ? ' <span class="mut">· ' . $e($a['responsable']) . '</span>' : '') . '</td>'
                    . '<td>' . ($a['trimestre'] ? 'T' . $a['trimestre'] : ($a['dateLancement'] ? $dt($a['dateLancement']) : '—')) . '</td>'
                    . '<td class="' . ($a['statut'] === 'abandonne' ? 'mut' : '') . '">' . ($a['effetAn'] !== null ? '+ ' . $k($a['effetAn']) : '—') . '</td>'
                    . '<td><span class="st ' . ($stCls[$a['statut']] ?? 'st-alanc') . '">' . $e($stNom[$a['statut']] ?? $a['statut']) . '</span></td>'
                    . '<td class="l ' . ($a['effetConstate'] !== '' ? 'ok' : 'mut') . '">' . ($a['effetConstate'] !== '' ? $e($a['effetConstate']) : '—') . '</td></tr>';
            }
            $h .= '<tr class="tot"><td class="l">Effet attendu des actions retenues</td><td></td><td>+ ' . $k($tot) . '</td><td></td><td></td></tr></table>';
        }
        $h .= '</div>';
        $h .= '<table class="sig"><tr><td>Le franchisé' . ($eng['engagePar'] ? ' — ' . $e($eng['engagePar']) : '') . '<br>' . ($eng['engageLe'] ? 'engagé le ' . $dt($eng['engageLe']) : '') . '</td>'
            . '<td>Le consultant' . ($eng['validePar'] ? ' — ' . $e($eng['validePar']) : '') . '<br>' . ($eng['valideLe'] ? 'validé le ' . $dt($eng['valideLe']) : '') . '</td>'
            . '<td>La marque — L’Atelier by<br></td></tr></table>';
    } else {
        $h .= '<div class="methode">Aucun engagement déposé pour ' . $ex . ' : le franchisé pose son objectif de l’année et les actions pour l’atteindre dans Plan de développement.'
            . (($A['objectifSource'] ?? null) === 'rampe' ? ' En attendant, l’année se lit contre la rampe de l’étude (' . $k($A['objectif']) . ').' : '') . '</div>';
    }

    // ── Page 2 : tous les trimestres, puis les quatre derniers annotés.
    $h .= '<div style="page-break-after:always"></div><div>' . $entete($droite)
        . '<div class="sec">2 · Tous les trimestres — les écarts d’un trait</div>'
        . '<table class="t"><tr><th class="l">Trimestre</th><th>Objectif</th><th>Attendu</th><th>Réalisé</th><th>Écart</th><th>Écart %</th><th>Clients</th><th>Food cost</th><th>Labour</th><th>Résultat net</th></tr>';
    $hist = $d['historique'] ?? [];
    $cumObj = 0.0; $cumAtt = 0.0; $cumRea = 0.0;
    foreach ($hist as $t) {
        $cur = ((int) $t['an'] === $ex && $t['t'] === $q);
        $cumObj += (float) ($t['objectif'] ?? 0); $cumAtt += (float) ($t['attendu'] ?? 0); $cumRea += (float) ($t['realise'] ?? 0);
        $h .= '<tr' . ($cur ? ' class="cur"' : '') . '><td class="l">' . ($cur ? '<b>' : '') . 'T' . $t['t'] . ' ' . $t['an'] . ($cur ? '</b>' : '') . ($t['enCours'] ? ' <span class="mut">en cours</span>' : '') . '</td>'
            . '<td>' . $k($t['objectif']) . '</td><td class="mut">' . $k($t['attendu']) . '</td><td><b>' . $k($t['realise']) . '</b></td>'
            . '<td class="' . $cls($t['ecart']) . '">' . $sigK($t['ecart']) . '</td><td class="' . $cls($t['ecartPct']) . '">' . $sigP($t['ecartPct']) . '</td>'
            . '<td class="' . $cls($t['ecart']) . '">' . $cli($t['clients']) . '</td>'
            . '<td>' . $pct($t['food']) . '</td><td>' . $pct($t['labour']) . '</td><td>' . $pct($t['netPct']) . '</td></tr>';
    }
    $cumEc = $cumAtt > 0 ? $cumRea - $cumAtt : null;
    $h .= '<tr class="tot"><td class="l">Cumul</td><td>' . $k($cumObj) . '</td><td>' . $k($cumAtt) . '</td><td>' . $k($cumRea) . '</td>'
        . '<td class="' . $cls($cumEc) . '">' . $sigK($cumEc) . '</td><td class="' . $cls($cumEc) . '">' . ($cumAtt > 0 ? $sigP(($cumRea / $cumAtt - 1) * 100) : '—') . '</td><td></td><td></td><td></td><td></td></tr></table>'
        . '<div class="mut" style="font-size:7.5pt;margin-bottom:3mm">Objectif = budget validé du mois, à défaut le CA théorique de l’étude. Attendu = les mois écoulés, le mois en cours au prorata des jours. Clients = écart ÷ panier moyen du trimestre. Ratios pondérés par le CA ; labour et résultat net connus depuis juillet 2026.</div>';

    // Les quatre derniers trimestres jusqu'à T{q} inclus, avec leurs voix.
    $derniers = [];
    foreach ($hist as $t) { if ((int) $t['an'] < $ex || ((int) $t['an'] === $ex && $t['t'] <= $q)) { $derniers[] = $t; } }
    $derniers = array_slice($derniers, -4);
    $h .= '<div class="sec">Les quatre derniers trimestres — constat et annotations</div>';
    foreach ($derniers as $t) {
        $an = (int) $t['an']; $notes = [];
        if ($an === $ex) { foreach ($trims as $tt) { if ($tt['t'] === $t['t']) { $notes = (array) $tt['notes']; } } }
        $cur = ($an === $ex && $t['t'] === $q);
        $h .= '<div class="trim' . ($cur ? ' cur' : '') . '"><table width="100%" cellpadding="0" cellspacing="0"><tr><td class="tt">T' . $t['t'] . ' ' . $an . ' · ' . $trimNom[$t['t']] . '</td>'
            . '<td align="right" class="' . $cls($t['ecart']) . '" style="font-size:8.6pt">' . $sigP($t['ecartPct']) . ' · ' . $k($t['realise']) . ' sur ' . $k($t['attendu']) . ($t['clients'] !== null ? ' · ' . $cli($t['clients']) . ' clients' : '') . '</td></tr></table>';
        foreach (['franchise' => 'fr', 'consultant' => 'co', 'marque' => 'ma'] as $v => $cl) {
            $n = $notes[$v] ?? null;
            $h .= '<div class="voix ' . $cl . '"><b>' . $voixNom[$v] . '</b>' . ($n ? '<span>' . $e($n['texte']) . ($n['par'] ? ' <span class="mut" style="font-size:7pt">— ' . $e($n['par']) . ', ' . $dt($n['le']) . '</span>' : '') . '</span>' : '<span class="vide">non annoté</span>') . '</div>';
        }
        $h .= '</div>';
    }
    if ($derniers === []) { $h .= '<div class="methode">Aucun trimestre avec des chiffres pour l’instant.</div>'; }
    $h .= '</div>';

    // ── Page 3 : le prochain trimestre.
    $h .= '<div style="page-break-after:always"></div><div>' . $entete($droite)
        . '<div class="sec">3 · Le prochain trimestre — T' . $qSuiv . ' ' . $anneeSuiv . ', ' . $trimNom[$qSuiv] . '</div>';
    $objSuiv = $suiv ? $suiv['objectif'] : null;
    $reste = ($A['objectif'] !== null) ? $A['objectif'] - $A['realise'] : null;
    // Ce que les budgets mensuels placent encore sur l'année après le trimestre courant.
    $prevu = 0.0; foreach ($trims as $tt) { if ($tt['t'] > $q) { $prevu += (float) ($tt['objectif'] ?? 0); } }
    $effort = ($reste !== null && $q < 4) ? $reste - $prevu : null;
    $panierT = $tq['panier'] ?? null;
    $actSuiv = array_values(array_filter($actions, static fn ($a) => $a['statut'] !== 'abandonne' && $a['statut'] !== 'fait'));
    $effetSuiv = 0.0; foreach ($actSuiv as $a) { if ($a['effetAn']) { $effetSuiv += $a['effetAn'] / 4; } }
    $h .= '<table class="grille" cellpadding="0" cellspacing="0"><tr>'
        . $tuile('Objectif T' . $qSuiv, $k($objSuiv), $objSuiv !== null && $A['objectif'] ? 'budget réparti · ' . (int) round(100 * $objSuiv / $A['objectif']) . ' % de ' . (($A['objectifSource'] ?? null) === 'rampe' ? 'la rampe' : 'l’engagement annuel') : 'budget mensuel réparti')
        . $tuile('Pour tenir ' . $k($A['objectif']), $k($reste), 'reste à faire sur l’année' . (($A['objectifSource'] ?? null) === 'rampe' ? ' · rampe de l’étude, à engager' : ''))
        . $tuile('Effort au-delà des budgets', $effort === null ? '—' : $sigK($effort), $effort !== null && $panierT ? 'soit ' . number_format(abs($effort) / $panierT, 0, ',', ' ') . ' clients ' . ($effort > 0 ? 'de plus' : 'de marge') . ' d’ici la fin de l’année' : '', $effort === null ? '' : ($effort > 0 ? 'acc' : 'ok'))
        . $tuile('Effet attendu des actions', '+ ' . $k($effetSuiv), count($actSuiv) . ' action(s) en cours ou à lancer · effet annuel ÷ 4', 'ok')
        . '</tr></table>';
    $h .= '<div class="k" style="margin-bottom:2mm">Ce qui est mis en route</div>';
    if ($actSuiv !== []) {
        $h .= '<table class="t"><tr><th class="l">Action</th><th class="l">Responsable</th><th>Lancement</th><th>Statut</th><th>Effet attendu / an</th><th class="l">Comment on le mesure</th></tr>';
        foreach ($actSuiv as $a) {
            $h .= '<tr><td class="l"><b>' . $e($a['libelle']) . '</b></td><td class="l">' . $e($a['responsable'] ?: '—') . '</td>'
                . '<td>' . ($a['dateLancement'] ? $dt($a['dateLancement']) : ($a['trimestre'] ? 'T' . $a['trimestre'] : '—')) . '</td>'
                . '<td><span class="st ' . ($stCls[$a['statut']] ?? 'st-alanc') . '">' . $e($stNom[$a['statut']] ?? $a['statut']) . '</span></td>'
                . '<td class="ok">' . ($a['effetAn'] !== null ? '+ ' . $k($a['effetAn']) : '—') . '</td><td class="l mut">' . $e($a['mesure'] ?: '—') . '</td></tr>';
        }
        $h .= '</table>';
    } else { $h .= '<div class="methode" style="margin-bottom:4mm">Aucune action en cours ni à lancer.</div>'; }
    $suite = $tq ? ((array) $tq['notes'])['suite'] ?? null : null;
    $h .= '<div class="cadre"><div class="k">Ce qu’on se dit pour T' . $qSuiv . '</div>'
        . '<div class="voix"><span style="width:170mm' . ($suite ? '' : ';color:#b5aea4;border:.5pt dashed #c9c1b5;background:#fff') . '">' . ($suite ? $e($suite['texte']) : 'pas encore écrit — étape 4 du rituel') . '</span></div></div>';
    $prochaine = $q < 4 ? 'début ' . ['', 'avril', 'juillet', 'octobre'][$q] . ' ' . $ex . ' — constat de T' . $q : 'première semaine de janvier ' . ($ex + 1) . ' — constat de T4, bilan de l’engagement ' . $ex . ', et dépôt de l’engagement ' . ($ex + 1);
    $rv = $tq['revue'] ?? null;
    $h .= '<div class="methode"><b style="color:#221E1A">Prochaine revue :</b> ' . $e($prochaine) . '.'
        . ($rv && $rv['valideLe'] ? ' Revue T' . $q . ' validée le ' . $dt($rv['valideLe']) . ($rv['validePar'] ? ' par ' . $e($rv['validePar']) : '') . '.' : ' Revue T' . $q . ' non validée.') . '</div>'
        . '<table class="sig"><tr><td>Le franchisé<br></td><td>Le consultant<br></td><td>La marque — L’Atelier by<br></td></tr></table>'
        . '</div></div>';
    return $h;
}
