<?php
declare(strict_types=1);

/**
 * L'assistant du dashboard magasin (12/11/2026, Sam : « B puis A pour creuser » ; maquettes
 * docs/maquettes/assistant-ia-magasin). Un bouton « Assistant » ouvre une modale : des questions toutes prêtes en
 * tuiles et une question libre ; la réponse arrive en BILAN (un résumé, des chiffres, trois priorités avec l'écran qui
 * agit) puis on CREUSE en conversation (les questions de suite, le fil gardé dans la modale).
 *
 *   GET  /assistant/magasin/questions        les questions toutes prêtes (tuiles et liste rangée)
 *   POST /assistant/magasin  {shop, question, fil:[{q, r}]}   une réponse
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

const AM_SYSTEME = <<<'TXT'
Tu es l'assistant du dashboard d'un magasin franchisé L'Atelier by (boulangerie, pâtisserie, traiteur ; Belgique). Tu réponds au franchisé, en français, en le vouvoyant, sur SON magasin.

Tu ne connais du magasin que la fiche de faits jointe (JSON, lue dans l'ERP au moment indiqué). Règles :
- Chaque chiffre que tu donnes vient de la fiche, tel quel ou par un calcul simple que tu peux montrer (somme, écart, pourcentage). N'invente aucun chiffre, aucun objectif, aucune cause. Si la fiche ne permet pas de répondre, dis-le en une phrase et indique ce qu'il faudrait lire.
- Écris les montants à la belge : « 14 255 € », « 13,13 € », « 4,1 % ». Les dates en clair : « mardi 3 novembre ».
- Sois bref et concret : le franchisé lit entre deux clients. Pas de formule de politesse, pas de conclusion générique.
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

function amJour(string $d): string
{
    return ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][((int) date('N', strtotime($d))) - 1] ?? '';
}

/* ---------------------------------------------------------------------------
 * L'appel
 * ------------------------------------------------------------------------- */
/** POST /v1/messages : la fiche (en cache), le fil, la question ; la réponse contrainte au schéma. */
function amDemander(array $faits, string $question, array $fil): array
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
        'system' => [['type' => 'text', 'text' => AM_SYSTEME, 'cache_control' => ['type' => 'ephemeral']]],
        'messages' => $messages,
        'output_config' => ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => amSchema()]],
        'fallbacks' => 'default',
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'x-api-key: ' . $c['cle'], 'anthropic-version: 2023-06-01', 'anthropic-beta: server-side-fallback-2026-07-01'],
            $c['espace'] !== '' ? ['anthropic-workspace-id: ' . $c['espace']] : []),
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
    return AM_QUESTIONS + ['actif' => class_exists('Anthropic') && Anthropic::configured()];
}

/** POST /assistant/magasin {shop, question, fil:[{q, r}]} → {reponse:{titre, resume, chiffres, points, priorites, suites}, luLe}. */
function wr_assistant_magasin(): array
{
    $b = body();
    $sid = ctype_digit((string) ($b['shop'] ?? '')) ? (int) $b['shop'] : 0;
    $q = trim(preg_replace('/\s+/u', ' ', (string) ($b['question'] ?? '')));
    if ($sid <= 0 || $q === '') { http_response_code(422); return ['error' => 'magasin ou question manquant']; }
    if (mb_strlen($q) > 500) { http_response_code(422); return ['error' => 'question trop longue (500 caractères au plus)']; }
    $faits = amFaits($sid);
    $r = amDemander($faits, $q, is_array($b['fil'] ?? null) ? $b['fil'] : []);
    if (isset($r['erreur'])) { http_response_code(502); return ['error' => $r['erreur']]; }
    if (function_exists('journalAdd')) { journalAdd('Dashboard', 'Assistant', null, 'Magasin ' . $sid . ' : « ' . mb_substr($q, 0, 120) . ' »'); }
    return ['reponse' => $r['reponse'], 'luLe' => $faits['luLe'] ?? null, 'modele' => $r['modele']];
}
