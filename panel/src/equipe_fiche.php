<?php
declare(strict_types=1);

/**
 * Mon équipe › LA FICHE D'UNE PERSONNE (24/11/2026, Sam : « dans Mon équipe je n'ai pas accès à l'édition de chaque
 * membre du personnel, évaluation, coût… », maquette panel/docs/maquettes/equipe-gestion/fiche-personne/, variante A :
 * une fenêtre à onglets ; « rajouter un onglet avec ses KPI de vente et un graphique sur les 12 dernières semaines »).
 *
 * Ce que TF Buddy garde d'une personne (swagger : Employee, EmployeeWrite, EmployeeEvaluationRaw), rien de plus :
 *   GET   /dashboard/equipe/personne?shop=&id=              la fiche (identité, contrat, taux, compétences, postes)
 *   PATCH /dashboard/equipe/personne {shop, id, champs, avant}  prénom, nom, nom affiché, langue, contrat, rôle, taux
 *   GET   /dashboard/equipe/personne/evaluations?shop=&id=  ses notes (une par service) et les services des 14 derniers
 *                                                            jours à noter
 *   POST  /dashboard/equipe/personne/evaluation {shop, id, service, date, note 1..5, mot}
 *   GET   /dashboard/equipe/personne/ventes?shop=&id=       les 12 dernières semaines complètes : vendu, tickets, panier,
 *                                                            pièces par ticket ; l'équipe ; les cibles du réseau
 *
 * Les contrôles du cockpit (comme les postes, equipe.php) : la personne est relue dans la liste des employés DU
 * magasin avant toute lecture de sa fiche et toute écriture ; un service noté est relu au planning du magasin, à sa
 * date, et doit être le sien. Le compte : celui du cockpit, puis le compte admin de l'ERP s'il est refusé
 * (eqpGetParallele, eqpEcrire). Le téléphone, le PIN et la désactivation ne s'écrivent pas d'ici (un changement de
 * téléphone ou de PIN révoque les accès de la personne : c'est au panel).
 *
 * Les ventes ne relisent pas les tickets : les jours déjà gravés par le cockpit (pvVentesJour : vendu et tickets par
 * vendeuse ; la moisson des tickets, pvLignesJour : les LIGNES par ticket, la règle du cockpit — « pièces par ticket »
 * à l'écran). Un jour de ventes manquant se lit (au plus EQF_VENTES_LECTURES par appel) ; les lignes, jamais : la
 * moisson est au cron. La couverture est rendue avec les chiffres.
 */

const EQF_CONTRATS = ['employment_contract' => 'Contrat de travail', 'civil_law_contract' => 'Contrat civil', 'b2b' => 'B2B',
    'temporary_work' => 'Intérim', 'internship' => 'Stage', 'volunteer' => 'Bénévole', 'other' => 'Autre'];
const EQF_ROLES = ['employee' => 'Employé·e', 'franchisee' => 'Franchisé·e (hors primes)', 'franchise_partner' => 'Associé·e'];
const EQF_LANGUES = ['fr' => 'Français', 'nl' => 'Néerlandais', 'en' => 'Anglais', 'pl' => 'Polonais'];
const EQF_SEMAINES = 12;
const EQF_A_NOTER_JOURS = 14;
const EQF_VENTES_LECTURES = 10;
/** Les tickets en dessous desquels une personne ne compte pas dans le rang au panier moyen (12 semaines). */
const EQF_RANG_TICKETS_MIN = 30;

function eqfRoute(string $method, string $path): ?array
{
    if ($path === '/dashboard/equipe/personne') {
        if ($method === 'GET') { return ep_equipe_personne(); }
        if ($method === 'PATCH') { return wr_equipe_personne(); }
    }
    if ($method === 'GET' && $path === '/dashboard/equipe/personne/evaluations') { return ep_equipe_personne_evaluations(); }
    if ($method === 'POST' && $path === '/dashboard/equipe/personne/evaluation') { return wr_equipe_personne_evaluation(); }
    if ($method === 'GET' && $path === '/dashboard/equipe/personne/ventes') { return ep_equipe_personne_ventes(); }
    return null;
}

