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
        'invendus' => ['titre' => 'Invendus encodés', 'cockpit' => 'GET /exploitation/invendus?shop={shop}&date={date}',
            'panel' => 'GET /shops/{shop}/products/waste', 'quand' => 'le jour même',
            'aide' => 'En ordre quand le magasin a déclaré ses pertes du jour au panel (au moins une ligne, « rien à jeter » compris s’il est déclaré).',
            'params' => []],
        'stock' => ['titre' => 'Stock compté', 'cockpit' => 'GET /ventes/stock?shop={shop}',
            'panel' => 'GET /shops/{shop}/material-inventory', 'quand' => 'le dernier comptage, quel que soit le jour',
            'aide' => 'En ordre quand le dernier comptage de l’inventaire matière date de moins de N jours.',
            'params' => [['k' => 'jours', 'label' => 'compté depuis au plus (jours)', 'defaut' => 7, 'min' => 1, 'max' => 60]]],
        'commandes' => ['titre' => 'Commandes clients clôturées', 'cockpit' => 'GET /ventes/commandes?shop={shop}',
            'panel' => 'GET /shops/{shop}/client-orders', 'quand' => 'l’état actuel',
            'aide' => 'En ordre quand il ne reste pas plus de N commandes dont la date est passée et qui sont encore « en cours » au panel.',
            'params' => [['k' => 'max', 'label' => 'commandes passées encore ouvertes, au plus', 'defaut' => 0, 'min' => 0, 'max' => 100]]],
        'photos' => ['titre' => 'Photos de contrôle rendues', 'cockpit' => 'GET /pwa/tasks?shop={shop}&date={date}',
            'panel' => 'GET /consultant/shops/{shop}/tasks + …/checklists/{id}/progress', 'quand' => 'le jour même',
            'aide' => 'En ordre quand au moins N % des tâches obligatoires qui demandent une photo ont leur photo.',
            'params' => [['k' => 'min', 'label' => 'photos rendues, au moins (%)', 'defaut' => 100, 'min' => 0, 'max' => 100]]],
        'taches' => ['titre' => 'Tâches obligatoires faites', 'cockpit' => 'GET /pwa/tasks?shop={shop}&date={date}',
            'panel' => 'GET /consultant/shops/{shop}/tasks + …/checklists/{id}/progress', 'quand' => 'le jour même',
            'aide' => 'En ordre quand au moins N % des tâches obligatoires du jour sont faites (avec leur photo quand elle est demandée).',
            'params' => [['k' => 'min', 'label' => 'tâches faites, au moins (%)', 'defaut' => 80, 'min' => 0, 'max' => 100]]],
        'nc' => ['titre' => 'Non-conformités reprises', 'cockpit' => 'GET /pwa/tasks/nc?shop={shop}&du=…&au={date}',
            'panel' => 'aucun : les notes du contrôleur (mac_task_review, base partagée)', 'quand' => 'les N derniers jours',
            'aide' => 'En ordre quand chaque photo notée sous le seuil sur la période a été reprise (une note conforme après).',
            'params' => [['k' => 'jours', 'label' => 'période regardée (jours)', 'defaut' => 30, 'min' => 1, 'max' => 90]]],
    ];
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
    return is_array($c) && !empty($c['points']) ? $c + ['majLe' => null, 'majPar' => null] : rsDefaut() + ['majLe' => null, 'majPar' => null];
}

