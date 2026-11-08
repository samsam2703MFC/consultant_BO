<?php
/**
 * La revue du soir (08/11/2026) : l'assistant de gestion qui passe la journée d'un magasin en revue avant la
 * fermeture — les checklists pas finies (une raison en un bouton) et les points de gestion. Il n'est pas une
 * checklist du panel : l'équipe ne le voit pas dans ses listes.
 *
 * Deux sortes de points :
 *  - « auto » : une SONDE lit une donnée que le cockpit sait déjà lire (les invendus encodés au panel, le dernier
 *    comptage du stock, les commandes clients encore ouvertes…). Donnée revenue et en ordre : rien à demander ;
 *    sinon la question du point s'affiche avec ses boutons de réponse.
 *  - « question » : ce que le cockpit ne peut pas voir (feedback à l'équipe, caisse, commande fournisseurs…).
 *
 * Le réglage vit dans `ceo_app_setting` sous `revueSoir` ; il s'édite au cockpit (Checklists › Revue du soir).
 *
 *   GET  /soir/config                     le réglage (défauts compris) et le catalogue des sondes
 *   PUT  /soir/config                     enregistre le réglage
 *   GET  /soir/sonder?shop=4&date=…       évalue chaque point « auto » pour un magasin et un jour : la donnée
 *                                         est-elle revenue, et dit-elle que c'est en ordre ? (&point=stock : un seul)
 */

/** Les sondes : ce qu'elles lisent (la route du cockpit et l'appel au panel derrière), leurs réglages, leur verdict. */
function rsSondes(): array
{
    return [
        'invendus' => ['titre' => 'Invendus encodés', 'cockpit' => '/exploitation/invendus?shop={shop}&date={date}',
            'panel' => '/shops/{shop}/products/waste?date_from={date}&date_to={date}', 'quand' => 'le jour même',
            'aide' => 'En ordre quand le magasin a déclaré ses pertes du jour au panel (au moins une ligne, « rien à jeter » compris s’il est déclaré).',
            'params' => []],
        'stock' => ['titre' => 'Stock compté', 'cockpit' => '/ventes/stock?shop={shop}',
            'panel' => '/shops/{shop}/material-inventory', 'quand' => 'le dernier comptage, quel que soit le jour',
            'aide' => 'En ordre quand le dernier comptage de l’inventaire matière date de moins de N jours.',
            'params' => [['k' => 'jours', 'label' => 'compté depuis au plus (jours)', 'defaut' => 7, 'min' => 1, 'max' => 60]]],
        'commandes' => ['titre' => 'Commandes clients clôturées', 'cockpit' => '/ventes/commandes?shop={shop}',
            'panel' => '/shops/{shop}/client-orders', 'quand' => 'l’état actuel',
            'aide' => 'En ordre quand il ne reste pas plus de N commandes dont la date est passée et qui sont encore « en cours » au panel.',
            'params' => [['k' => 'max', 'label' => 'commandes passées encore ouvertes, au plus', 'defaut' => 0, 'min' => 0, 'max' => 100]]],
        'photos' => ['titre' => 'Photos de contrôle rendues', 'cockpit' => '/pwa/tasks?shop={shop}&date={date}',
            'panel' => '/consultant/shops/{shop}/tasks?date={date}', 'quand' => 'le jour même',
            'aide' => 'En ordre quand au moins N % des tâches obligatoires qui demandent une photo ont leur photo.',
            'params' => [['k' => 'min', 'label' => 'photos rendues, au moins (%)', 'defaut' => 100, 'min' => 0, 'max' => 100]]],
        'taches' => ['titre' => 'Tâches obligatoires faites', 'cockpit' => '/pwa/tasks?shop={shop}&date={date}',
            'panel' => '/consultant/shops/{shop}/tasks?date={date}', 'quand' => 'le jour même',
            'aide' => 'En ordre quand au moins N % des tâches obligatoires du jour sont faites (avec leur photo quand elle est demandée).',
            'params' => [['k' => 'min', 'label' => 'tâches faites, au moins (%)', 'defaut' => 80, 'min' => 0, 'max' => 100]]],
        'nc' => ['titre' => 'Non-conformités reprises', 'cockpit' => '/pwa/tasks/nc?shop={shop}&du={du}&au={date}',
            'panel' => '', 'quand' => 'les N derniers jours',
            'aide' => 'En ordre quand chaque photo notée sous le seuil sur la période a été reprise (une note conforme après).',
            'params' => [['k' => 'jours', 'label' => 'période regardée (jours)', 'defaut' => 30, 'min' => 1, 'max' => 90]]],
    ];
}

