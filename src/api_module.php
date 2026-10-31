<?php

declare(strict_types=1);

/**
 * « API du module » (24/10/2026, Sam : « un sous-menu dans le rail qui indique quelles API sont utilisées pour le
 * module entier », validé avec l'état en direct) — tout ce que le module des primes et l'application employé
 * appellent, source par source : le cockpit, le panel tfbuddy, l'ERP admin, la base partagée, Twilio. Pour chaque
 * route : la méthode, le chemin, ce qu'elle fait, les pages qui s'en servent, ce que le cockpit en garde, et ce
 * qu'elle rend à l'instant. Maquette : docs/maquettes/api-module.
 *
 * Les sondes ne font que lire : des GET (les écritures sont listées, jamais appelées), un SELECT d'une ligne par
 * table, l'âge des calculs gardés (mot_cache.maj). Le résultat est gardé une minute (mot_cache apiModule) ; ?frais=1
 * resonde. Aucun identifiant ne sort : les chemins restent écrits avec {id}, {date}.
 *
 * L'inventaire est tenu à la main, comme le code qu'il décrit : bin/tests_primes.php (section 20) vérifie que chaque
 * route du cockpit existe dans public/api/index.php et que chaque appel du panel fait par l'application y figure.
 */

const API_MOD_CACHE = 60;        // une minute : la page se rouvre sans resonder
const API_MOD_DELAI = 8;         // secondes accordées à chaque sonde
const API_MOD_LENT = 3000;       // au-delà de 3 s, une route qui répond est dite lente

/** Les pages du module, telles que la page les nomme. */
const API_MOD_PAGES = ['admin' => 'Page admin', 'franchise' => 'Page franchisé', 'app' => 'Application'];

/**
 * L'inventaire : les sources, leurs groupes, leurs routes. Chaque route : m (méthode, ou LIT / ÉCRIT pour une table),
 * r (le chemin), dit, pages, garde (ce que le cockpit en garde), sonde (comment la mesurer ; absente : écriture,
 * jamais appelée ; « pas » : lecture qu'on ne peut pas sonder, et pourquoi).
 */