function rsRoute(string $method, string $path): array
{
    if ($method === 'GET' && $path === '/soir/config') {
        $s = rsSondes();
        return ['config' => rsConfig(), 'defaut' => rsDefaut(), 'sondes' => array_map(fn ($k, $v) => ['cle' => $k] + $v, array_keys($s), $s)];
    }
    if ($method === 'PUT' && $path === '/soir/config') { return rsEnregistrer(body()); }
    if ($method === 'GET' && $path === '/soir/sonder') { return rsSonder(); }
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
        $id = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($p['id'] ?? ''))) ?: 'p' . substr(md5($titre . count($points)), 0, 6);
        while (isset($ids[$id])) { $id .= 'x'; }
        $ids[$id] = true;
        $points[] = ['id' => $id, 'actif' => !empty($p['actif']), 'type' => $type, 'sonde' => $sonde, 'params' => $params,
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

/** Une sonde pour un magasin et un jour : la donnée est-elle revenue (`revenue`), et que dit-elle (`etat` ok | ko) ? */
function rsEvaluer(string $sonde, array $params, int $shop, string $date): array
{
    $out = ['revenue' => false, 'etat' => 'inconnu', 'texte' => '', 'valeur' => null];
    switch ($sonde) {
        case 'invendus':
            [$d, $ms, $err] = rsAppel('ep_exploitation_invendus', ['shop' => (string) $shop, 'date' => $date]);
            $out['ms'] = $ms;
            if ($err || isset($d['error']) || empty($d['lu'])) { $out['texte'] = 'le panel n’a pas répondu' . (isset($d['error']) ? ' : ' . $d['error'] : ''); break; }
            $out['revenue'] = true; $out['valeur'] = (int) ($d['pieces'] ?? 0);
            if (!empty($d['declare'])) { $out['etat'] = 'ok'; $out['texte'] = 'déclarés : ' . (int) ($d['pieces'] ?? 0) . ' pièce(s) · ' . number_format((float) ($d['cout'] ?? 0), 2, ',', ' ') . ' € de coût'; }
            else { $out['etat'] = 'ko'; $out['texte'] = 'rien de déclaré au panel pour ce jour'; }
            break;
        case 'stock':
            [$d, $ms, $err] = rsAppel('ep_ventes_stock', ['shop' => (string) $shop]);
            $out['ms'] = $ms;
            if ($err || !empty($d['indispo']) || isset($d['error'])) { $out['texte'] = (string) ($d['motif'] ?? $d['error'] ?? 'le panel n’a pas répondu'); break; }
            $der = (string) ($d['dernierComptage'] ?? '');
            if ($der === '') { $out['revenue'] = true; $out['etat'] = 'ko'; $out['texte'] = 'aucun comptage connu'; break; }
            $age = (int) floor((strtotime($date . ' 23:59:59') - strtotime($der)) / 86400);
            $out['revenue'] = true; $out['valeur'] = $age;
            $out['etat'] = $age <= (int) ($params['jours'] ?? 7) ? 'ok' : 'ko';
            $out['texte'] = 'dernier comptage le ' . date('d/m à H:i', strtotime($der)) . ' — il y a ' . $age . ' jour' . ($age > 1 ? 's' : '');
            break;
        case 'commandes':
            [$d, $ms, $err] = rsAppel('ep_ventes_commandes', ['shop' => (string) $shop]);
            $out['ms'] = $ms;
            $C = $d['commandes'] ?? null;
            if ($err || !is_array($C) || !isset($C['retard'])) { $out['texte'] = 'les commandes n’ont pas pu être lues'; break; }
            $n = (int) $C['retard'];
            $out['revenue'] = true; $out['valeur'] = $n;
            $out['etat'] = $n <= (int) ($params['max'] ?? 0) ? 'ok' : 'ko';
            $out['texte'] = $n ? $n . ' commande' . ($n > 1 ? 's' : '') . ' passée' . ($n > 1 ? 's' : '') . ' encore « en cours » au panel' : 'aucune commande passée encore ouverte';
            break;
        case 'photos':
        case 'taches':
            [$d, $ms, $err] = rsAppel('ep_pwa_tasks', ['shop' => (string) $shop, 'date' => $date]);
            $out['ms'] = $ms;
            $sh = null; foreach ((array) ($d['shops'] ?? []) as $x) { if ((int) ($x['shopId'] ?? 0) === $shop) { $sh = $x; } }
            if ($err || !empty($d['indispo']) || (!$sh && !empty($d['api']['erreur']))) { $out['texte'] = 'les tâches n’ont pas pu être lues' . (!empty($d['api']['erreur']) ? ' : ' . $d['api']['erreur'] : ''); break; }
            $T = array_values(array_filter((array) ($sh['taches'] ?? []), fn ($t) => ($t['obligatoire'] ?? null) !== false));
            $faite = fn ($t) => in_array($t['statut'] ?? '', ['notee', 'aControler', 'aValider'], true) || (($t['statut'] ?? '') === 'sansPhoto' && empty($t['photoRequise']));
            if ($sonde === 'photos') {
                $P = array_filter($T, fn ($t) => !empty($t['photoRequise']) || ($t['statut'] ?? '') === 'notee');
                $f = count(array_filter($P, fn ($t) => in_array($t['statut'] ?? '', ['notee', 'aControler', 'aValider'], true))); $n = count($P);
                $lib = 'photos rendues';
            } else { $f = count(array_filter($T, $faite)); $n = count($T); $lib = 'tâches obligatoires faites'; }
            $pct = $n ? (int) round(100 * $f / $n) : 100;
            $out['revenue'] = true; $out['valeur'] = $pct;
            $out['etat'] = $pct >= (int) ($params['min'] ?? ($sonde === 'photos' ? 100 : 80)) ? 'ok' : 'ko';
            $out['texte'] = $n ? $f . ' / ' . $n . ' ' . $lib . ' (' . $pct . ' %)' : 'rien de prévu ce jour';
            break;
        case 'nc':
            $du = date('Y-m-d', strtotime($date . ' -' . max(0, (int) ($params['jours'] ?? 30) - 1) . ' days'));
            [$d, $ms, $err] = rsAppel('ep_pwa_tasks_nc', ['shop' => (string) $shop, 'du' => $du, 'au' => $date]);
            $out['ms'] = $ms;
            if ($err || !empty($d['indispo']) || isset($d['error'])) { $out['texte'] = 'les notes du contrôleur n’ont pas pu être lues'; break; }
            $nc = (array) ($d['nc'] ?? []);
            $ouvertes = array_filter($nc, fn ($n) => empty($n['suite']['conforme']));
            $out['revenue'] = true; $out['valeur'] = count($ouvertes);
            $out['etat'] = $ouvertes ? 'ko' : 'ok';
            $out['texte'] = !$nc ? 'aucune non-conformité sur la période (' . (int) ($d['notees'] ?? 0) . ' photos notées)'
                : count($nc) . ' non-conformité(s), ' . (count($nc) - count($ouvertes)) . ' reprise(s)' . ($ouvertes ? ', ' . count($ouvertes) . ' pas encore' : '');
            break;
        default:
            $out['texte'] = 'sonde inconnue';
    }
    return $out;
}

function rsSonder(): array
{
    $shop = (int) ($_GET['shop'] ?? 0);
    $date = (string) ($_GET['date'] ?? date('Y-m-d'));
    if ($shop <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { http_response_code(422); return ['error' => 'shop et date (YYYY-MM-DD) sont requis']; }
    $seul = (string) ($_GET['point'] ?? '');
    $S = rsSondes();
    $res = [];
    foreach (rsConfig()['points'] as $p) {
        if (($p['type'] ?? '') !== 'auto' || ($seul !== '' && $p['id'] !== $seul) || ($seul === '' && empty($p['actif']))) { continue; }
        $s = $S[$p['sonde']] ?? null;
        $e = $s ? rsEvaluer($p['sonde'], (array) ($p['params'] ?? []), $shop, $date) : ['revenue' => false, 'etat' => 'inconnu', 'texte' => 'sonde inconnue'];
        $res[] = ['id' => $p['id'], 'titre' => $p['titre'], 'sonde' => $p['sonde'],
            'cockpit' => $s ? str_replace(['{shop}', '{date}'], [(string) $shop, $date], $s['cockpit']) : null,
            'panel' => $s ? str_replace('{shop}', (string) $shop, $s['panel']) : null] + $e;
    }
    return ['shop' => $shop, 'date' => $date, 'le' => date('Y-m-d H:i:s'), 'points' => $res];
}