/** Les variables d'un chemin : {shop}, {date} et {du} (le début de la période d'une sonde qui regarde N jours). */
function rsChemin(string $tpl, int $shop, string $date, array $params = []): string
{
    $du = date('Y-m-d', strtotime($date . ' -' . max(0, (int) ($params['jours'] ?? 30) - 1) . ' days'));
    return str_replace(['{shop}', '{date}', '{du}'], [(string) $shop, $date, $du], $tpl);
}

/** Un chemin saisi au cockpit : une route relative, lue en GET, sans hôte ni remontée ; jamais la revue elle-même. */
function rsCheminValide(string $c, bool $cockpit): bool
{
    if ($c === '') { return !$cockpit; }
    return (bool) preg_match('#^/[A-Za-z0-9/_\-.{}?=&%,:]*$#', $c) && !str_contains($c, '..') && !str_contains($c, '//')
        && (!$cockpit || !str_starts_with($c, '/soir/'));
}

/** Un point complété : la route du cockpit et l'appel au panel par défaut de sa sonde quand il n'en a pas. */
function rsNormalise(array $p): array
{
    $s = ($p['type'] ?? '') === 'auto' ? (rsSondes()[$p['sonde'] ?? ''] ?? null) : null;
    if ($s) { $p['cockpit'] = (string) ($p['cockpit'] ?? '') ?: $s['cockpit']; $p['panel'] = array_key_exists('panel', $p) && $p['panel'] !== null ? (string) $p['panel'] : $s['panel']; }
    return $p;
}

