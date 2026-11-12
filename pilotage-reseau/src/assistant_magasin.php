<?php
declare(strict_types=1);

/**
 * L'assistant du dashboard magasin (12/11/2026, Sam : « B puis A pour creuser » ; maquettes
 * docs/maquettes/assistant-ia-magasin). Un bouton « Assistant » ouvre une modale : des questions toutes prêtes en
 * tuiles et une question libre ; la réponse arrive en BILAN (un résumé, des chiffres, trois priorités avec l'écran qui
 * agit) puis on CREUSE en conversation (les questions de suite, le fil gardé dans la modale).
 *
 *   GET  /assistant/magasin/questions        les questions toutes prêtes (tuiles et liste rangée)
 *   POST /assistant/magasin  {shop, question, ton, fil:[{q, r}]}   une réponse, avec le coach choisi (AM_COACHS)
 *   GET  /assistant/coachs  ·  POST /assistant/coachs {coachs, qui}   le réglage des coachs, au cockpit
 *
 * Trois règles :
 *  1. l'assistant ne lit QUE la fiche de faits du magasin, assemblée ici par l'API du panel (ventes, P&L, contrôles,
 *     pertes, commandes, fidélité, rang dans le réseau) — pas d'accès libre, pas de base. La fiche de la société
 *     (que la route des pertes renvoie avec des secrets) n'en fait jamais partie ;
 *  2. il ne calcule rien qu'il ne cite : chaque chiffre de la réponse vient de la fiche ; ce qui n'y est pas, il le dit ;
 *  3. la clé reste sur le serveur (src/anthropic.php) ; le navigateur ne reçoit que la réponse.
 *
 * Modèle : réglage `assistantMagasin.modele` (défaut claude-opus-5-5), effort `assistantMagasin.effort` (défaut
 * medium). Réponse contrainte par un schéma (`output_config.format`) ; un refus de sécurité passe au modèle de repli
 * côté serveur (`fallbacks: "default"`).
 */

const AM_MODELE = 'claude-opus-5-5';
const AM_ECRANS = ['produits', 'taches', 'semaine', 'mois', 'fidelite', 'ops', 'promos', 'aucun'];
const AM_QUESTIONS = [
    'tuiles' => [
        ['Comment s’est passée ma semaine ?', 'ventes, équipe, contrôles, clients'],
        ['Que puis-je améliorer ?', 'les trois priorités, chiffrées'],
        ['Qu’est-ce qui marche le mieux ?', 'jours, produits, moments'],
        ['Comment va ma journée ?', 'à cette heure, face à hier et à la semaine'],
    ],
    'groupes' => [
        ['Ma semaine', ['Comment s’est passée ma semaine ?', 'Que puis-je améliorer ?', 'Qu’est-ce qui a le mieux marché ?']],
        ['Mes ventes', ['Quel est mon jour le plus faible, et pourquoi ?', 'Mon panier moyen est-il bon ?', 'Comment je me situe dans le réseau ?']],
        ['Mon équipe', ['Mes salaires collent-ils à mes ventes ?', 'Où en sont mes contrôles ?']],
        ['Mes clients', ['Qui sont mes clients fidèles ?', 'Combien de clients sont reconnus en caisse ?']],
        ['Ma production', ['Qu’est-ce que je jette le plus ?', 'Quelles commandes clients arrivent ?']],
    ],
];

/* Les deux coachs (12/11/2026, Sam : « mettre un choix d'assistant », puis « les deux sont des coachs business de
 * pointe, le ton est différent ; dans le cockpit : sur quoi il met l'accent, ce qu'il vérifie — par rapport au budget,
 * au trafic, aux tâches réalisées »). Le franchisé choisit le sien dans la modale ; le CEO règle chacun dans le cockpit
 * (ERP franchisé › Assistant coachs) : son ton, et pour chaque axe le poids (accent fort, suivi, n'en parle pas) et ce
 * qu'il vérifie. Le réglage `assistantCoachs` (ceo_app_setting) recouvre les valeurs ci-dessous, champ par champ.
 * Le personnage ne change que la manière de dire : les chiffres, les règles et le schéma restent les mêmes. Le Coach
 * porte un nom inventé (il ne parle au nom d'aucune personne réelle) et il est rude sans insulter : cette limite-là
 * n'est pas réglable, elle est écrite dans AM_SYSTEME. */
