<?php
declare(strict_types=1);

/**
 * Cockpit CEO — la note de campagne envoyée aux franchisés.
 *
 * Une campagne se décide à la centrale et se joue en boutique. Entre les deux,
 * il manquait la feuille qui dit ce qui va se passer ce mois-ci : les dates, le
 * budget engagé, l'objectif chiffré et ce qu'on attend du magasin. Elle se
 * lisait dans l'assistant, écran par écran, par celui qui l'avait écrite —
 * personne d'autre.
 *
 * Ce fichier produit donc UNE page A4, imprimable et joignable à un e-mail.
 * Le PDF et le courrier sortent des mêmes données : ce que le franchisé lit
 * dans sa boîte et ce qu'il imprime ne peuvent pas diverger.
 *
 * L'expéditeur, le sujet et le corps du courrier sont des réglages, pas du
 * code : la maison change de formule sans qu'on redéploie.
 */

/** Réglages par défaut — le squelette du courrier compris. */
function mktBriefDefauts(): array
{
    return [
        // L'adresse de la campagne, distincte de celle des achats : un
        // franchisé qui répond « je n'ai pas reçu les affiches » ne doit pas
        // tomber dans la boîte des commandes fournisseurs.
        'expediteur' => 'Marketing L’Atelier by <marketing@atelierby.be>',
        'repondreA'  => 'marketing@atelierby.be',
        'sujet'      => '{{campagne}} — ce qui se passe du {{du}} au {{au}}',
        'intro'      => 'Bonjour {{franchise}},

Voici la campagne du mois pour {{magasin}}. La note complète est en pièce jointe (PDF) — elle tient sur une page.',
        'pied'       => 'Une question sur cette campagne ? Répondez à ce courriel : il arrive directement au marketing.',
        // Le gabarit ENTIER est éditable, variables comprises. Vide = celui-ci.
        'html'       => '',
        // Carnet d'adresses par magasin : le cockpit ne connaît pas l'adresse
        // d'un franchisé, et l'inventer serait pire que de la demander.
        'carnet'     => [],
        'copie'      => '',
    ];
}

function mktBriefConfig(): array
{
    $c = setting('mktBrief');
    $c = is_array($c) ? $c : [];
    $out = array_merge(mktBriefDefauts(), $c);
    $out['carnet'] = is_array($out['carnet'] ?? null) ? $out['carnet'] : [];
    return $out;
}

/** Une adresse plausible, ou '' — on n'envoie jamais « à peu près ». */
function mktBriefAdresse(mixed $v): string
{
    $v = trim((string) $v);
    return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
}

/** `01/09/2026`. */
function mktBriefJour(string $iso): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? $m[3] . '/' . $m[2] . '/' . $m[1] : $iso;
}

/** `4 150 €` — l'espace fine insécable comme partout ailleurs. */
function mktBriefEuros(?float $v): string
{
    return $v === null ? '—' : number_format($v, 0, ',', ' ') . ' €';
}

/**
 * Tout ce que la note dit d'une campagne, rassemblé une fois.
 *
 * @return array<string,mixed>|null null : la campagne n'existe pas.
 */