/** Le réglage par défaut : ce que montraient les maquettes M2 et M3 (08/11/2026). */
function rsDefaut(): array
{
    $r = fn (string $t, bool $ok) => ['t' => $t, 'ok' => $ok];
    return [
        'version' => 1, 'heure' => '18:00', 'checklists' => true,
        'raisons' => ['Fait, pas coché', 'Pas eu le temps', 'Manque de personnel', 'Oublié', 'Pas concerné aujourd’hui', 'Produit / matériel manquant'],
        'points' => [
            ['id' => 'invendus', 'actif' => true, 'type' => 'auto', 'sonde' => 'invendus', 'params' => [], 'icone' => '🗑', 'titre' => 'Invendus encodés',
                'question' => 'Les invendus et les pertes du jour ne sont pas encore déclarés au panel.',
                'reponses' => [$r('Je le fais maintenant', true), $r('Rien à déclarer', true), $r('Oublié', false)]],
            ['id' => 'stock', 'actif' => true, 'type' => 'auto', 'sonde' => 'stock', 'params' => ['jours' => 7], 'icone' => '📦', 'titre' => 'Stock compté',
                'question' => 'Le stock n’a plus été compté depuis trop longtemps. Il est compté ce soir ?',
                'reponses' => [$r('Compté ce soir', true), $r('Prévu demain', true), $r('Pas cette semaine', false)]],
            ['id' => 'commandes', 'actif' => true, 'type' => 'auto', 'sonde' => 'commandes', 'params' => ['max' => 0], 'icone' => '🧾', 'titre' => 'Commandes clients clôturées',
                'question' => 'Des commandes clients passées sont encore « en cours » au panel. On les clôture ?',
                'reponses' => [$r('Clôturées maintenant', true), $r('Déjà retirées, à clôturer', false), $r('À vérifier demain', false)]],
            ['id' => 'photos', 'actif' => true, 'type' => 'auto', 'sonde' => 'photos', 'params' => ['min' => 100], 'icone' => '📷', 'titre' => 'Photos rendues',
                'question' => 'Des photos de contrôle manquent. Pourquoi ?',
                'reponses' => [$r('Pas eu le temps', false), $r('Manque de personnel', false), $r('Oublié', false), $r('Faites, pas envoyées', false)]],
            ['id' => 'nc', 'actif' => true, 'type' => 'auto', 'sonde' => 'nc', 'params' => ['jours' => 30], 'icone' => '✅', 'titre' => 'Non-conformités reprises',
                'question' => 'Une non-conformité n’a pas encore été reprise. Elle est corrigée ?',
                'reponses' => [$r('Corrigée aujourd’hui', true), $r('En cours', false), $r('Pas encore', false)]],
            ['id' => 'feedback', 'actif' => true, 'type' => 'question', 'sonde' => null, 'params' => [], 'icone' => '💬', 'titre' => 'Feedback à l’équipe',
                'question' => 'Les retours du jour (photos, vitrine, ce qui manquait) ont-ils été donnés à l’équipe ?',
                'reponses' => [$r('Oui, au briefing', true), $r('Oui, un par un', true), $r('Pas encore', false), $r('Pas nécessaire', true)]],
            ['id' => 'caisse', 'actif' => true, 'type' => 'question', 'sonde' => null, 'params' => [], 'icone' => '💶', 'titre' => 'Caisse comptée et clôturée',
                'question' => 'Fond de caisse compté, écart noté, journée fermée dans la caisse ?',
                'reponses' => [$r('Oui, sans écart', true), $r('Oui, avec écart', false), $r('Pas encore', false)]],
            ['id' => 'fournisseurs', 'actif' => true, 'type' => 'question', 'sonde' => null, 'params' => [], 'icone' => '🚚', 'titre' => 'Commande fournisseurs de demain',
                'question' => 'La commande fournisseurs de demain est-elle passée et confirmée ?',
                'reponses' => [$r('Passée', true), $r('Pas nécessaire', true), $r('Pas encore', false)]],
            ['id' => 'demain', 'actif' => true, 'type' => 'question', 'sonde' => null, 'params' => [], 'icone' => '📋', 'titre' => 'Demain est prêt',
                'question' => 'Bon de commande imprimé, commandes traiteur préparées, équipe de demain prévenue ?',
                'reponses' => [$r('Tout est prêt', true), $r('En partie', false), $r('Pas encore', false)]],
        ],
    ];
}

function rsConfig(): array
{
    $c = setting('revueSoir', null);
    $c = is_array($c) && !empty($c['points']) ? $c + ['majLe' => null, 'majPar' => null] : rsDefaut() + ['majLe' => null, 'majPar' => null];
    $c['points'] = array_map('rsNormalise', $c['points']);
    return $c;
}

function rsRoute(string $method, string $path): array
{
    if ($method === 'GET' && $path === '/soir/config') {
        $s = rsSondes();
        $d = rsDefaut(); $d['points'] = array_map('rsNormalise', $d['points']);
        return ['config' => rsConfig(), 'defaut' => $d, 'sondes' => array_map(fn ($k, $v) => ['cle' => $k] + $v, array_keys($s), $s)];
    }
    if ($method === 'PUT' && $path === '/soir/config') { return rsEnregistrer(body()); }
    if ($method === 'GET' && $path === '/soir/sonder') { return rsSonder(); }
    if ($method === 'POST' && $path === '/soir/tester') { return rsTester(body()); }
    http_response_code(404);
    return ['error' => 'route inconnue'];
}