const AM_AXES = ['budget' => 'Budget', 'trafic' => 'Trafic', 'controles' => 'Contrôles qualité'];
const AM_NIVEAUX = ['fort' => 'Accent fort', 'suivi' => 'Suivi', 'non' => 'N’en parle pas'];
const AM_COACHS = [
    'pierre' => [
        'nom' => 'Pierre',
        'dit' => 'bienveillant, motivateur',
        'ton' => "Coach business de pointe, bienveillant et motivateur. Tu vouvoies le franchisé. Commence par ce qui va bien, chiffre à l'appui, puis présente ce qui peut progresser comme une occasion, avec un premier pas simple à faire aujourd'hui. Pas de flatterie vide, pas de leçon.",
        'accents' => [
            'budget' => ['niveau' => 'suivi', 'verifie' => "Où en est le mois face au budget, et ce qu'il faut faire par jour restant pour le tenir. Souligne chaque jour où l'objectif a été atteint."],
            'trafic' => ['niveau' => 'fort', 'verifie' => "Les tickets face à la semaine dernière et à la cible de trafic ; le jour le plus faible et une idée simple pour y faire venir du monde ; le panier moyen face au réseau."],
            'controles' => ['niveau' => 'suivi', 'verifie' => "Les contrôles faits à l'heure, en particulier le contrôle qualité pâtisserie ; la tendance des sept derniers jours. Encourage la régularité plutôt que de reprocher."],
        ],
    ],
    'coach' => [
        'nom' => 'Le Coach',
        'dit' => 'à la dure : ici, on joue pour gagner',
        'ton' => "Coach business de pointe, à la dure. Tu tutoies. Style vestiaire, cash et exigeant : ici on joue pour gagner, les excuses ne comptent pas, seuls les chiffres parlent. Rude, familier, ironique sur la performance (« bouge-toi », « ça, c'est pas un score de gagnant »). Tu finis sur l'ordre de marche du jour.",
        'accents' => [
            'budget' => ['niveau' => 'fort', 'verifie' => "L'écart au budget en euros, aujourd'hui, sur la semaine et sur le mois ; la projection de fin de journée ; le montant à faire par jour restant. Sous 100 %, tu le dis sans détour."],
            'trafic' => ['niveau' => 'fort', 'verifie' => "Les tickets face à la semaine dernière et à la cible de trafic ; chaque jour en recul est nommé ; le panier sous le réseau, c'est de l'argent laissé sur le comptoir."],
            'controles' => ['niveau' => 'fort', 'verifie' => "Les contrôles en retard à l'heure qu'il est, checklist par checklist ; le contrôle qualité pâtisserie du jour fait ou pas ; zéro tolérance sur l'hygiène et la fermeture."],
        ],
    ],
];
const AM_TON_DEFAUT = 'pierre';

const AM_SYSTEME = <<<'TXT'
Tu es l'assistant du dashboard d'un magasin franchisé L'Atelier by (boulangerie, pâtisserie, traiteur ; Belgique). Tu réponds au franchisé, en français, sur SON magasin, avec le ton du personnage décrit à la fin de ces consignes (il décide du tutoiement ou du vouvoiement).

Tu ne connais du magasin que la fiche de faits jointe (JSON, lue dans l'ERP au moment indiqué). Règles :
- Chaque chiffre que tu donnes vient de la fiche, tel quel ou par un calcul simple que tu peux montrer (somme, écart, pourcentage). N'invente aucun chiffre, aucun objectif, aucune cause. Si la fiche ne permet pas de répondre, dis-le en une phrase et indique ce qu'il faudrait lire.
- Écris les montants à la belge : « 14 255 € », « 13,13 € », « 4,1 % ». Les dates en clair : « mardi 3 novembre ».
- Sois bref et concret : le franchisé lit entre deux clients. Pas de formule de politesse, pas de conclusion générique.
- Le personnage change la manière de dire, jamais les faits : mêmes chiffres, mêmes règles, quel que soit le ton. Quel que soit le personnage : aucune insulte, aucune grossièreté, aucune humiliation ; on s'en prend aux résultats, jamais à la personne.
- La fiche porte le budget du magasin (objectif de CA du mois, du jour et de la semaine, saisi au cockpit) : l'écart au budget se calcule depuis ces valeurs ; sans budget saisi, dis-le.
- Quand c'est utile, propose jusqu'à trois priorités, de la plus rentable à la plus simple ; chacune dit le constat chiffré, ce que ça peut rapporter si un calcul simple le permet (sinon laisse le gain vide), et l'écran du dashboard où agir.
- Les écrans possibles : produits (ventes par produit), taches (contrôles et checklists), semaine, mois, fidelite (profils et clients), ops (la journée : commandes, pertes, stock), promos, aucun.
- Les questions de suite sont courtes (moins de 60 caractères), posées par le franchisé, et la fiche doit permettre d'y répondre.
- Le coût matière n'est souvent pas renseigné : sans lui, ne parle ni de marge ni de résultat.
TXT;

/** Le schéma de la réponse : il sert le bilan (B) et la conversation (A). */
function amSchema(): array
{
    $txt = ['type' => 'string'];
    return [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['titre', 'resume', 'chiffres', 'points', 'priorites', 'suites'],
        'properties' => [
            'titre' => $txt,
            'resume' => $txt,
            'chiffres' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['valeur', 'libelle'],
                'properties' => ['valeur' => $txt, 'libelle' => $txt]]],
            'points' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['titre', 'texte'],
                'properties' => ['titre' => $txt, 'texte' => $txt]]],
            'priorites' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['titre', 'constat', 'gain', 'ecran'],
                'properties' => ['titre' => $txt, 'constat' => $txt, 'gain' => $txt, 'ecran' => ['type' => 'string', 'enum' => AM_ECRANS]]]],
            'suites' => ['type' => 'array', 'items' => $txt],
        ],
    ];
}

/* ---------------------------------------------------------------------------
 * La fiche de faits, par l'API du panel
 * ------------------------------------------------------------------------- */