function apiModInventaire(): array
{
    $AF = ['admin', 'franchise']; $AFE = ['admin', 'franchise', 'app'];
    $perso = static fn (string $p): array => ['panel' => $p, 'acces' => 'personne'];
    return [
        ['cle' => 'cockpit', 'nom' => 'Cockpit', 'hote' => '…/api/cockpit', 'unite' => 'route',
            'resume' => 'calcule les primes, garde les calculs',
            'dit' => 'Notre API, sur le même serveur. Les pages ne lisent qu’elle ; elle seule parle aux sources.',
            'groupes' => [
                ['nom' => 'Lectures', 'routes' => [
                    ['m' => 'GET', 'r' => '/ventes/primes-reglages', 'dit' => 'Les primes posées (marque, sponsors, franchisés), la récompense, le SMS', 'pages' => $AF, 'garde' => '', 'sonde' => ['reglage' => 'ventePrimesApp']],
                    ['m' => 'GET', 'r' => '/ventes/primes-fiches/equipe?shop=', 'dit' => 'Les fiches à imprimer, et qui recevra l’affiche par mail (adresse masquée)', 'pages' => ['franchise'], 'garde' => ''],
                    ['m' => 'GET', 'r' => '/ventes/primes-equipe?shop=&m=', 'dit' => 'Qui a quelle prime, à quel niveau, dans un magasin et un mois', 'pages' => ['franchise'], 'garde' => '10 min · 1 j', 'sonde' => ['cache' => 'primesEq']],
                    ['m' => 'GET', 'r' => '/ventes/primes-annee?y=[&shop=]', 'dit' => 'L’année des primes, par magasin ou par vendeuse', 'pages' => $AF, 'garde' => '1 h · 1 j', 'sonde' => ['cache' => 'primesAn']],
                    ['m' => 'GET', 'r' => '/ventes/primes-moyennes?n=', 'dit' => 'Les moyennes du réseau, pour caler un objectif', 'pages' => ['admin'], 'garde' => '1 j', 'sonde' => ['cache' => 'prMoy']],
                    ['m' => 'GET', 'r' => '/ventes/primes-promos', 'dit' => 'Les promotions actives de l’ERP, prêtes à devenir des primes', 'pages' => ['admin'], 'garde' => '1 h', 'sonde' => ['cache' => 'primesPromos3']],
                    ['m' => 'GET', 'r' => '/ventes/primes-catalogue', 'dit' => 'Les produits et catégories, pour cibler une prime', 'pages' => $AF, 'garde' => '1 j', 'sonde' => ['cache' => 'primesCatalogue']],
                    ['m' => 'GET', 'r' => '/ventes/primes-reference?ids=&du=&au=&shops=', 'dit' => 'Ce que des produits ont vendu, pour proposer un objectif', 'pages' => $AF, 'garde' => '', 'sonde' => ['pas' => 'calculée à la demande, rien n’en est gardé']],
                    ['m' => 'GET', 'r' => '/ventes/grands-prix[?shop=]', 'dit' => 'Les grands prix et leur classement', 'pages' => $AF, 'garde' => '1 h', 'sonde' => ['cache' => 'grandsPrix']],
                    ['m' => 'GET', 'r' => '/ventes/moi?m=', 'dit' => 'La fiche « Mes primes » de la personne connectée (son jeton, vérifié chez le panel)', 'pages' => ['app'], 'garde' => '15 min · 1 j', 'sonde' => ['cache' => 'primesMoi']],
                    ['m' => 'GET', 'r' => '/ventes/moi/planning?du=&au=', 'dit' => 'Le planning de la personne connectée, lu à la route du planning du panel (ses services seuls)', 'pages' => ['app'], 'garde' => '5 min', 'sonde' => ['cache' => 'planShop']],
                    ['m' => 'GET', 'r' => '/ventes/moi/profil?panel=', 'dit' => '« Mon profil » de la personne connectée, déchiffré pour elle seule', 'pages' => ['app'], 'garde' => '', 'sonde' => ['pas' => 'il faut le jeton de la personne']],
                    ['m' => 'GET', 'r' => '/ventes/equipe/profils?shop=', 'dit' => 'Les fiches d’un magasin : rempli à combien, ce qui manque, ce qui est à reporter au panel', 'pages' => ['franchise'], 'garde' => '', 'sonde' => ['pas' => 'lue à la demande dans mot_profil']],
                    ['m' => 'GET', 'r' => '/ventes/equipe/profil?emp=[&voir=1]', 'dit' => 'Une fiche, n° national et IBAN masqués (en clair : au journal)', 'pages' => ['franchise'], 'garde' => '', 'sonde' => ['pas' => 'une fiche ouverte se lit pour une personne']],
                    ['m' => 'GET', 'r' => '/ventes/equipe/profil/carte?emp=&face=', 'dit' => 'Une photo de la carte d’identité, déchiffrée (au journal)', 'pages' => ['franchise'], 'garde' => '', 'sonde' => ['pas' => 'l’ouvrir s’écrit au journal']],
                ]],
                ['nom' => 'Écritures', 'routes' => [
                    ['m' => 'POST', 'r' => '/ventes/primes-app', 'dit' => 'Enregistre les primes, la récompense, les primes externes, le SMS', 'pages' => $AF],
                    ['m' => 'POST', 'r' => '/ventes/primes-fiches/envoyer', 'dit' => 'Envoie l’affiche de l’app (FR, NL) à chaque personne cochée de l’équipe', 'pages' => ['franchise']],
                    ['m' => 'POST', 'r' => '/ventes/primes-icone', 'dit' => 'Dépose l’icône d’une prime ou le logo d’un sponsor', 'pages' => $AF],
                    ['m' => 'POST', 'r' => '/ventes/croisees', 'dit' => 'La règle des ventes croisées (cible, tickets minimum)', 'pages' => ['admin']],
                    ['m' => 'POST', 'r' => '/ventes/grands-prix', 'dit' => 'Crée, modifie, allume ou éteint un grand prix', 'pages' => ['admin']],
                    ['m' => 'POST', 'r' => '/ventes/grands-prix/retirer', 'dit' => 'Retire un grand prix', 'pages' => ['admin']],
                    ['m' => 'POST', 'r' => '/ventes/moi/profil', 'dit' => 'Enregistre une fiche de « Mon profil » (contrôlée, chiffrée)', 'pages' => ['app']],
                    ['m' => 'POST', 'r' => '/ventes/moi/profil/carte', 'dit' => 'Dépose une photo de la carte d’identité (chiffrée)', 'pages' => ['app']],
                ]],
            ]],
        ['cle' => 'panel', 'nom' => 'Panel tfbuddy', 'hote' => 'atelierby.tfbuddy.com/api/v1', 'unite' => 'route',
            'resume' => 'ventes, tickets, personnes, planning, tâches',
            'dit' => 'La caisse et les personnes. Le cockpit le lit avec le compte consultant ; l’application, avec le jeton de la personne.',
            'groupes' => [
                ['nom' => 'Lu par le cockpit · compte consultant', 'routes' => [
                    ['m' => 'GET', 'r' => '/shops/{id}/statistics/sales/employees/{date}', 'dit' => 'CA et tickets par vendeuse, jour par jour', 'pages' => $AFE, 'garde' => '1 h · gravé', 'sonde' => ['panel' => '/shops/{sid}/statistics/sales/employees/{date}']],
                    ['m' => 'GET', 'r' => '/shops/{id}/statistics/sales/hourly-distribution/{date}', 'dit' => 'Les ventes par créneau (la difficulté du coefficient)', 'pages' => $AFE, 'garde' => '1 h · gravé', 'sonde' => ['panel' => '/shops/{sid}/statistics/sales/hourly-distribution/{date}']],
                    ['m' => 'GET', 'r' => '/shops/{id}/statistics/sales/kpis?date_from=&date_to=', 'dit' => 'Panier moyen, articles par ticket du mois', 'pages' => $AFE, 'garde' => '1 h', 'sonde' => ['panel' => '/shops/{sid}/statistics/sales/kpis?date_from={m1}&date_to={date}']],
                    ['m' => 'GET', 'r' => '/shops/{id}/transactions?date=', 'dit' => 'Les tickets (record, ventes croisées, catégories), moissonnés puis gardés', 'pages' => $AFE, 'garde' => '10 min · gravé', 'sonde' => ['panel' => '/shops/{sid}/transactions?date={date}']],
                    ['m' => 'GET', 'r' => '/transactions/{id}?include=products', 'dit' => 'Les lignes d’un ticket', 'pages' => $AFE, 'garde' => '10 min · gravé', 'sonde' => ['panel' => '/transactions/{tid}?include=products']],
                    ['m' => 'GET', 'r' => '/employees/{id}', 'dit' => 'Confirme le jeton d’une personne avant de rendre sa fiche', 'pages' => ['app'], 'garde' => '10 min', 'sonde' => $perso('/employees/{emp}')],
                    ['m' => 'GET', 'r' => '/shops/{id}/schedule', 'dit' => 'Le planning du magasin : la synchro horaire en remplit la table locale, l’app y lit ses services par le cockpit', 'pages' => $AFE, 'garde' => '5 min · 1 h', 'sonde' => ['pas' => 'rend tout l’historique du magasin, trop lourd pour une sonde']],
                    ['m' => 'GET', 'r' => '/consultant/shops', 'dit' => 'Les magasins du compte', 'pages' => $AF, 'garde' => '1 j', 'sonde' => ['panel' => '/consultant/shops']],
                    ['m' => 'GET', 'r' => '/product/categories', 'dit' => 'Les catégories, pour cibler une prime', 'pages' => $AF, 'garde' => '1 j', 'sonde' => ['panel' => ['/product/categories', '/product-categories']]],
                    ['m' => 'GET', 'r' => '/product/category-groups', 'dit' => 'Les groupes de catégories', 'pages' => $AF, 'garde' => '1 j', 'sonde' => ['panel' => ['/product/category-groups', '/product-category-groups']]],
                ]],
                ['nom' => 'Lu par l’application · jeton de la personne', 'routes' => [
                    ['m' => 'POST', 'r' => '/employees/authenticate', 'dit' => 'La connexion', 'pages' => ['app']],
                    ['m' => 'POST', 'r' => '/employees/refresh', 'dit' => 'La session renouvelée', 'pages' => ['app']],
                    ['m' => 'GET', 'r' => '/employees/{id}', 'dit' => 'Le profil', 'pages' => ['app'], 'garde' => '', 'sonde' => $perso('/employees/{emp}')],
                    ['m' => 'GET', 'r' => '/employees/{id}/tasks', 'dit' => 'Les tâches du jour', 'pages' => ['app'], 'garde' => '', 'sonde' => $perso('/employees/{emp}/tasks')],
                    ['m' => 'GET', 'r' => '/tasks/completions/{id}', 'dit' => 'Une tâche faite', 'pages' => ['app'], 'garde' => '', 'sonde' => ['pas' => 'il faut une tâche faite à relire']],
                    ['m' => 'POST', 'r' => '/employees/{id}/tasks/{task}/mark-as-done', 'dit' => 'Marque une tâche faite (avec ses photos)', 'pages' => ['app']],
                    ['m' => 'PATCH', 'r' => '/employees/{id}', 'dit' => 'Le PIN de caisse, changé par la personne dans « Mon profil » (le seul champ envoyé)', 'pages' => ['app']],
                    ['m' => 'GET', 'r' => '/shops/{id}', 'dit' => 'Le magasin de la personne', 'pages' => ['app'], 'garde' => '', 'sonde' => $perso('/shops/{sid}')],
                    ['m' => 'GET', 'r' => '/attachments/{id}/meta', 'dit' => 'Une photo de tâche', 'pages' => ['app'], 'garde' => '', 'sonde' => ['pas' => 'il faut une photo à relire']],
                    ['m' => 'GET', 'r' => '/attachments/{id}/presigned-url', 'dit' => 'Le lien d’une photo', 'pages' => ['app'], 'garde' => '', 'sonde' => ['pas' => 'il faut une photo à relire']],
                ]],
            ]],
        ['cle' => 'erp', 'nom' => 'ERP admin', 'hote' => 'atelierby.tfbuddy.com/api/v1/admin', 'unite' => 'route',
            'resume' => 'les promotions actives, à transformer en primes',
            'dit' => 'Le compte admin ERP (Paramètres → Compte admin ERP). Sans lui, les promotions ne se lisent pas : le compte consultant reçoit 403.',
            'groupes' => [
                ['nom' => 'Lectures', 'routes' => [
                    ['m' => 'GET', 'r' => '/admin/promotions/bundles', 'dit' => 'Les menus', 'pages' => ['admin'], 'garde' => '1 h', 'sonde' => ['erp' => '/admin/promotions/bundles?status=ACTIVE&limit=1']],
                    ['m' => 'GET', 'r' => '/admin/promotions/buy-x-get-y', 'dit' => 'Les « X achetés, Y offerts »', 'pages' => ['admin'], 'garde' => '1 h', 'sonde' => ['erp' => '/admin/promotions/buy-x-get-y?status=ACTIVE&limit=1']],
                    ['m' => 'GET', 'r' => '/admin/promotions/quantity', 'dit' => 'Les remises à la quantité', 'pages' => ['admin'], 'garde' => '1 h', 'sonde' => ['erp' => '/admin/promotions/quantity?status=ACTIVE&limit=1']],
                    ['m' => 'GET', 'r' => '/admin/promotions/scheduled-product-discount', 'dit' => 'Les remises programmées', 'pages' => ['admin'], 'garde' => '1 h', 'sonde' => ['erp' => '/admin/promotions/scheduled-product-discount?status=ACTIVE&limit=1']],
                ]],
            ]],
        ['cle' => 'base', 'nom' => 'Base partagée', 'hote' => 'MySQL du serveur', 'unite' => 'table',
            'resume' => 'heures, clients pro, note Google, réglages',
            'dit' => 'Lue directement, sans passer par une API.',
            'groupes' => [
                ['nom' => 'Lues', 'routes' => [
                    ['m' => 'LIT', 'r' => 'franchisee_employee_schedule', 'dit' => 'Les heures du planning (condition d’heures des primes)', 'pages' => $AFE, 'garde' => '', 'sonde' => ['table' => ['franchisee_employee_schedule']]],
                    ['m' => 'LIT', 'r' => 'franchisee_employee', 'dit' => 'Les personnes, leur téléphone', 'pages' => $AF, 'garde' => '', 'sonde' => ['table' => ['franchisee_employee']]],
                    ['m' => 'LIT', 'r' => 'transaction ⨝ client', 'dit' => 'Les commandes pro (prime B2B), le plus gros ticket (grands prix)', 'pages' => $AFE, 'garde' => '15 min · 1 j', 'sonde' => ['table' => ['transaction', 'client']]],
                    ['m' => 'LIT', 'r' => 'mar_avis_fiche', 'dit' => 'La note Google du magasin (tenue par Réputation)', 'pages' => $AFE, 'garde' => '', 'sonde' => ['table' => ['mar_avis_fiche']]],
                    ['m' => 'LIT', 'r' => 'ceo_shop_month_perf', 'dit' => 'L’objectif de CA du mois', 'pages' => $AFE, 'garde' => '', 'sonde' => ['table' => ['ceo_shop_month_perf']]],
                    ['m' => 'LIT', 'r' => 'shops', 'dit' => 'Les magasins actifs', 'pages' => $AF, 'garde' => '', 'sonde' => ['table' => ['shops']]],
                ]],
                ['nom' => 'Tenues par le module', 'routes' => [
                    ['m' => 'ÉCRIT', 'r' => 'mot_reglage', 'dit' => 'Les réglages des primes, les SMS déjà envoyés', 'pages' => $AF, 'garde' => '', 'sonde' => ['table' => ['mot_reglage']]],
                    ['m' => 'ÉCRIT', 'r' => 'mot_cache', 'dit' => 'Les calculs gardés (fiches, mois, équipe, année)', 'pages' => $AFE, 'garde' => '', 'sonde' => ['table' => ['mot_cache']]],
                    ['m' => 'ÉCRIT', 'r' => 'mot_grand_prix', 'dit' => 'Les grands prix', 'pages' => ['admin'], 'garde' => '', 'sonde' => ['table' => ['mot_grand_prix']]],
                    ['m' => 'ÉCRIT', 'r' => 'mot_profil', 'dit' => 'Les profils des employés, chiffrés (la clé et les photos de la carte hors du site)', 'pages' => ['franchise', 'app'], 'garde' => '', 'sonde' => ['table' => ['mot_profil']]],
                    ['m' => 'ÉCRIT', 'r' => 'ceo_app_setting', 'dit' => 'Les mois de ventes gravés (un mois clos ne se relit plus)', 'pages' => $AFE, 'garde' => '', 'sonde' => ['table' => ['ceo_app_setting']]],
                ]],
            ]],
        ['cle' => 'sms', 'nom' => 'SMS · Twilio', 'hote' => 'api.twilio.com', 'unite' => 'route',
            'resume' => 'le SMS « prime gagnée »',
            'dit' => 'Réglé dans config.php › sms. Le numéro : la fiche locale (franchisee_employee.phone), sinon celle du panel.',
            'groupes' => [
                ['nom' => 'Écriture', 'routes' => [
                    ['m' => 'POST', 'r' => '/2010-04-01/Accounts/{sid}/Messages.json', 'dit' => '« Bravo, prime gagnée » : un SMS par personne et par mois', 'pages' => ['admin'], 'garde' => '', 'sonde' => ['twilio' => true]],
                ]],
            ]],
    ];
}