function mktBriefDonnees(int $id): ?array
{
    $c = Db::row('SELECT c.*, t.label AS type_label, t.icon_path, t.color_hex AS type_color,
                         t.default_kpi_label, t.lever_id, t.lever_badge_label,
                         l.label AS lever_label, l.code AS lever_code, l.color_hex AS lever_color,
                         s.label AS statut_label
                    FROM mar_campaign c
                    LEFT JOIN mar_campaign_type t ON t.id = c.type_id
                    LEFT JOIN mar_lever l ON l.id = t.lever_id
                    LEFT JOIN mar_campaign_status s ON s.code = c.status_code
                   WHERE c.id = ?', [$id]);
    if ($c === null) { return null; }

    $du = (string) ($c['starts_on'] ?? '');
    $au = (string) ($c['ends_on'] ?: $c['starts_on']);
    $reseau = str_starts_with(strtoupper((string) ($c['scope'] ?? '')), 'RESEAU')
        || str_starts_with(strtoupper((string) ($c['scope'] ?? '')), 'RÉSEAU')
        || str_starts_with(strtoupper((string) ($c['scope'] ?? '')), 'NETWORK');

    // Périmètre. Une campagne réseau porte quand même des lignes de liaison :
    // c'est le SCOPE qui fait foi — la même règle que la mesure, sinon la note
    // annoncerait un périmètre que le bilan ne mesure pas.
    $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name');
    $nomDe = [];
    foreach ($shops as $s) { $nomDe[(string) $s['id']] = (string) $s['name']; }
    $perim = [];
    if (!$reseau) {
        try {
            foreach (Db::rows('SELECT shop_id FROM mar_campaign_shop WHERE campaign_id = ?', [$id]) as $r) {
                $sid = (string) $r['shop_id'];
                if (isset($nomDe[$sid])) { $perim[] = $sid; }
            }
        } catch (PDOException $e) { /* pas de liaison : réseau entier */ }
    }
    if ($perim === []) { $perim = array_map('strval', array_keys($nomDe)); }

    // L'objectif, dans l'unité du KPI du levier — le même calcul que
    // l'assistant, appelé et non recopié.
    $kpi = null;
    if ($du !== '' && $au !== '' && $au >= $du) {
        $kpi = mktKpiPeriode(['du' => $du, 'au' => $au,
            'levier' => (string) ($c['lever_code'] ?? ''),
            'magasins' => implode(',', $perim)]);
        if (!empty($kpi['error'])) { $kpi = null; }
    }

    $pct = $c['objective_coef_pct'] !== null ? (float) $c['objective_coef_pct'] : null;

    // Ce qui est attendu, magasin par magasin : la référence de l'an dernier et
    // la cible qui en découle. Un magasin sans relevé le dit — sa cible se pose
    // à la main, et la note ne doit pas prétendre le contraire.
    $lignes = [];
    foreach (($kpi['magasins'] ?? []) as $m) {
        $ref = $m['valeurPendant'];
        $lignes[] = [
            'id' => (string) $m['id'], 'nom' => (string) $m['nom'],
            'reference' => $ref,
            'cible' => ($ref === null || $pct === null) ? $ref : $ref * (1 + $pct / 100),
            'sansN1' => (bool) $m['sansN1'],
        ];
    }

    $etapes = [];
    try {
        // `mar_retroplanning_step` : le nom que porte la table du module. Un nom
        // deviné rendait la note muette sur le calendrier, sans erreur — et
        // « ce qui va se passer ce mois-ci » est précisément ce qu'elle vient
        // dire au franchisé.
        foreach (Db::rows('SELECT label, days_before_launch, done_at FROM mar_retroplanning_step
                            WHERE campaign_id = ? ORDER BY sort_order, days_before_launch DESC', [$id]) as $t) {
            $jours = (int) ($t['days_before_launch'] ?? 0);
            $etapes[] = ['libelle' => (string) $t['label'],
                'quand' => $du === '' ? '' : mktBriefJour(mesDecale($du, -$jours)),
                'fait' => $t['done_at'] !== null];
        }
    } catch (PDOException $e) { /* pas de rétroplanning dans cette base */ }

    return [
        'id' => $id,
        'nom' => (string) $c['name'],
        'type' => (string) ($c['type_label'] ?? 'Sans type'),
        'typeCouleur' => (string) ($c['type_color'] ?? ''),
        'iconePath' => (string) ($c['icon_path'] ?? ''),
        'levier' => (string) ($c['lever_badge_label'] ?: $c['lever_label'] ?: ''),
        'levierCode' => (string) ($c['lever_code'] ?? ''),
        'statut' => (string) ($c['statut_label'] ?? ''),
        'du' => $du, 'au' => $au,
        'jours' => ($du !== '' && $au !== '') ? mesJours($du, $au) : 0,
        'portee' => $reseau ? 'Réseau' : 'Magasins désignés',
        'magasins' => array_values(array_map(fn ($s) => $nomDe[$s], $perim)),
        'magasinIds' => $perim,
        'budget' => $c['budget_amount'] !== null ? (float) $c['budget_amount'] : null,
        'engage' => $c['spent_amount'] !== null ? (float) $c['spent_amount'] : null,
        'objectifPct' => $pct,
        'kpiNom' => $kpi['kpi']['nom'] ?? (string) ($c['default_kpi_label'] ?? ''),
        'kpiUnite' => $kpi['kpi']['unite'] ?? '',
        'kpiDecimales' => (int) ($kpi['kpi']['decimales'] ?? 1),
        'kpiMesure' => $kpi !== null,
        'kpiMotifs' => $kpi['motifs'] ?? [],
        'fenetres' => $kpi['fenetres'] ?? null,
        'reference' => $kpi['reseau']['valeurPendant'] ?? null,
        'cible' => ($kpi['reseau']['valeurPendant'] ?? null) !== null && $pct !== null
            ? $kpi['reseau']['valeurPendant'] * (1 + $pct / 100) : null,
        'lignes' => $lignes,
        'etapes' => $etapes,
        'image' => (string) ($c['image_url'] ?? ''),
    ];
}

/**
 * Le magasin, nom et franchisé — lu là où il se trouve.
 *
 * Le cockpit lit les boutiques tantôt dans `ceo_shop`, tantôt dans `shops`
 * selon la base : supposer l'une des deux rendait la note muette sur le nom du
 * franchisé, et « Bonjour , » partait au destinataire.
 *
 * @return array{name:string,franchisee:string}|null
 */
function mktBriefMagasin(string $id): ?array
{
    foreach (['SELECT name, franchisee FROM ceo_shop WHERE id = ?',
              'SELECT name, NULL AS franchisee FROM shops WHERE id = ?'] as $sql) {
        try {
            $r = Db::row($sql, [$id]);
            if ($r !== null) { return ['name' => (string) $r['name'], 'franchisee' => (string) ($r['franchisee'] ?? '')]; }
        } catch (PDOException $e) { /* table absente ici : on tente la suivante */ }
    }
    return null;
}

/** Un nombre dans l'unité du KPI, ou « — ». */
function mktBriefValeur(?float $v, array $d): string
{
    if ($v === null) { return '—'; }
    $n = number_format($v, $d['kpiDecimales'], ',', ' ');
    return str_contains((string) $d['kpiUnite'], '€') ? $n . ' €' : $n;
}

/**
 * La page A4 elle-même.
 *
 * Écrite en HTML avec des styles en ligne : c'est ce que le moteur PDF du
 * serveur sait rendre, et c'est aussi ce que le navigateur imprime à
 * l'identique quand aucun moteur n'est installé.
 */
function mktBriefPdfHtml(array $d, string $magasin = ''): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logo = rapLogoDataUri();
    $accent = '#8D1D2C';

    $entete = '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid ' . $accent . ';padding-bottom:10px;margin-bottom:18px"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" alt="L’Atelier by" style="height:34px">' : '<strong style="font-size:15px">L’Atelier by</strong>')
        . '</td><td align="right" style="font-size:10px;color:#7a736a;line-height:1.5">Note de campagne'
        . ($magasin !== '' ? '<br>' . $e($magasin) : '') . '</td></tr></table>';

    $cartes = [
        ['Période', mktBriefJour($d['du']) . ' → ' . mktBriefJour($d['au']), $d['jours'] . ' jours'],
        ['Budget engagé par la centrale', mktBriefEuros($d['budget']), 'à la charge du fonds marketing'],
        ['Portée', $d['portee'], count($d['magasins']) . ' magasin' . (count($d['magasins']) > 1 ? 's' : '')],
    ];
    $blocCartes = '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:18px"><tr>';
    foreach ($cartes as [$t, $v, $s]) {
        $blocCartes .= '<td width="33%" valign="top" style="padding:11px 12px;border:1px solid #e6e0d8;border-radius:8px">'
            . '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a">' . $e($t) . '</div>'
            . '<div style="font-size:15px;font-weight:600;margin-top:3px">' . $e($v) . '</div>'
            . '<div style="font-size:9.5px;color:#7a736a;margin-top:2px">' . $e($s) . '</div></td><td width="8"></td>';
    }
    $blocCartes .= '</tr></table>';

    // L'objectif. Sans KPI mesuré, on écrit pourquoi plutôt qu'un chiffre rond
    // qui aurait l'air d'en être un.
    $obj = '<div style="border:1px solid #e6e0d8;border-radius:8px;padding:13px 14px;margin-bottom:16px;background:#fbf9f5">'
        . '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a">Ce qu’on vise</div>';
    if ($d['objectifPct'] === null) {
        $obj .= '<div style="font-size:12px;margin-top:5px">Aucun écart chiffré n’est fixé pour cette campagne : le suivi se fera sur les valeurs réelles, sans cible.</div>';
    } elseif (!$d['kpiMesure'] || $d['reference'] === null) {
        $obj .= '<div style="font-size:14px;font-weight:600;margin-top:4px">'
            . ($d['objectifPct'] >= 0 ? '+' : '−') . abs((float) $d['objectifPct']) . ' % par rapport à l’an dernier</div>'
            . '<div style="font-size:10.5px;color:#7a736a;margin-top:3px">'
            . ($d['kpiNom'] !== '' ? $e($d['kpiNom']) . ' — ' : '')
            . 'la référence de l’an dernier n’a pas pu être lue en caisse pour cette période : la cible en valeur reste à poser.</div>';
    } else {
        $obj .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px"><tr>'
            . '<td style="font-size:12px;line-height:1.55">' . $e($d['kpiNom']) . '<br>'
            . '<span style="color:#7a736a;font-size:10.5px">l’an dernier, même période : <strong>' . mktBriefValeur($d['reference'], $d) . '</strong></span></td>'
            . '<td align="right" style="font-size:22px;font-weight:600;color:' . $accent . ';white-space:nowrap">'
            . mktBriefValeur($d['cible'], $d)
            . '<div style="font-size:9.5px;font-weight:400;color:#7a736a">cible, soit '
            . ($d['objectifPct'] >= 0 ? '+' : '−') . abs((float) $d['objectifPct']) . ' %</div></td></tr></table>';
    }
    $obj .= '</div>';

    // Le tableau par magasin : ce que chacun doit faire, pas une moyenne.
    $tab = '';
    if ($d['lignes'] !== []) {
        $tab = '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;margin-bottom:5px">Magasin par magasin</div>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:11px;margin-bottom:16px">'
            . '<tr><th align="left" style="padding:6px 8px;border-bottom:1px solid #e6e0d8;font-size:8.5px;letter-spacing:.06em;text-transform:uppercase;color:#7a736a">Magasin</th>'
            . '<th align="right" style="padding:6px 8px;border-bottom:1px solid #e6e0d8;font-size:8.5px;letter-spacing:.06em;text-transform:uppercase;color:#7a736a">L’an dernier</th>'
            . '<th align="right" style="padding:6px 8px;border-bottom:1px solid #e6e0d8;font-size:8.5px;letter-spacing:.06em;text-transform:uppercase;color:#7a736a">Cible</th></tr>';
        foreach ($d['lignes'] as $l) {
            $tab .= '<tr><td style="padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06)">' . $e($l['nom']) . '</td>';
            $tab .= $l['sansN1']
                ? '<td colspan="2" align="right" style="padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06);color:#b8b2a8">pas de relevé l’an dernier — cible à poser ensemble</td></tr>'
                : '<td align="right" style="padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06)">' . mktBriefValeur($l['reference'], $d) . '</td>'
                  . '<td align="right" style="padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06);font-weight:600">' . mktBriefValeur($l['cible'], $d) . '</td></tr>';
        }
        $tab .= '</table>';
    }

    // Le rétroplanning : « ce qui va se passer », dans l'ordre.
    $plan = '';
    if ($d['etapes'] !== []) {
        $plan = '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;margin-bottom:5px">Le calendrier</div><table width="100%" cellpadding="0" cellspacing="0" style="font-size:11px;margin-bottom:14px">';
        foreach ($d['etapes'] as $t) {
            $plan .= '<tr><td width="70" style="padding:4px 0;color:#7a736a">' . $e($t['quand']) . '</td>'
                . '<td style="padding:4px 0">' . $e($t['libelle'])
                . ($t['fait'] ? ' <span style="color:#3f7a52">✓ fait</span>' : '') . '</td></tr>';
        }
        $plan .= '</table>';
    }

    $titre = '<div style="font-size:9px;letter-spacing:.09em;text-transform:uppercase;color:' . $accent . '">'
        . $e($d['type']) . ($d['levier'] !== '' ? ' · levier ' . $e($d['levier']) : '') . '</div>'
        . '<h1 style="font-size:21px;margin:3px 0 14px;font-weight:600">' . $e($d['nom']) . '</h1>';

    $pied = '<div style="margin-top:20px;padding-top:9px;border-top:1px solid #e6e0d8;font-size:9px;color:#7a736a;line-height:1.6">'
        . 'Note éditée le ' . date('d/m/Y') . ' par la centrale L’Atelier by.'
        . ($d['kpiMesure'] ? ' Les valeurs de l’an dernier sont lues sur la caisse, sur les mêmes dates décalées de 364 jours.' : '')
        . '</div>';

    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>'
        . $e($d['nom']) . '</title></head>'
        . '<body style="margin:0;padding:26px 30px;font-family:Helvetica,Arial,sans-serif;color:#1c1a17;background:#fff">'
        . $entete . $titre . $blocCartes . $obj . $tab . $plan . $pied . '</body></html>';
}