function amKpis(int $sid, string $du, string $au): ?array
{
    $r = PanelApi::sondeGet('/shops/' . $sid . '/statistics/sales/kpis?' . http_build_query(['date_from' => $du, 'date_to' => $au]), 20);
    $c = is_array($r['corps']) ? $r['corps'] : null;
    return $c ? ['du' => $du, 'au' => $au, 'ca' => round((float) ($c['ca'] ?? 0), 2), 'tickets' => (int) ($c['tickets'] ?? 0),
        'panier' => round((float) ($c['avg_basket'] ?? 0), 2), 'articlesParTicket' => round((float) ($c['products_per_ticket'] ?? 0), 2)] : null;
}

/** La fiche de faits d'un magasin, gardée 10 minutes : une conversation ne relit pas l'ERP à chaque question. */
function amFaits(int $sid): array
{
    $cle = 'assistantFaits|' . $sid;
    $m = setting($cle, null);
    if (is_array($m) && ($m['le'] ?? 0) > time() - 600 && is_array($m['v'] ?? null)) { return $m['v']; }
    $auj = date('Y-m-d'); $hier = date('Y-m-d', strtotime('-1 day'));
    $lundi = date('Y-m-d', strtotime('monday this week'));
    $lundiP = date('Y-m-d', strtotime($lundi . ' -7 days')); $dimP = date('Y-m-d', strtotime($lundi . ' -1 day'));
    $lundiPP = date('Y-m-d', strtotime($lundi . ' -14 days')); $dimPP = date('Y-m-d', strtotime($lundi . ' -8 days'));
    $mois = date('Y-m-01');
    $nom = '';
    foreach (fidMagasins() as $x) { if ((int) $x['id'] === $sid) { $nom = (string) $x['nom']; } }
    $f = ['magasin' => $nom !== '' ? $nom : 'magasin ' . $sid, 'luLe' => date('Y-m-d H:i'), 'aujourdhui' => $auj . ' (' . amJour($auj) . ')'];
    $f['ventes'] = ['aujourdhuiJusquIci' => amKpis($sid, $auj, $auj), 'hier' => amKpis($sid, $hier, $hier), 'cetteSemaine' => amKpis($sid, $lundi, $auj),
        'semaineDerniere' => amKpis($sid, $lundiP, $dimP), 'semaineAvant' => amKpis($sid, $lundiPP, $dimPP), 'moisEnCours' => amKpis($sid, $mois, $auj)];
    // Jour par jour, 21 jours : CA, tickets, panier (margin-heatmap) et salaires (P&L quotidien).
    $du21 = date('Y-m-d', strtotime($auj . ' -20 days'));
    $hm = PanelApi::sondeGet('/consultant/shops/' . $sid . '/margin-heatmap?' . http_build_query(['from' => $du21, 'to' => $auj]), 30);
    $pnl = PanelApi::sondeGet('/consultant/shops/' . $sid . '/pnl/daily?' . http_build_query(['date_from' => $du21, 'date_to' => $auj, 'from' => $du21, 'to' => $auj]), 30);
    $sal = [];
    foreach ((array) (is_array($pnl['corps']) ? ($pnl['corps']['days'] ?? []) : []) as $d) { $sal[(string) ($d['date'] ?? '')] = round((float) ($d['labour'] ?? 0), 2); }
    $jours = [];
    foreach ((array) (is_array($hm['corps']) ? ($hm['corps']['days'] ?? []) : []) as $d) {
        $j = (string) ($d['date'] ?? '');
        $jours[] = ['date' => $j, 'jour' => amJour($j), 'ca' => round((float) ($d['ca'] ?? 0), 2), 'tickets' => (int) ($d['tickets'] ?? 0),
            'panier' => round((float) ($d['avg_basket'] ?? 0), 2), 'salaires' => $sal[$j] ?? null];
    }
    $f['joursParJour'] = $jours;
    $f['coutMatiere'] = 'non renseigné dans l’ERP : ni marge ni résultat calculables';
    // Les cibles du réseau (seuils t1 bon, t2 moyen, t3 bas).
    $ci = PanelApi::sondeGet('/consultant/shops/' . $sid . '/targets', 20);
    $f['cibles'] = [];
    foreach ((array) (is_array($ci['corps']) ? $ci['corps'] : []) as $k => $c) {
        if (!is_array($c)) { continue; }
        $v = is_array($c['consultant'] ?? null) ? $c['consultant'] : (is_array($c['admin'] ?? null) ? $c['admin'] : ($c['default'] ?? null));
        $f['cibles'][$k] = ['libelle' => $c['label'] ?? $k, 'unite' => $c['unit'] ?? null, 'plusBasEstMieux' => !empty($c['lower_is_better']), 'seuils' => $v];
    }
    // Le budget (12/11/2026, Sam : « par rapport au budget ») : l'objectif de CA saisi au cockpit (Budget), tel que le
    // tableau du jour le calcule — mois, jour (part du jour de semaine) et semaine jour par jour.
    $f['budget'] = amBudget($sid, $f['ventes']['moisEnCours']['ca'] ?? null);
    // Le rang d'hier dans le réseau (les autres magasins ne sont pas nommés).
    $res = PanelApi::sondeGet('/consultant/shops/sales-kpis?' . http_build_query(['date_from' => $hier, 'date_to' => $hier]), 20);
    $L = array_values(array_filter((array) (is_array($res['corps']) ? ($res['corps']['shops'] ?? []) : []), 'is_array'));
    usort($L, static fn ($a, $b) => (float) ($b['ca'] ?? 0) <=> (float) ($a['ca'] ?? 0));
    foreach ($L as $i => $x) { if ((int) ($x['shop_id'] ?? 0) === $sid) { $f['reseauHier'] = ['rangCa' => $i + 1, 'magasins' => count($L),
        'panierMoyenReseau' => round(array_sum(array_column($L, 'ca')) / max(1, array_sum(array_column($L, 'tickets'))), 2)]; } }
    // Les contrôles : le jour et la tendance sur sept jours.
    $tk = PanelApi::sondeGet('/consultant/shops/' . $sid . '/tasks?date=' . $auj, 20);
    $T = is_array($tk['corps']) ? $tk['corps'] : [];
    $parCl = [];
    foreach ((array) ($T['tasks'] ?? []) as $t) {
        $k = substr((string) ($t['execution_time'] ?? ''), 0, 5) . ' ' . (string) ($t['checklist_name'] ?? '?');
        $parCl[$k]['total'] = ($parCl[$k]['total'] ?? 0) + 1;
        $parCl[$k]['faites'] = ($parCl[$k]['faites'] ?? 0) + (strtoupper((string) ($t['status'] ?? '')) === 'DONE' ? 1 : 0);
    }
    ksort($parCl);
    $f['controles'] = ['heure' => date('H:i'), 'aujourdhui' => $T['summary'] ?? null, 'parChecklist' => $parCl,
        'septDerniersJours' => array_map(static fn ($d) => ['date' => $d['day'] ?? null, 'faites' => $d['tasks_done'] ?? null, 'total' => $d['tasks_total'] ?? null, 'taux' => $d['completion_rate'] ?? null], (array) ($T['trend'] ?? []))];
    // Les pertes de la semaine : le résumé et les produits qui pèsent (jamais l'enveloppe `shop`).
    $w = PanelApi::sondeGet('/shops/' . $sid . '/products/waste?' . http_build_query(['from' => $lundi, 'to' => $auj, 'date_from' => $lundi, 'date_to' => $auj]), 30);
    $W = is_array($w['corps']) ? $w['corps'] : [];
    $prods = is_array($W['products'] ?? null) ? $W['products'] : [];
    usort($prods, static fn ($a, $b) => (float) ($b['ca_waste_net'] ?? 0) <=> (float) ($a['ca_waste_net'] ?? 0));
    $f['pertesCetteSemaine'] = ['resume' => isset($W['period_summary']) ? ['pieces' => $W['period_summary']['total_waste_qty'] ?? null, 'ventesPerdues' => round((float) ($W['period_summary']['total_ca_waste_net'] ?? 0), 2)] : null,
        'produits' => array_map(static fn ($p) => ['produit' => $p['product_name'] ?? null, 'jete' => (float) ($p['waste_qty'] ?? 0), 'vendu' => (float) ($p['sold_qty'] ?? 0),
            'pctJeteSurVendu' => (float) ($p['pct_waste_to_sold'] ?? 0), 'ventesPerdues' => (float) ($p['ca_waste_net'] ?? 0), 'motif' => $p['top_reason'] ?? null], array_slice($prods, 0, 8))];
    // Les commandes clients à partir d'aujourd'hui : comptées, sans nom.
    $co = PanelApi::sondeGet('/shops/' . $sid . '/client-orders?date_from=' . $auj, 20);
    $cmd = [];
    foreach (PanelApi::liste(is_array($co['corps']) ? $co['corps'] : []) as $o) {
        $j = substr((string) ($o['pick_up_datetime'] ?? ''), 0, 10);
        $k = $j . ' · ' . (!empty($o['is_webshop']) ? 'webshop' : 'comptoir') . ' · ' . ((int) ($o['id_transaction'] ?? 0) > 0 ? 'encaissée' : 'à encaisser');
        $cmd[$k]['n'] = ($cmd[$k]['n'] ?? 0) + 1; $cmd[$k]['montant'] = round(($cmd[$k]['montant'] ?? 0) + (float) ($o['total_value'] ?? 0), 2);
    }
    ksort($cmd);
    $f['commandesAVenir'] = $cmd;
    // La fidélité : les profils du magasin (sans nom de client).
    if (function_exists('fpMagasin')) {
        try {
            $m2 = fpMagasin($sid, true, false);
            $f['fidelite'] = ['clientsScannes' => $m2['scannes'], 'ticketsRattaches' => $m2['identifies'] . ' sur ' . $m2['tickets'] . ' (' . $m2['memoire'] . ' jours)',
                'membresCarteActive' => 'aucun : la carte fidélité n’est activée chez aucun client',
                'profils' => array_map(static fn ($p) => ['profil' => $p['nom'], 'condition' => $p['condition'], 'clients' => $p['clients'], 'part' => $p['part'] . ' %', 'panier' => $p['panier']], $m2['profils'])];
        } catch (Throwable $e) { $f['fidelite'] = 'illisible : ' . $e->getMessage(); }
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$cle, json_encode(['le' => time(), 'v' => $f], JSON_UNESCAPED_UNICODE)]);
    return $f;
}