/** L'identifiant d'une personne : la requête (GET) ou le corps. */
function eqfId(?array $b = null): int
{
    $v = $b === null ? ($_GET['id'] ?? 0) : ($b['id'] ?? 0);
    return is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : 0;
}

/**
 * La personne, relue dans la liste des employés du magasin : [entrée brute | null, erreur | null].
 * L'erreur est la réponse à rendre (le panel muet, ou une personne d'un autre magasin).
 */
function eqfPersonneDuMagasin(int $shop, int $id): array
{
    if (!class_exists('PanelApi') || !PanelApi::configured()) { return [null, eqpErreur(503, 'compte du panel non configuré')]; }
    $L = eqpLire($shop, ['emp']);
    if ($L['emp'] === null) { return [null, eqpErreur(502, 'le panel n’a pas rendu l’équipe du magasin')]; }
    foreach ($L['emp'] as $r) {
        if (is_array($r) && (int) ($r['id'] ?? 0) === $id && (int) ($r['id_shop'] ?? 0) === $shop) { return [$r, null]; }
    }
    return [null, eqpErreur(404, 'cette personne n’est pas de l’équipe du magasin', ['motif' => 'employe_inconnu'])];
}

/** Le nom d'un élément d'une liste du panel (compétence, poste, rôle) : name, title, label ou code. */
function eqfNomDe(mixed $x): string
{
    if (is_string($x)) { return trim($x); }
    if (!is_array($x)) { return ''; }
    foreach (['name', 'title', 'label', 'display_name', 'code'] as $k) { if (isset($x[$k]) && is_scalar($x[$k]) && trim((string) $x[$k]) !== '') { return trim((string) $x[$k]); } }
    return '';
}

/** La fiche, réduite à ce que l'écran montre (jamais un secret ni le téléphone en clair). */
function eqfFiche(array $r, ?array $detail = null): array
{
    $d = is_array($detail) ? $detail + $r : $r;
    $tel = (string) ($d['phone_e164'] ?? $d['phone'] ?? '');
    $chiffres = preg_replace('/\D/', '', $tel) ?? '';
    $niveau = static function ($p): ?string {
        if (!is_array($p)) { return null; }
        foreach (['level', 'position_level', 'current_level'] as $k) { if (isset($p[$k])) { $n = eqfNomDe($p[$k]); if ($n !== '') { return $n; } if (is_scalar($p[$k])) { return (string) $p[$k]; } } }
        return null;
    };
    return eqpPersonne($r) + [
        'prenom' => trim((string) ($d['name'] ?? '')), 'nomFamille' => trim((string) ($d['surname'] ?? '')),
        'affiche' => trim((string) ($d['display_name'] ?? '')),
        'langue' => strtolower(trim((string) ($d['lang_code'] ?? ''))),
        'contrat' => array_key_exists((string) ($d['employment_type'] ?? ''), EQF_CONTRATS) ? (string) $d['employment_type'] : null,
        'role' => array_key_exists((string) ($d['franchise_role'] ?? ''), EQF_ROLES) ? (string) $d['franchise_role'] : 'employee',
        // Le téléphone masqué : ses deux derniers chiffres (l'identifiant de l'app employés ; il se change au panel).
        'telephone' => strlen($chiffres) >= 6 ? '+' . substr($chiffres, 0, 2) . ' ·· ·· ' . substr($chiffres, -2) : null,
        'pin' => !empty($d['pin_set']), 'appPrete' => !empty($d['login_ready']),
        'roles' => array_values(array_filter(array_map('eqfNomDe', (array) ($d['roles'] ?? [])))),
        'competences' => array_values(array_filter(array_map('eqfNomDe', (array) ($d['competencies'] ?? [])))),
        'positions' => array_values(array_filter(array_map(static fn ($p) => ($n = eqfNomDe($p)) !== '' ? ['nom' => $n, 'niveau' => $niveau($p)] : null, (array) ($d['positions'] ?? [])))),
    ];
}