/** Le corps du courrier — le gabarit d'achats, réutilisé tel quel. */
function mktBriefMailHtml(array $d, array $c, string $magasin, string $franchise): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $vars = [
        'campagne' => $d['nom'], 'type' => $d['type'], 'levier' => $d['levier'],
        'du' => mktBriefJour($d['du']), 'au' => mktBriefJour($d['au']),
        'jours' => (string) $d['jours'],
        'budget' => mktBriefEuros($d['budget']),
        'magasin' => $magasin !== '' ? $magasin : 'votre magasin',
        'franchise' => $franchise !== '' ? $franchise : 'Bonjour',
        'objectif' => $d['objectifPct'] === null ? 'sans écart chiffré'
            : (($d['objectifPct'] >= 0 ? '+' : '−') . abs((float) $d['objectifPct']) . ' %'),
        'kpi' => $d['kpiNom'],
        'cible' => mktBriefValeur($d['cible'], $d),
    ];

    $intro = nl2br($e(caMailRemplir((string) ($c['intro'] ?? ''), array_map($e, $vars))));
    $pied = $e(caMailRemplir((string) ($c['pied'] ?? ''), array_map($e, $vars)));

    $cartes = '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0">';
    foreach ([['Période', mktBriefJour($d['du']) . ' → ' . mktBriefJour($d['au'])],
              ['Budget centrale', mktBriefEuros($d['budget'])],
              ['Objectif', $vars['objectif'] . ($d['cible'] !== null ? ' — ' . $vars['cible'] . ' ' . $d['kpiUnite'] : '')]] as [$t, $v]) {
        $cartes .= '<tr><td style="padding:6px 0;border-bottom:1px solid #e6e0d8;font-size:12px;color:#7a736a">' . $e($t)
            . '</td><td align="right" style="padding:6px 0;border-bottom:1px solid #e6e0d8;font-size:13px;font-weight:600">' . $e($v) . '</td></tr>';
    }
    $cartes .= '</table>';

    $contenu = $intro . $cartes
        . '<p style="margin:14px 0 0;font-size:12.5px;line-height:1.6">La note complète est en pièce jointe, en PDF : une page, à imprimer et à afficher en réserve.</p>'
        . '<p style="margin:14px 0 0;font-size:11.5px;color:#7a736a;line-height:1.6">' . $pied . '</p>';

    $squelette = trim((string) ($c['html'] ?? ''));
    if ($squelette === '') { $squelette = caMailSquelette(); }

    return caMailRemplir($squelette, [
        // Le logo voyage en data-URI : un lien vers le cockpit ne s'affiche
        // pas chez un destinataire qui n'y a pas accès.
        'logo' => rapLogoDataUri() === '' ? '' : '<img src="' . rapLogoDataUri() . '" alt="L’Atelier by" style="height:34px;display:block">', 'marque' => 'L’Atelier by', 'contenu' => $contenu,
        'entete' => '<div style="font-size:12px;color:#7a736a">Campagne ' . $e($d['type']) . '</div>',
        'cartes' => '',
        'fournisseur' => $e($magasin),
        'nCommandes' => '0',
    ]);
}