/** Le budget du magasin, lu dans le calcul du jour du cockpit (ep_exploitation_jour : ceo_shop_month_perf). */
function amBudget(int $sid, mixed $caMois): array
{
    if (!function_exists('ep_exploitation_jour')) { return ['etat' => 'budget non lu']; }
    $g = $_GET;
    try { $_GET = ['date' => date('Y-m-d')]; $ex = ep_exploitation_jour(); }
    catch (Throwable $e) { $ex = null; }
    finally { $_GET = $g; }
    $m = null;
    foreach ((array) ($ex['magasins'] ?? []) as $x) { if ((int) ($x['shopId'] ?? 0) === $sid) { $m = $x; } }
    if (!$m) { return ['etat' => 'le calcul du jour ne répond pas encore : budget non lu']; }
    if (($m['objectifMois'] ?? null) === null) { return ['etat' => 'aucun budget saisi pour ce magasin ce mois-ci (cockpit › Budget)']; }
    $obj = (float) $m['objectifMois'];
    $ca = is_numeric($caMois) ? (float) $caMois : null;
    $fin = (int) date('t'); $jour = (int) date('j');
    $sem = []; $caSem = 0.0; $objSem = 0.0;
    foreach ((array) ($m['semaine'] ?? []) as $d) {
        $sem[] = ['date' => $d['date'], 'jour' => amJour($d['date']), 'ca' => $d['ca'], 'objectif' => $d['objectif'], 'passe' => (bool) $d['passe']];
        if (!empty($d['passe']) && empty($d['aujourdhui']) && $d['ca'] !== null && $d['objectif'] !== null) { $caSem += $d['ca']; $objSem += $d['objectif']; }
    }
    return [
        'source' => ($m['objectifSource'] ?? '') === 'theorique' ? 'CA théorique (pas de budget saisi : repli)' : 'budget saisi au cockpit',
        'objectifMois' => round($obj, 2),
        'caMoisJusquIci' => $ca,
        'atteinteMoisPct' => $ca !== null && $obj > 0 ? round($ca / $obj * 100, 1) : null,
        'resteAFaireMois' => $ca !== null ? round(max(0, $obj - $ca), 2) : null,
        'joursRestantsApresAujourdhui' => $fin - $jour,
        'objectifJour' => $m['objectifJour'],
        'caJourJusquIci' => $m['ca'],
        'projectionFinDeJour' => $m['projection'],
        'semaineJoursPassesHorsAujourdhui' => ['ca' => round($caSem, 2), 'objectif' => round($objSem, 2), 'ecart' => round($caSem - $objSem, 2)],
        'semaineJourParJour' => $sem,
    ];
}