/** GET /dashboard/equipe/personne?shop=&id= */
function ep_equipe_personne(?int $shop = null, ?int $id = null): array
{
    $shop ??= eqpShop(); $id ??= eqfId();
    if ($shop <= 0 || $id <= 0) { return eqpErreur(400, 'magasin et personne attendus'); }
    [$r, $err] = eqfPersonneDuMagasin($shop, $id);
    if ($err !== null) { return $err; }
    $detail = eqpGet('/employees/' . $id . '?include=competencies,positions');
    if (is_array($detail) && isset($detail['data']) && is_array($detail['data']) && !array_is_list($detail['data'])) { $detail = $detail['data']; }
    if (!is_array($detail) || (int) ($detail['id'] ?? $id) !== $id) { $detail = null; }
    return ['personne' => eqfFiche($r, $detail), 'detailLu' => $detail !== null,
        'choix' => ['contrats' => EQF_CONTRATS, 'roles' => EQF_ROLES, 'langues' => EQF_LANGUES]];
}

/**
 * Le corps du PATCH : seulement les champs changés, validés ; [corps, erreur | null]. `avant` : ce que l'écran a lu —
 * un champ qui a changé au panel depuis rend un conflit (409) plutôt que d'écraser.
 */
function eqfCorpsPatch(array $champs, array $actuel, array $avant): array
{
    $map = ['prenom' => 'name', 'nomFamille' => 'surname', 'affiche' => 'display_name', 'langue' => 'lang_code',
        'contrat' => 'employment_type', 'role' => 'franchise_role', 'taux' => 'money_per_hour'];
    $corps = []; $conflit = [];
    foreach ($champs as $k => $v) {
        if (!isset($map[$k])) { return [[], 'champ inconnu : ' . $k]; }
        if ($k === 'taux') {
            if (!is_numeric($v) || (float) $v <= 0 || (float) $v > 100) { return [[], 'taux horaire : un nombre entre 0 et 100 €/h']; }
            $v = round((float) $v, 2);
            if ($actuel['taux'] !== null && abs($actuel['taux'] - $v) < 0.005) { continue; }
        } elseif ($k === 'contrat') {
            if (!is_string($v) || !array_key_exists($v, EQF_CONTRATS)) { return [[], 'type de contrat inconnu']; }
            if ($actuel['contrat'] === $v) { continue; }
        } elseif ($k === 'role') {
            if (!is_string($v) || !array_key_exists($v, EQF_ROLES)) { return [[], 'rôle inconnu']; }
            if ($actuel['role'] === $v) { continue; }
        } elseif ($k === 'langue') {
            if (!is_string($v) || !preg_match('/^[a-z]{2}$/', $v)) { return [[], 'langue : un code de deux lettres']; }
            if ($actuel['langue'] === $v) { continue; }
        } else {
            if (!is_string($v)) { return [[], $k . ' : du texte attendu']; }
            $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
            if (($k === 'prenom' && $v === '') || mb_strlen($v) > 60) { return [[], $k === 'prenom' ? 'le prénom ne peut pas être vide' : $k . ' : 60 caractères au plus']; }
            if ($actuel[$k] === $v) { continue; }
        }
        if (array_key_exists($k, $avant) && $avant[$k] !== null && (string) $avant[$k] !== (string) $actuel[$k]
            && !($k === 'taux' && is_numeric($avant[$k]) && $actuel['taux'] !== null && abs((float) $avant[$k] - $actuel['taux']) < 0.005)) { $conflit[] = $k; }
        $corps[$map[$k]] = $v;
    }
    if ($conflit !== []) { return [[], 'conflit']; }
    return [$corps, null];
}

