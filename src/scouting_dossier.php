<?php
declare(strict_types=1);

/**
 * Scouting — le dossier d'implantation, en PDF.
 *
 * L'écran sait tout calculer (ménages de la grille du recensement, concurrence,
 * emprise, CA) et le serveur ne sait rien de la zone dessinée : c'est donc
 * l'écran qui envoie le dossier, sous forme de DONNÉES (titres, tableaux,
 * carte en data-URI), jamais de HTML. Le serveur met en page, avec la même
 * chaîne que l'analyse magasin (rapPdfRendu : wkhtmltopdf, pied de page avec
 * le logo), et rend le fichier. Chaque chaîne est échappée ici : un dossier
 * ne porte que ce qu'on lui a donné.
 */

/** POST /scouting/dossier.pdf — le dossier d'implantation d'une zone, en PDF. */
function wr_scouting_dossier_pdf(): array
{
    $d = scoutingDossierValide(body());
    if ($d === null) { http_response_code(422); return ['error' => 'dossier incomplet : commune et chiffres attendus']; }
    $nl = $d['lang'] === 'nl';
    $doc = '<!doctype html><html lang="' . $d['lang'] . '"><head><meta charset="utf-8"><title>'
        . htmlspecialchars($d['titre'], ENT_QUOTES, 'UTF-8') . '</title></head><body>'
        . scoutingDossierHtml($d) . '</body></html>';
    $pdf = rapPdfRendu($doc, [
        'magasin' => $d['commune'],
        'rapport' => $nl ? 'Vestigingsstudie' : 'Étude d’implantation',
        'genere' => date($nl ? 'd/m/Y \\o\\m H:i' : 'd/m/Y à H:i'),
        'envoye' => '',
        'lang' => $d['lang'],
    ]);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    journalAdd('CEO', 'Scouting', $d['commune'],
        'Dossier d’implantation édité' . ($nl ? ' en néerlandais' : '') . ' — ' . $d['commune'] . ' · ' . $d['zone'] . ' · score ' . $d['score'] . '/100');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . ($nl ? 'vestigingsdossier-' : 'dossier-implantation-') . scoutingSlug($d['commune'])
        . '-' . date('Y-m-d') . '.pdf"');
    echo $pdf;
    exit;
}

/** Un nom de fichier sans accent ni espace. */
function scoutingSlug(string $texte): string
{
    $sans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);
    $sans = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $sans === false ? $texte : $sans) ?? '');
    return mb_substr(trim($sans, '-'), 0, 40) ?: 'zone';
}

/**
 * Ce que le dossier accepte, et rien d'autre : des chaînes bornées, des
 * nombres, des listes de lignes de longueur fixe, une image PNG/JPEG en
 * data-URI de moins de trois mégaoctets.
 *
 * @return array<string,mixed>|null
 */
function scoutingDossierValide(array $b): ?array
{
    $s = static fn ($v, int $max) => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, $max);
    $lignes = static function ($v, int $colonnes, int $max, int $larg) use ($s): array {
        $out = [];
        if (!is_array($v)) { return $out; }
        foreach ($v as $l) {
            if (!is_array($l)) { continue; }
            $row = [];
            for ($i = 0; $i < $colonnes; $i++) {
                $c = $l[$i] ?? '';
                $row[] = is_bool($c) ? $c : $s($c, $larg);
            }
            $out[] = $row;
            if (count($out) >= $max) { break; }
        }
        return $out;
    };
    $commune = $s($b['commune'] ?? '', 120);
    if ($commune === '') { return null; }
    $carte = (string) ($b['carte'] ?? '');
    if ($carte !== '' && (strlen($carte) > 3000000 || preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $carte) !== 1)) {
        $carte = '';
    }
    $notes = [];
    foreach ((array) ($b['notes'] ?? []) as $n) { if (is_scalar($n) && trim((string) $n) !== '') { $notes[] = $s($n, 500); } if (count($notes) >= 6) { break; } }
    // la langue du dossier, choisie à l'écran avant le téléchargement : FR ou NL
    $nl = ($b['lang'] ?? '') === 'nl';
    return [
        'lang' => $nl ? 'nl' : 'fr',
        'titre' => $s($b['titre'] ?? ($commune . ($nl ? ' — vestigingsstudie' : ' — étude d’implantation')), 160),
        'commune' => $commune,
        'geo' => $s($b['geo'] ?? '', 160),
        'date' => $s($b['date'] ?? date('d/m/Y'), 60),
        'score' => max(0, min(100, (int) ($b['score'] ?? 0))),
        'verdict' => $s($b['verdict'] ?? '', 120),
        'verdictNote' => $s($b['verdictNote'] ?? '', 400),
        'verdictOk' => !empty($b['verdictOk']),
        'zone' => $s($b['zone'] ?? '', 200),
        'carte' => $carte,
        'carteNote' => $s($b['carteNote'] ?? '', 240),
        // la page de garde : à 5 et 10 minutes en voiture, trois lignes sur
        // le magasin, le CA prévu avec la montée en charge
        'garde' => $lignes($b['garde'] ?? [], 4, 6, 60),
        'gardeCols' => $lignes([$b['gardeCols'] ?? []], 3, 1, 40)[0] ?? ($nl ? ['5 min met de auto', '10 min met de auto', 'Bestudeerde zone'] : ['5 min en voiture', '10 min en voiture', 'Zone étudiée']),
        'gardeLignes' => $lignes($b['gardeLignes'] ?? [], 3, 6, 200),
        'rampe' => $lignes($b['rampe'] ?? [], 5, 5, 60),
        'rampeNote' => $s($b['rampeNote'] ?? '', 400),
        'essentiel' => $lignes($b['essentiel'] ?? [], 3, 6, 80),
        'marche' => $lignes($b['marche'] ?? [], 3, 16, 200),
        'concurrence' => $lignes($b['concurrence'] ?? [], 8, 150, 120),
        'concurrenceNote' => $s($b['concurrenceNote'] ?? '', 700),
        'ecartesNote' => $s($b['ecartesNote'] ?? '', 1200),
        'indirecte' => $lignes($b['indirecte'] ?? [], 6, 40, 120),
        'indirecteNote' => $s($b['indirecteNote'] ?? '', 500),
        'ecolesNote' => $s($b['ecolesNote'] ?? '', 700),
        'fluxNote' => $s($b['fluxNote'] ?? '', 500),
        'tissu' => $lignes($b['tissu'] ?? [], 3, 12, 200),
        'zonings' => $lignes($b['zonings'] ?? [], 5, 30, 120),
        'ecoles' => $lignes($b['ecoles'] ?? [], 6, 40, 120),
        'flux' => $lignes($b['flux'] ?? [], 6, 40, 120),
        'etudeNote' => $s($b['etudeNote'] ?? '', 500),
        'reseau' => $lignes($b['reseau'] ?? [], 12, 12, 160),
        'reseauNote' => $s($b['reseauNote'] ?? '', 1400),
        'montee' => $s($b['montee'] ?? '', 500),
        'monteeCols' => $lignes([$b['monteeCols'] ?? []], 4, 1, 30)[0] ?? ($nl ? ['Jaar 1 · 70 %', 'Jaar 2 · 80 %', 'Jaar 3 · 90 %', 'Jaar 4 en later · 100 %'] : ['Année 1 · 70 %', 'Année 2 · 80 %', 'Année 3 · 90 %', 'Année 4 et + · 100 %']),
        'monteeRows' => $lignes($b['monteeRows'] ?? [], 6, 12, 80),
        'cartes' => (static function ($v) use ($s, $lignes): array {
            $out = [];
            if (!is_array($v)) { return $out; }
            foreach ($v as $c) {
                if (!is_array($c) || !is_array($c['ligne'] ?? null)) { continue; }
                $ligne = $lignes([$c['ligne']], 8, 1, 120)[0] ?? null;
                if ($ligne === null) { continue; }
                $f = is_array($c['fiche'] ?? null) ? $c['fiche'] : null;
                $photo = $f !== null ? (string) ($f['photo'] ?? '') : '';
                if ($photo !== '' && (strlen($photo) > 1200000 || preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $photo) !== 1)) { $photo = ''; }
                $out[] = ['ligne' => $ligne, 'fiche' => $f === null ? null : [
                    'url' => preg_match('#^https://(maps\.google\.com|www\.google\.com/maps|maps\.app\.goo\.gl)/#', (string) ($f['url'] ?? '')) === 1 ? (string) $f['url'] : '',
                    'photo' => $photo, 'photoAuteur' => $s($f['photoAuteur'] ?? '', 60), 'avis' => $lignes($f['avis'] ?? [], 4, 3, 340)]];
                if (count($out) >= 60) { break; }
            }
            return $out;
        })($b['cartes'] ?? []),
        'avisGoogle' => (static function ($v) use ($s, $lignes): array {
            $out = [];
            if (!is_array($v)) { return $out; }
            foreach ($v as $f) {
                if (!is_array($f)) { continue; }
                $photo = (string) ($f['photo'] ?? '');
                if ($photo !== '' && (strlen($photo) > 1200000 || preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $photo) !== 1)) { $photo = ''; }
                $out[] = ['nom' => $s($f['nom'] ?? '', 120), 'adresse' => $s($f['adresse'] ?? '', 160), 'note' => $s($f['note'] ?? '', 10), 'n' => (int) ($f['n'] ?? 0),
                    'dist' => $s($f['dist'] ?? '', 20), 'url' => preg_match('#^https://(maps\.google\.com|www\.google\.com/maps|maps\.app\.goo\.gl)/#', (string) ($f['url'] ?? '')) === 1 ? (string) $f['url'] : '',
                    'photo' => $photo, 'photoAuteur' => $s($f['photoAuteur'] ?? '', 60), 'avis' => $lignes($f['avis'] ?? [], 4, 3, 340)];
                if (count($out) >= 10) { break; }
            }
            return $out;
        })($b['avisGoogle'] ?? []),
        'googleNote' => $s($b['googleNote'] ?? '', 500),
        'motFin' => $s($b['motFin'] ?? '', 900),
        'hypotheses' => $lignes($b['hypotheses'] ?? [], 2, 24, 120),
        'notes' => $notes,
        'sources' => $s($b['sources'] ?? '', 900),
        // Retour de l'étude de potentiel (09/10/2026) : les deux estimations côte à côte (rayon et zone
        // construite par temps de parcours), le relevé terrain de la zone, les flux mesurés, et le mot sur
        // ce que l'outil fait et ne fait pas.
        'estimations' => $lignes($b['estimations'] ?? [], 4, 10, 120),
        'estimationsCols' => $lignes([$b['estimationsCols'] ?? []], 3, 1, 50)[0] ?? ($nl ? ['Straal', 'Opgebouwde zone', 'Duiding'] : ['Rayon', 'Zone construite', 'Lecture']),
        'estimationsNote' => $s($b['estimationsNote'] ?? '', 900),
        'terrain' => $lignes($b['terrain'] ?? [], 2, 12, 320),
        'fluxMesures' => $lignes($b['fluxMesures'] ?? [], 4, 6, 90),
        'terrainNote' => $s($b['terrainNote'] ?? '', 600),
        'motOutil' => $s($b['motOutil'] ?? '', 700),
    ];
}