/** Le PDF, ou null si aucun moteur ne rend sur ce serveur. */
function mktBriefPdf(array $d, string $magasin = ''): ?string
{
    return rapPdfRendu(mktBriefPdfHtml($d, $magasin), [
        'magasin' => $magasin !== '' ? $magasin : $d['portee'],
        'rapport' => 'Note de campagne — ' . $d['nom'],
        'genere' => date('d/m/Y à H:i'),
        'envoye' => '',
    ]);
}

/** Nom de fichier lisible : `note-reseaux-sociaux-2026-09.pdf`. */
function mktBriefNomFichier(array $d): string
{
    $base = mktSlug($d['nom']);
    return 'note-' . ($base !== '' ? $base : 'campagne-' . $d['id'])
        . ($d['du'] !== '' ? '-' . substr($d['du'], 0, 7) : '') . '.pdf';
}

/**
 * GET /marketing/campagne/{id}/note — l'aperçu, les destinataires, le journal.
 */
function ep_mkt_brief(int $id): array
{
    $d = mktBriefDonnees($id);
    if ($d === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    $c = mktBriefConfig();

    // Les destinataires : un par magasin du périmètre, avec l'adresse du
    // carnet quand elle existe. Les manquantes sont NOMMÉES — c'est ce qui
    // permet de les compléter, plutôt que de découvrir un envoi partiel.
    $dest = [];
    foreach ($d['magasinIds'] as $i => $sid) {
        $shop = mktBriefMagasin((string) $sid) ?? ['name' => $d['magasins'][$i] ?? $sid, 'franchisee' => ''];
        $dest[] = ['id' => (string) $sid, 'magasin' => (string) $shop['name'],
            'franchise' => (string) ($shop['franchisee'] ?? ''),
            'adresse' => mktBriefAdresse($c['carnet'][(string) $sid] ?? '')];
    }

    $journal = array_values(array_filter(
        is_array(setting('mktBriefJournal')) ? setting('mktBriefJournal') : [],
        static fn ($e) => (int) ($e['campagne'] ?? 0) === $id));

    return ['campagne' => $d, 'destinataires' => $dest,
        'config' => ['expediteur' => $c['expediteur'], 'repondreA' => $c['repondreA'],
            'sujet' => $c['sujet'], 'intro' => $c['intro'], 'pied' => $c['pied'],
            'html' => (string) ($c['html'] ?? ''), 'copie' => (string) ($c['copie'] ?? '')],
        'apercuPdf' => mktBriefPdfHtml($d),
        'apercuMail' => mktBriefMailHtml($d, $c, $dest[0]['magasin'] ?? '', $dest[0]['franchise'] ?? ''),
        'fichier' => mktBriefNomFichier($d),
        'journal' => array_slice($journal, 0, 30),
        'moteurPdf' => mktBriefMoteur()];
}

/** Y a-t-il un moteur PDF sur ce serveur ? L'écran doit pouvoir le dire. */
function mktBriefMoteur(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = false;
        if (function_exists('shell_exec')) {
            foreach (['/usr/local/bin/wkhtmltopdf', 'wkhtmltopdf', 'chromium', 'chromium-browser', 'google-chrome'] as $bin) {
                $r = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
                if (trim((string) $r) !== '') { $ok = true; break; }
            }
        }
    }
    return $ok;
}