function amJour(string $d): string
{
    return ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][((int) date('N', strtotime($d))) - 1] ?? '';
}

/* ---------------------------------------------------------------------------
 * L'appel
 * ------------------------------------------------------------------------- */
/** POST /v1/messages : la fiche (en cache), le fil, la question ; la réponse contrainte au schéma. */
/** Les coachs tels que le cockpit les a réglés : le réglage `assistantCoachs` sur les valeurs d'AM_COACHS, champ par champ. */
function amCoachs(): array
{
    $r = setting('assistantCoachs', []);
    $r = is_array($r) ? $r : [];
    $out = [];
    foreach (AM_COACHS as $k => $d) {
        $x = is_array($r[$k] ?? null) ? $r[$k] : [];
        $c = $d;
        foreach (['nom' => 40, 'dit' => 80, 'ton' => 1200] as $champ => $max) {
            $v = trim((string) ($x[$champ] ?? ''));
            if ($v !== '') { $c[$champ] = mb_substr($v, 0, $max); }
        }
        foreach (AM_AXES as $ax => $lib) {
            $y = is_array($x['accents'][$ax] ?? null) ? $x['accents'][$ax] : [];
            if (isset(AM_NIVEAUX[$y['niveau'] ?? ''])) { $c['accents'][$ax]['niveau'] = $y['niveau']; }
            $v = trim((string) ($y['verifie'] ?? ''));
            if ($v !== '') { $c['accents'][$ax]['verifie'] = mb_substr($v, 0, 800); }
        }
        $out[$k] = $c;
    }
    return $out;
}

/** Le bloc « personnage » du message système : le ton, puis les axes, l'accent fort d'abord. */
function amPersona(string $k): string
{
    $C = amCoachs();
    $c = $C[$k] ?? $C[AM_TON_DEFAUT];
    $t = "Tu es {$c['nom']}. {$c['ton']}\n\nCe que tu regardes, et comment :";
    foreach (['fort', 'suivi', 'non'] as $niv) {
        foreach (AM_AXES as $ax => $lib) {
            $a = $c['accents'][$ax];
            if ($a['niveau'] !== $niv) { continue; }
            if ($niv === 'non') { $t .= "\n- {$lib} : n'en parle que si la question le demande."; continue; }
            $t .= "\n- {$lib} (" . ($niv === 'fort' ? "accent fort : tu l'abordes dans chaque bilan, avant les axes suivis" : 'suivi : tu en parles quand les chiffres le justifient') . ") : {$a['verifie']}";
        }
    }
    return $t . "\nCes accents orientent tes priorités et ton résumé ; ils ne t'autorisent aucun chiffre absent de la fiche.";
}

