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

    // Ce que la campagne devrait rapporter, en clients et en euros — le même
    // calcul que « Budget × Campagnes », nourri du trafic déjà lu.
    $effet = ($kpi === null) ? ['entete' => [], 'magasins' => []]
        : mktEffetAttendu($id, $du, $au, $perim, $kpi);

    // Le budget de la période, magasin par magasin : c'est LE chiffre que le
    // franchisé a en tête, et la campagne se juge contre lui.
    $budgetDe = [];
    try {
        $an = (int) substr($du, 0, 4);
        $mois = [];
        foreach (Db::rows('SELECT shop_id, month, revenue_budget, ca_theorique
                             FROM ceo_shop_month_perf WHERE year = ?', [$an]) as $r) {
            $sid = (string) $r['shop_id']; $m = (int) $r['month'];
            if ($m < 1 || $m > 12) { continue; }
            if (!isset($mois[$sid])) { $mois[$sid] = array_fill(0, 12, ['budget' => null, 'theorique' => null]); }
            $mois[$sid][$m - 1] = [
                'budget' => $r['revenue_budget'] !== null ? (float) $r['revenue_budget'] : null,
                'theorique' => $r['ca_theorique'] !== null ? (float) $r['ca_theorique'] : null];
        }
        foreach ($perim as $sid) {
            $b = budgetSurFenetre($mois[$sid] ?? [], $du, $au);
            $budgetDe[$sid] = $b;
        }
    } catch (PDOException $e) { /* pas de budget encodé dans cette base */ }

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
        $ef = $effet['magasins'][(string) $m['id']] ?? [];
        $bud = $budgetDe[(string) $m['id']] ?? ['montant' => null, 'source' => null];
        $lignes[] = [
            'id' => (string) $m['id'], 'nom' => (string) $m['nom'],
            'reference' => $ref,
            'cible' => $cible,
            // Le détail que le franchisé lit dans SON courriel.
            'clientsA1' => $ef['clientsA1'] ?? null,
            'clientsPrevus' => $ef['clientsPrevus'] ?? null,
            'caA1' => $ef['base'] ?? null,
            'caAttendu' => (($ef['base'] ?? null) !== null && ($ef['gain'] ?? null) !== null)
                ? $ef['base'] + $ef['gain'] : null,
            'budget' => $bud['montant'],
            'budgetSource' => $bud['source'],
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

    // Le point de visée du visuel : c'est lui qui décide de ce qui reste dans
    // un cadre plus étroit. Il vit sur le visuel maître, pas sur la campagne.
    $cadrage = 50.0; $ajuste = 'cover';
    try {
        $m = Db::row('SELECT focal_point_y, fit FROM mar_campaign_asset
                       WHERE campaign_id = ? ORDER BY is_master DESC, id LIMIT 1', [$id]);
        if ($m !== null && $m['focal_point_y'] !== null) { $cadrage = (float) $m['focal_point_y']; }
        // « contain » est un choix de l'assistant : un logo ou une affiche
        // entière ne se recadre pas — le recadrer coupait la tête du dessin.
        if ($m !== null && (string) ($m['fit'] ?? '') === 'contain') { $ajuste = 'contain'; }
    } catch (PDOException $e) { /* pas de visuel : le centre fera l'affaire */ }

    // Les formats de publication retenus : « Post Facebook », « Story »… Le
    // franchisé doit voir de quoi la campagne aura l'air sur SON écran, pas
    // seulement le visuel d'origine.
    $formats = [];
    try {
        foreach (Db::rows('SELECT DISTINCT f.id, f.name, f.width_px, f.height_px, f.note, f.sort_order
                             FROM mar_asset_render ar
                             JOIN mar_campaign_asset ca ON ca.id = ar.campaign_asset_id
                             JOIN mar_format f ON f.id = ar.format_id
                            WHERE ca.campaign_id = ?
                            ORDER BY f.sort_order, f.id', [$id]) as $f2) {
            $formats[] = ['nom' => (string) $f2['name'],
                'largeur' => (int) $f2['width_px'], 'hauteur' => (int) $f2['height_px'],
                'note' => (string) ($f2['note'] ?? '')];
        }
    } catch (PDOException $e) { /* pas de déclinaisons dans cette base */ }

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
        'formats' => $formats,
        'cadrage' => $cadrage,
        'ajuste' => $ajuste,
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
    if ($url === '') { return ['uri' => '', 'motif' => '', 'largeur' => 0, 'hauteur' => 0]; }

    // « ./uploads/xxx.jpg » vit sous la page de l'assistant.
    $rel = ltrim(preg_replace('#^\./#', '', $url) ?? $url, '/');
    $chemin = __DIR__ . '/../public/assistant/' . $rel;
    if (!is_file($chemin)) { $chemin = __DIR__ . '/../public/' . $rel; }
    if (!is_file($chemin)) {
        return ['uri' => '', 'motif' => 'le visuel de la campagne est introuvable sur le serveur', 'largeur' => 0, 'hauteur' => 0];
    }

    $octets = (string) file_get_contents($chemin);
    $info = @getimagesizefromstring($octets);
    $type = is_array($info) ? (string) $info['mime'] : 'image/jpeg';

    if (strlen($octets) > 1000000) {
        if (!function_exists('imagecreatefromstring')) {
            return ['uri' => '', 'motif' => 'le visuel dépasse 1 Mo et le serveur ne sait pas le réduire', 'largeur' => 0, 'hauteur' => 0];
        }
        $src = @imagecreatefromstring($octets);
        if ($src === false) { return ['uri' => '', 'motif' => 'le visuel n’a pas pu être lu', 'largeur' => 0, 'hauteur' => 0]; }
        $l = imagesx($src); $h = imagesy($src);
        $k = min(1.0, 1600 / max($l, $h));
        $dst = imagecreatetruecolor((int) round($l * $k), (int) round($h * $k));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $l, $h);
        ob_start(); imagejpeg($dst, null, 78); $octets = (string) ob_get_clean();
        imagedestroy($src); imagedestroy($dst);
        $type = 'image/jpeg';
    }

    $dim = @getimagesizefromstring($octets);
    return ['uri' => 'data:' . $type . ';base64,' . base64_encode($octets), 'motif' => '',
        'largeur' => is_array($dim) ? (int) $dim[0] : 0, 'hauteur' => is_array($dim) ? (int) $dim[1] : 0];
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
function mktBriefPdfHtml(array $d, string $magasin = '', array $c = [], string $shopId = ''): string
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
    // Adressée à un magasin, la note vise CE magasin : lui montrer la cible
    // réseau — 783 clients par jour pour une boutique qui en fait 136 — ne lui
    // dit rien de ce qu'on attend de lui.
    $sien = null;
    if ($shopId !== '') {
        foreach ($d['lignes'] as $l) { if ((string) $l['id'] === $shopId) { $sien = $l; break; } }
    }
    $refBloc = $sien !== null ? $sien['reference'] : $d['reference'];
    $cibleBloc = $sien !== null ? $sien['cible'] : $d['cible'];

    $obj = '<div style="border:1px solid #e6e0d8;border-radius:8px;padding:13px 14px;margin-bottom:16px;background:#fbf9f5">'
        . '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a">Ce qu’on vise'
        . ($sien !== null ? ' — ' . $e($sien['nom']) : '') . '</div>';
    if ($d['objectifPct'] === null) {
        $obj .= '<div style="font-size:12px;margin-top:5px">Aucun écart chiffré n’est fixé pour cette campagne : le suivi se fera sur les valeurs réelles, sans cible.</div>';
    } elseif (!$d['kpiMesure'] || $refBloc === null) {
        $obj .= '<div style="font-size:14px;font-weight:600;margin-top:4px">'
            . ($d['objectifPct'] >= 0 ? '+' : '−') . abs((float) $d['objectifPct']) . ' % par rapport à l’an dernier</div>'
            . '<div style="font-size:10.5px;color:#7a736a;margin-top:3px">'
            . ($d['kpiNom'] !== '' ? $e($d['kpiNom']) . ' — ' : '')
            . 'la référence de l’an dernier n’a pas pu être lue en caisse pour cette période : la cible en valeur reste à poser.</div>';
    } else {
        $obj .= '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px"><tr>'
            . '<td style="font-size:12px;line-height:1.55">' . $e($d['kpiNom']) . '<br>'
            . '<span style="color:#7a736a;font-size:10.5px">'
            . ($sien !== null
                ? 'l’an dernier, même période : <strong>' . mktBriefValeur($refBloc, $d) . '</strong>'
                  . (($sien['source'] ?? '') === 'repli' ? ' (moyenne des 3 derniers mois)' : '')
                : ($d['referenceSource'] === 'n1'
                    ? 'l’an dernier, même période : <strong>' . mktBriefValeur($refBloc, $d) . '</strong>'
                    : 'référence du réseau : <strong>' . mktBriefValeur($refBloc, $d) . '</strong>'
                      . ' — dont ' . $d['referenceNRepli'] . ' magasin'
                      . ($d['referenceNRepli'] > 1 ? 's' : '') . ' sur moyenne 3 mois'))
            . '</span></td>'
            . '<td align="right" style="font-size:22px;font-weight:600;color:' . $accent . ';white-space:nowrap">'
            . mktBriefValeur($cibleBloc, $d)
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
            $moi = $shopId !== '' && (string) $l['id'] === $shopId;
            $fond = $moi ? 'background:#fbf9f5;' : '';
            $tab .= '<tr><td style="' . $fond . 'padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06)'
                . ($moi ? ';font-weight:600' : '') . '">' . $e($l['nom'])
                . ($moi ? ' <span style="color:' . $accent . ';font-weight:600">— votre magasin</span>' : '') . '</td>';
            if ($l['reference'] === null) {
                $tab .= '<td colspan="2" align="right" style="' . $fond . 'padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06);color:#b8b2a8">aucun relevé exploitable — cible à poser ensemble</td></tr>';
                continue;
            }
            $marque = ($l['source'] ?? '') === 'repli'
                ? ' <sup style="color:' . $accent . ';font-weight:600">(i)</sup>' : '';
            if ($marque !== '') { $repli = true; }
            $tab .= '<td align="right" style="' . $fond . 'padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06)">'
                . mktBriefValeur($l['reference'], $d) . $marque . '</td>'
                . '<td align="right" style="' . $fond . 'padding:7px 8px;border-bottom:1px solid rgba(34,34,34,.06);font-weight:600">'
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
          . '<td width="112" align="right" valign="middle">'
          . mktBriefVignette($visuel, (int) ($d['visuel']['largeur'] ?? 0), (int) ($d['visuel']['hauteur'] ?? 0),
              104, 104, (float) ($d['cadrage'] ?? 50), 'border-radius:8px;border:1px solid #e6e0d8',
              (string) ($d['ajuste'] ?? 'cover'))
          . '</td>'
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

    $annexe = mktBriefAnnexe($d, $visuel, $accent);
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
        . ($annexe !== '' ? ' L’annexe, page 2, montre le visuel format par format et la liste des fichiers joints.' : '')
        . (($d['visuel']['motif'] ?? '') !== '' ? ' Annexe absente : ' . $e($d['visuel']['motif']) . '.' : '')
        . '</div>';


    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>'
        . $e($d['nom']) . '</title></head>'
        . '<body style="margin:0;padding:26px 30px;font-family:Helvetica,Arial,sans-serif;color:#1c1a17;background:#fff">'
        . $entete . $titre . $blocCartes . $obj . $mot . $lev . $tab . $com . $plan . $listeAnnexes . $pied . $annexe . '</body></html>';
}

/**
 * Une image RECADRÉE à la taille demandée, sans `object-fit`.
 *
 * Les moteurs PDF du serveur ne rendent pas `object-fit` : l'image était
 * étirée au lieu d'être recadrée, ce qui donnait un visuel déformé — et,
 * page d'annexe, un cadrage qui n'est pas celui qui sera publié. On calcule
 * donc la couverture ici, en pixels, et on la pose dans une boîte qui coupe.
 *
 * `$focal` : le point de visée vertical du visuel, en pourcentage.
 */
function mktBriefVignette(string $uri, int $iw, int $ih, int $l, int $h, float $focal,
    string $bord = '', string $ajuste = 'cover'): string
{
    if ($uri === '') { return ''; }
    // Fond blanc : un PNG à fond transparent posé sur une boîte sans couleur
    // sort NOIR à l'impression comme au rendu PDF.
    $boite = 'width:' . $l . 'px;height:' . $h . 'px;overflow:hidden;position:relative;background:#fff;' . $bord;
    if ($iw <= 0 || $ih <= 0) {
        // Dimensions inconnues : mieux vaut une image entière un peu petite
        // qu'une image déformée dont on ne saurait pas qu'elle l'est.
        return '<div style="' . $boite . '"><img src="' . $uri . '" alt="" style="width:' . $l . 'px"></div>';
    }
    // « contain » : l'image ENTIÈRE tient dans la boîte, centrée. « cover » :
    // elle la remplit et déborde, au point de visée.
    $k = $ajuste === 'contain' ? min($l / $iw, $h / $ih) : max($l / $iw, $h / $ih);
    $pw = (int) ceil($iw * $k); $ph = (int) ceil($ih * $k);
    $x = (int) round(($l - $pw) / 2);
    $y = $ajuste === 'contain'
        ? (int) round(($h - $ph) / 2)
        : (int) round(-($ph - $h) * max(0.0, min(100.0, $focal)) / 100);

    return '<div style="' . $boite . '">'
        . '<img src="' . $uri . '" alt="" style="position:absolute;left:' . $x . 'px;top:' . $y . 'px;width:' . $pw . 'px;height:' . $ph . 'px">'
        . '</div>';
}

/**
 * L'annexe : UNE page A4, pas une de plus.
 *
 * Elle porte deux choses et rien d'autre : de quoi la campagne aura l'air dans
 * chaque format publié — post Facebook, story, header — et la liste des
 * documents joints au courriel. Le visuel occupait auparavant la page entière,
 * ce qui montrait l'image d'origine mais pas ce que le client verra : un visuel
 * carré recadré en 1200 × 630 perd la moitié de sa hauteur, et c'est ce
 * cadrage-là qu'il faut regarder avant de publier.
 *
 * Le recadrage est calculé ICI, en pixels : `object-fit` n'est pas rendu par
 * tous les moteurs PDF, et une vignette étirée mentirait sur le cadrage.
 */
function mktBriefAnnexe(array $d, string $visuel, string $accent): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $jointes = array_values(array_filter($d['annexes'] ?? [], static fn ($a) => $a['enMail'] && $a['existe']));
    $formats = $d['formats'] ?? [];
    if ($visuel === '' && $jointes === []) { return ''; }

    // Trois vignettes par rangée dans la largeur utile d'un A4 (180 mm).
    $boite = 210; $gouttiere = 12;
    $iw = (int) ($d['visuel']['largeur'] ?? 0);
    $ih = (int) ($d['visuel']['hauteur'] ?? 0);
    $focal = max(0.0, min(100.0, (float) ($d['cadrage'] ?? 50)));
    $ajuste = (string) ($d['ajuste'] ?? 'cover');

    $vignettes = '';
    if ($visuel !== '' && $formats !== [] && $iw > 0 && $ih > 0) {
        $cases = [];
        foreach (array_slice($formats, 0, 6) as $f) {
            $fw = max(1, (int) $f['largeur']); $fh = max(1, (int) $f['hauteur']);
            // Hauteur bornée et COMMUNE : un format 1080 × 1920 ferait une
            // vignette de 373 px de haut, la rangée s'aligne sur la plus haute
            // et la liste des fichiers passerait sur une seconde page. Chaque
            // vignette garde en revanche ses proportions — c'est le cadrage
            // publié qu'on vient regarder, pas une image redressée.
            $h = min(132, (int) round($boite * $fh / $fw));
            $l = (int) round($h * $fw / $fh);
            // Couverture : l'image déborde la case, jamais l'inverse.
            $cases[] = '<td width="' . ($boite + $gouttiere) . '" valign="bottom" style="padding:0 ' . $gouttiere . 'px 12px 0">'
                . mktBriefVignette($visuel, $iw, $ih, $l, $h, $focal, 'border:1px solid #e6e0d8;border-radius:5px', $ajuste)
                . '<div style="font-size:10px;font-weight:600;margin-top:4px">' . $e($f['nom']) . '</div>'
                . '<div style="font-size:9px;color:#7a736a">' . $fw . ' × ' . $fh . ' px'
                . ($f['note'] !== '' ? ' · ' . $e($f['note']) : '') . '</div></td>';
        }
        $rangees = array_chunk($cases, 3);
        $vignettes = '<table cellpadding="0" cellspacing="0" style="margin-bottom:14px">'
            . implode('', array_map(static fn ($r) => '<tr>' . implode('', $r) . '</tr>', $rangees))
            . '</table>';
    } elseif ($visuel !== '') {
        // Aucun format déclaré : on montre le visuel tel quel, en petit, et on
        // dit pourquoi il n'y a pas de déclinaison à regarder.
        $vignettes = '<img src="' . $visuel . '" alt="" style="max-width:340px;border:1px solid #e6e0d8;border-radius:5px">'
            . '<div style="font-size:9.5px;color:#7a736a;margin:5px 0 14px">Aucun format de publication n’est retenu pour cette campagne :'
            . ' le visuel est montré tel qu’il a été déposé.</div>';
    }

    $liste = '';
    if ($jointes !== []) {
        $liste = '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;margin:2px 0 5px">Fichiers joints à ce courriel</div>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:11px">'
            . implode('', array_map(static fn ($a) =>
                '<tr><td style="padding:6px 8px 6px 0;border-bottom:1px solid rgba(34,34,34,.06)">'
                . $e($a['nom'])
                . ($a['type'] !== '' ? '<span style="color:#7a736a"> · ' . $e($a['type']) . '</span>' : '')
                . '</td><td width="90" align="right" style="padding:6px 0 6px 8px;border-bottom:1px solid rgba(34,34,34,.06);color:#7a736a;white-space:nowrap">PDF · '
                . $e($a['tailleTxt']) . '</td></tr>', $jointes))
            . '</table>';
    }

    return '<div style="page-break-before:always;padding-top:8px">'
        . '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:' . $accent . '">Annexe</div>'
        . '<div style="font-size:13px;font-weight:600;margin:3px 0 12px">' . $e($d['nom'])
        . ' · ' . mktBriefJour($d['du']) . ' → ' . mktBriefJour($d['au']) . '</div>'
        . ($vignettes === '' ? '' : '<div style="font-size:8.5px;letter-spacing:.07em;text-transform:uppercase;color:#7a736a;margin-bottom:6px">Le visuel, format par format</div>')
        . $vignettes . $liste
        . '</div>';
}

/** Le corps du courrier — le gabarit d'achats, réutilisé tel quel. */
/**
 * Une bannière au format lettre : large, basse, recadrée au point de visée.
 *
 * Le visuel d'origine est carré ou portrait ; posé en pleine largeur dans un
 * courriel, il occupait deux écrans de téléphone avant le premier mot. On le
 * recadre donc en 2,5:1 côté serveur — un `<img>` simple, sans position
 * absolue ni `object-fit`, les deux étant ignorés par Outlook.
 *
 * Sans GD, on renonce à la bannière plutôt que d'envoyer l'image entière :
 * mieux vaut pas d'image qu'une image qui repousse le texte hors de l'écran.
 */
function mktBriefBanniere(array $d): string
{
    $uri = (string) ($d['visuel']['uri'] ?? '');
    if ($uri === '' || !function_exists('imagecreatefromstring')) { return ''; }

    $octets = base64_decode((string) substr($uri, strpos($uri, ',') + 1), true);
    if ($octets === false) { return ''; }
    $src = @imagecreatefromstring($octets);
    if ($src === false) { return ''; }

    $iw = imagesx($src); $ih = imagesy($src);
    $lw = 1200; $lh = 480;
    $contain = (string) ($d['ajuste'] ?? 'cover') === 'contain';
    $k = $contain ? min($lw / $iw, $lh / $ih) : max($lw / $iw, $lh / $ih);
    $pw = (int) ceil($iw * $k); $ph = (int) ceil($ih * $k);
    $focal = max(0.0, min(100.0, (float) ($d['cadrage'] ?? 50)));
    $dst = imagecreatetruecolor($lw, $lh);
    // Fond BLANC avant tout : un PNG transparent sur une toile neuve donne un
    // bandeau NOIR — c'est ce qui partait aux franchisés.
    imagefilledrectangle($dst, 0, 0, $lw, $lh, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src,
        (int) round(($lw - $pw) / 2),
        $contain ? (int) round(($lh - $ph) / 2) : (int) round(-($ph - $lh) * $focal / 100),
        0, 0, $pw, $ph, $iw, $ih);
    ob_start(); imagejpeg($dst, null, 80); $bandeau = (string) ob_get_clean();
    imagedestroy($src); imagedestroy($dst);

    return 'data:image/jpeg;base64,' . base64_encode($bandeau);
}

function mktBriefMailHtml(array $d, array $c, string $magasin, string $franchise, string $shopId = ''): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // L'objectif du MAGASIN, pas celui du réseau. Chaque franchisé recevait la
    // cible réseau — « 783 clients par jour » pour une boutique qui en fait
    // 299 : le chiffre ne voulait rien dire, et il décourageait.
    $sien = null;
    foreach ($d['lignes'] as $l) {
        if ($shopId !== '' && (string) $l['id'] === $shopId) { $sien = $l; break; }
    }
    $cible = $sien !== null ? $sien['cible'] : $d['cible'];
    $reference = $sien !== null ? $sien['reference'] : $d['reference'];

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
        'cible' => mktBriefValeur($cible, $d),
    ];

    $intro = nl2br($e(caMailRemplir((string) ($c['intro'] ?? ''), array_map($e, $vars))));
    $pied = $e(caMailRemplir((string) ($c['pied'] ?? ''), array_map($e, $vars)));

    // Les faits, en deux colonnes qui tiennent sur 320 points : le libellé à
    // gauche, la valeur à droite, et le libellé qui ne casse pas la valeur.
    $fleche = static fn (?string $a, ?string $b): string => ($a ?? '—') . '  →  ' . ($b ?? '—');
    $nb = static fn (?int $v): ?string => $v === null ? null : number_format($v, 0, ',', ' ');

    $faits = [['Période', mktBriefJour($d['du']) . ' → ' . mktBriefJour($d['au']) . ' · ' . $d['jours'] . ' jours'],
              ['Budget engagé par la centrale', mktBriefEuros($d['budget'])]];

    // Ce que le franchisé vient chercher : d'où il part, où on veut aller, et
    // ce que son budget attendait de toute façon. Les trois côte à côte —
    // séparés, ils ne se comparent pas.
    if ($cible !== null) {
        $faits[] = [$d['kpiNom'] . ' — l’an dernier → visé',
            $fleche(mktBriefValeur($reference, $d), mktBriefValeur($cible, $d))];
    }
    if ($sien !== null && ($sien['clientsPrevus'] ?? null) !== null) {
        $faits[] = ['Clients sur la période — l’an dernier → visés',
            $fleche($nb($sien['clientsA1']), $nb($sien['clientsPrevus']))];
    }
    if ($sien !== null && ($sien['caAttendu'] ?? null) !== null) {
        $faits[] = ['Chiffre d’affaires — l’an dernier → attendu',
            $fleche(mktBriefEuros($sien['caA1']), mktBriefEuros($sien['caAttendu']))];
    }
    if ($sien !== null && ($sien['budget'] ?? null) !== null) {
        $faits[] = [($sien['budgetSource'] ?? '') === 'theorique'
            ? 'Objectif de la période (CA théorique de l’étude)'
            : 'Objectif de la période (budget validé)',
            mktBriefEuros($sien['budget'])];
    }
    if ($cible === null) { $faits[] = ['Objectif', $vars['objectif']]; }

    $cartes = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 0">';
    foreach ($faits as [$t, $v]) {
        $cartes .= '<tr><td style="padding:9px 0;border-bottom:1px solid #e6e0d8;font-size:12.5px;color:#7a736a;line-height:1.45">'
            . $e($t) . '</td>'
            . '<td align="right" valign="top" style="padding:9px 0 9px 10px;border-bottom:1px solid #e6e0d8;font-size:13.5px;font-weight:600;line-height:1.45">'
            . $e($v) . '</td></tr>';
    }
    $cartes .= '</table>';

    $mot = ($d['mot']['texte'] ?? '') === '' ? ''
        : '<div style="border-left:3px solid #8D1D2C;padding:2px 0 2px 13px;margin:18px 0 0">'
          . '<div class="cmd" style="font-size:13px;line-height:1.65">' . nl2br($e($d['mot']['texte'])) . '</div>'
          . '<div style="font-size:11.5px;color:#7a736a;margin-top:6px">' . $e($d['mot']['nom'])
          . (($d['mot']['fonction'] ?? '') !== '' ? ' — ' . $e($d['mot']['fonction']) : '')
          . '</div></div>';

    // Le visuel en tête, en bandeau : le franchisé voit la campagne avant de
    // lire ses chiffres, sans que l'image mange l'écran.
    $bandeau = mktBriefBanniere($d);
    $banniere = $bandeau === '' ? ''
        : '<tr><td style="padding:0"><img src="' . $bandeau . '" alt="' . $e($d['nom'])
          . '" width="600" style="width:100%;max-width:100%;height:auto;display:block"></td></tr>';

    $agence = $c['agence'] ?? [];
    $signature = (($agence['nom'] ?? '') === '' && ($agence['logo'] ?? '') === '') ? ''
        : '<table cellpadding="0" cellspacing="0" style="margin-top:16px"><tr>'
          . (($agence['logo'] ?? '') !== ''
              ? '<td valign="middle" style="padding-right:9px"><img src="' . $e($agence['logo']) . '" alt="" style="height:24px"></td>' : '')
          . '<td valign="middle" style="font-size:11px;color:#7a736a;line-height:1.5">Création : <strong>'
          . $e(($agence['nom'] ?? '') !== '' ? $agence['nom'] : 'agence partenaire') . '</strong>'
          . (($agence['site'] ?? '') !== '' ? '<br>' . $e($agence['site']) : '') . '</td></tr></table>';

    // Le corps entier tient dans UNE cellule padée : c'est la seule structure
    // qu'un courriel rend pareil du téléphone au bureau. Les paragraphes
    // posés directement dans le <table> du squelette en sortaient — les
    // navigateurs les remontent hors du tableau, d'où une lettre sans marges
    // et une image en pleine largeur.
    $contenu = $banniere
        . '<tr><td class="pad" style="padding:20px 26px 4px;font-family:\'Segoe UI\',Arial,sans-serif;color:#221E1A">'
        . '<div class="cmd" style="font-size:13.5px;line-height:1.65">' . $intro . '</div>'
        . $mot . $cartes
        . '<p style="margin:18px 0 0;font-size:12.5px;line-height:1.6">'
        . (($d['visuel']['uri'] ?? '') === ''
            ? 'La note complète est en pièce jointe, en PDF — une page, à imprimer et à afficher en réserve.'
            : 'La note complète est en pièce jointe : une page de chiffres, puis le visuel format par format et la liste des documents.')
        . '</p>'
        . '<p style="margin:16px 0 0;font-size:11.5px;color:#7a736a;line-height:1.6">' . $pied . '</p>'
        . $signature
        . '</td></tr>';

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
function mktBriefPdf(array $d, string $magasin = '', array $c = [], string $shopId = ''): ?string
{
    return rapPdfRendu(mktBriefPdfHtml($d, $magasin, $c, $shopId), [
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
        // L'aperçu montre la note telle qu'elle partira au PREMIER destinataire :
        // une note « réseau » n'est plus envoyée à personne, l'afficher
        // laisserait croire le contraire.
        'apercuPdf' => mktBriefPdfHtml($d, (string) ($dest[0]['magasin'] ?? ''), $c, (string) ($dest[0]['id'] ?? '')),
        'apercuPour' => (string) ($dest[0]['magasin'] ?? ''),
        'apercuMail' => mktBriefMailHtml($d, $c, $dest[0]['magasin'] ?? '', $dest[0]['franchise'] ?? '', (string) ($dest[0]['id'] ?? '')),
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
    $sid = trim((string) ($_GET['shop'] ?? ''));
    if ($sid !== '' && $magasin === '') {
        foreach ($d['lignes'] as $l) { if ((string) $l['id'] === $sid) { $magasin = (string) $l['nom']; } }
    }
    $pdf = mktBriefPdf($d, $magasin, mktBriefConfig(), $sid);
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

    // UN PDF PAR MAGASIN : la note s'adresse à lui, elle porte son nom, sa
    // cible et sa ligne marquée. Un seul fichier pour tout le monde envoyait
    // au franchisé de Halle la cible du réseau.
    //
    // Le rendu est mis en cache par magasin : quatre destinataires, quatre
    // rendus — pas quatre par destinataire.
    $nomFichier = mktBriefNomFichier($d);
    $pdfs = [];
    $pdfDe = static function (string $sid, string $magasin) use (&$pdfs, $d, $c): ?string {
        if (!array_key_exists($sid, $pdfs)) { $pdfs[$sid] = mktBriefPdf($d, $magasin, $c, $sid); }
        return $pdfs[$sid];
    };
    // Le poids des annexes se calcule sur la note la plus lourde : c'est celle
    // qui décidera si le courriel passe.
    $pdf = $pdfDe((string) ($cibles[0]['id'] ?? ''), (string) ($cibles[0]['magasin'] ?? ''));

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
        $html = mktBriefMailHtml($d, $c, $t['magasin'], $t['franchise'], (string) ($t['id'] ?? ''));
        // Le PDF est joint QUAND il existe : sans moteur sur le serveur, la
        // lettre part quand même et le dit, plutôt que de ne pas partir.
        $sien = $pdfDe((string) $t['id'], (string) $t['magasin']);
        $pieces = $sien === null ? $annexes
            : array_merge([['nom' => $nomFichier, 'type' => 'application/pdf', 'contenu' => $sien]], $annexes);
        if ($sien === null) {
            // La phrase promet une pièce jointe : sans PDF, elle mentirait.
            $html = (string) preg_replace('#<p[^>]*>La note complète est en pièce jointe.*?</p>#s',
                '<p style="margin:14px 0 0;font-size:12.5px;line-height:1.6">La note détaillée suit par un autre envoi : le serveur n’a pas pu produire le PDF cette fois.</p>',
                $html);
        }

        $ok = Smtp::envoyer($t['adresse'], $sujet, $html, $pieces, (string) $c['expediteur']);
        if ($ok === true) {
            $envoyes++;
            mktBriefJournalAdd($id, 'envoye', $d['nom'] . ' — ' . $t['magasin']
                . ($sien === null ? ' (sans PDF : aucun moteur sur le serveur)' : ''), $t['adresse'],
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
