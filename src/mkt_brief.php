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
        // L'agence qui signe la création. Son logo voyage en data-URI, comme
        // celui de la maison : un lien vers le cockpit ne s'affiche pas chez un
        // destinataire externe, et ne s'imprime pas du tout.
        'agence'     => ['nom' => '', 'site' => '', 'logo' => ''],
    ];
}

function mktBriefConfig(): array
{
    $c = setting('mktBrief');
    $c = is_array($c) ? $c : [];
    $out = array_merge(mktBriefDefauts(), $c);
    $out['carnet'] = is_array($out['carnet'] ?? null) ? $out['carnet'] : [];
    $ag = is_array($out['agence'] ?? null) ? $out['agence'] : [];
    $out['agence'] = ['nom' => trim((string) ($ag['nom'] ?? '')),
        'site' => trim((string) ($ag['site'] ?? '')),
        'logo' => (string) ($ag['logo'] ?? '')];
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
        // La valeur RETENUE : l'an dernier quand il existe, sinon la moyenne
        // des trois derniers mois. Un magasin ouvert cette année a une
        // activité, elle ; lui écrire « à poser ensemble » lui donnait la
        // seule ligne vide de la note.
        $ref = $m['valeurRetenue'] ?? $m['valeurPendant'];
        // La cible s'arrondit dans l'unité du KPI, comme la référence : la
        // colonne « cible » doit additionner à son propre total.
        $cible = ($ref === null || $pct === null) ? $ref
            : round((float) $ref * (1 + $pct / 100), (int) ($kpi['kpi']['decimales'] ?? 0));
        $lignes[] = [
            'id' => (string) $m['id'], 'nom' => (string) $m['nom'],
            'reference' => $ref,
            'cible' => $cible,
            'sansN1' => (bool) $m['sansN1'],
            'source' => (string) ($m['source'] ?? ''),
            'repli' => $m['repli'] ?? null,
        ];
    }

    // Où va le budget, levier par levier — et ce qu'on ira relire pour chacun.
    // Le montant seul ne dit pas ce qu'on attend en retour ; le KPI seul ne dit
    // pas ce qu'on y met. La note doit porter les deux, sinon le franchisé lit
    // « 4 150 € » sans savoir sur quoi il sera jugé.
    $kpiParLevier = [];
    foreach (mktKpiParLevier() as $l) { $kpiParLevier[(int) $l['id']] = $l; }
    $investis = [];
    try {
        foreach (Db::rows('SELECT t.lever_id, t.target_value, t.target_unit, l.label, l.code, l.color_hex
                             FROM mar_campaign_lever_target t
                             LEFT JOIN mar_lever l ON l.id = t.lever_id
                            WHERE t.campaign_id = ?
                            ORDER BY t.target_value DESC', [$id]) as $t) {
            $lid = (int) $t['lever_id'];
            $k = $kpiParLevier[$lid] ?? null;
            $investis[] = [
                'nom' => (string) ($t['label'] ?? 'Levier ' . $lid),
                'couleur' => (string) ($t['color_hex'] ?? ''),
                'montant' => $t['target_value'] !== null ? (float) $t['target_value'] : null,
                'unite' => (string) ($t['target_unit'] ?? 'EUR'),
                'kpi' => $k !== null && $k['kpi'] !== null ? (string) $k['kpi']['nom'] : null,
                'calcul' => $k !== null && $k['kpi'] !== null ? (string) $k['kpi']['calcul'] : null,
                'raison' => $k !== null ? ($k['raison'] ?? null) : null,
                'suivi' => $k !== null && (string) $k['code'] === strtoupper((string) ($c['lever_code'] ?? '')),
            ];
        }
    } catch (PDOException $e) { /* pas de ventilation par levier dans cette base */ }

    // La communication : ce que la campagne achète, canal par canal. Les
    // budgets viennent de l'étape « Communication » de l'assistant — c'est là
    // qu'ils se saisissent, et les recopier ailleurs aurait fait deux vérités.
    // Un canal RETENU mais pas validé compte à part : il n'est pas engagé.
    $canaux = []; $engage = 0.0; $envisage = 0.0;
    try {
        foreach (Db::rows('SELECT ch.label, ch.family, cc.budget_amount, cc.is_enabled,
                                  a.name AS agence
                             FROM mar_campaign_channel cc
                             JOIN mar_channel ch ON ch.id = cc.channel_id
                             LEFT JOIN mar_agency a ON a.id = cc.agency_id
                            WHERE cc.campaign_id = ?
                            ORDER BY ch.family, ch.sort_order', [$id]) as $r) {
            $montant = $r['budget_amount'] !== null ? (float) $r['budget_amount'] : 0.0;
            $valide = (bool) $r['is_enabled'];
            if ($valide) { $engage += $montant; } else { $envisage += $montant; }
            $canaux[] = ['nom' => (string) $r['label'],
                'famille' => (string) $r['family'] === 'DIGITAL' ? 'Digital' : 'Physique',
                'montant' => $montant, 'valide' => $valide,
                'agence' => (string) ($r['agence'] ?? '')];
        }
    } catch (PDOException $e) { /* pas de canaux dans cette base */ }

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
        // La référence réseau est la valeur RETENUE : celle qui égale la somme
        // des lignes du tableau. Prendre le seul cumul N-1 donnait un total
        // plus petit que ses propres lignes dès qu'un magasin était sur repli.
        'reference' => $kpi['reseau']['valeurRetenue'] ?? ($kpi['reseau']['valeurPendant'] ?? null),
        // Le total des cibles est la SOMME DES CIBLES AFFICHÉES pour un KPI qui
        // s'additionne ; pour un panier — un rapport — il se recalcule.
        'cible' => mktBriefCibleReseau($kpi, $lignes, $pct),
        'referenceSource' => (string) ($kpi['reseau']['source'] ?? 'n1'),
        'referenceNRepli' => (int) ($kpi['reseau']['nRepli'] ?? 0),
        'lignes' => $lignes,
        'etapes' => $etapes,
        'investis' => $investis,
        'canaux' => $canaux,
        // Les documents joints à la note : plan de publication, liste des
        // produits… Ceux qui portent la case partent avec le courriel.
        'annexes' => function_exists('annexeListe') ? annexeListe($id) : [],
        'canauxEngage' => $engage,
        'canauxEnvisage' => $envisage,
        'mot' => mktBriefMot($id),
        'image' => (string) ($c['image_url'] ?? ''),
        'visuel' => mktBriefVisuel((string) ($c['image_url'] ?? '')),
    ];
}