function amDemander(array $faits, string $question, array $fil, string $ton = AM_TON_DEFAUT): array
{
    $c = Anthropic::config();
    if ($c['cle'] === '') { return ['erreur' => 'L’assistant n’est pas branché : aucune clé Anthropic dans les Paramètres du cockpit.']; }
    $r = setting('assistantMagasin', []);
    $r = is_array($r) ? $r : [];
    $modele = trim((string) ($r['modele'] ?? '')) ?: AM_MODELE;
    $effort = in_array($r['effort'] ?? '', ['low', 'medium', 'high'], true) ? $r['effort'] : 'medium';
    // La fiche ouvre la conversation, marquée pour le cache : les questions de suite la relisent sans la repayer.
    $messages = [['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => "Fiche de faits du magasin (JSON) :\n" . json_encode($faits, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'cache_control' => ['type' => 'ephemeral']],
    ]]];
    foreach (array_slice($fil, -6) as $t) {
        if (!is_array($t) || trim((string) ($t['q'] ?? '')) === '' || !is_array($t['r'] ?? null)) { continue; }
        $messages[count($messages) - 1]['content'][] = ['type' => 'text', 'text' => 'Question : ' . mb_substr((string) $t['q'], 0, 400)];
        $messages[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => json_encode($t['r'], JSON_UNESCAPED_UNICODE)]]];
        $messages[] = ['role' => 'user', 'content' => []];
    }
    $dernier = count($messages) - 1;
    $messages[$dernier]['content'][] = ['type' => 'text', 'text' => 'Question : ' . $question];
    $corps = [
        'model' => $modele,
        'max_tokens' => 16000,
        // Le personnage vient après le bloc commun mis en cache : il est court, et changer d'assistant ne repaie que lui.
        'system' => [['type' => 'text', 'text' => AM_SYSTEME, 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => "Ton personnage :\n" . amPersona($ton)]],
        'messages' => $messages,
        'output_config' => ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => amSchema()]],
        'fallbacks' => 'default',
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'x-api-key: ' . $c['cle'], 'anthropic-version: 2023-06-01', 'anthropic-beta: server-side-fallback-2026-07-01'],
            ($c['espace'] ?? '') !== '' ? ['anthropic-workspace-id: ' . $c['espace']] : []),
        CURLOPT_POSTFIELDS => json_encode($corps, JSON_UNESCAPED_UNICODE), CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) { return ['erreur' => 'API Anthropic injoignable : ' . $err]; }
    $rep = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300) { return ['erreur' => 'API Anthropic → HTTP ' . $code . ' : ' . mb_substr((string) ($rep['error']['message'] ?? ''), 0, 240)]; }
    if (($rep['stop_reason'] ?? '') === 'refusal') { return ['erreur' => 'L’assistant a décliné cette question. Reformulez-la sur les chiffres du magasin.']; }
    if (($rep['stop_reason'] ?? '') === 'max_tokens') { return ['erreur' => 'La réponse a été coupée : posez une question plus précise.']; }
    foreach ((array) ($rep['content'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'text') {
            $j = json_decode((string) $b['text'], true);
            if (is_array($j)) { return ['reponse' => $j, 'modele' => (string) ($rep['model'] ?? $modele), 'usage' => $rep['usage'] ?? null]; }
        }
    }
    return ['erreur' => 'Réponse illisible de l’assistant.'];
}

/* ---------------------------------------------------------------------------
 * Les routes
 * ------------------------------------------------------------------------- */
/** Les questions toutes prêtes, et si l'assistant est branché (sans clé, le dashboard ne montre pas le bouton). */
function ep_assistant_questions(): array
{
    $tons = [];
    foreach (amCoachs() as $k => ['nom' => $nom, 'dit' => $dit]) {
        // Le portrait, s'il existe (public/dashboard/avatars/{clé}.svg, 12/11/2026 : celui de Pierre) ; sinon l'icône.
        $av = is_file(__DIR__ . '/../public/dashboard/avatars/' . $k . '.svg') ? 'avatars/' . $k . '.svg' : null;
        $tons[] = ['cle' => $k, 'nom' => $nom, 'dit' => $dit, 'avatar' => $av];
    }
    return AM_QUESTIONS + ['tons' => $tons, 'tonDefaut' => AM_TON_DEFAUT, 'actif' => class_exists('Anthropic') && Anthropic::configured()];
}