/** Nettoie et enregistre le réglage : des textes courts, des sondes connues, des réglages bornés. */
function rsEnregistrer(array $b): array
{
    $txt = fn ($v, int $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $v)), 0, $n);
    $S = rsSondes();
    $points = []; $ids = [];
    foreach ((array) ($b['points'] ?? []) as $p) {
        if (!is_array($p)) { continue; }
        $titre = $txt($p['titre'] ?? '', 80);
        if ($titre === '') { continue; }
        $type = ($p['type'] ?? '') === 'auto' ? 'auto' : 'question';
        $sonde = $type === 'auto' ? (string) ($p['sonde'] ?? '') : null;
        if ($type === 'auto' && !isset($S[$sonde])) { http_response_code(422); return ['error' => '« ' . $titre . ' » : sonde inconnue (' . $sonde . ')']; }
        $params = [];
        foreach ($type === 'auto' ? $S[$sonde]['params'] : [] as $d) {
            $v = $p['params'][$d['k']] ?? $d['defaut'];
            $params[$d['k']] = max($d['min'], min($d['max'], (int) $v));
        }
        $rep = [];
        foreach ((array) ($p['reponses'] ?? []) as $r) {
            $t = $txt(is_array($r) ? ($r['t'] ?? '') : $r, 40);
            if ($t !== '') { $rep[] = ['t' => $t, 'ok' => is_array($r) && !empty($r['ok'])]; }
        }
        if (!$rep) { http_response_code(422); return ['error' => '« ' . $titre . ' » : au moins un bouton de réponse est attendu']; }
        $cockpit = $type === 'auto' ? trim((string) ($p['cockpit'] ?? '')) : null;
        $panel = $type === 'auto' ? trim((string) ($p['panel'] ?? '')) : null;
        if ($type === 'auto' && ($cockpit === '' || !rsCheminValide($cockpit, true))) { http_response_code(422); return ['error' => '« ' . $titre . ' » : route du cockpit invalide (une route relative lue en GET, ex. /ventes/stock?shop={shop})']; }
        if ($type === 'auto' && !rsCheminValide($panel, false)) { http_response_code(422); return ['error' => '« ' . $titre . ' » : appel au panel invalide (ex. /shops/{shop}/material-inventory)']; }
        $id = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($p['id'] ?? ''))) ?: 'p' . substr(md5($titre . count($points)), 0, 6);
        while (isset($ids[$id])) { $id .= 'x'; }
        $ids[$id] = true;
        $points[] = ['id' => $id, 'actif' => !empty($p['actif']), 'type' => $type, 'sonde' => $sonde, 'params' => $params, 'cockpit' => $cockpit, 'panel' => $panel,
            'icone' => $txt($p['icone'] ?? '', 4), 'titre' => $titre, 'question' => $txt($p['question'] ?? '', 240), 'reponses' => array_slice($rep, 0, 8)];
    }
    if (!$points) { http_response_code(422); return ['error' => 'aucun point : la revue du soir en demande au moins un']; }
    $raisons = array_values(array_unique(array_filter(array_map(fn ($r) => $txt($r, 40), (array) ($b['raisons'] ?? [])))));
    $heure = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($b['heure'] ?? '')) ? (string) $b['heure'] : '18:00';
    $qui = function_exists('currentUserName') ? (string) currentUserName() : 'cockpit';
    $c = ['version' => 1, 'heure' => $heure, 'checklists' => !empty($b['checklists']), 'raisons' => array_slice($raisons, 0, 10),
        'points' => $points, 'majLe' => date('Y-m-d H:i'), 'majPar' => $qui];
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['revueSoir', json_encode($c, JSON_UNESCAPED_UNICODE)]);
    if (function_exists('journalAdd')) {
        journalAdd($qui, 'reglage', 'Revue du soir', count($points) . ' points (' . count(array_filter($points, fn ($p) => $p['type'] === 'auto')) . ' vérifiés automatiquement), '
            . count($c['raisons']) . ' raisons pour les checklists, à partir de ' . $heure);
    }
    return ['ok' => true, 'config' => $c];
}