/**
 * Le visuel de la campagne, prêt à imprimer.
 *
 * Embarqué EN DATA-URI et non en lien : le moteur PDF tourne sans réseau, et
 * un destinataire externe n'a pas accès au cockpit — il verrait un cadre vide.
 * Au-delà d'un mégaoctet on réduit, et si le serveur n'a pas GD on renonce
 * plutôt que d'envoyer une lettre de quinze mégaoctets.
 *
 * @return array{uri:string,motif:string}
 */
function mktBriefVisuel(string $url): array
{
    $url = trim($url);
    if ($url === '') { return ['uri' => '', 'motif' => '']; }

    // « ./uploads/xxx.jpg » vit sous la page de l'assistant.
    $rel = ltrim(preg_replace('#^\./#', '', $url) ?? $url, '/');
    $chemin = __DIR__ . '/../public/assistant/' . $rel;
    if (!is_file($chemin)) { $chemin = __DIR__ . '/../public/' . $rel; }
    if (!is_file($chemin)) {
        return ['uri' => '', 'motif' => 'le visuel de la campagne est introuvable sur le serveur'];
    }

    $octets = (string) file_get_contents($chemin);
    $info = @getimagesizefromstring($octets);
    $type = is_array($info) ? (string) $info['mime'] : 'image/jpeg';

    if (strlen($octets) > 1000000) {
        if (!function_exists('imagecreatefromstring')) {
            return ['uri' => '', 'motif' => 'le visuel dépasse 1 Mo et le serveur ne sait pas le réduire'];
        }
        $src = @imagecreatefromstring($octets);
        if ($src === false) { return ['uri' => '', 'motif' => 'le visuel n’a pas pu être lu']; }
        $l = imagesx($src); $h = imagesy($src);
        $k = min(1.0, 1600 / max($l, $h));
        $dst = imagecreatetruecolor((int) round($l * $k), (int) round($h * $k));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $l, $h);
        ob_start(); imagejpeg($dst, null, 78); $octets = (string) ob_get_clean();
        imagedestroy($src); imagedestroy($dst);
        $type = 'image/jpeg';
    }

    return ['uri' => 'data:' . $type . ';base64,' . base64_encode($octets), 'motif' => ''];
}

/**
 * Le mot du responsable pour une campagne — texte, nom et fonction.
 *
 * Une note chiffrée dit ce qu'on attend ; elle ne dit pas pourquoi, ni qui le
 * demande. Le mot est la seule partie de la page écrite par quelqu'un, et il
 * est signé : le franchisé sait à qui répondre.
 *
 * La signature est préremplie avec le consultant connecté — son nom et sa
 * FONCTION, pas un titre inventé. Elle reste modifiable : le mot peut être
 * celui du franchiseur alors que la note est préparée par le consultant.
 *
 * @return array{texte:string,nom:string,fonction:string}
 */