/** POST /assistant/magasin {shop, question, ton, fil:[{q, r}]} → {reponse:{titre, resume, chiffres, points, priorites, suites}, luLe, ton}. */
function wr_assistant_magasin(): array
{
    $b = body();
    $sid = ctype_digit((string) ($b['shop'] ?? '')) ? (int) $b['shop'] : 0;
    $q = trim(preg_replace('/\s+/u', ' ', (string) ($b['question'] ?? '')));
    if ($sid <= 0 || $q === '') { http_response_code(422); return ['error' => 'magasin ou question manquant']; }
    if (mb_strlen($q) > 500) { http_response_code(422); return ['error' => 'question trop longue (500 caractères au plus)']; }
    $faits = amFaits($sid);
    $ton = isset(AM_COACHS[$b['ton'] ?? '']) ? (string) $b['ton'] : AM_TON_DEFAUT;
    $fil = is_array($b['fil'] ?? null) ? $b['fil'] : [];
    $orig = in_array($b['origine'] ?? '', AM_ORIGINES, true) ? (string) $b['origine'] : 'libre';
    $t0 = microtime(true);
    $r = amDemander($faits, $q, $fil, $ton);
    amGarder($sid, $ton, $orig, $q, count($fil), $r, (int) round((microtime(true) - $t0) * 1000));
    if (isset($r['erreur'])) { http_response_code(502); return ['error' => $r['erreur']]; }
    if (function_exists('journalAdd')) { journalAdd('Dashboard', 'Assistant', null, 'Magasin ' . $sid . ' (' . amCoachs()[$ton]['nom'] . ') : « ' . mb_substr($q, 0, 120) . ' »'); }
    return ['reponse' => $r['reponse'], 'luLe' => $faits['luLe'] ?? null, 'modele' => $r['modele'], 'ton' => $ton];
}

/** GET /assistant/coachs → les coachs réglés, leurs valeurs de départ, les axes et les niveaux (cockpit). */
function ep_assistant_coachs(): array
{
    $av = [];
    foreach (array_keys(AM_COACHS) as $k) { $av[$k] = is_file(__DIR__ . '/../public/dashboard/avatars/' . $k . '.svg') ? 'dashboard/avatars/' . $k . '.svg' : null; }
    return ['coachs' => amCoachs(), 'defauts' => AM_COACHS, 'axes' => AM_AXES, 'niveaux' => AM_NIVEAUX, 'avatars' => $av, 'defaut' => AM_TON_DEFAUT];
}

/** POST /assistant/coachs {coachs:{pierre:{nom, dit, ton, accents:{budget:{niveau, verifie}, …}}, coach:{…}}, qui} : publie le réglage. */
function wr_assistant_coachs(): array
{
    $b = body();
    $qui = mb_substr(trim((string) ($b['qui'] ?? '')) ?: 'Cockpit', 0, 60);
    $in = is_array($b['coachs'] ?? null) ? $b['coachs'] : [];
    $reg = [];
    foreach (AM_COACHS as $k => $d) {
        $x = is_array($in[$k] ?? null) ? $in[$k] : [];
        $c = [];
        foreach (['nom' => 40, 'dit' => 80, 'ton' => 1200] as $champ => $max) {
            $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) ($x[$champ] ?? '')));
            if (mb_strlen($v) > $max) { http_response_code(422); return ['error' => $d['nom'] . ' : « ' . $champ . ' » dépasse ' . $max . ' caractères']; }
            if ($v !== '' && $v !== $d[$champ]) { $c[$champ] = $v; }
        }
        if (isset($c['nom']) && mb_strlen($c['nom']) < 2) { http_response_code(422); return ['error' => 'le nom d’un coach compte au moins deux caractères']; }
        foreach (AM_AXES as $ax => $lib) {
            $y = is_array($x['accents'][$ax] ?? null) ? $x['accents'][$ax] : [];
            $niv = (string) ($y['niveau'] ?? '');
            if ($niv !== '' && !isset(AM_NIVEAUX[$niv])) { http_response_code(422); return ['error' => $lib . ' : niveau inconnu']; }
            $ver = trim((string) ($y['verifie'] ?? ''));
            if (mb_strlen($ver) > 800) { http_response_code(422); return ['error' => $d['nom'] . ' · ' . $lib . ' : 800 caractères au plus']; }
            if ($niv !== '' && $niv !== $d['accents'][$ax]['niveau']) { $c['accents'][$ax]['niveau'] = $niv; }
            if ($ver !== '' && $ver !== $d['accents'][$ax]['verifie']) { $c['accents'][$ax]['verifie'] = $ver; }
        }
        if ($c) { $reg[$k] = $c; }
    }
    Db::exec('INSERT INTO ceo_app_setting VALUES (?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['assistantCoachs', json_encode($reg, JSON_UNESCAPED_UNICODE)]);
    if (function_exists('journalAdd')) { journalAdd($qui, 'Assistant', null, 'Coachs de l’assistant publiés' . ($reg ? ' (' . implode(', ', array_map(static fn ($k) => AM_COACHS[$k]['nom'], array_keys($reg))) . ' réglés)' : ' (valeurs de départ)')); }
    return ['ok' => true] + ep_assistant_coachs();
}

/* --- Les questions posées (12/11/2026, Sam : « garde toutes les demandes dans une table, pour après créer des agents
 * spécifiques ») ---------------------------------------------------------------------------------------------------
 * Chaque question part dans ceo_assistant_question : le magasin, le coach, d'où elle vient (tuile du bilan, liste toute
 * prête, question de suite, question libre), son rang dans la conversation, la réponse (JSON), le modèle, les jetons et
 * la durée — l'échec aussi, avec son message. `theme` reste vide : il se remplira quand on rangera les questions pour
 * décider des agents spécialisés. Les questions ne portent pas de nom de personne : le magasin et l'heure suffisent. */
const AM_ORIGINES = ['tuile', 'liste', 'suite', 'libre'];