/** L'identifiant d'une route dans l'inventaire : sa source, sa méthode, son chemin. */
function apiModId(string $source, array $route): string
{
    return $source . ' ' . $route['m'] . ' ' . $route['r'];
}

/**
 * Le chemin d'une sonde, ses repères remplacés : {sid} un magasin, {emp} une personne au planning, {tid} un ticket du
 * jour, {date} aujourd'hui, {m1} le 1er du mois. Null si un repère manque.
 */
function apiModChemin(string $modele, array $ctx): ?string
{
    $out = preg_replace_callback('/\{(sid|emp|tid|date|m1)\}/', static function ($m) use ($ctx) {
        $v = $ctx[$m[1]] ?? null;
        return $v === null || $v === '' ? '{?}' : rawurlencode((string) $v);
    }, $modele);
    return str_contains((string) $out, '{?}') ? null : $out;
}

/**
 * L'état d'une route, prêt à montrer : {niveau, texte}. Niveaux : ok, lent, neutre (rien d'anormal : pas de calcul
 * gardé, réservé à la personne), alerte (à régler), erreur, ecriture (jamais appelée), pas (lecture non sondée).
 */
function apiModEtat(?array $route, ?array $mes): array
{
    $sonde = $route['sonde'] ?? null;
    if ($sonde === null) { return ['niveau' => 'ecriture', 'texte' => 'écriture, pas testée']; }
    if (isset($sonde['pas'])) { return ['niveau' => 'pas', 'texte' => 'pas sondée : ' . $sonde['pas']]; }
    if ($mes === null) { return ['niveau' => 'neutre', 'texte' => 'pas mesurée']; }
    $ms = static fn (int $v): string => $v >= 1000 ? str_replace('.', ',', (string) round($v / 1000, 1)) . ' s' : $v . ' ms';
    $age = static function (?string $maj): string {
        $t = $maj !== null ? strtotime($maj) : false;
        if (!$t) { return ''; }
        $min = (int) floor(max(0, time() - $t) / 60);
        return $min < 1 ? 'à l’instant' : ($min < 60 ? 'il y a ' . $min . ' min' : ($min < 1440 ? 'il y a ' . intdiv($min, 60) . ' h' : 'le ' . date('d/m', $t)));
    };
    switch ($mes['type'] ?? '') {
        case 'cache':
            return (int) ($mes['n'] ?? 0) > 0
                ? ['niveau' => 'ok', 'texte' => 'calculé ' . $age($mes['maj'] ?? null) . ' · ' . (int) $mes['n'] . ' en mémoire']
                : ['niveau' => 'neutre', 'texte' => 'rien en mémoire : calculé à la prochaine ouverture'];
        case 'reglage':
            return !empty($mes['maj'])
                ? ['niveau' => 'ok', 'texte' => 'enregistrés ' . $age($mes['maj'])]
                : ['niveau' => 'neutre', 'texte' => 'jamais enregistrés : les valeurs par défaut'];
        case 'table':
            if (!empty($mes['erreur'])) { return ['niveau' => 'erreur', 'texte' => 'illisible : ' . $mes['erreur']]; }
            $l = $mes['lignes'] ?? null;
            return ['niveau' => 'ok', 'texte' => 'lisible' . ($l !== null ? ' · ~' . number_format((int) $l, 0, ',', ' ') . ' lignes' : '') . ' · ' . $ms((int) ($mes['ms'] ?? 0))];
        case 'manque':
            return ['niveau' => $mes['niveau'] ?? 'alerte', 'texte' => (string) ($mes['texte'] ?? 'non réglé')];
        case 'http':
            $code = (int) ($mes['code'] ?? 0);
            $t = $ms((int) ($mes['ms'] ?? 0));
            $via = !empty($mes['via']) ? ' (via ' . $mes['via'] . ')' : '';
            if ($code >= 200 && $code < 300) {
                return (int) ($mes['ms'] ?? 0) > API_MOD_LENT ? ['niveau' => 'lent', 'texte' => 'lente · ' . $t . $via] : ['niveau' => 'ok', 'texte' => 'répond · ' . $t . $via];
            }
            if ($code === 0) { return ['niveau' => 'erreur', 'texte' => 'injoignable' . (!empty($mes['erreur']) ? ' : ' . $mes['erreur'] : '')]; }
            if (($code === 401 || $code === 403) && ($sonde['acces'] ?? '') === 'personne') {
                return ['niveau' => 'neutre', 'texte' => 'répond · réservée au jeton de la personne (HTTP ' . $code . ')'];
            }
            if ($code === 401 || $code === 403) { return ['niveau' => 'erreur', 'texte' => 'refusée (HTTP ' . $code . ')']; }
            if ($code === 404) { return ['niveau' => 'erreur', 'texte' => 'introuvable (HTTP 404)']; }
            if ($code >= 500) { return ['niveau' => 'erreur', 'texte' => 'en panne (HTTP ' . $code . ')']; }
            return ['niveau' => 'alerte', 'texte' => 'HTTP ' . $code . ' · ' . $t];
    }
    return ['niveau' => 'neutre', 'texte' => 'pas mesurée'];
}