/** PATCH /dashboard/equipe/personne {shop, id, champs: {…}, avant: {…}} */
function wr_equipe_personne(): array
{
    $b = body();
    $shop = eqpShop($b); $id = eqfId($b);
    if ($shop <= 0 || $id <= 0) { return eqpErreur(400, 'magasin et personne attendus'); }
    $champs = is_array($b['champs'] ?? null) ? $b['champs'] : [];
    if ($champs === []) { return eqpErreur(422, 'rien à enregistrer'); }
    [$r, $err] = eqfPersonneDuMagasin($shop, $id);
    if ($err !== null) { return $err; }
    $p = eqfFiche($r);
    if (!$p['actif']) { return eqpErreur(422, 'cette personne est désactivée', ['motif' => 'employe_inactif']); }
    [$corps, $e] = eqfCorpsPatch($champs, $p, is_array($b['avant'] ?? null) ? $b['avant'] : []);
    if ($e === 'conflit') { return eqpErreur(409, 'la fiche a changé au panel entre-temps : relisez-la avant de l’enregistrer', ['motif' => 'conflit']); }
    if ($e !== null) { return eqpErreur(422, $e, ['motif' => 'champ_invalide']); }
    if ($corps === []) { return ['ok' => true, 'inchange' => true] + ep_equipe_personne($shop, $id); }
    $w = eqpEcrire('PATCH', '/employees/' . $id, $corps);
    if ($w['code'] < 200 || $w['code'] >= 300) {
        return eqpErreur(in_array($w['code'], [400, 403, 404, 409, 422], true) ? $w['code'] : 502, 'le panel a refusé la modification', ['detail' => $w['erreur']]);
    }
    $lib = ['name' => 'prénom', 'surname' => 'nom', 'display_name' => 'nom affiché', 'lang_code' => 'langue', 'employment_type' => 'contrat', 'franchise_role' => 'rôle', 'money_per_hour' => 'taux horaire'];
    try { journalAdd('Magasin ' . $shop, 'Équipe', null, 'Fiche de ' . $p['nom'] . ' : ' . implode(', ', array_map(static fn ($k) => $lib[$k] . ($k === 'money_per_hour' ? ' ' . number_format((float) $corps[$k], 2, ',', ' ') . ' €/h' : ''), array_keys($corps)))); }
    catch (Throwable $x) { /* le journal ne bloque pas */ }
    return ['ok' => true] + ep_equipe_personne($shop, $id);
}

/* ── Les évaluations ──────────────────────────────────────────────────────────────────────────────────────────── */

/** Une évaluation du panel, réduite : {id, service, date, de, a, note, mot, par}. */
function eqfEvaluation(array $x, array $noms): array
{
    $par = (int) ($x['evaluated_by'] ?? 0);
    return ['id' => (int) ($x['id'] ?? 0), 'service' => (int) ($x['id_schedule'] ?? 0),
        'date' => substr((string) ($x['evaluated_date'] ?? $x['work_date'] ?? $x['created_at'] ?? ''), 0, 10),
        'de' => substr((string) ($x['start_hour'] ?? ''), 0, 5), 'a' => substr((string) ($x['end_hour'] ?? ''), 0, 5),
        'note' => isset($x['rating']) && is_numeric($x['rating']) ? (int) $x['rating'] : null,
        'mot' => trim((string) ($x['note'] ?? '')), 'par' => $noms[$par] ?? null];
}

/**
 * Les services passés d'une personne, pas encore notés : [{service, date, de, a}], du plus récent au plus ancien.
 * $services : date → services du planning (eqpServices) ; $notes : les identifiants de service déjà notés.
 */
function eqfANoter(int $id, array $services, array $notes, string $maintenant): array
{
    $out = [];
    foreach ($services as $date => $L) {
        foreach ($L as $s) {
            if ($s['employe'] !== $id || $s['id'] <= 0 || isset($notes[$s['id']])) { continue; }
            if ($date . ' ' . $s['a'] > $maintenant) { continue; }   // pas fini
            $out[] = ['service' => $s['id'], 'date' => $date, 'de' => $s['de'], 'a' => $s['a']];
        }
    }
    usort($out, static fn ($x, $y) => strcmp($y['date'] . $y['de'], $x['date'] . $x['de']));
    return $out;
}