function amQuestionsSchema(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS ceo_assistant_question (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cree_le DATETIME NOT NULL,
        shop_id INT NOT NULL,
        coach VARCHAR(20) NOT NULL,
        origine VARCHAR(10) NOT NULL,
        rang SMALLINT NOT NULL DEFAULT 0,
        question VARCHAR(500) NOT NULL,
        reponse MEDIUMTEXT NULL,
        modele VARCHAR(60) NULL,
        jetons_entree INT NULL,
        jetons_sortie INT NULL,
        duree_ms INT NULL,
        erreur VARCHAR(300) NULL,
        theme VARCHAR(40) NULL,
        KEY k_date (cree_le), KEY k_shop (shop_id, cree_le)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Garde une question et ce qu'elle a donné. Une panne d'écriture ne coûte jamais la réponse au franchisé. */
function amGarder(int $sid, string $coach, string $orig, string $q, int $rang, array $r, int $ms): void
{
    try {
        amQuestionsSchema();
        $u = is_array($r['usage'] ?? null) ? $r['usage'] : [];
        $entree = isset($u['input_tokens']) ? (int) $u['input_tokens'] + (int) ($u['cache_creation_input_tokens'] ?? 0) + (int) ($u['cache_read_input_tokens'] ?? 0) : null;
        Db::exec('INSERT INTO ceo_assistant_question (cree_le, shop_id, coach, origine, rang, question, reponse, modele, jetons_entree, jetons_sortie, duree_ms, erreur)
                  VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$sid, $coach, $orig, $rang, $q, isset($r['reponse']) ? json_encode($r['reponse'], JSON_UNESCAPED_UNICODE) : null, $r['modele'] ?? null,
             $entree, isset($u['output_tokens']) ? (int) $u['output_tokens'] : null, $ms, isset($r['erreur']) ? mb_substr((string) $r['erreur'], 0, 300) : null]);
    } catch (Throwable $e) { error_log('ceo_assistant_question : ' . $e->getMessage()); }
}

/** GET /assistant/questions-posees?jours=30 → les questions de la période : totaux, les plus posées, les dernières. */
function ep_assistant_questions_posees(): array
{
    amQuestionsSchema();
    $j = max(1, min(365, (int) ($_GET['jours'] ?? 30)));
    $du = date('Y-m-d 00:00:00', strtotime('-' . ($j - 1) . ' days'));
    $noms = [];
    foreach (fidMagasins() as $m) { $noms[(int) $m['id']] = (string) $m['nom']; }
    $C = amCoachs();
    $tot = Db::row('SELECT COUNT(*) n, COUNT(DISTINCT shop_id) mags, SUM(erreur IS NOT NULL) ko, ROUND(AVG(duree_ms)) ms, SUM(jetons_sortie) sortie FROM ceo_assistant_question WHERE cree_le >= ?', [$du]) ?: [];
    $par = static fn (string $col) => Db::rows("SELECT $col k, COUNT(*) n FROM ceo_assistant_question WHERE cree_le >= ? GROUP BY $col ORDER BY n DESC", [$du]);
    $top = Db::rows('SELECT LOWER(question) q, MIN(question) libelle, COUNT(*) n, COUNT(DISTINCT shop_id) mags, MAX(cree_le) dernier FROM ceo_assistant_question WHERE cree_le >= ? GROUP BY LOWER(question) ORDER BY n DESC, dernier DESC LIMIT 15', [$du]);
    $der = Db::rows('SELECT id, cree_le, shop_id, coach, origine, rang, question, erreur, duree_ms FROM ceo_assistant_question WHERE cree_le >= ? ORDER BY id DESC LIMIT 60', [$du]);
    return [
        'jours' => $j,
        'total' => (int) ($tot['n'] ?? 0), 'magasins' => (int) ($tot['mags'] ?? 0), 'echecs' => (int) ($tot['ko'] ?? 0),
        'dureeMoyenneMs' => isset($tot['ms']) ? (int) $tot['ms'] : null, 'jetonsSortie' => (int) ($tot['sortie'] ?? 0),
        'parCoach' => array_map(static fn ($r) => ['coach' => $C[$r['k']]['nom'] ?? $r['k'], 'n' => (int) $r['n']], $par('coach')),
        'parOrigine' => array_map(static fn ($r) => ['origine' => $r['k'], 'n' => (int) $r['n']], $par('origine')),
        'parMagasin' => array_map(static fn ($r) => ['magasin' => $noms[(int) $r['k']] ?? 'magasin ' . $r['k'], 'n' => (int) $r['n']], $par('shop_id')),
        'lesPlusPosees' => array_map(static fn ($r) => ['question' => $r['libelle'], 'n' => (int) $r['n'], 'magasins' => (int) $r['mags'], 'dernier' => $r['dernier']], $top),
        'dernieres' => array_map(static fn ($r) => ['le' => $r['cree_le'], 'magasin' => $noms[(int) $r['shop_id']] ?? 'magasin ' . $r['shop_id'], 'coach' => $C[$r['coach']]['nom'] ?? $r['coach'],
            'origine' => $r['origine'], 'rang' => (int) $r['rang'], 'question' => $r['question'], 'echec' => $r['erreur'] !== null, 'dureeMs' => $r['duree_ms'] !== null ? (int) $r['duree_ms'] : null], $der),
    ];
}