/**
 * L'inventaire et les mesures réunis : chaque route reçoit son état, chaque source le décompte de ses états et le
 * pire d'entre eux (erreur, puis alerte, puis lent ; ok sinon). Pur : les mesures sont fournies, clé = apiModId().
 */
function apiModuleVue(array $inv, array $mesures, array $extra = []): array
{
    $rang = ['erreur' => 3, 'alerte' => 2, 'lent' => 1, 'ok' => 0];
    $sources = [];
    foreach ($inv as $s) {
        $compte = ['ok' => 0, 'lent' => 0, 'neutre' => 0, 'alerte' => 0, 'erreur' => 0, 'ecriture' => 0, 'pas' => 0];
        $n = 0;
        $groupes = [];
        foreach ($s['groupes'] as $g) {
            $routes = [];
            foreach ($g['routes'] as $r) {
                $id = apiModId($s['cle'], $r);
                $etat = apiModEtat($r, $mesures[$id] ?? null);
                $compte[$etat['niveau']]++;
                $n++;
                $routes[] = ['id' => $id, 'm' => $r['m'], 'r' => $r['r'], 'dit' => $r['dit'], 'pages' => $r['pages'],
                    'garde' => (string) ($r['garde'] ?? ''), 'etat' => $etat];
            }
            $groupes[] = ['nom' => $g['nom'], 'routes' => $routes];
        }
        $pire = 'ok';
        foreach ($rang as $niv => $v) { if ($compte[$niv] > 0 && $v > $rang[$pire]) { $pire = $niv; } }
        $sources[] = ['cle' => $s['cle'], 'nom' => $s['nom'], 'hote' => $s['hote'], 'unite' => $s['unite'], 'resume' => $s['resume'],
            'dit' => $s['dit'], 'n' => $n, 'etat' => $pire, 'compte' => $compte, 'note' => (string) ($extra['notes'][$s['cle']] ?? ''),
            'groupes' => $groupes];
    }
    return ['pages' => API_MOD_PAGES, 'sources' => $sources];
}