/** La mise en page du dossier — mêmes conventions que l'analyse magasin. */
function scoutingDossierHtml(array $d): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // les libellés fixes, dans la langue du dossier ; le reste arrive traduit de l'écran
    $L = static fn (string $fr, string $nl): string => ($d['lang'] ?? 'fr') === 'nl' ? $nl : $fr;
    $logo = rapLogoDataUri();
    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A;font-size:9pt}
      .serif{font-family:Georgia,"DejaVu Serif","Times New Roman",serif}
      .k{font-size:7.2pt;letter-spacing:.09em;text-transform:uppercase;color:#7a736a;font-weight:normal}
      .mut{color:#7a736a}.acc{color:#8D1D2C}.ok{color:#2d7a3e}
      .h1{font-size:20pt;letter-spacing:-.01em;margin:4mm 0 1mm}
      .soustitre{font-size:9pt;color:#7a736a;margin:0 0 1.5mm}
      .zone{font-size:9pt;color:#2d7a3e;font-weight:bold;margin:0 0 5mm}
      .sec{font-family:Georgia,"DejaVu Serif",serif;font-size:12pt;margin:0 0 2.5mm;padding-bottom:1.2mm;border-bottom:1.4pt solid #8D1D2C}
      .verdict{border-radius:8px;padding:3mm 3.5mm;margin:0 0 5mm}
      .verdict .t{font-weight:bold;font-size:9.5pt;margin-bottom:.8mm}
      .verdict .n{font-size:8pt;color:#7a736a;line-height:1.5}
      .tile{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm}
      .tile .v{font-family:Georgia,"DejaVu Serif",serif;font-size:15pt;margin-top:1mm}
      .tile .s{font-size:7.5pt;color:#7a736a;margin-top:.8mm;line-height:1.45}
      table.grille{width:100%;border-collapse:separate;border-spacing:1.6mm 0;margin:0 -1.6mm 5mm}
      table.t{width:100%;border-collapse:collapse;margin-bottom:5mm}
      .t th{font-size:6.8pt;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;font-weight:normal;
            text-align:right;padding:1.5mm 2mm;border-bottom:1pt solid #221E1A}
      .t td{font-size:8.6pt;text-align:right;padding:1.4mm 2mm;border-bottom:.5pt solid #EAE3D8;vertical-align:top}
      .t .l{text-align:left}
      .t td b{font-weight:bold}
      .carte{width:100%;height:auto;display:block;border:1px solid #e6e0d8;border-radius:6px}
      .legende{font-size:7.5pt;color:#7a736a;margin:1.5mm 0 5mm;line-height:1.5}
      .score{font-family:Georgia,"DejaVu Serif",serif;font-size:26pt;color:#2d7a3e;line-height:1}
      .methode{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm;
               font-size:7.6pt;color:#7a736a;line-height:1.6;margin-bottom:4mm}
      .ccard{border:1px solid #e6e0d8;border-radius:8px;margin:0 0 2.6mm;page-break-inside:avoid}
      .methode + .ccard{margin-top:3.5mm}
      .ccard.fort{border-color:#c98a94}
      .chead{background:#fbf9f5;padding:1.8mm 3mm;font-size:8.6pt;border-bottom:1px solid #eee7de}
      .chead .chiffres{float:right;font-size:8pt;white-space:nowrap}
      .gcard{border:1px solid #e6e0d8;border-radius:8px;margin-bottom:3mm;page-break-inside:avoid}
      .gphoto{width:38mm;padding:2.5mm}.gphoto img{width:38mm;height:auto;display:block;border-radius:5px}
      .gcred{font-size:6.5pt;color:#7a736a;margin-top:1mm}
      .gtxt{padding:2.5mm 3mm}.gnom{font-size:9.5pt;font-weight:bold}.gnote{color:#2d7a3e}
      .gadr{font-size:8pt;color:#7a736a;margin:.6mm 0 1.4mm}
      .gavis{font-size:7.9pt;line-height:1.5;margin-top:1mm;color:#221E1A}
      .motfin{border:1.5pt solid #8D1D2C;border-radius:8px;background:#fff;padding:3.5mm 4mm;margin-top:5mm;
              font-family:Georgia,"DejaVu Serif",serif;font-size:9.5pt;line-height:1.6;color:#221E1A;page-break-inside:avoid}
      .motfin table.mini{font-family:Helvetica,Arial,sans-serif;font-size:8pt;margin-bottom:0}
      .motfin table.mini td{padding:1.2mm 2mm;border-bottom:.5pt solid #EAE3D8}
      .motfin table.mini td.cur{background:#F6E4E7;font-weight:bold}
      .motfin .curk{font-size:6.3pt;font-weight:normal;color:#8D1D2C;letter-spacing:.05em;text-transform:uppercase}
      .hl{display:inline-block;padding:.8mm 2mm;border-radius:4px;font-weight:bold;background:#f1ede6}
      .hl.pos{background:#E3EFE6;color:#2d7a3e}.hl.neg{background:#F6E4E7;color:#8D1D2C}
      .t th.v5,.t td.v5{background:#E8F0E9}.t th.v10,.t td.v10{background:#F0F6E8}
      table.t.garde{margin-bottom:2.5mm}.t.garde th{padding:1.1mm 2mm}.t.garde td{padding:1.1mm 2mm}.t.garde td.l:first-child{white-space:nowrap}
      .doc.garde .zone{margin-bottom:3mm}.doc.garde .verdict{margin-bottom:3mm}.doc.garde .sec{margin-bottom:1.8mm}
      .t tr.kv td{padding-top:2mm;padding-bottom:2mm}
      .t tr.tot td{font-weight:bold;border-top:1pt solid #221E1A;border-bottom:0;background:#fbf9f5}
      .t.reseau td{vertical-align:middle;white-space:nowrap}.t.reseau tr.p td{border-bottom:0;padding-bottom:.3mm}
      .t.reseau tr.p td[rowspan]{border-bottom:.5pt solid #EAE3D8}.t.reseau tr.r td{padding-top:.3mm}
      .t.reseau td.lib{font-size:6.5pt;letter-spacing:.05em;text-transform:uppercase}
    </style>';

    $h = $css . '<div class="doc' . ($d['garde'] !== [] ? ' garde' : '') . '">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #8D1D2C;padding-bottom:2.6mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" alt="L’Atelier by" style="height:34px">'
            : '<strong style="font-size:12pt">L’Atelier by</strong>') . '</td>'
        . '<td align="right" style="font-size:7.5pt;color:#7a736a;line-height:1.6">' . $L('Étude d’implantation<br>éditée le ', 'Vestigingsstudie<br>opgemaakt op ') . $e($d['date']) . '</td>'
        . '</tr></table>'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td valign="top">'
        . '<div class="serif h1">' . $e($d['titre']) . '</div>'
        . '<p class="soustitre">' . $e($d['geo']) . '</p>'
        . ($d['zone'] !== '' ? '<p class="zone">' . $e($d['zone']) . '</p>' : '')
        . '</td><td align="right" valign="top" style="width:28mm;padding-top:5mm"><div class="score">' . (int) $d['score'] . '</div>'
        . '<div class="k">score / 100</div></td></tr></table>';

    if ($d['verdict'] !== '') {
        $bg = $d['verdictOk'] ? '#E3EFE6' : '#F6E4E7';
        $col = $d['verdictOk'] ? '#2d7a3e' : '#8D1D2C';
        $h .= '<div class="verdict" style="background:' . $bg . '"><div class="t" style="color:' . $col . '">' . $e($d['verdict']) . '</div>'
            . ($d['verdictNote'] !== '' ? '<div class="n">' . $e($d['verdictNote']) . '</div>' : '') . '</div>';
    }

    // avec les isochrones de la page de garde, la carte se passe de titre :
    // elle porte sa légende, et la page doit tenir en une
    $garde = $d['garde'] !== [];
    if ($d['carte'] !== '') {
        $h .= ($garde ? '' : '<div class="sec">' . $L('Situation', 'Ligging') . '</div>') . '<img class="carte" src="' . $d['carte'] . '" alt="">'
            . '<div class="legende"' . ($garde ? ' style="margin-bottom:3mm"' : '') . '>' . $e($d['carteNote'] !== '' ? $d['carteNote'] : $L('Fond de carte © OpenStreetMap.', 'Achtergrondkaart © OpenStreetMap.')) . '</div>';
    }

    // La page de garde : sous la carte, à 5 et 10 minutes en voiture, le
    // magasin en trois lignes, le CA prévu avec la montée en charge. Le reste
    // du dossier commence sur la page suivante.
    if ($d['garde'] !== [] || $d['gardeLignes'] !== [] || $d['rampe'] !== []) {
        $h .= '<div class="sec">' . $L('En bref', 'In het kort') . '</div>';
        if ($d['garde'] !== [] || $d['gardeLignes'] !== []) {
            $h .= '<table class="t garde" cellpadding="0" cellspacing="0">';
            if ($d['garde'] !== []) {
                $c = $d['gardeCols'] + [$L('5 min en voiture', '5 min met de auto'), $L('10 min en voiture', '10 min met de auto'), $L('Zone étudiée', 'Bestudeerde zone')];
                $h .= '<tr><th class="l"></th><th class="v5">' . $e($c[0]) . '</th><th class="v10">' . $e($c[1]) . '</th><th>' . $e($c[2]) . '</th></tr>';
                foreach ($d['garde'] as $r) {
                    $h .= '<tr><td class="l"><b>' . $e($r[0]) . '</b></td><td class="v5" style="white-space:nowrap"><b>' . $e($r[1]) . '</b></td>'
                        . '<td class="v10" style="white-space:nowrap"><b>' . $e($r[2]) . '</b></td><td style="white-space:nowrap">' . $e($r[3]) . '</td></tr>';
                }
            }
            foreach ($d['gardeLignes'] as $r) {
                $h .= '<tr class="kv"><td class="l"><b>' . $e($r[0]) . '</b></td><td class="l" colspan="3"><b>' . $e($r[1]) . '</b>'
                    . ($r[2] !== '' ? ' <span class="mut">· ' . $e($r[2]) . '</span>' : '') . '</td></tr>';
            }
            $h .= '</table>';
        }
        if ($d['rampe'] !== []) {
            $h .= '<table class="t garde" cellpadding="0" cellspacing="0"><tr><th class="l">' . $L('CA prévu TTC · montée en charge', 'Geplande omzet incl. btw · aanloop') . '</th><th>' . $L('Par semaine', 'Per week') . '</th><th>' . $L('Par mois', 'Per maand') . '</th><th>' . $L('Sur l’année', 'Op jaarbasis') . '</th><th>' . $L('Clients / jour', 'Klanten / dag') . '</th></tr>';
            $n = count($d['rampe']);
            foreach ($d['rampe'] as $i => $r) {
                $h .= '<tr' . ($i === $n - 1 ? ' class="tot"' : '') . '><td class="l">' . $e($r[0]) . '</td><td style="white-space:nowrap">' . $e($r[1]) . '</td>'
                    . '<td style="white-space:nowrap">' . $e($r[2]) . '</td><td style="white-space:nowrap"><b>' . $e($r[3]) . '</b></td><td style="white-space:nowrap">' . $e($r[4]) . '</td></tr>';
            }
            $h .= '</table>' . ($d['rampeNote'] !== '' ? '<div class="legende">' . $e($d['rampeNote']) . '</div>' : '');
        }
        $h .= '<div style="page-break-before:always"></div>';
    }

    if ($d['essentiel'] !== []) {
        $h .= '<div class="sec">' . $L('L’essentiel', 'Kerncijfers') . '</div><table class="grille" cellpadding="0" cellspacing="0"><tr>';
        $w = (int) floor(100 / max(1, count($d['essentiel'])));
        foreach ($d['essentiel'] as $t) {
            $h .= '<td width="' . $w . '%" valign="top" class="tile"><div class="k">' . $e($t[0]) . '</div>'
                . '<div class="v">' . $e($t[1]) . '</div>' . ($t[2] !== '' ? '<div class="s">' . $e($t[2]) . '</div>' : '') . '</td>';
        }
        $h .= '</tr></table>';
    }

    if ($d['marche'] !== []) {
        $h .= '<div class="sec">' . $L('Le marché', 'De markt') . '</div><table class="t" cellpadding="0" cellspacing="0"><tr>'
            . '<th class="l">' . $L('Mesure', 'Maatstaf') . '</th><th>' . $L('Valeur', 'Waarde') . '</th><th class="l" style="padding-left:6mm">' . $L('Source · calcul', 'Bron · berekening') . '</th></tr>';
        foreach ($d['marche'] as $r) {
            $h .= '<tr><td class="l">' . $e($r[0]) . '</td><td style="white-space:nowrap"><b>' . $e($r[1]) . '</b></td>'
                . '<td class="l mut" style="padding-left:6mm">' . $e($r[2]) . '</td></tr>';
        }
        $h .= '</table>';
    }

    if ($d['estimations'] !== []) {
        $c = $d['estimationsCols'] + [$L('Rayon', 'Straal'), $L('Zone construite', 'Opgebouwde zone'), $L('Lecture', 'Duiding')];
        $h .= '<div class="sec">' . $L('Deux estimations', 'Twee ramingen') . '</div><table class="t" cellpadding="0" cellspacing="0"><tr>'
            . '<th class="l">' . $L('Mesure', 'Maatstaf') . '</th><th>' . $e($c[0]) . '</th><th class="v5">' . $e($c[1]) . '</th><th class="l" style="padding-left:6mm">' . $e($c[2]) . '</th></tr>';
        foreach ($d['estimations'] as $r) {
            $h .= '<tr><td class="l">' . $e($r[0]) . '</td><td style="white-space:nowrap">' . $e($r[1]) . '</td><td class="v5" style="white-space:nowrap"><b>' . $e($r[2]) . '</b></td>'
                . '<td class="l mut" style="padding-left:6mm">' . $e($r[3]) . '</td></tr>';
        }
        $h .= '</table>' . ($d['estimationsNote'] !== '' ? '<div class="methode">' . $e($d['estimationsNote']) . '</div>' : '');
    }

    $h .= '<div class="sec">' . $L('La concurrence, en détail', 'De concurrentie in detail') . '</div>';
    if ($d['concurrenceNote'] !== '') { $h .= '<div class="methode" style="color:#221E1A">' . $e($d['concurrenceNote']) . '</div>'; }
    if ($d['ecartesNote'] !== '') { $h .= '<div class="methode">' . $e($d['ecartesNote']) . '</div>'; }
    if ($d['cartes'] !== []) {
        // la liste : chaque concurrent, sa ligne, puis sa fiche Google
        foreach ($d['cartes'] as $i => $c) {
            $r = $c['ligne']; $f = $c['fiche']; $fort = !empty($r[6]); $ch = (string) ($r[7] ?? '');
            $h .= '<table class="ccard' . ($fort ? ' fort' : '') . '" width="100%" cellpadding="0" cellspacing="0">'
                . '<tr><td class="chead" colspan="2"><span class="mut">' . ($i + 1) . '</span> <b>' . $e($r[0]) . '</b>' . ($ch !== '' ? ' <span class="mut">· ' . $L('chaîne', 'keten') . ' ' . $e($ch) . '</span>' : '')
                . ' <span class="mut">' . $e($r[1]) . '</span>'
                . '<span class="chiffres">' . $e($r[2]) . ' &nbsp;·&nbsp; ' . ($r[3] === '—' ? $L('sans note', 'zonder score') : $e($r[3]) . ' ★') . ' &nbsp;·&nbsp; ' . $e($r[4]) . ' &nbsp;·&nbsp; ' . $L('force', 'sterkte') . ' ' . $e($r[5]) . ' &nbsp;·&nbsp; <span class="' . ($fort ? 'acc' : 'mut') . '">' . ($fort ? '<b>' . $L('concurrent fort', 'sterke concurrent') . '</b>' : 'concurrent') . '</span></span></td></tr>';
            if ($f !== null) {
                $h .= '<tr>';
                if ($f['photo'] !== '') { $h .= '<td class="gphoto" valign="top"><img src="' . $f['photo'] . '" alt="">' . ($f['photoAuteur'] !== '' ? '<div class="gcred">' . $L('photo : ', 'foto: ') . $e($f['photoAuteur']) . '</div>' : '') . '</td>'; }
                $h .= '<td valign="top" class="gtxt"' . ($f['photo'] === '' ? ' colspan="2"' : '') . '>';
                if ($f['avis'] === []) { $h .= '<div class="mut" style="font-size:7.8pt">' . $L('Aucun avis rendu par Google.', 'Geen reviews teruggegeven door Google.') . '</div>'; }
                foreach ($f['avis'] as $a) {
                    $h .= '<div class="gavis"><b>' . $e($a[1]) . ' ★</b> <span class="mut">' . $e($a[0]) . ' · ' . $e($a[2]) . '</span>' . ($a[3] !== '' ? ' — ' . $e($a[3]) : '') . '</div>';
                }
                $h .= '</td></tr>';
            }
            $h .= '</table>';
        }
        if ($d['googleNote'] !== '') { $h .= '<div class="methode">' . $e($d['googleNote']) . '</div>'; }
    } elseif ($d['concurrence'] === []) {
        $h .= '<p class="ok" style="font-size:9pt;margin:0 0 5mm">' . $L('Aucune boulangerie ni pâtisserie relevée dans la zone.', 'Geen bakkerij of banketbakkerij gevonden in de zone.') . '</p>';
    } else {
        $h .= '<table class="t" cellpadding="0" cellspacing="0"><tr>'
            . '<th class="l">' . $L('Commerce', 'Zaak') . '</th><th class="l">' . $L('Commune · adresse', 'Gemeente · adres') . '</th><th>' . $L('Distance', 'Afstand') . '</th><th>' . $L('Note / 5', 'Score / 5') . '</th><th class="l">' . $L('Taille (avis)', 'Grootte (reviews)') . '</th><th>' . $L('Force', 'Sterkte') . '</th><th class="l" style="padding-left:3mm">' . $L('Lecture', 'Duiding') . '</th></tr>';
        foreach ($d['concurrence'] as $r) {
            $fort = !empty($r[6]);
            $ch = (string) ($r[7] ?? '');
            $h .= '<tr><td class="l"><b>' . $e($r[0]) . '</b></td><td class="l mut">' . $e($r[1]) . '</td>'
                . '<td style="white-space:nowrap">' . $e($r[2]) . '</td><td>' . $e($r[3]) . '</td><td class="l mut">' . $e($r[4]) . '</td><td>' . $e($r[5]) . '</td>'
                . '<td class="l ' . ($fort ? 'acc' : 'mut') . '" style="padding-left:3mm">' . ($fort ? '<b>' . $L('concurrent fort', 'sterke concurrent') . '</b>' : 'concurrent') . ($ch !== '' ? ' · ' . $L('chaîne', 'keten') . ' ' . $e($ch) : '') . '</td></tr>';
        }
        $h .= '</table>';
    }

    // Le relevé terrain de la zone : ce qu'aucun outil ne voit — la visite, la
    // visibilité, l'accès, les flux comptés sur les axes.
    if ($d['terrain'] !== [] || $d['fluxMesures'] !== []) {
        $h .= '<div class="sec">' . $L('Le relevé terrain', 'De terreinverkenning') . '</div>';
        if ($d['terrain'] !== []) {
            $h .= '<table class="t" cellpadding="0" cellspacing="0">';
            foreach ($d['terrain'] as $r) { $h .= '<tr class="kv"><td class="l" style="width:34mm;white-space:nowrap"><b>' . $e($r[0]) . '</b></td><td class="l">' . $e($r[1]) . '</td></tr>'; }
            $h .= '</table>';
        }
        if ($d['fluxMesures'] !== []) {
            $h .= '<table class="t" cellpadding="0" cellspacing="0"><tr><th class="l">' . $L('Axe', 'As') . '</th><th>' . $L('Véhicules / jour', 'Voertuigen / dag') . '</th><th class="l" style="padding-left:4mm">' . $L('Sens', 'Richting') . '</th><th class="l" style="padding-left:4mm">' . $L('Source · date', 'Bron · datum') . '</th></tr>';
            foreach ($d['fluxMesures'] as $r) { $h .= '<tr><td class="l"><b>' . $e($r[0]) . '</b></td><td style="white-space:nowrap">' . $e($r[1]) . '</td><td class="l mut" style="padding-left:4mm">' . $e($r[2]) . '</td><td class="l mut" style="padding-left:4mm">' . $e($r[3]) . '</td></tr>'; }
            $h .= '</table>';
        }
        if ($d['terrainNote'] !== '') { $h .= '<div class="methode">' . $e($d['terrainNote']) . '</div>'; }
    }

    // (Les fiches Google vivent désormais dans la liste des concurrents ; ceci ne
    // sert plus qu'à un ancien client qui enverrait encore avisGoogle.)
    if ($d['avisGoogle'] !== [] && $d['cartes'] === []) {
        $h .= '<div class="sec">' . $L('Ce que Google dit des concurrents les plus proches', 'Wat Google zegt over de dichtstbijzijnde concurrenten') . '</div>';
        foreach ($d['avisGoogle'] as $f) {
            $h .= '<table class="gcard" width="100%" cellpadding="0" cellspacing="0"><tr>';
            if ($f['photo'] !== '') { $h .= '<td class="gphoto" valign="top"><img src="' . $f['photo'] . '" alt="">' . ($f['photoAuteur'] !== '' ? '<div class="gcred">' . $L('photo : ', 'foto: ') . $e($f['photoAuteur']) . '</div>' : '') . '</td>'; }
            $h .= '<td valign="top" class="gtxt"><div class="gnom">' . $e($f['nom']) . ' <span class="gnote">' . $e($f['note']) . ' ★</span> <span class="mut">' . (int) $f['n'] . $L(' avis', ' reviews') . ($f['dist'] !== '' ? $L(' · à ', ' · op ') . $e($f['dist']) : '') . '</span></div>'
                . ($f['adresse'] !== '' ? '<div class="gadr">' . $e($f['adresse']) . '</div>' : '');
            if ($f['avis'] === []) { $h .= '<div class="mut" style="font-size:7.8pt;margin-top:1mm">' . $L('Aucun avis rendu par Google.', 'Geen reviews teruggegeven door Google.') . '</div>'; }
            foreach ($f['avis'] as $a) {
                $h .= '<div class="gavis"><b>' . $e($a[1]) . ' ★</b> <span class="mut">' . $e($a[0]) . ' · ' . $e($a[2]) . '</span>' . ($a[3] !== '' ? ' — ' . $e($a[3]) : '') . '</div>';
            }
            $h .= '</td></tr></table>';
        }
        if ($d['googleNote'] !== '') { $h .= '<div class="methode">' . $e($d['googleNote']) . '</div>'; }
    }

    // L'étude de marché locale : ce qu'OpenStreetMap sait du rayon.
    // Une liste : colonnes de gauche (les `$gauche` premières et, si la
    // dernière est un nom, la dernière), chiffres à droite, une note dessous.
    $liste = static function (string $titre, array $rows, array $tetes, string $vide, string $note = '', int $gauche = 2, bool $derniereGauche = false) use (&$h, $e): void {
        $h .= '<div class="sec">' . $e($titre) . '</div>';
        if ($rows === []) { $h .= '<p class="mut" style="font-size:8.5pt;margin:0 0 5mm">' . $e($vide) . '</p>'; return; }
        $n = count($tetes);
        $h .= '<table class="t" cellpadding="0" cellspacing="0"><tr>';
        foreach ($tetes as $i => $t) { $h .= '<th' . ($i < $gauche || ($derniereGauche && $i === $n - 1) ? ' class="l"' . ($derniereGauche && $i === $n - 1 ? ' style="padding-left:3mm"' : '') : '') . '>' . $e($t) . '</th>'; }
        $h .= '</tr>';
        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($r as $i => $c) {
                $g = $i < $gauche || ($derniereGauche && $i === $n - 1);
                $h .= '<td class="' . ($i === 0 ? 'l' : ($g ? 'l mut' : '')) . '"' . ($derniereGauche && $i === $n - 1 ? ' style="padding-left:3mm"' : '') . '>' . ($i === 0 ? '<b>' . $e($c) . '</b>' : $e($c)) . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</table>';
        if ($note !== '') { $h .= '<div class="legende" style="margin-top:-3mm">' . $e($note) . '</div>'; }
    };
    if ($d['indirecte'] !== [] || $d['tissu'] !== [] || $d['zonings'] !== [] || $d['ecoles'] !== [] || $d['flux'] !== []) {
        $bandes = [$L('Genre', 'Soort'), '< 1 km', '< 2 km', '< 3 km', $L('Dans le rayon', 'Binnen de straal'), $L('Le plus proche', 'Dichtstbijzijnde')];
        $liste($L('La concurrence indirecte', 'De indirecte concurrentie'), $d['indirecte'], $bandes, $L('Ni supermarché, ni sandwicherie, ni salon de thé relevés dans le rayon.', 'Geen supermarkt, broodjeszaak of theesalon gevonden binnen de straal.'), $d['indirecteNote'], 1, true);
        if ($d['tissu'] !== []) {
            $h .= '<div class="sec">' . $L('Le tissu économique', 'Het economisch weefsel') . '</div><table class="t" cellpadding="0" cellspacing="0"><tr>'
                . '<th class="l">' . $L('Famille', 'Familie') . '</th><th>' . $L('Entreprises', 'Bedrijven') . '</th><th class="l" style="padding-left:6mm">' . $L('Ce qu’on y compte', 'Wat er meetelt') . '</th></tr>';
            foreach ($d['tissu'] as $r) {
                $h .= '<tr><td class="l">' . $e($r[0]) . '</td><td style="white-space:nowrap"><b>' . $e($r[1]) . '</b></td>'
                    . '<td class="l mut" style="padding-left:6mm">' . $e($r[2]) . '</td></tr>';
            }
            $h .= '</table>';
        }
        $liste($L('Les zonings et parcs d’activité', 'Bedrijventerreinen en bedrijvenparken'), $d['zonings'], ['Zone', $L('Genre', 'Soort'), $L('Distance', 'Afstand'), $L('Surface', 'Oppervlakte'), $L('Entreprises', 'Bedrijven')], $L('Aucun zoning ni parc d’activité cartographié dans le rayon.', 'Geen bedrijventerrein of bedrijvenpark in kaart gebracht binnen de straal.'));
        $liste($L('Les écoles', 'De scholen'), $d['ecoles'], ['Niveau', '< 1 km', '< 2 km', '< 3 km', $L('Dans le rayon', 'Binnen de straal'), $L('La plus proche', 'Dichtstbijzijnde')], $L('Aucune école cartographiée dans le rayon.', 'Geen school in kaart gebracht binnen de straal.'), $d['ecolesNote'], 1, true);
        $liste($L('Les générateurs de flux', 'De publiekstrekkers'), $d['flux'], $bandes, $L('Ni gare, ni hôpital, ni administration, ni centre sportif relevés dans le rayon.', 'Geen station, ziekenhuis, administratie of sportcentrum gevonden binnen de straal.'), $d['fluxNote'], 1, true);
        if ($d['etudeNote'] !== '') { $h .= '<div class="methode">' . $e($d['etudeNote']) . '</div>'; }
    }

    if ($d['reseau'] !== []) {
        $h .= '<div class="sec">' . $L('Le réseau : prévu et réel — semaine, mois, année', 'Het netwerk: gepland en werkelijk — week, maand, jaar') . '</div>';
        if ($d['montee'] !== '') {
            $h .= '<div class="motfin" style="margin:0 0 3mm;font-size:8.8pt"><div class="k" style="color:#8D1D2C;margin-bottom:1.2mm">' . $L('La montée en charge', 'De aanloop') . '</div>' . $e($d['montee']);
            if ($d['monteeRows'] !== []) {
                $h .= '<table class="t mini" cellpadding="0" cellspacing="0" style="margin:2.5mm 0 0"><tr><th class="l"></th>';
                foreach ($d['monteeCols'] as $c) { $h .= '<th>' . $e($c) . '</th>'; }
                $h .= '</tr>';
                foreach ($d['monteeRows'] as $i => $r) {
                    $cur = (int) ($r[5] ?? 0);
                    $h .= '<tr><td class="l' . ($i === 0 ? ' ok' : '') . '">' . $e($r[0]) . '</td>';
                    foreach ([1, 2, 3, 4] as $k) { $h .= '<td' . ($cur === $k ? ' class="cur"' : '') . ' style="white-space:nowrap">' . $e($r[$k]) . ($cur === $k ? '<div class="curk">' . $L('en cours', 'lopend') . '</div>' : '') . '</td>'; }
                    $h .= '</tr>';
                }
                $h .= '</table>';
            }
            $h .= '</div>';
        }
        $h .= '<table class="t reseau" cellpadding="0" cellspacing="0"><tr>'
            . '<th class="l">' . $L('Magasin', 'Winkel') . '</th><th class="l"></th><th>' . $L('Par semaine', 'Per week') . '</th><th>' . $L('Par mois', 'Per maand') . '</th><th>' . $L('Sur l’année', 'Op jaarbasis') . '</th><th>' . $L('Cible de l’année', 'Jaardoel') . '</th><th>' . $L('Réel / cible', 'Werkelijk / doel') . '</th><th>' . $L('Réel / plan', 'Werkelijk / plan') . '</th></tr>';
        foreach ($d['reseau'] as $i => $r) {
            $sens = (string) ($r[10] ?? '');
            // deux lignes par magasin, prévu puis réel ; une seule sans réel
            $reel = ($r[2] ?? '—') !== '—' || ($r[4] ?? '—') !== '—' || ($r[6] ?? '—') !== '—';
            $sp = $reel ? ' rowspan="2"' : '';
            $h .= '<tr class="' . ($reel ? 'p' : 'seul') . '"><td class="l"' . $sp . '><b>' . $e($r[0]) . '</b></td><td class="l mut lib">' . $L('prévu', 'gepland') . '</td>';
            foreach ([1, 3, 5] as $k) { $h .= '<td class="' . ($i === 0 ? 'ok' : '') . '">' . $e($r[$k]) . '</td>'; }
            $h .= '<td' . $sp . '><b>' . $e($r[7]) . '</b></td>'
                . '<td' . $sp . '><span class="hl' . ($sens === 'pos' ? ' pos' : ($sens === 'neg' ? ' neg' : '')) . '">' . $e($r[8]) . '</span></td>'
                . '<td class="mut"' . $sp . '>' . $e($r[9]) . '</td></tr>';
            if ($reel) {
                $h .= '<tr class="r"><td class="l mut lib">' . $L('réel', 'werkelijk') . '</td>';
                foreach ([2, 4, 6] as $k) { $h .= '<td><b>' . $e($r[$k]) . '</b></td>'; }
                $h .= '</tr>';
            }
        }
        $h .= '</table>' . ($d['reseauNote'] !== '' ? '<div class="legende" style="color:#221E1A;margin-bottom:1.5mm">' . $e($d['reseauNote']) . '</div>' : '')
            . '<div class="legende">' . $L('Tout est TTC. Le prévu (le plan) : le CA annuel prévu réaliste saisi dans « Magasins du réseau », sinon celui de l’étude de marché — divisé par 52 pour la semaine, par 12 pour le mois. Le réel : les ventes TTC du P&L mensuel du panel, complétées par les ventes caisse (montants bruts après remises) pour les mois sans P&L — moyenne des mois clos disponibles (douze au plus), ramenée à la semaine, et projetée sur douze mois pour l’année. La cible de l’année : le palier de la phase (70, 80, 90 ou 100 % du plan). Réel / cible, en évidence, est l’écart qui compte ; réel / plan dit le chemin qui reste.',
                'Alles incl. btw. Het geplande (het plan): de realistische geplande jaaromzet ingevoerd in “Magasins du réseau”, anders die van de marktstudie — gedeeld door 52 voor de week, door 12 voor de maand. Het werkelijke: de omzet incl. btw uit de maandelijkse P&L van het panel, aangevuld met de kassaverkopen (brutobedragen na kortingen) voor de maanden zonder P&L — gemiddelde van de beschikbare afgesloten maanden (maximaal twaalf), herleid naar de week en geëxtrapoleerd naar twaalf maanden voor het jaar. Het jaardoel: de trede van de fase (70, 80, 90 of 100 % van het plan). Werkelijk / doel, uitgelicht, is het verschil dat telt; werkelijk / plan toont de weg die nog rest.') . '</div>';
    }

    foreach ($d['notes'] as $n) {
        $h .= '<div class="methode">' . $e($n) . '</div>';
    }

    if ($d['hypotheses'] !== []) {
        $h .= '<div class="sec">' . $L('Les hypothèses au moment de l’édition', 'De aannames op het moment van opmaak') . '</div><table class="t" cellpadding="0" cellspacing="0">';
        $cols = array_chunk($d['hypotheses'], (int) ceil(count($d['hypotheses']) / 2));
        $n = max(array_map('count', $cols));
        for ($i = 0; $i < $n; $i++) {
            $h .= '<tr>';
            foreach ($cols as $col) {
                $r = $col[$i] ?? ['', ''];
                $h .= '<td class="l mut" style="width:32%">' . $e($r[0]) . '</td><td class="l" style="width:18%"><b>' . $e($r[1]) . '</b></td>';
            }
            $h .= '</tr>';
        }
        $h .= '</table>';
    }

    if ($d['sources'] !== '') {
        $h .= '<div class="methode"><b style="color:#221E1A">' . $L('Sources.', 'Bronnen.') . '</b> ' . $e($d['sources']) . '</div>';
    }
    if ($d['motOutil'] !== '') { $h .= '<div class="methode" style="color:#221E1A"><b>' . $L('L’outil repère, l’étude confirme.', 'De tool spoort op, de studie bevestigt.') . '</b> ' . $e($d['motOutil']) . '</div>'; }
    if ($d['motFin'] !== '') {
        $h .= '<div class="motfin"><div class="k" style="color:#8D1D2C;margin-bottom:1.5mm">' . $L('À lire avant de signer', 'Te lezen vóór ondertekening') . '</div>' . $e($d['motFin']) . '</div>';
    }
    return $h . '</div>';
}

/** POST /scouting/plan.pdf — le plan d'expansion, en PDF. */
function wr_scouting_plan_pdf(): array
{
    $d = scoutingPlanValide(body());
    if ($d === null) { http_response_code(422); return ['error' => 'plan vide : aucune province']; }
    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>'
        . htmlspecialchars($d['titre'], ENT_QUOTES, 'UTF-8') . '</title></head><body>'
        . scoutingPlanHtml($d) . '</body></html>';
    $pdf = rapPdfRendu($doc, [
        'magasin' => 'Réseau',
        'rapport' => 'Plan d’expansion',
        'genere' => date('d/m/Y à H:i'),
        'envoye' => '',
    ]);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    journalAdd('CEO', 'Scouting', '—', 'Plan d’expansion édité — ' . $d['sousTitre'] . ' · ' . $d['total']);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="plan-expansion-' . date('Y-m-d') . '.pdf"');
    echo $pdf;
    exit;
}

/** @return array<string,mixed>|null */
function scoutingPlanValide(array $b): ?array
{
    $s = static fn ($v, int $max) => mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, $max);
    $lignes = static function ($v, int $colonnes, int $max, int $larg) use ($s): array {
        $out = [];
        if (!is_array($v)) { return $out; }
        foreach ($v as $l) {
            if (!is_array($l)) { continue; }
            $row = [];
            for ($i = 0; $i < $colonnes; $i++) { $row[] = $s($l[$i] ?? '', $larg); }
            $out[] = $row;
            if (count($out) >= $max) { break; }
        }
        return $out;
    };
    $carte = (string) ($b['carte'] ?? '');
    if ($carte !== '' && (strlen($carte) > 3000000 || preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $carte) !== 1)) { $carte = ''; }
    $provinces = [];
    foreach ((array) ($b['provinces'] ?? []) as $p) {
        if (!is_array($p)) { continue; }
        $nom = $s($p['nom'] ?? '', 60);
        if ($nom === '') { continue; }
        $provinces[] = ['nom' => $nom, 'detail' => $s($p['detail'] ?? '', 120), 'sousTotal' => $s($p['sousTotal'] ?? '', 40),
            'lignes' => $lignes($p['lignes'] ?? [], 10, 5, 120)];
        if (count($provinces) >= 11) { break; }
    }
    if ($provinces === []) { return null; }
    return [
        'titre' => $s($b['titre'] ?? 'Plan d’expansion', 160),
        'sousTitre' => $s($b['sousTitre'] ?? '', 160),
        'date' => $s($b['date'] ?? date('d/m/Y'), 60),
        'total' => $s($b['total'] ?? '', 40),
        'resume' => $lignes($b['resume'] ?? [], 3, 6, 120),
        'carte' => $carte, 'carteNote' => $s($b['carteNote'] ?? '', 240),
        'provinces' => $provinces,
        'classement' => $lignes($b['classement'] ?? [], 5, 10, 80),
        'hypotheses' => $lignes($b['hypotheses'] ?? [], 2, 20, 120),
        'methode' => $s($b['methode'] ?? '', 900),
        'sources' => $s($b['sources'] ?? '', 700),
    ];
}

/** La mise en page du plan — les conventions du dossier. */
function scoutingPlanHtml(array $d): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logo = rapLogoDataUri();
    $css = '<style>
      .doc{font-family:Helvetica,Arial,sans-serif;color:#221E1A;font-size:9pt}
      .serif{font-family:Georgia,"DejaVu Serif","Times New Roman",serif}
      .k{font-size:7.2pt;letter-spacing:.09em;text-transform:uppercase;color:#7a736a;font-weight:normal}
      .mut{color:#7a736a}.acc{color:#8D1D2C}.ok{color:#2d7a3e}
      .h1{font-size:20pt;letter-spacing:-.01em;margin:4mm 0 1mm}
      .soustitre{font-size:9pt;color:#7a736a;margin:0 0 5mm}
      .sec{font-family:Georgia,"DejaVu Serif",serif;font-size:12pt;margin:0 0 2.5mm;padding-bottom:1.2mm;border-bottom:1.4pt solid #8D1D2C}
      .sec small{font-family:Helvetica,Arial,sans-serif;font-size:7.5pt;color:#7a736a;margin-left:3mm}
      .tile{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm}
      .tile .v{font-family:Georgia,"DejaVu Serif",serif;font-size:15pt;margin-top:1mm}
      .tile .s{font-size:7.5pt;color:#7a736a;margin-top:.8mm;line-height:1.45}
      table.grille{width:100%;border-collapse:separate;border-spacing:1.6mm 0;margin:0 -1.6mm 5mm}
      table.t{width:100%;border-collapse:collapse;margin-bottom:5mm}
      .t th{font-size:6.8pt;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;font-weight:normal;text-align:right;padding:1.5mm 2mm;border-bottom:1pt solid #221E1A}
      .t td{font-size:8.4pt;text-align:right;padding:1.3mm 2mm;border-bottom:.5pt solid #EAE3D8;vertical-align:top}
      .t .l{text-align:left}
      .t tr.tot td{font-weight:bold;border-top:1pt solid #221E1A;border-bottom:0;background:#fbf9f5}
      .carte{width:100%;height:auto;display:block;border:1px solid #e6e0d8;border-radius:6px}
      .legende{font-size:7.5pt;color:#7a736a;margin:1.5mm 0 5mm;line-height:1.5}
      .total{font-family:Georgia,"DejaVu Serif",serif;font-size:20pt;color:#2d7a3e;line-height:1;white-space:nowrap}
      .methode{border:1px solid #e6e0d8;border-radius:8px;background:#fbf9f5;padding:3mm 3.5mm;font-size:7.6pt;color:#7a736a;line-height:1.6;margin-bottom:4mm}
    </style>';
    $h = $css . '<div class="doc">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #8D1D2C;padding-bottom:2.6mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" alt="L’Atelier by" style="height:34px">' : '<strong style="font-size:12pt">L’Atelier by</strong>') . '</td>'
        . '<td align="right" style="font-size:7.5pt;color:#7a736a;line-height:1.6">Plan d’expansion<br>édité le ' . $e($d['date']) . '</td></tr></table>'
        . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td valign="top"><div class="serif h1">' . $e($d['titre']) . '</div>'
        . '<p class="soustitre">' . $e($d['sousTitre']) . '</p></td>'
        . '<td align="right" valign="top" style="width:48mm;padding-top:5mm"><div class="total">' . $e($d['total']) . '</div><div class="k">CA annuel estimé</div></td></tr></table>';
    if ($d['resume'] !== []) {
        $h .= '<div class="sec">Ce que le plan peut dégager</div><table class="grille" cellpadding="0" cellspacing="0"><tr>';
        $w = (int) floor(100 / max(1, count($d['resume'])));
        foreach ($d['resume'] as $t) {
            $h .= '<td width="' . $w . '%" valign="top" class="tile"><div class="k">' . $e($t[0]) . '</div><div class="v">' . $e($t[1]) . '</div>'
                . ($t[2] !== '' ? '<div class="s">' . $e($t[2]) . '</div>' : '') . '</td>';
        }
        $h .= '</tr></table>';
    }
    if ($d['carte'] !== '') {
        $h .= '<div class="sec">Où ouvrir</div><img class="carte" src="' . $d['carte'] . '" alt="">'
            . '<div class="legende">' . $e($d['carteNote'] !== '' ? $d['carteNote'] : 'Fond de carte © OpenStreetMap.') . '</div>';
    }
    foreach ($d['provinces'] as $p) {
        $h .= '<div class="sec">' . $e($p['nom']) . '<small>' . $e($p['detail']) . '</small></div>';
        if ($p['lignes'] === []) { $h .= '<p class="mut" style="font-size:8.5pt;margin:0 0 5mm">Aucune zone retenue dans cette province.</p>'; continue; }
        $h .= '<table class="t" cellpadding="0" cellspacing="0"><tr><th class="l">#</th><th class="l">Commune</th><th class="l">Arrondissement</th><th>Score</th><th>Ménages</th><th>Concurrents</th><th class="l">Chaînes</th><th>Emprise</th><th>CA estimé</th><th>€/m²</th></tr>';
        foreach ($p['lignes'] as $l) {
            $h .= '<tr><td class="l mut">' . $e($l[0]) . '</td><td class="l"><b>' . $e($l[1]) . '</b></td><td class="l mut">' . $e($l[2]) . '</td><td>' . $e($l[3]) . '</td><td>' . $e($l[4]) . '</td>'
                . '<td>' . $e($l[5]) . '</td><td class="l mut">' . $e($l[6]) . '</td><td>' . $e($l[7]) . '</td><td style="white-space:nowrap"><b>' . $e($l[8]) . '</b></td><td class="mut">' . $e($l[9]) . '</td></tr>';
        }
        $h .= '<tr class="tot"><td colspan="8" class="l">' . count($p['lignes']) . ' ouverture' . (count($p['lignes']) > 1 ? 's' : '') . '</td><td style="white-space:nowrap">' . $e($p['sousTotal']) . '</td><td></td></tr></table>';
    }
    if ($d['classement'] !== []) {
        $h .= '<div class="sec">Les dix meilleures, toutes provinces</div><table class="t" cellpadding="0" cellspacing="0"><tr><th class="l">#</th><th class="l">Commune</th><th class="l">Province</th><th>Score</th><th>CA estimé</th></tr>';
        foreach ($d['classement'] as $l) {
            $h .= '<tr><td class="l mut">' . $e($l[0]) . '</td><td class="l"><b>' . $e($l[1]) . '</b></td><td class="l mut">' . $e($l[2]) . '</td><td>' . $e($l[3]) . '</td><td style="white-space:nowrap"><b>' . $e($l[4]) . '</b></td></tr>';
        }
        $h .= '</table>';
    }
    if ($d['methode'] !== '') { $h .= '<div class="methode"><b style="color:#221E1A">Méthode.</b> ' . $e($d['methode']) . '</div>'; }
    if ($d['hypotheses'] !== []) {
        $h .= '<div class="sec">Les hypothèses au moment de l’édition</div><table class="t" cellpadding="0" cellspacing="0">';
        $cols = array_chunk($d['hypotheses'], (int) ceil(count($d['hypotheses']) / 2));
        $n = max(array_map('count', $cols));
        for ($i = 0; $i < $n; $i++) {
            $h .= '<tr>';
            foreach ($cols as $col) { $r = $col[$i] ?? ['', '']; $h .= '<td class="l mut" style="width:32%">' . $e($r[0]) . '</td><td class="l" style="width:18%"><b>' . $e($r[1]) . '</b></td>'; }
            $h .= '</tr>';
        }
        $h .= '</table>';
    }
    if ($d['sources'] !== '') { $h .= '<div class="methode"><b style="color:#221E1A">Sources.</b> ' . $e($d['sources']) . '</div>'; }
    return $h . '</div>';
}