/** GET /dashboard/equipe/personne/evaluations?shop=&id= */
function ep_equipe_personne_evaluations(?int $shop = null, ?int $id = null): array
{
    $shop ??= eqpShop(); $id ??= eqfId();
    if ($shop <= 0 || $id <= 0) { return eqpErreur(400, 'magasin et personne attendus'); }
    [$r, $err] = eqfPersonneDuMagasin($shop, $id);
    if ($err !== null) { return $err; }
    $jours = [];
    for ($i = 0; $i < EQF_A_NOTER_JOURS; $i++) { $jours[] = date('Y-m-d', strtotime('-' . $i . ' days')); }
    $chemins = ['ev' => '/employees/' . $id . '/evaluations', 'emp' => '/shops/' . $shop . '/employees'];
    foreach ($jours as $j) { $chemins['sch' . $j] = '/shops/' . $shop . '/schedule?date=' . $j; }
    $res = eqpGetParallele($chemins, 6);
    $noms = [];
    foreach (analyseListe($res['emp'] ?? null) as $e) { if (is_array($e)) { $p = eqpPersonne($e); $noms[$p['id']] = $p['nom']; } }
    $ev = [];
    foreach (analyseListe($res['ev'] ?? null) as $x) { if (is_array($x) && (int) ($x['id_employee'] ?? $id) === $id) { $ev[] = eqfEvaluation($x, $noms); } }
    usort($ev, static fn ($x, $y) => strcmp($y['date'] . $y['de'], $x['date'] . $x['de']));
    $notes = [];
    foreach ($ev as $x) { if ($x['service'] > 0) { $notes[$x['service']] = true; } }
    $services = [];
    foreach ($jours as $j) { $services[$j] = is_array($res['sch' . $j] ?? null) ? eqpServices($res['sch' . $j], $shop, $j) : []; }
    $notees = array_values(array_filter(array_column($ev, 'note'), static fn ($n) => $n !== null));
    return ['evaluations' => $ev, 'lues' => is_array($res['ev'] ?? null),
        'aNoter' => eqfANoter($id, $services, $notes, date('Y-m-d H:i')), 'joursLus' => count(array_filter($jours, static fn ($j) => is_array($res['sch' . $j] ?? null))),
        'moyenne' => $notees !== [] ? round(array_sum($notees) / count($notees), 2) : null, 'echelle' => 5];
}