/** Le magasin, la personne et le jour que les sondes prennent pour exemple (jamais montrés). */
function apiModContexte(): array
{
    $ctx = ['sid' => null, 'emp' => null, 'tid' => null, 'date' => date('Y-m-d'), 'm1' => date('Y-m-01')];
    try {
        $r = Db::row('SELECT id_employee FROM franchisee_employee_schedule WHERE work_date <= ? ORDER BY work_date DESC LIMIT 1', [date('Y-m-d')]);
        if ($r !== null) { $ctx['emp'] = (int) $r['id_employee']; }
    } catch (Throwable $e) { /* pas de planning : les routes d'une personne ne se sondent pas */ }
    try {
        if ($ctx['emp'] !== null && function_exists('venteEmployes')) { $ctx['sid'] = (string) (venteEmployes()[$ctx['emp']]['shop'] ?? '') ?: null; }
        if ($ctx['sid'] === null) {
            $r = Db::row('SELECT id FROM shops WHERE active = 1 ORDER BY id LIMIT 1');
            if ($r !== null) { $ctx['sid'] = (string) $r['id']; }
        }
    } catch (Throwable $e) { /* pas de magasin : les routes d'un magasin ne se sondent pas */ }
    return $ctx;
}

/**
 * Des GET menés de front (quatre à la fois), chronométrés. $req : [clé => [url, en-têtes]]. Rend [clé => {code, ms,
 * erreur, corps}]. Rien n'est écrit.
 */