/** Appelle une route du cockpit dans ce processus, avec ses paramètres, sans toucher la requête en cours. */
function rsAppel(callable $f, array $get): array
{
    $avant = $_GET; $code = http_response_code();
    $_GET = $get; $t = microtime(true);
    try { $r = $f(); }
    catch (Throwable $e) { $r = ['error' => $e->getMessage()]; }
    finally { $_GET = $avant; }
    $codeR = http_response_code();
    http_response_code($code ?: 200);
    return [is_array($r) ? $r : [], (int) round(1000 * (microtime(true) - $t)), $codeR >= 400 ? $codeR : null];
}

/** Lit une route du cockpit dans ce processus : « /ventes/stock?shop=4 » → [réponse, ms, code d'erreur]. */
function rsLireCockpit(string $chemin): array
{
    $u = parse_url($chemin);
    $q = []; parse_str((string) ($u['query'] ?? ''), $q);
    $path = (string) ($u['path'] ?? '');
    return rsAppel(static fn () => route('GET', $path), $q);
}

/** Ce qu'une réponse contient, en une ligne : un aperçu pour dire « la donnée est revenue ». */
function rsApercu(mixed $d): string
{
    if (!is_array($d)) { return $d === null ? 'réponse vide' : 'réponse : ' . mb_substr((string) json_encode($d, JSON_UNESCAPED_UNICODE), 0, 80); }
    $L = class_exists('PanelApi') ? PanelApi::liste($d) : (array_is_list($d) ? $d : []);
    if (array_is_list($d) || ($L && count($d) <= 3)) {
        $prem = is_array($L[0] ?? null) ? array_slice(array_keys($L[0]), 0, 6) : [];
        return count($L) . ' élément' . (count($L) > 1 ? 's' : '') . ($prem ? ' · champs : ' . implode(', ', $prem) . (count($L[0]) > 6 ? '…' : '') : '');
    }
    $k = array_keys($d);
    return 'objet · ' . implode(', ', array_slice($k, 0, 8)) . (count($k) > 8 ? '…' : '');
}