/** POST /dashboard/equipe/personne/evaluation {shop, id, service, date, note: 1..5, mot} */
function wr_equipe_personne_evaluation(): array
{
    $b = body();
    $shop = eqpShop($b); $id = eqfId($b);
    $sv = is_int($b['service'] ?? null) ? $b['service'] : 0; $date = is_string($b['date'] ?? null) ? $b['date'] : '';
    $note = $b['note'] ?? null; $mot = is_string($b['mot'] ?? null) ? trim($b['mot']) : '';
    if ($shop <= 0 || $id <= 0 || $sv <= 0) { return eqpErreur(400, 'magasin, personne et service attendus'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d') || $date < date('Y-m-d', strtotime('-60 days'))) { return eqpErreur(422, 'date : un jour des 60 derniers jours', ['motif' => 'date']); }
    if (!is_int($note) || $note < 1 || $note > 5) { return eqpErreur(422, 'la note : de 1 à 5', ['motif' => 'note']); }
    if (mb_strlen($mot) > 500) { return eqpErreur(422, 'le mot : 500 caractères au plus', ['motif' => 'mot']); }
    [$r, $err] = eqfPersonneDuMagasin($shop, $id);
    if ($err !== null) { return $err; }
    $sch = eqpGet('/shops/' . $shop . '/schedule?date=' . $date);
    if (!is_array($sch)) { return eqpErreur(502, 'le panel n’a pas rendu le planning de ce jour : rien n’est enregistré'); }
    $s = null;
    foreach (eqpServices($sch, $shop, $date) as $x) { if ($x['id'] === $sv) { $s = $x; break; } }
    if ($s === null || $s['employe'] !== $id) { return eqpErreur(404, 'ce service n’est pas celui de cette personne ce jour-là', ['motif' => 'service_inconnu']); }
    if ($date . ' ' . $s['a'] > date('Y-m-d H:i')) { return eqpErreur(422, 'ce service n’est pas encore fini', ['motif' => 'pas_fini']); }
    $w = eqpEcrire('POST', '/schedule/' . $sv . '/evaluation', ['rating' => $note] + ($mot !== '' ? ['note' => $mot] : []));
    if ($w['code'] < 200 || $w['code'] >= 300) {
        return eqpErreur(in_array($w['code'], [400, 403, 404, 422], true) ? $w['code'] : 502, 'le panel a refusé la note', ['detail' => $w['erreur']]);
    }
    try { journalAdd('Magasin ' . $shop, 'Équipe', null, 'Note ' . $note . '/5 pour ' . eqpPersonne($r)['nom'] . ' (service du ' . $date . ', ' . $s['de'] . '–' . $s['a'] . ')'); }
    catch (Throwable $x) { /* le journal ne bloque pas */ }
    return ['ok' => true] + ep_equipe_personne_evaluations($shop, $id);
}

/* ── Les ventes : les 12 dernières semaines complètes ─────────────────────────────────────────────────────────── */

/** Les lundis des n dernières semaines complètes avant la semaine de $auj, du plus ancien au plus récent. */
function eqfSemaines(string $auj, int $n = EQF_SEMAINES): array
{
    $lundi = date('Y-m-d', strtotime($auj . ' -' . (((int) date('N', strtotime($auj))) - 1) . ' days'));
    $out = [];
    for ($i = $n; $i >= 1; $i--) { $out[] = date('Y-m-d', strtotime($lundi . ' -' . (7 * $i) . ' days')); }
    return $out;
}

/**
 * Le calcul, pur. $ventes : date → [employé → {t, ca}] (null : jour pas lu) ; $lignes : date → [employé → {l, t}]
 * (null : jour pas moissonné). Rend ses semaines, ses totaux, l'équipe, son rang au panier moyen et la couverture.
 */
function eqfVentesAgreger(int $id, array $semaines, array $ventes, array $lignes): array
{
    $S = []; $moi = ['ca' => 0.0, 't' => 0, 'l' => 0, 'tl' => 0]; $eq = ['ca' => 0.0, 't' => 0, 'l' => 0, 'tl' => 0];
    $parEmp = []; $jv = 0; $jl = 0; $nj = 0;
    foreach ($semaines as $du) {
        $w = ['du' => $du, 'ca' => 0.0, 't' => 0, 'l' => 0, 'tl' => 0, 'jours' => 0, 'joursLignes' => 0];
        for ($k = 0; $k < 7; $k++) {
            $j = date('Y-m-d', strtotime($du . ' +' . $k . ' days')); $nj++;
            $v = $ventes[$j] ?? null; $l = $lignes[$j] ?? null;
            if (is_array($v)) {
                $jv++; $w['jours']++;
                foreach ($v as $e => $x) {
                    $ca = (float) ($x['ca'] ?? 0); $t = (int) ($x['t'] ?? 0);
                    $eq['ca'] += $ca; $eq['t'] += $t;
                    $parEmp[$e] = ['ca' => ($parEmp[$e]['ca'] ?? 0) + $ca, 't' => ($parEmp[$e]['t'] ?? 0) + $t];
                    if ((int) $e === $id) { $w['ca'] += $ca; $w['t'] += $t; }
                }
            }
            if (is_array($l)) {
                $jl++; $w['joursLignes']++;
                foreach ($l as $e => $x) {
                    $eq['l'] += (int) ($x['l'] ?? 0); $eq['tl'] += (int) ($x['t'] ?? 0);
                    if ((int) $e === $id) { $w['l'] += (int) ($x['l'] ?? 0); $w['tl'] += (int) ($x['t'] ?? 0); }
                }
            }
        }
        $moi['ca'] += $w['ca']; $moi['t'] += $w['t']; $moi['l'] += $w['l']; $moi['tl'] += $w['tl'];
        $S[] = ['du' => $du, 'ca' => round($w['ca'], 2), 'tickets' => $w['t'],
            'panier' => $w['t'] > 0 ? round($w['ca'] / $w['t'], 2) : null,
            'pieces' => $w['tl'] > 0 ? round($w['l'] / $w['tl'], 2) : null,
            'jours' => $w['jours'], 'joursLignes' => $w['joursLignes']];
    }
    // Le rang au panier moyen : parmi celles et ceux qui ont au moins EQF_RANG_TICKETS_MIN tickets sur la période.
    $paniers = [];
    foreach ($parEmp as $e => $x) { if ($x['t'] >= EQF_RANG_TICKETS_MIN) { $paniers[(int) $e] = $x['ca'] / $x['t']; } }
    arsort($paniers);
    $rang = array_search($id, array_keys($paniers), true);
    return ['semaines' => $S,
        'total' => ['ca' => round($moi['ca'], 2), 'tickets' => $moi['t'], 'panier' => $moi['t'] > 0 ? round($moi['ca'] / $moi['t'], 2) : null,
            'pieces' => $moi['tl'] > 0 ? round($moi['l'] / $moi['tl'], 2) : null, 'part' => $eq['ca'] > 0 ? round(100 * $moi['ca'] / $eq['ca'], 1) : null],
        'equipe' => ['panier' => $eq['t'] > 0 ? round($eq['ca'] / $eq['t'], 2) : null, 'pieces' => $eq['tl'] > 0 ? round($eq['l'] / $eq['tl'], 2) : null],
        'rang' => $rang === false ? null : ['position' => $rang + 1, 'sur' => count($paniers)],
        'couverture' => ['jours' => $nj, 'ventes' => $jv, 'lignes' => $jl]];
}

/** GET /dashboard/equipe/personne/ventes?shop=&id= */
function ep_equipe_personne_ventes(): array
{
    $shop = eqpShop(); $id = eqfId();
    if ($shop <= 0 || $id <= 0) { return eqpErreur(400, 'magasin et personne attendus'); }
    [$r, $err] = eqfPersonneDuMagasin($shop, $id);
    if ($err !== null) { return $err; }
    $semaines = eqfSemaines(date('Y-m-d'));
    $ventes = []; $lignes = []; $lus = 0;
    foreach ($semaines as $du) {
        for ($k = 0; $k < 7; $k++) {
            $j = date('Y-m-d', strtotime($du . ' +' . $k . ' days'));
            $c = function_exists('setting') ? setting('pvE' . $shop . ':' . $j) : null;
            if (is_array($c) && is_array($c['e'] ?? null)) { $ventes[$j] = $c['e']; }
            elseif ($lus < EQF_VENTES_LECTURES && function_exists('pvVentesJour')) { $lus++; $ventes[$j] = pvVentesJour($shop, $j); }
            else { $ventes[$j] = null; }
            $cl = function_exists('setting') ? setting('pvL' . $shop . ':' . $j) : null;
            $lignes[$j] = is_array($cl) && is_array($cl['e'] ?? null) ? $cl['e'] : null;
        }
    }
    return ['personne' => eqpPersonne($r)['nom']] + eqfVentesAgreger($id, $semaines, $ventes, $lignes)
        + ['cibles' => ['panier' => function_exists('panierCible') ? panierCible() : 13.0, 'pieces' => function_exists('articlesCible') ? articlesCible() : 3.6]];
}