/** GET /marketing/campagne/{id}/note.pdf — le fichier, tel qu'il partira. */
function ep_mkt_brief_pdf(int $id): array
{
    $d = mktBriefDonnees($id);
    if ($d === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    $magasin = trim((string) ($_GET['magasin'] ?? ''));
    $pdf = mktBriefPdf($d, $magasin);
    if ($pdf === null) {
        http_response_code(501);
        return ['error' => 'aucun moteur PDF sur ce serveur — utilisez « Imprimer » dans l’aperçu, le navigateur produit le même fichier'];
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . mktBriefNomFichier($d) . '"');
    echo $pdf;
    exit;
}

/** Trace d'envoi — même forme que le journal des achats, écran compris. */
function mktBriefJournalAdd(int $campagne, string $type, string $detail, string $destinataire, array $plus = []): void
{
    $j = setting('mktBriefJournal');
    if (!is_array($j)) { $j = []; }
    array_unshift($j, array_merge(['quand' => date('Y-m-d H:i'), 'campagne' => $campagne,
        'type' => $type, 'detail' => mb_substr($detail, 0, 200), 'destinataire' => $destinataire], $plus));
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['mktBriefJournal', json_encode(array_slice($j, 0, 200), JSON_UNESCAPED_UNICODE)]);
}