/** Un point « auto » pour un magasin et un jour : la donnée est-elle revenue (`revenue`), et que dit-elle (`etat` ok | ko) ? */
function rsEvaluer(array $p, int $shop, string $date): array
{
    $p = rsNormalise($p);
    $sonde = (string) ($p['sonde'] ?? ''); $params = (array) ($p['params'] ?? []);
    $out = ['revenue' => false, 'etat' => 'inconnu', 'texte' => '', 'valeur' => null, 'ms' => null];
    if (!isset(rsSondes()[$sonde])) { $out['texte'] = 'sonde inconnue'; return $out; }
    [$d, $ms, $err] = rsLireCockpit(rsChemin((string) $p['cockpit'], $shop, $date, $params));
    $out['ms'] = $ms;
    $rate = fn (string $t) => $out + ['texte' => $t . ($err ? ' (HTTP ' . $err . ')' : '') . (isset($d['error']) ? ' : ' . $d['error'] : '')];
    switch ($sonde) {
        case 'invendus':
            if ($err || isset($d['error']) || empty($d['lu'])) { return $rate('le panel n’a pas répondu'); }
            $out['revenue'] = true; $out['valeur'] = (int) ($d['pieces'] ?? 0);
            if (!empty($d['declare'])) { $out['etat'] = 'ok'; $out['texte'] = 'déclarés : ' . (int) ($d['pieces'] ?? 0) . ' pièce(s) · ' . number_format((float) ($d['cout'] ?? 0), 2, ',', ' ') . ' € de coût'; }
            else { $out['etat'] = 'ko'; $out['texte'] = 'rien de déclaré au panel pour ce jour'; }
            return $out;
        case 'stock':
            if ($err || !empty($d['indispo']) || isset($d['error']) || !array_key_exists('dernierComptage', $d)) { return $rate((string) ($d['motif'] ?? 'l’inventaire n’a pas pu être lu')); }
            $der = (string) ($d['dernierComptage'] ?? '');
            $out['revenue'] = true;
            if ($der === '') { $out['etat'] = 'ko'; $out['texte'] = 'aucun comptage connu'; return $out; }
            // L'inventaire dit le DERNIER comptage : regardé sur un jour passé, il peut dater d'après ce jour-là.
            $apres = substr($der, 0, 10) > $date;
            $age = $apres ? 0 : (int) floor((strtotime($date . ' 23:59:59') - strtotime($der)) / 86400);
            $out['valeur'] = $age;
            $out['etat'] = $age <= (int) ($params['jours'] ?? 7) ? 'ok' : 'ko';
            $out['texte'] = 'dernier comptage le ' . date('d/m à H:i', strtotime($der)) . ($apres ? ' (après ce jour)' : ($age ? ' — il y a ' . $age . ' jour' . ($age > 1 ? 's' : '') : ' — ce jour-là'));
            return $out;
        case 'commandes':
            $C = $d['commandes'] ?? null;
            if ($err || !is_array($C) || !isset($C['retard'])) { return $rate('les commandes n’ont pas pu être lues'); }
            $n = (int) $C['retard'];
            $out['revenue'] = true; $out['valeur'] = $n;
            $out['etat'] = $n <= (int) ($params['max'] ?? 0) ? 'ok' : 'ko';
            $out['texte'] = $n ? $n . ' commande' . ($n > 1 ? 's' : '') . ' passée' . ($n > 1 ? 's' : '') . ' encore « en cours » au panel' : 'aucune commande passée encore ouverte';
            return $out;
        case 'photos':
        case 'taches':
            $sh = null; foreach ((array) ($d['shops'] ?? []) as $x) { if ((int) ($x['shopId'] ?? 0) === $shop) { $sh = $x; } }
            if ($err || !empty($d['indispo']) || !isset($d['shops']) || (!$sh && !empty($d['api']['erreur']))) { return $rate('les tâches n’ont pas pu être lues' . (!empty($d['api']['erreur']) ? ' : ' . $d['api']['erreur'] : '')); }
            $T = array_values(array_filter((array) ($sh['taches'] ?? []), fn ($t) => ($t['obligatoire'] ?? null) !== false));
            $rendue = fn ($t) => in_array($t['statut'] ?? '', ['notee', 'aControler', 'aValider'], true);
            if ($sonde === 'photos') {
                $P = array_filter($T, fn ($t) => !empty($t['photoRequise']) || ($t['statut'] ?? '') === 'notee');
                $f = count(array_filter($P, $rendue)); $n = count($P); $lib = 'photos rendues';
            } else {
                $f = count(array_filter($T, fn ($t) => $rendue($t) || (($t['statut'] ?? '') === 'sansPhoto' && empty($t['photoRequise'])))); $n = count($T); $lib = 'tâches obligatoires faites';
            }
            $pct = $n ? (int) round(100 * $f / $n) : 100;
            $out['revenue'] = true; $out['valeur'] = $pct;
            $out['etat'] = $pct >= (int) ($params['min'] ?? ($sonde === 'photos' ? 100 : 80)) ? 'ok' : 'ko';
            $out['texte'] = $n ? $f . ' / ' . $n . ' ' . $lib . ' (' . $pct . ' %)' : 'rien de prévu ce jour';
            return $out;
        case 'nc':
            if ($err || !empty($d['indispo']) || isset($d['error']) || !isset($d['nc'])) { return $rate('les notes du contrôleur n’ont pas pu être lues'); }
            $nc = (array) $d['nc'];
            $ouvertes = array_filter($nc, fn ($n) => empty($n['suite']['conforme']));
            $out['revenue'] = true; $out['valeur'] = count($ouvertes);
            $out['etat'] = $ouvertes ? 'ko' : 'ok';
            $out['texte'] = !$nc ? 'aucune non-conformité sur la période (' . (int) ($d['notees'] ?? 0) . ' photos notées)'
                : count($nc) . ' non-conformité(s), ' . (count($nc) - count($ouvertes)) . ' reprise(s)' . ($ouvertes ? ', ' . count($ouvertes) . ' pas encore' : '');
            return $out;
    }
    return $out;
}