function apiModGet(array $req, int $delai = API_MOD_DELAI): array
{
    $out = [];
    if ($req === []) { return $out; }
    $multi = curl_multi_init();
    $file = array_keys($req); $cle = []; $actifs = 0;
    $lancer = static function () use (&$file, &$cle, &$actifs, $multi, $req, $delai): void {
        $k = array_shift($file);
        $ch = curl_init($req[$k][0]);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $delai, CURLOPT_CONNECTTIMEOUT => min(5, $delai),
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $req[$k][1])]);
        curl_multi_add_handle($multi, $ch);
        $cle[spl_object_id($ch)] = $k; $actifs++;
    };
    while ($actifs < 4 && $file) { $lancer(); }
    do {
        $st = curl_multi_exec($multi, $en);
        while (($info = curl_multi_info_read($multi)) !== false) {
            $ch = $info['handle']; $k = $cle[spl_object_id($ch)];
            $raw = curl_multi_getcontent($ch);
            $out[$k] = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'ms' => (int) round(1000 * (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME)),
                'erreur' => $info['result'] !== CURLE_OK ? curl_strerror($info['result']) : null, 'corps' => is_string($raw) ? json_decode($raw, true) : null];
            curl_multi_remove_handle($multi, $ch); curl_close($ch); $actifs--;
            if ($file) { $lancer(); }
        }
        if ($actifs > 0 && curl_multi_select($multi, 1.0) === -1) { usleep(2000); }
    } while ($actifs > 0 && $st === CURLM_OK);
    curl_multi_close($multi);
    return $out;
}