function mktBriefMot(int $id): array
{
    $tous = setting('mktBriefMots');
    $m = (is_array($tous) && isset($tous[(string) $id]) && is_array($tous[(string) $id]))
        ? $tous[(string) $id] : [];
    $u = setting('utilisateur', []);
    $u = is_array($u) ? $u : [];

    return [
        'texte' => trim((string) ($m['texte'] ?? '')),
        'nom' => trim((string) ($m['nom'] ?? ($u['nom'] ?? ''))),
        'fonction' => trim((string) ($m['fonction'] ?? ($u['role'] ?? ''))),
    ];
}

/** PUT /marketing/campagne/{id}/note-mot. */
function wr_mkt_brief_mot(int $id): array
{
    if (Db::row('SELECT 1 FROM mar_campaign WHERE id = ?', [$id]) === null) {
        http_response_code(404); return ['error' => 'campagne inconnue'];
    }
    $b = body();
    $tous = setting('mktBriefMots');
    if (!is_array($tous)) { $tous = []; }
    $avant = mktBriefMot($id);
    $tous[(string) $id] = [
        'texte' => mb_substr(trim((string) ($b['texte'] ?? $avant['texte'])), 0, 1200),
        'nom' => mb_substr(trim((string) ($b['nom'] ?? $avant['nom'])), 0, 120),
        'fonction' => mb_substr(trim((string) ($b['fonction'] ?? $avant['fonction'])), 0, 120),
    ];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['mktBriefMots', json_encode($tous, JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'mot' => $tous[(string) $id]];
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

/**
 * La cible du réseau. Somme des cibles des magasins quand le KPI s'additionne
 * (clients/jour, CA/jour) ; sinon le rapport recalculé — additionner quatre
 * paniers moyens donnerait un panier de quarante-huit euros.
 */
function mktBriefCibleReseau(?array $kpi, array $lignes, ?float $pct): ?float
{
    if ($pct === null || $kpi === null) { return null; }
    $dec = (int) ($kpi['kpi']['decimales'] ?? 0);
    $base = $kpi['reseau']['valeurRetenue'] ?? ($kpi['reseau']['valeurPendant'] ?? null);
    if (($kpi['mesure'] ?? '') === 'panier') {
        return $base === null ? null : round((float) $base * (1 + $pct / 100), $dec);
    }
    $somme = null;
    foreach ($lignes as $l) {
        if ($l['cible'] === null) { continue; }
        $somme = ($somme ?? 0) + (float) $l['cible'];
    }
    return $somme === null ? null : round($somme, $dec);
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
function mktBriefPdfHtml(array $d, string $magasin = '', array $c = []): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $logo = rapLogoDataUri();
    $accent = '#8D1D2C';
    if ($c === []) { $c = mktBriefConfig(); }
    $agence = $c['agence'] ?? ['nom' => '', 'site' => '', 'logo' => ''];
    $visuel = (string) (($d['visuel']['uri'] ?? '') ?: '');

    $entete = '<table width="100%" cellpadding="0" cellspacing="0" style="border-bottom:2px solid ' . $accent . ';padding-bottom:10px;margin-bottom:18px"><tr>'
        . '<td>' . ($logo !== '' ? '<img src="' . $logo . '" alt="L’Atelier by" style="height:34px">' : '<strong style="font-size:15px">L’Atelier by</strong>')
        . '</td><td align="right" style="font-size:10px;color:#7a736a;line-height:1.5">Note de campagne'
        . ($magasin !== '' ? '<br>' . $e($magasin) : '') . '</td></tr></table>';

    // La tuile du budget dit ce qui est DÉJÀ arrêté : l'enveloppe seule ne
    // distingue pas ce qui est engagé de ce qui reste à décider.
    $valides = array_values(array_filter($d['canaux'], static fn ($c2) => $c2['valide']));
    $sousBudget = 'à la charge du fonds marketing';
    if ($valides !== []) {
        // Les plus gros d'abord : trois noms tiennent dans la tuile, autant que
        // ce soient ceux qui pèsent.
        usort($valides, static fn ($x, $y) => $y['montant'] <=> $x['montant']);
        $noms = array_slice(array_map(static fn ($c2) => $c2['nom'], $valides), 0, 3);
        $sousBudget = mktBriefEuros($d['canauxEngage']) . ' validés — ' . implode(', ', $noms)
            . (count($valides) > 3 ? ' +' . (count($valides) - 3) : '');
    }
    $cartes = [
        ['Période', mktBriefJour($d['du']) . ' → ' . mktBriefJour($d['au']), $d['jours'] . ' jours'],
        ['Budget engagé par la centrale', mktBriefEuros($d['budget']), $sousBudget],
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
            . '<span style="color:#7a736a;font-size:10.5px">'
            . ($d['referenceSource'] === 'n1'
                ? 'l’an dernier, même période : <strong>' . mktBriefValeur($d['reference'], $d) . '</strong>'
                : 'référence du réseau : <strong>' . mktBriefValeur($d['reference'], $d) . '</strong>'
                  . ' — dont ' . $d['referenceNRepli'] . ' magasin'
                  . ($d['referenceNRepli'] > 1 ? 's' : '') . ' sur moyenne 3 mois')
            . '</span></td>'
            . '<td align="right" style="font-size:22px;font-weight:600;color:' . $accent . ';white-space:nowrap">'
            . mktBriefValeur($d['cible'], $d)
            . '<div style="font-size:9.5px;font-weight:400;color:#7a736a">cible, soit '
            . ($d['objectifPct'] >= 0 ? '+' : '−') . abs((float) $d['objectifPct']) . ' %</div></td></tr></table>';
    }
    $obj .= '</div>';

    // Où va le budget : un levier, un montant, un KPI. C'est la réponse à
    // « on met 4 150 € sur quoi, et je serai jugé sur quoi ».
    $lev = '';
    if ($d['investis'] !== []) {
        $lev = '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;margin-bottom:5px">Où va le budget — et ce qu’on ira relire</div>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:11px;margin-bottom:16px">';
        foreach ($d['investis'] as $i) {
            $lev .= '<tr><td width="150" valign="top" style="padding:7px 8px 7px 0;border-bottom:1px solid rgba(34,34,34,.06)">'
                . ($i['couleur'] !== '' ? '<span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:' . $e($i['couleur']) . ';margin-right:6px"></span>' : '')
                . '<strong>' . $e($i['nom']) . '</strong>'
                . ($i['suivi'] ? '<div style="font-size:9px;color:' . $accent . ';margin-top:2px">levier suivi de la campagne</div>' : '')
                . '</td>'
                . '<td valign="top" style="padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06);color:#7a736a">'
                . ($i['kpi'] !== null
                    ? 'KPI : <strong style="color:#1c1a17">' . $e($i['kpi']) . '</strong>'
                      . ($i['calcul'] !== null ? ' <span style="font-size:9.5px">(' . $e($i['calcul']) . ')</span>' : '')
                    : 'Pas de KPI en caisse — ' . $e((string) ($i['raison'] ?? 'relevé au bilan')))
                . '</td>'
                . '<td width="86" align="right" valign="top" style="padding:7px 0 7px 8px;border-bottom:1px solid rgba(34,34,34,.06);font-weight:600;white-space:nowrap">'
                . ($i['montant'] === null ? '—'
                    : ($i['unite'] === 'EUR' ? mktBriefEuros($i['montant']) : number_format($i['montant'], 0, ',', ' ')))
                . '</td></tr>';
        }
        $lev .= '</table>';
    }

    // Le tableau par magasin : ce que chacun doit faire, pas une moyenne.
    $tab = '';
    if ($d['lignes'] !== []) {
        $tab = '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;margin-bottom:5px">Magasin par magasin</div>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:11px;margin-bottom:16px">'
            . '<tr><th align="left" style="padding:6px 8px;border-bottom:1px solid #e6e0d8;font-size:8.5px;letter-spacing:.06em;text-transform:uppercase;color:#7a736a">Magasin</th>'
            . '<th align="right" style="padding:6px 8px;border-bottom:1px solid #e6e0d8;font-size:8.5px;letter-spacing:.06em;text-transform:uppercase;color:#7a736a">L’an dernier</th>'
            . '<th align="right" style="padding:6px 8px;border-bottom:1px solid #e6e0d8;font-size:8.5px;letter-spacing:.06em;text-transform:uppercase;color:#7a736a">Cible</th></tr>';
        $repli = false;
        foreach ($d['lignes'] as $l) {
            $tab .= '<tr><td style="padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06)">' . $e($l['nom']) . '</td>';
            if ($l['reference'] === null) {
                $tab .= '<td colspan="2" align="right" style="padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06);color:#b8b2a8">aucun relevé exploitable — cible à poser ensemble</td></tr>';
                continue;
            }
            $marque = ($l['source'] ?? '') === 'repli'
                ? ' <sup style="color:' . $accent . ';font-weight:600">(i)</sup>' : '';
            if ($marque !== '') { $repli = true; }
            $tab .= '<td align="right" style="padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06)">'
                . mktBriefValeur($l['reference'], $d) . $marque . '</td>'
                . '<td align="right" style="padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06);font-weight:600">'
                . mktBriefValeur($l['cible'], $d) . '</td></tr>';
        }
        $tab .= '</table>';
        // La légende n'apparaît que si une ligne la porte : une note de bas de
        // tableau sans renvoi se lit comme un avertissement général.
        if ($repli) {
            $r0 = null;
            foreach ($d['lignes'] as $l) { if (($l['source'] ?? '') === 'repli') { $r0 = $l['repli']; break; } }
            $tab .= '<div style="font-size:9.5px;color:#7a736a;margin:-10px 0 16px">'
                . '<sup style="color:' . $accent . ';font-weight:600">(i)</sup> '
                . 'Pas de relevé sur ces dates l’an dernier — magasin ouvert depuis. La référence est la '
                . '<strong>moyenne des 3 derniers mois</strong>'
                . ($r0 !== null ? ' (' . mktBriefJour((string) $r0['du']) . ' → ' . mktBriefJour((string) $r0['au']) . ')' : '')
                . ' : un point de départ, pas une comparaison saisonnière.</div>';
        }
    }

    // Ce que la campagne achète. Les canaux VALIDÉS d'abord — ce sont eux qui
    // partent en production ; les autres suivent, marqués, parce qu'un
    // franchisé qui lit « affichage bus » doit savoir s'il aura lieu.
    $com = '';
    if ($d['canaux'] !== []) {
        $com = '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;margin-bottom:5px">Ce que la campagne achète</div>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:11px;margin-bottom:6px">';
        foreach ($d['canaux'] as $ca) {
            $com .= '<tr><td style="padding:6px 8px 6px 0;border-bottom:1px solid rgba(34,34,34,.06)">'
                . $e($ca['nom'])
                . '<span style="color:#b8b2a8;font-size:9.5px"> · ' . $e($ca['famille']) . '</span>'
                . ($ca['agence'] !== '' ? '<span style="color:#7a736a;font-size:9.5px"> · ' . $e($ca['agence']) . '</span>' : '')
                . '</td>'
                . '<td width="92" align="right" style="padding:6px 8px;border-bottom:1px solid rgba(34,34,34,.06);color:'
                . ($ca['valide'] ? '#3f7a52' : '#b8b2a8') . ';font-size:9.5px;white-space:nowrap">'
                . ($ca['valide'] ? '✓ validé' : 'à valider') . '</td>'
                . '<td width="80" align="right" style="padding:6px 0 6px 8px;border-bottom:1px solid rgba(34,34,34,.06);font-weight:600;white-space:nowrap'
                . ($ca['valide'] ? '' : ';color:#b8b2a8') . '">' . mktBriefEuros($ca['montant']) . '</td></tr>';
        }
        $com .= '<tr><td style="padding:7px 8px 7px 0;font-weight:600">Total validé</td>'
            . '<td></td><td align="right" style="padding:7px 0 7px 8px;font-weight:600;white-space:nowrap">'
            . mktBriefEuros($d['canauxEngage']) . '</td></tr>';
        if ($d['canauxEnvisage'] > 0) {
            $com .= '<tr><td style="padding:0 8px 7px 0;color:#7a736a">Encore à valider</td>'
                . '<td></td><td align="right" style="padding:0 0 7px 8px;color:#7a736a;white-space:nowrap">'
                . mktBriefEuros($d['canauxEnvisage']) . '</td></tr>';
        }
        $com .= '</table>';

        // L'écart avec l'enveloppe : ni alarme ni silence — la phrase dit ce
        // qui reste, ou ce qui dépasse, et laisse décider.
        if ($d['budget'] !== null) {
            $reste = (float) $d['budget'] - $d['canauxEngage'] - $d['canauxEnvisage'];
            $com .= '<div style="font-size:9.5px;color:#7a736a;margin:0 0 16px">'
                . 'Enveloppe de la campagne : ' . mktBriefEuros($d['budget']) . ' — '
                . ($reste >= 0
                    ? 'il reste ' . mktBriefEuros($reste) . ' non affectés.'
                    : 'les canaux dépassent l’enveloppe de ' . mktBriefEuros(abs($reste)) . '.')
                . '</div>';
        }
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

    // Le visuel accompagne le titre : une vignette, pas une affiche — la page
    // sert à lire des chiffres. L'affiche entière est en annexe.
    $bloc = '<div style="font-size:9px;letter-spacing:.09em;text-transform:uppercase;color:' . $accent . '">'
        . $e($d['type']) . ($d['levier'] !== '' ? ' · levier ' . $e($d['levier']) : '') . '</div>'
        . '<h1 style="font-size:21px;margin:3px 0 0;font-weight:600">' . $e($d['nom']) . '</h1>';
    $titre = $visuel === ''
        ? $bloc . '<div style="height:14px"></div>'
        : '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:14px"><tr>'
          . '<td valign="middle">' . $bloc . '</td>'
          . '<td width="112" align="right" valign="middle"><img src="' . $visuel . '" alt="" style="width:104px;height:104px;object-fit:cover;border-radius:8px;border:1px solid #e6e0d8"></td>'
          . '</tr></table>';

    // Le mot, s'il y en a un. Pas de bloc vide : une signature sans texte
    // ferait une page qui a l'air inachevée.
    $mot = '';
    if (($d['mot']['texte'] ?? '') !== '') {
        $mot = '<div style="border-left:3px solid ' . $accent . ';padding:2px 0 2px 12px;margin:2px 0 16px">'
            . '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;margin-bottom:4px">Le mot du responsable</div>'
            . '<div style="font-size:11.5px;line-height:1.65">' . nl2br($e($d['mot']['texte'])) . '</div>'
            . '<div style="font-size:10.5px;color:#7a736a;margin-top:7px">'
            . $e($d['mot']['nom'])
            . (($d['mot']['fonction'] ?? '') !== '' ? ' — ' . $e($d['mot']['fonction']) : '')
            . '</div></div>';
    }

    $signature = '';
    if (($agence['nom'] ?? '') !== '' || ($agence['logo'] ?? '') !== '') {
        $signature = '<table cellpadding="0" cellspacing="0" style="margin-top:16px"><tr>'
            . (($agence['logo'] ?? '') !== ''
                ? '<td valign="middle" style="padding-right:9px"><img src="' . $e($agence['logo']) . '" alt="" style="height:26px"></td>' : '')
            . '<td valign="middle" style="font-size:9.5px;color:#7a736a;line-height:1.5">Création : <strong>'
            . $e($agence['nom'] !== '' ? $agence['nom'] : 'agence partenaire') . '</strong>'
            . (($agence['site'] ?? '') !== '' ? '<br>' . $e($agence['site']) : '')
            . '</td></tr></table>';
    }

    $jointes = array_values(array_filter($d['annexes'] ?? [], static fn ($a) => $a['enMail'] && $a['existe']));
    $listeAnnexes = $jointes === [] ? ''
        : '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;margin-bottom:5px">Documents joints</div>'
          . '<table width="100%" cellpadding="0" cellspacing="0" style="font-size:11px;margin-bottom:14px">'
          . implode('', array_map(static fn ($a) =>
              '<tr><td style="padding:4px 8px 4px 0">' . htmlspecialchars((string) $a['nom'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
              . ($a['type'] !== '' ? '<span style="color:#7a736a"> · ' . htmlspecialchars((string) $a['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>' : '')
              . '</td><td align="right" style="padding:4px 0;color:#7a736a;white-space:nowrap">PDF · ' . htmlspecialchars((string) $a['tailleTxt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td></tr>', $jointes))
          . '</table>';

    $pied = $signature
        . '<div style="margin-top:14px;padding-top:9px;border-top:1px solid #e6e0d8;font-size:9px;color:#7a736a;line-height:1.6">'
        . 'Note éditée le ' . date('d/m/Y') . ' par la centrale L’Atelier by.'
        . ($d['kpiMesure'] ? ' Les valeurs de l’an dernier sont lues sur la caisse, sur les mêmes dates décalées de 364 jours.' : '')
        . ($visuel !== '' ? ' Le visuel de la campagne est en annexe, page 2.' : '')
        . (($d['visuel']['motif'] ?? '') !== '' ? ' Annexe absente : ' . $e($d['visuel']['motif']) . '.' : '')
        . '</div>';

    // Annexe : le visuel seul, en pleine page. C'est ce que le magasin
    // affichera — le voir petit à côté d'un tableau ne dit pas de quoi il a
    // l'air en vitrine. Pas de visuel, pas de page : une annexe vide se
    // remarque plus qu'une annexe absente.
    $annexe = $visuel === '' ? ''
        : '<div style="page-break-before:always;padding-top:8px">'
          . '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a">Annexe — le visuel de la campagne</div>'
          . '<div style="font-size:13px;font-weight:600;margin:3px 0 10px">' . $e($d['nom'])
          . ' · ' . mktBriefJour($d['du']) . ' → ' . mktBriefJour($d['au']) . '</div>'
          . '<img src="' . $visuel . '" alt="Visuel de la campagne" style="width:100%;max-height:232mm;object-fit:contain;border:1px solid #e6e0d8;border-radius:6px">'
          . '</div>';

    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>'
        . $e($d['nom']) . '</title></head>'
        . '<body style="margin:0;padding:26px 30px;font-family:Helvetica,Arial,sans-serif;color:#1c1a17;background:#fff">'
        . $entete . $titre . $blocCartes . $obj . $mot . $lev . $tab . $com . $plan . $listeAnnexes . $pied . $annexe . '</body></html>';
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

    $mot = ($d['mot']['texte'] ?? '') === '' ? ''
        : '<div style="border-left:3px solid #8D1D2C;padding:2px 0 2px 12px;margin:16px 0 0">'
          . '<div style="font-size:12.5px;line-height:1.65">' . nl2br($e($d['mot']['texte'])) . '</div>'
          . '<div style="font-size:11.5px;color:#7a736a;margin-top:6px">' . $e($d['mot']['nom'])
          . (($d['mot']['fonction'] ?? '') !== '' ? ' — ' . $e($d['mot']['fonction']) : '')
          . '</div></div>';

    // Le visuel en tête du courrier : le franchisé voit la campagne avant de
    // lire ses chiffres. Il est déjà réduit — c'est le même que la note.
    $banniere = ($d['visuel']['uri'] ?? '') === '' ? ''
        : '<img src="' . $d['visuel']['uri'] . '" alt="' . $e($d['nom'])
          . '" style="width:100%;max-width:100%;border-radius:8px;display:block;margin:0 0 14px">';

    $agence = $c['agence'] ?? [];
    $signature = (($agence['nom'] ?? '') === '' && ($agence['logo'] ?? '') === '') ? ''
        : '<table cellpadding="0" cellspacing="0" style="margin-top:16px"><tr>'
          . (($agence['logo'] ?? '') !== ''
              ? '<td valign="middle" style="padding-right:9px"><img src="' . $e($agence['logo']) . '" alt="" style="height:24px"></td>' : '')
          . '<td valign="middle" style="font-size:11px;color:#7a736a;line-height:1.5">Création : <strong>'
          . $e(($agence['nom'] ?? '') !== '' ? $agence['nom'] : 'agence partenaire') . '</strong>'
          . (($agence['site'] ?? '') !== '' ? '<br>' . $e($agence['site']) : '') . '</td></tr></table>';

    $contenu = $banniere . $intro . $mot . $cartes
        . '<p style="margin:14px 0 0;font-size:12.5px;line-height:1.6">La note complète est en pièce jointe, en PDF'
        . (($d['visuel']['uri'] ?? '') === '' ? ' : une page, à imprimer et à afficher en réserve.'
            : ' : la note en page 1, le visuel de la campagne en annexe page 2, à imprimer et à afficher.')
        . '</p>'
        . '<p style="margin:14px 0 0;font-size:11.5px;color:#7a736a;line-height:1.6">' . $pied . '</p>'
        . $signature;

    $squelette = trim((string) ($c['html'] ?? ''));
    if ($squelette === '') {
        $squelette = caMailSquelette('Marketing',
            'cette note accompagne la campagne du mois. Répondez à ce courriel pour joindre le marketing.');
    }

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
function mktBriefPdf(array $d, string $magasin = '', array $c = []): ?string
{
    return rapPdfRendu(mktBriefPdfHtml($d, $magasin, $c), [
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

    return ['campagne' => $d, 'destinataires' => $dest, 'mot' => $d['mot'],
        'config' => ['expediteur' => $c['expediteur'], 'repondreA' => $c['repondreA'],
            'sujet' => $c['sujet'], 'intro' => $c['intro'], 'pied' => $c['pied'],
            'html' => (string) ($c['html'] ?? ''), 'copie' => (string) ($c['copie'] ?? ''),
            'agence' => $c['agence']],
        'visuel' => ['present' => ($d['visuel']['uri'] ?? '') !== '', 'motif' => $d['visuel']['motif'] ?? ''],
        'apercuPdf' => mktBriefPdfHtml($d, '', $c),
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

    $pdf = mktBriefPdf($d, '', $c);
    $nomFichier = mktBriefNomFichier($d);

    // Les annexes cochées, lues UNE fois pour tous les destinataires — le même
    // fichier part à quatre magasins, le relire quatre fois ne l'améliore pas.
    // Plafond global : au-delà, les serveurs de messagerie refusent le message
    // entier, et le franchisé ne recevrait même pas la note.
    $annexes = []; $poids = $pdf === null ? 0 : strlen($pdf); $ecartees = [];
    foreach ($d['annexes'] as $a) {
        if (!$a['enMail']) { continue; }
        $oct = annexeOctets($a);
        if ($oct === null) { $ecartees[] = $a['nom'] . ' (fichier absent du serveur)'; continue; }
        if ($poids + strlen($oct) > 18 * 1024 * 1024) {
            $ecartees[] = $a['nom'] . ' (le courriel dépasserait 18 Mo)';
            continue;
        }
        $poids += strlen($oct);
        $annexes[] = ['nom' => annexeNomFichier((string) $a['nom']),
            'type' => 'application/pdf', 'contenu' => $oct];
    }

    $envoyes = 0; $echecs = [];

    foreach ($cibles as $t) {
        $vars = ['campagne' => $d['nom'], 'du' => mktBriefJour($d['du']), 'au' => mktBriefJour($d['au']),
            'magasin' => $t['magasin'], 'franchise' => $t['franchise'], 'type' => $d['type']];
        $sujet = caMailRemplir((string) $c['sujet'], $vars);
        $html = mktBriefMailHtml($d, $c, $t['magasin'], $t['franchise']);
        // Le PDF est joint QUAND il existe : sans moteur sur le serveur, la
        // lettre part quand même et le dit, plutôt que de ne pas partir.
        $pieces = $pdf === null ? $annexes
            : array_merge([['nom' => $nomFichier, 'type' => 'application/pdf', 'contenu' => $pdf]], $annexes);
        if ($pdf === null) {
            // La phrase promet une pièce jointe : sans PDF, elle mentirait.
            $html = (string) preg_replace('#<p[^>]*>La note complète est en pièce jointe.*?</p>#s',
                '<p style="margin:14px 0 0;font-size:12.5px;line-height:1.6">La note détaillée suit par un autre envoi : le serveur n’a pas pu produire le PDF cette fois.</p>',
                $html);
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
        'avecPdf' => $pdf !== null, 'annexes' => count($annexes), 'ecartees' => $ecartees,
        'message' => $envoyes . ' note(s) envoyée(s)'
            . ($annexes === [] ? '' : ' avec ' . count($annexes) . ' annexe(s)')
            . ($pdf === null ? ', sans PDF : aucun moteur sur ce serveur' : '')
            // Une annexe écartée se DIT : on ne laisse pas croire qu'elle est
            // partie parce que l'envoi a réussi.
            . ($ecartees === [] ? '' : ' — non jointes : ' . implode(', ', $ecartees))
            . ($echecs === [] ? '' : ' — refusé pour : ' . implode(', ', $echecs))];
}

/** GET /marketing/note-config — les réglages, pour l'écran Paramètres. */
function ep_mkt_brief_config(): array
{
    $c = mktBriefConfig();
    return ['expediteur' => $c['expediteur'], 'repondreA' => $c['repondreA'],
        'sujet' => $c['sujet'], 'intro' => $c['intro'], 'pied' => $c['pied'],
        'html' => (string) ($c['html'] ?? ''), 'agence' => $c['agence'],
        // Le carnet n'est pas un secret, mais il n'a rien à faire ici : il se
        // remplit devant la campagne qu'on envoie.
        'nCarnet' => count($c['carnet'])];
}

/** PUT /marketing/note-config — l'expéditeur, le sujet, le gabarit, le carnet. */
function wr_mkt_brief_config(): array
{
    $b = body();
    $c = mktBriefConfig();
    foreach (['expediteur', 'repondreA', 'sujet', 'intro', 'pied', 'html', 'copie'] as $k) {
        if (array_key_exists($k, $b)) { $c[$k] = (string) $b[$k]; }
    }
    if (isset($b['agence']) && is_array($b['agence'])) {
        $a = $b['agence'];
        $logo = trim((string) ($a['logo'] ?? $c['agence']['logo']));
        // Un logo est une IMAGE embarquée, pas une adresse : accepter une URL
        // ferait un cadre vide chez le destinataire et rien du tout à
        // l'impression. Un demi-mégaoctet suffit largement pour 26 px de haut.
        if ($logo !== '' && !preg_match('#^data:image/(png|jpeg|gif|webp|svg\+xml);base64,#', $logo)) { $logo = ''; }
        if (strlen($logo) > 700000) { $logo = ''; }
        $c['agence'] = [
            'nom' => mb_substr(trim((string) ($a['nom'] ?? $c['agence']['nom'])), 0, 120),
            'site' => mb_substr(trim((string) ($a['site'] ?? $c['agence']['site'])), 0, 160),
            'logo' => $logo,
        ];
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