/**
 * POST /marketing/campagne/{id}/note — envoi de la note aux franchisés.
 *
 * Corps : `{ destinataires: [{ id, adresse }], essai: bool }`. Les adresses
 * reçues sont MÉMORISÉES dans le carnet : la deuxième campagne ne redemande
 * pas ce qu'on a déjà saisi pour la première.
 */
function wr_mkt_brief_envoyer(int $id): array
{
    $d = mktBriefDonnees($id);
    if ($d === null) { http_response_code(404); return ['error' => 'campagne inconnue']; }
    $b = body();
    $c = mktBriefConfig();

    $carnet = $c['carnet'];
    $cibles = [];
    foreach ((array) ($b['destinataires'] ?? []) as $x) {
        $adresse = mktBriefAdresse($x['adresse'] ?? '');
        $sid = (string) ($x['id'] ?? '');
        if ($adresse === '') { continue; }
        if ($sid !== '') { $carnet[$sid] = $adresse; }
        $shop = $sid === '' ? null : mktBriefMagasin($sid);
        $cibles[] = ['adresse' => $adresse, 'id' => $sid,
            'magasin' => (string) ($x['magasin'] ?? ($shop['name'] ?? '')),
            'franchise' => (string) ($x['franchise'] ?? ($shop['franchisee'] ?? ''))];
    }
    if ($cibles === []) {
        http_response_code(422);
        return ['error' => 'aucune adresse valable : renseignez au moins un destinataire'];
    }

    // Le carnet se garde AVANT l'envoi : un SMTP qui refuse ne doit pas faire
    // perdre les adresses qu'on vient de saisir.
    $c['carnet'] = $carnet;
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['mktBrief', json_encode($c, JSON_UNESCAPED_UNICODE)]);

    $pdf = mktBriefPdf($d);
    $nomFichier = mktBriefNomFichier($d);
    $envoyes = 0; $echecs = [];

    foreach ($cibles as $t) {
        $vars = ['campagne' => $d['nom'], 'du' => mktBriefJour($d['du']), 'au' => mktBriefJour($d['au']),
            'magasin' => $t['magasin'], 'franchise' => $t['franchise'], 'type' => $d['type']];
        $sujet = caMailRemplir((string) $c['sujet'], $vars);
        $html = mktBriefMailHtml($d, $c, $t['magasin'], $t['franchise']);
        // Le PDF est joint QUAND il existe : sans moteur sur le serveur, la
        // lettre part quand même et le dit, plutôt que de ne pas partir.
        $pieces = $pdf === null ? []
            : [['nom' => $nomFichier, 'type' => 'application/pdf', 'contenu' => $pdf]];
        if ($pdf === null) {
            $html = str_replace('La note complète est en pièce jointe, en PDF : une page, à imprimer et à afficher en réserve.',
                'La note détaillée suit par un autre envoi : le serveur n’a pas pu produire le PDF cette fois.', $html);
        }

        $ok = Smtp::envoyer($t['adresse'], $sujet, $html, $pieces, (string) $c['expediteur']);
        if ($ok === true) {
            $envoyes++;
            mktBriefJournalAdd($id, 'envoye', $d['nom'] . ' — ' . $t['magasin']
                . ($pdf === null ? ' (sans PDF : aucun moteur sur le serveur)' : ''), $t['adresse'],
                ['sujet' => $sujet, 'magasin' => $t['magasin']]);
        } else {
            $echecs[] = $t['adresse'];
            mktBriefJournalAdd($id, 'echec', is_string($ok) ? $ok : 'refus du serveur d’envoi', $t['adresse'],
                ['sujet' => $sujet, 'magasin' => $t['magasin']]);
        }
    }

    journalAdd('CEO', 'Campagne', $d['nom'],
        'Note de campagne envoyée à ' . $envoyes . ' magasin(s)'
        . ($echecs === [] ? '' : ' — échec pour ' . implode(', ', $echecs)));

    return ['ok' => $echecs === [], 'envoyes' => $envoyes, 'echecs' => $echecs,
        'avecPdf' => $pdf !== null,
        'message' => $envoyes . ' note(s) envoyée(s)'
            . ($pdf === null ? ', sans PDF : aucun moteur sur ce serveur' : '')
            . ($echecs === [] ? '' : ' — refusé pour : ' . implode(', ', $echecs))];
}

/** PUT /marketing/note-config — l'expéditeur, le sujet, le gabarit, le carnet. */
function wr_mkt_brief_config(): array
{
    $b = body();
    $c = mktBriefConfig();
    foreach (['expediteur', 'repondreA', 'sujet', 'intro', 'pied', 'html', 'copie'] as $k) {
        if (array_key_exists($k, $b)) { $c[$k] = (string) $b[$k]; }
    }
    if (isset($b['carnet']) && is_array($b['carnet'])) {
        $carnet = [];
        foreach ($b['carnet'] as $sid => $adresse) {
            $a = mktBriefAdresse($adresse);
            if ($a !== '') { $carnet[(string) $sid] = $a; }
        }
        $c['carnet'] = $carnet;
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['mktBrief', json_encode($c, JSON_UNESCAPED_UNICODE)]);
    journalAdd('CEO', 'Paramètre', 'Note de campagne', 'Gabarit du courrier modifié');
    return ['ok' => true];
}