function rsSonder(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if ($shop <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { http_response_code(422); return ['error' => 'shop et date (YYYY-MM-DD) sont requis']; }
    $seul = (string) ($_GET['point'] ?? '');
    $res = [];
    foreach (rsConfig()['points'] as $p) {
        if (($p['type'] ?? '') !== 'auto' || ($seul !== '' && $p['id'] !== $seul) || ($seul === '' && empty($p['actif']))) { continue; }
        $res[] = ['id' => $p['id'], 'titre' => $p['titre'], 'sonde' => $p['sonde'],
            'cockpit' => rsChemin((string) $p['cockpit'], $shop, $date, (array) $p['params']),
            'panel' => $p['panel'] !== '' ? rsChemin((string) $p['panel'], $shop, $date, (array) $p['params']) : null] + rsEvaluer($p, $shop, $date);
    }
    return ['shop' => $shop, 'date' => $date, 'le' => date('Y-m-d H:i:s'), 'points' => $res];
}

/**
 * POST /soir/tester { point, shop, date } — le bouton « Tester » d'un point, sur ce qui est SAISI (même pas encore
 * enregistré) : la route du cockpit répond-elle, l'appel au panel répond-il, et que conclut la sonde ? Lecture seule :
 * deux GET.
 */
function rsTester(array $b): array
{
    $p = is_array($b['point'] ?? null) ? $b['point'] : [];
    $shop = (int) ($b['shop'] ?? 0); $date = (string) ($b['date'] ?? '');
    if ($shop <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { http_response_code(422); return ['error' => 'shop et date (YYYY-MM-DD) sont requis']; }
    $p['type'] = 'auto';
    $cockpit = trim((string) ($p['cockpit'] ?? '')); $panel = trim((string) ($p['panel'] ?? ''));
    $out = ['cockpit' => null, 'panel' => null, 'verdict' => null];
    if (!rsCheminValide($cockpit, true) || $cockpit === '') {
        $out['cockpit'] = ['chemin' => $cockpit, 'ok' => false, 'erreur' => 'route invalide : une route relative du cockpit, lue en GET (ex. /ventes/stock?shop={shop})'];
    } else {
        $ch = rsChemin($cockpit, $shop, $date, (array) ($p['params'] ?? []));
        [$d, $ms, $err] = rsLireCockpit($ch);
        $out['cockpit'] = ['chemin' => $ch, 'ok' => !$err && !isset($d['error']), 'code' => $err ?: 200, 'ms' => $ms,
            'erreur' => $err || isset($d['error']) ? (string) ($d['error'] ?? 'HTTP ' . $err) : null, 'apercu' => rsApercu($d)];
        $out['verdict'] = rsEvaluer($p, $shop, $date);
    }
    if ($panel !== '') {
        if (!rsCheminValide($panel, false)) { $out['panel'] = ['chemin' => $panel, 'ok' => false, 'erreur' => 'appel invalide (ex. /shops/{shop}/material-inventory)']; }
        elseif (!PanelApi::configured()) { $out['panel'] = ['chemin' => $panel, 'ok' => false, 'erreur' => 'compte du panel non configuré (Paramètres)']; }
        else {
            $ch = rsChemin($panel, $shop, $date, (array) ($p['params'] ?? []));
            $r = PanelApi::sonde('GET', $ch, null, 20);
            $out['panel'] = ['chemin' => $ch, 'ok' => $r['code'] >= 200 && $r['code'] < 300, 'code' => $r['code'], 'ms' => $r['ms'],
                'erreur' => $r['code'] >= 200 && $r['code'] < 300 ? null : ($r['erreur'] ?: 'HTTP ' . $r['code']), 'apercu' => rsApercu($r['corps'])];
        }
    }
    return $out;
}
