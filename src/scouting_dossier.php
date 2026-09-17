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
    $doc = '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>'
        . htmlspecialchars($d['titre'], ENT_QUOTES, 'UTF-8') . '</title></head><body>'
        . scoutingDossierHtml($d) . '</body></html>';
    $pdf = rapPdfRendu($doc, [
        'magasin' => $d['commune'],
        'rapport' => 'Étude d’implantation',
        'genere' => date('d/m/Y à H:i'),
        'envoye' => '',
    ]);
    if ($pdf === null) { http_response_code(501); return ['error' => 'aucun moteur PDF sur ce serveur']; }
    journalAdd('CEO', 'Scouting', $d['commune'],
        'Dossier d’implantation édité — ' . $d['commune'] . ' · ' . $d['zone'] . ' · score ' . $d['score'] . '/100');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="dossier-implantation-' . scoutingSlug($d['commune'])
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
    return [
        'titre' => $s($b['titre'] ?? ($commune . ' — étude d’implantation'), 160),
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
        'essentiel' => $lignes($b['essentiel'] ?? [], 3, 6, 80),
        'marche' => $lignes($b['marche'] ?? [], 3, 16, 200),
        'concurrence' => $lignes($b['concurrence'] ?? [], 6, 40, 120),
        'reseau' => $lignes($b['reseau'] ?? [], 7, 12, 120),
        'hypotheses' => $lignes($b['hypotheses'] ?? [], 2, 20, 120),
        'notes' => $notes,
        'sources' => $s($b['sources'] ?? '', 700),
    ];
}

/** La mise en page du dossier — mêmes conventions que l'analyse magasin. */
function scoutingDossierHtml(array $d): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    </style>';

    $h = $css . '<div class="doc">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid #8D1D2C;padding-bottom:2.6mm"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" alt="L’Atelier by" style="height:34px">'
            : '<strong style="font-size:12pt">L’Atelier by</strong>') . '</td>'
        . '<td align="right" style="font-size:7.5pt;color:#7a736a;line-height:1.6">Étude d’implantation<br>éditée le ' . $e($d['date']) . '</td>'
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

    if ($d['carte'] !== '') {
        $h .= '<div class="sec">Situation</div><img class="carte" src="' . $d['carte'] . '" alt="">'
            . ($d['carteNote'] !== '' ? '<div class="legende">' . $e($d['carteNote']) . '</div>' : '<div class="legende">Fond de carte © OpenStreetMap.</div>');
    }

    if ($d['essentiel'] !== []) {
        $h .= '<div class="sec">L’essentiel</div><table class="grille" cellpadding="0" cellspacing="0"><tr>';
        $w = (int) floor(100 / max(1, count($d['essentiel'])));
        foreach ($d['essentiel'] as $t) {
            $h .= '<td width="' . $w . '%" valign="top" class="tile"><div class="k">' . $e($t[0]) . '</div>'
                . '<div class="v">' . $e($t[1]) . '</div>' . ($t[2] !== '' ? '<div class="s">' . $e($t[2]) . '</div>' : '') . '</td>';
        }
        $h .= '</tr></table>';
    }

    if ($d['marche'] !== []) {
        $h .= '<div class="sec">Le marché</div><table class="t" cellpadding="0" cellspacing="0"><tr>'
            . '<th class="l">Mesure</th><th>Valeur</th><th class="l" style="padding-left:6mm">Source · calcul</th></tr>';
        foreach ($d['marche'] as $r) {
            $h .= '<tr><td class="l">' . $e($r[0]) . '</td><td style="white-space:nowrap"><b>' . $e($r[1]) . '</b></td>'
                . '<td class="l mut" style="padding-left:6mm">' . $e($r[2]) . '</td></tr>';
        }
        $h .= '</table>';
    }

    $h .= '<div class="sec">La concurrence relevée</div>';
    if ($d['concurrence'] === []) {
        $h .= '<p class="ok" style="font-size:9pt;margin:0 0 5mm">Aucune boulangerie ni pâtisserie relevée dans la zone.</p>';
    } else {
        $h .= '<table class="t" cellpadding="0" cellspacing="0"><tr>'
            . '<th class="l">Commerce</th><th class="l">Commune</th><th>Distance</th><th>Note / 5</th><th>Force</th><th class="l" style="padding-left:4mm">Lecture</th></tr>';
        foreach ($d['concurrence'] as $r) {
            $fort = !empty($r[5]);
            $h .= '<tr><td class="l"><b>' . $e($r[0]) . '</b></td><td class="l mut">' . $e($r[1]) . '</td>'
                . '<td>' . $e($r[2]) . '</td><td>' . $e($r[3]) . '</td><td>' . $e($r[4]) . '</td>'
                . '<td class="l ' . ($fort ? 'acc' : 'mut') . '" style="padding-left:4mm">' . ($fort ? '<b>concurrent fort</b>' : 'concurrent') . '</td></tr>';
        }
        $h .= '</table>';
    }

    if ($d['reseau'] !== []) {
        $h .= '<div class="sec">Comparaison au réseau</div><table class="t" cellpadding="0" cellspacing="0"><tr>'
            . '<th class="l">Point de vente</th><th class="l">Statut</th><th>Ménages</th><th>Dépense</th><th>Emprise</th><th>CA annuel</th><th class="l" style="padding-left:4mm">Note</th></tr>';
        foreach ($d['reseau'] as $i => $r) {
            $h .= '<tr><td class="l"><b>' . $e($r[0]) . '</b></td><td class="l mut">' . $e($r[1]) . '</td>'
                . '<td>' . $e($r[2]) . '</td><td>' . $e($r[3]) . '</td><td>' . $e($r[4]) . '</td>'
                . '<td class="' . ($i === 0 ? 'ok' : '') . '" style="white-space:nowrap"><b>' . $e($r[5]) . '</b></td>'
                . '<td class="l mut" style="padding-left:4mm">' . $e($r[6]) . '</td></tr>';
        }
        $h .= '</table>';
    }

    foreach ($d['notes'] as $n) {
        $h .= '<div class="methode">' . $e($n) . '</div>';
    }

    if ($d['hypotheses'] !== []) {
        $h .= '<div class="sec">Les hypothèses au moment de l’édition</div><table class="t" cellpadding="0" cellspacing="0">';
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
        $h .= '<div class="methode"><b style="color:#221E1A">Sources.</b> ' . $e($d['sources']) . '</div>';
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