/** Le premier ticket d'une liste de tickets du panel (pour sonder la lecture d'un ticket), ou null. */
function apiModPremierTicket(mixed $corps): ?int
{
    $l = is_array($corps) ? ($corps['data'] ?? $corps) : [];
    if (is_array($l) && isset($l['data']) && is_array($l['data'])) { $l = $l['data']; }
    foreach (is_array($l) ? $l : [] as $t) {
        $id = is_array($t) ? (int) ($t['id'] ?? $t['id_transaction'] ?? 0) : 0;
        if ($id > 0) { return $id; }
    }
    return null;
}

/** Les mesures de toutes les routes de l'inventaire : [apiModId => mesure], plus des notes par source. */
function apiModMesurer(array $inv): array
{
    $mes = []; $notes = [];
    $ctx = apiModContexte();
    $routes = [];
    foreach ($inv as $s) { foreach ($s['groupes'] as $g) { foreach ($g['routes'] as $r) { if (isset($r['sonde'])) { $routes[] = [$s['cle'], $r]; } } } }

    // Le cockpit : l'âge des calculs gardés, la date des réglages (les tables mot_ créées au besoin).
    if (function_exists('motTables')) { try { motTables(); } catch (Throwable $e) { /* la lecture dira l'erreur */ } }
    if (function_exists('profilTable')) { try { profilTable(); } catch (Throwable $e) { /* la lecture dira l'erreur */ } }
    foreach ($routes as [$src, $r]) {
        $id = apiModId($src, $r);
        try {
            if (isset($r['sonde']['cache'])) {
                $x = Db::row('SELECT COUNT(*) n, MAX(maj) maj FROM mot_cache WHERE cle LIKE ?', [$r['sonde']['cache'] . '%']);
                $mes[$id] = ['type' => 'cache', 'n' => (int) ($x['n'] ?? 0), 'maj' => $x['maj'] ?? null];
            } elseif (isset($r['sonde']['reglage'])) {
                $x = Db::row('SELECT maj FROM mot_reglage WHERE cle = ?', [$r['sonde']['reglage']]);
                $mes[$id] = ['type' => 'reglage', 'maj' => $x['maj'] ?? null];
            }
        } catch (Throwable $e) { $mes[$id] = ['type' => 'manque', 'niveau' => 'erreur', 'texte' => 'illisible : ' . $e->getMessage()]; }
    }

    // La base : une ligne lue par table, le nombre de lignes estimé par MySQL.
    $lignes = [];
    try {
        foreach (Db::rows('SELECT TABLE_NAME t, TABLE_ROWS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $x) { $lignes[(string) $x['t']] = $x['n'] !== null ? (int) $x['n'] : null; }
    } catch (Throwable $e) { /* sans information_schema : pas de décompte */ }
    foreach ($routes as [$src, $r]) {
        if (!isset($r['sonde']['table'])) { continue; }
        $t0 = microtime(true); $err = null;
        foreach ($r['sonde']['table'] as $t) {
            try { Db::row('SELECT 1 FROM `' . str_replace('`', '', $t) . '` LIMIT 1'); } catch (Throwable $e) { $err = $e->getMessage(); break; }
        }
        $mes[apiModId($src, $r)] = ['type' => 'table', 'ms' => (int) round(1000 * (microtime(true) - $t0)), 'erreur' => $err,
            'lignes' => $lignes[$r['sonde']['table'][0]] ?? null];
    }

    // Le panel, l'ERP admin et Twilio : des GET menés de front.
    $req = []; $pour = [];
    $jPanel = class_exists('PanelApi') && PanelApi::configured() ? PanelApi::jetonSonde() : null;
    $jErp = class_exists('ErpApi') && ErpApi::disponible() ? ErpApi::jetonSonde() : null;
    if ($jPanel === null) { $notes['panel'] = class_exists('PanelApi') && PanelApi::configured() ? 'Le compte consultant ne se connecte pas : ' . (PanelApi::$lastError ?? 'connexion refusée') . '.' : 'Le compte consultant n’est pas réglé (Paramètres).'; }
    if ($jErp === null) { $notes['erp'] = 'Le compte admin ERP n’est pas réglé (Paramètres → Compte admin ERP) : les promotions ne se lisent pas.'; }
    $variantes = [];
    foreach ($routes as [$src, $r]) {
        $id = apiModId($src, $r);
        $s = $r['sonde'];
        if (isset($s['panel'])) {
            if ($jPanel === null) { $mes[$id] = ['type' => 'manque', 'niveau' => 'erreur', 'texte' => 'compte consultant indisponible']; continue; }
            $mods = (array) $s['panel'];
            $chemin = apiModChemin($mods[0], $ctx);
            if (str_contains($mods[0], '{tid}')) { continue; }          // au second tour, avec un ticket du jour
            if ($chemin === null) { $mes[$id] = ['type' => 'manque', 'niveau' => 'neutre', 'texte' => 'pas d’exemple à sonder (aucun magasin ou personne au planning)']; continue; }
            $req['p:' . $chemin] = [PanelApi::config()['base'] . $chemin, ['Authorization: Bearer ' . $jPanel]];
            $pour[$id] = 'p:' . $chemin;
            if (count($mods) > 1) { $variantes[$id] = $mods; }
        } elseif (isset($s['erp'])) {
            if ($jErp === null) { $mes[$id] = ['type' => 'manque', 'niveau' => 'alerte', 'texte' => 'compte admin ERP non réglé']; continue; }
            $req['e:' . $s['erp']] = [ErpApi::config()['base'] . $s['erp'], ['Authorization: Bearer ' . $jErp]];
            $pour[$id] = 'e:' . $s['erp'];
        } elseif (isset($s['twilio'])) {
            $c = function_exists('smsConfig') ? smsConfig() : [];
            if (!function_exists('smsConfigure') || !smsConfigure()) {
                $mes[$id] = ['type' => 'manque', 'niveau' => 'alerte', 'texte' => 'non réglé (config.php › sms) : aucun SMS ne part'];
                continue;
            }
            // Le compte, relu : prouve les identifiants sans envoyer de SMS.
            $req['t'] = ['https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($c['sid']) . '.json', ['Authorization: Basic ' . base64_encode($c['sid'] . ':' . $c['token'])]];
            $pour[$id] = 't';
        }
    }
    $rep = apiModGet($req);
    // Un chemin du panel à deux écritures : la seconde est essayée si la première n'a pas répondu.
    $req2 = [];
    foreach ($variantes as $id => $mods) {
        $c = (int) ($rep[$pour[$id]]['code'] ?? 0);
        if ($c >= 200 && $c < 300) { continue; }
        $req2['v:' . $mods[1]] = [PanelApi::config()['base'] . $mods[1], ['Authorization: Bearer ' . $jPanel]];
    }
    // Un ticket du jour, s'il y en a, pour sonder la lecture d'un ticket.
    $tid = null;
    foreach ($rep as $k => $x) { if (str_starts_with((string) $k, 'p:') && str_contains((string) $k, '/transactions?date=')) { $tid = apiModPremierTicket($x['corps']); } }
    $ctx['tid'] = $tid;
    foreach ($routes as [$src, $r]) {
        $mod = (array) ($r['sonde']['panel'] ?? []);
        if ($mod === [] || !str_contains($mod[0], '{tid}') || $jPanel === null) { continue; }
        $id = apiModId($src, $r);
        $chemin = apiModChemin($mod[0], $ctx);
        if ($chemin === null) { $mes[$id] = ['type' => 'manque', 'niveau' => 'neutre', 'texte' => 'pas de ticket aujourd’hui à relire']; continue; }
        $req2['p:' . $chemin] = [PanelApi::config()['base'] . $chemin, ['Authorization: Bearer ' . $jPanel]];
        $pour[$id] = 'p:' . $chemin;
    }
    $rep += apiModGet($req2);
    foreach ($pour as $id => $k) {
        $x = $rep[$k] ?? ['code' => 0, 'ms' => 0, 'erreur' => 'pas de réponse'];
        $via = null;
        if (isset($variantes[$id]) && !((int) $x['code'] >= 200 && (int) $x['code'] < 300) && isset($rep['v:' . $variantes[$id][1]])) {
            $x = $rep['v:' . $variantes[$id][1]];
            $via = $variantes[$id][1];
        }
        $mes[$id] = ['type' => 'http', 'code' => (int) $x['code'], 'ms' => (int) $x['ms'], 'erreur' => $x['erreur'] ?? null, 'via' => $via];
    }
    return ['mesures' => $mes, 'notes' => $notes];
}

/** GET /ventes/primes-api[?frais=1] : l'inventaire du module et l'état de chaque route, gardé une minute. */
function ep_primes_api(): array
{
    $frais = ($_GET['frais'] ?? '') === '1';
    $c = function_exists('motCache') ? motCache('apiModule') : null;
    if (!$frais && is_array($c) && isset($c['sources']) && (int) ($c['quand'] ?? 0) > time() - API_MOD_CACHE) { return $c; }
    $t0 = microtime(true);
    $inv = apiModInventaire();
    $m = apiModMesurer($inv);
    $out = apiModuleVue($inv, $m['mesures'], ['notes' => $m['notes']]);
    $out['quand'] = time();
    $out['le'] = date('c');
    $out['ms'] = (int) round(1000 * (microtime(true) - $t0));
    if (function_exists('motCacheEcrire')) { try { motCacheEcrire('apiModule', $out); } catch (Throwable $e) { /* sans cache : resondé à la prochaine ouverture */ } }
    return $out;
}
