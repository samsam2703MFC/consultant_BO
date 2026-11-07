<?php
declare(strict_types=1);

/*
 * Répartition des charges alignée sur le Compte de résultat réseau du CRM (07/11/2026).
 *
 * Client : « option A, % validé réseau, corrige les fautes, mets en ligne — le CRM fait foi ; dans le Cockpit, c'est
 * pour voir si le franchisé est dans les clous ». Analyse et maquette : docs/maquettes/budget-pnl-crm/.
 *
 * Le CRM (portail Recrutement & Développement) tient le compte de résultat du réseau : `impl_pnl`, rubriques → postes
 * à CLÉ stable (`royalties_pct`…), sous-totaux « a − b », un % réseau par poste — celui du DIP, du contrat et des
 * dossiers d'implantation. Le Cockpit le LIT par la route d'intégration du Recrutement
 * (`GET …/v1/integration/recrutement/compte-resultat`, jeton de service, `erpLireRecrutement`) — jamais ses tables —
 * et réécrit SON réglage `budgetCharges` d'après lui :
 *   - catégories = rubriques, lignes = postes (libellé, description, gestion, PCMN, clé `cle`), dans l'ordre du CRM ;
 *   - `pctTheo` (« % réseau ») = le % du CRM, verrouillé à l'écran ;
 *   - `pct` (« % validé », un taux RÉSEAU) = celui du Cockpit, gardé : la ligne du Cockpit qui portait déjà la clé,
 *     sinon reconnue à son libellé (même libellé, même premier mot, ou un mot distinctif — « assistance » rejoint
 *     « Royalties franchise & assistance ») ; deux lignes du Cockpit pour un poste du CRM = leurs % validés additionnés ;
 *   - l'IDENTIFIANT d'une ligne est gardé (les charges encodées mois par mois, `ceo_shop_charge_month`, s'y
 *     rattachent) ; celles d'une ligne fondue dans une autre y sont reportées ;
 *   - étapes = sous-totaux du CRM (« Marge brute = CA − Matière… », « Disponible pour rémunération = CA − toutes les
 *     rubriques » → opérande `tout`).
 * Une ligne du Cockpit sans poste du CRM, qui porte des charges encodées, est gardée dans « Hors compte de résultat du
 * CRM » (rien ne se perd) ; vide, elle disparaît. Avant la première synchronisation, le réglage est sauvé dans
 * `budgetCharges_avant_crm_2026_11`. État dans `budgetChargesCrm` : {ok, lu, modifier, erreur, labels}.
 *
 * Quand : à la lecture de /stores/budgets si la dernière lecture a plus de BUDGET_CRM_TTL secondes, et sur
 * « ↻ Relire le CRM » (POST /budget/charges/crm). Le CRM injoignable : la dernière copie reste, l'écran le dit.
 * Le seuil coût matière du dashboard (`seuilsCharges`) suit : il lit la catégorie que l'étape « marge brute » retranche.
 */

const BUDGET_CRM_TTL = 600;   // dix minutes entre deux lectures du CRM

/** Un libellé ramené à ses mots : sans accents ni casse ni ponctuation. */
function bcMots(string $s): array
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', '’' => ' ', "'" => ' ']);
    $m = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter($m, static fn ($w) => !in_array($w, ['de', 'des', 'du', 'la', 'le', 'les', 'et', 'en', 'etc', 'a'], true)));
}

/** L'état de la dernière lecture du CRM. */
function bcEtat(): array
{
    $e = setting('budgetChargesCrm');
    return is_array($e) ? $e : [];
}

/** Synchronise si la dernière lecture est trop ancienne (lecture de /stores/budgets). */
function bcSyncSiVieux(): void
{
    $e = bcEtat();
    $lu = isset($e['tente']) ? strtotime((string) $e['tente']) : 0;
    if ($lu !== false && $lu > 0 && time() - $lu < BUDGET_CRM_TTL) { return; }
    try { bcSync(false); } catch (Throwable $x) { error_log('budget CRM : ' . $x->getMessage()); }
}

/** POST /budget/charges/crm — relire le CRM tout de suite. */
function wr_budget_crm(): array
{
    $r = bcSync(true);
    if (!$r['ok']) { http_response_code(502); }
    return $r;
}

/**
 * Lit le Compte de résultat réseau du CRM et réécrit `budgetCharges` d'après lui. Rend {ok, change, …}.
 * $journal : écrire au journal quand la structure ou un % réseau change.
 */
function bcSync(bool $journal): array
{
    $etat = bcEtat();
    $etat['tente'] = date('Y-m-d H:i:s');
    $d = function_exists('erpLireRecrutement') ? erpLireRecrutement('compte-resultat') : null;
    $pnl = is_array($d) && is_array($d['pnl'] ?? null) ? $d['pnl'] : null;
    if ($pnl === null || !is_array($pnl['items'] ?? null)) {
        $etat['erreur'] = 'Compte de résultat du CRM illisible (route d’intégration du Recrutement injoignable, ou jeton refusé).';
        bcEcrireEtat($etat);
        return ['ok' => false, 'erreur' => $etat['erreur'], 'lu' => $etat['lu'] ?? null];
    }
    $avant = setting('budgetCharges');
    $avant = is_array($avant) ? $avant : ['categories' => [], 'paliers' => []];
    if (setting('budgetCharges_avant_crm_2026_11') === null) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = value',
            ['budgetCharges_avant_crm_2026_11', json_encode($avant, JSON_UNESCAPED_UNICODE)]);
    }

    // Les lignes du Cockpit, à plat.
    $anciennes = [];
    foreach ((array) ($avant['categories'] ?? []) as $c) {
        foreach ((array) ($c['lignes'] ?? []) as $l) {
            if (!is_array($l) || trim((string) ($l['poste'] ?? '')) === '') { continue; }
            $anciennes[] = $l + ['_cat' => (string) ($c['nom'] ?? '')];
        }
    }
    $prise = array_fill(0, count($anciennes), null);   // index de ligne du Cockpit → clé du CRM

    // Les postes du CRM, dans l'ordre.
    $postes = [];
    foreach ($pnl['items'] as $it) {
        if (($it['kind'] ?? '') !== 'rubrique') { continue; }
        foreach ((array) ($it['postes'] ?? []) as $p) {
            if (is_array($p) && ($p['key'] ?? '') !== '') { $postes[(string) $p['key']] = $p; }
        }
    }
    // 1. la clé déjà posée ; 2. le même libellé ; 3. le même premier mot ; 4. un mot distinctif (6 lettres et plus).
    $regles = [
        static fn (array $l, string $k, array $p): bool => ($l['cle'] ?? '') === $k,
        static fn (array $l, string $k, array $p): bool => bcMots((string) $l['poste']) === bcMots((string) ($p['libelle'] ?? '')),
        static function (array $l, string $k, array $p): bool {
            $a = bcMots((string) $l['poste']); $b = bcMots((string) ($p['libelle'] ?? ''));
            return $a !== [] && $b !== [] && $a[0] === $b[0] && strlen($a[0]) >= 4;
        },
        static function (array $l, string $k, array $p): bool {
            $b = array_filter(bcMots((string) ($p['libelle'] ?? '')), static fn ($w) => strlen($w) >= 6);
            return $b !== [] && array_intersect(bcMots((string) $l['poste']), $b) !== [];
        },
    ];
    foreach ($regles as $regle) {
        foreach ($anciennes as $i => $l) {
            if ($prise[$i] !== null) { continue; }
            foreach ($postes as $k => $p) {
                if ($regle($l, (string) $k, $p)) { $prise[$i] = (string) $k; break; }
            }
        }
    }

    // Les catégories et les étapes, d'après le CRM.
    $cats = []; $paliers = []; $libRub = []; $libSt = []; $derniere = ''; $fondues = [];
    foreach ($pnl['items'] as $it) {
        $kind = (string) ($it['kind'] ?? '');
        $lib = trim((string) ($it['libelle'] ?? ''));
        if ($kind === 'rubrique') {
            $lignes = [];
            foreach ((array) ($it['postes'] ?? []) as $p) {
                $k = (string) ($p['key'] ?? '');
                if ($k === '') { continue; }
                $miennes = array_keys(array_filter($prise, static fn ($x) => $x === $k));
                $id = '';
                $valide = 0.0;
                foreach ($miennes as $j) {
                    $valide += (float) ($anciennes[$j]['pct'] ?? 0);
                    $idj = (string) ($anciennes[$j]['id'] ?? '');
                    if ($id === '') { $id = $idj; } elseif ($idj !== '') { $fondues[$idj] = $id; }
                }
                if ($id === '') { $id = 'k' . substr(preg_replace('/[^a-z0-9]/', '', $k), 0, 30); }
                $lignes[] = ['id' => $id, 'cle' => $k, 'poste' => (string) ($p['libelle'] ?? ''), 'description' => (string) ($p['description'] ?? ''),
                    'gestion' => (string) ($p['gestion'] ?? ''), 'pcmn' => (string) ($p['pcmn'] ?? ''),
                    'pct' => round($valide, 2), 'pctTheo' => round((float) ($p['pct'] ?? 0), 2),
                    'special' => ($p['special'] ?? null) === 'loyer' ? 'loyer' : null];
            }
            $cats[] = ['nom' => $lib !== '' ? $lib : 'Rubrique', 'lignes' => $lignes];
            $libRub[(string) ($it['key'] ?? '')] = $cats[count($cats) - 1]['nom'];
            $derniere = $cats[count($cats) - 1]['nom'];
        } elseif ($kind === 'soustotal') {
            $a = (string) ($it['a'] ?? 'ca'); $b = (string) ($it['b'] ?? '');
            $paliers[] = ['nom' => $lib !== '' ? $lib : 'Sous-total',
                'gauche' => $a === 'ca' || !isset($libSt[$a]) ? 'ca' : 'pal:' . $libSt[$a],
                'droite' => $b === 'all' || $b === 'tout' ? 'tout' : (isset($libRub[$b]) ? 'cat:' . $libRub[$b] : (isset($libSt[$b]) ? 'pal:' . $libSt[$b] : '')),
                'apres' => $derniere];
            $libSt[(string) ($it['key'] ?? '')] = $paliers[count($paliers) - 1]['nom'];
        }
    }

    // Une ligne du Cockpit sans poste du CRM : gardée si des charges y sont encodées.
    $hors = [];
    foreach ($anciennes as $i => $l) {
        if ($prise[$i] !== null) { continue; }
        $id = (string) ($l['id'] ?? '');
        $n = $id !== '' ? (int) (Db::row('SELECT COUNT(*) n FROM ceo_shop_charge_month WHERE poste_id = ?', [$id])['n'] ?? 0) : 0;
        if ($n > 0) {
            $hors[] = ['id' => $id, 'poste' => (string) $l['poste'], 'description' => (string) ($l['description'] ?? ''),
                'gestion' => (string) ($l['gestion'] ?? ''), 'pcmn' => (string) ($l['pcmn'] ?? ''),
                'pct' => (float) ($l['pct'] ?? 0), 'pctTheo' => 0.0, 'hors' => true];
        }
    }
    if ($hors !== []) { $cats[] = ['nom' => 'Hors compte de résultat du CRM', 'lignes' => $hors, 'hors' => true]; }

    // Les charges encodées d'une ligne fondue rejoignent celle qui reste.
    foreach ($fondues as $de => $vers) {
        foreach (Db::rows('SELECT shop_id, fiscal_year, month, amount FROM ceo_shop_charge_month WHERE poste_id = ?', [$de]) as $r) {
            Db::exec('INSERT INTO ceo_shop_charge_month (shop_id, fiscal_year, month, poste_id, amount) VALUES (?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)', [$r['shop_id'], $r['fiscal_year'], $r['month'], $vers, $r['amount']]);
        }
        Db::exec('DELETE FROM ceo_shop_charge_month WHERE poste_id = ?', [$de]);
    }

    $nouveau = ['categories' => $cats, 'paliers' => $paliers, 'source' => 'crm'];
    $change = json_encode(bcEmpreinte($avant)) !== json_encode(bcEmpreinte($nouveau));
    if ($change) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['budgetCharges', json_encode($nouveau, JSON_UNESCAPED_UNICODE)]);
        $n = 0; foreach ($cats as $c) { $n += count($c['lignes']); }
        try {
            journalAdd('Cockpit', 'Budget', null, 'Répartition des charges alignée sur le Compte de résultat réseau du CRM — '
                . count($cats) . ' rubrique(s), ' . $n . ' poste(s), ' . count($paliers) . ' étape(s)'
                . ($fondues ? ', ' . count($fondues) . ' ligne(s) fondue(s)' : '') . ($hors ? ', ' . count($hors) . ' hors CRM' : ''));
        } catch (Throwable $x) { /* le journal ne bloque pas */ }
    }
    $etat = ['ok' => true, 'lu' => date('Y-m-d H:i:s'), 'tente' => $etat['tente'], 'erreur' => null,
        'modifier' => is_string($d['modifier'] ?? null) ? $d['modifier'] : null,
        'labels' => is_array($pnl['labels'] ?? null) ? $pnl['labels'] : null];
    bcEcrireEtat($etat);
    return ['ok' => true, 'change' => $change, 'lu' => $etat['lu'], 'rubriques' => count($cats), 'etapes' => count($paliers)];
}

/** Ce qui compte pour dire « changé » : structure, libellés, % (pas l'ordre des clés du JSON). */
function bcEmpreinte(array $m): array
{
    $c = [];
    foreach ((array) ($m['categories'] ?? []) as $cat) {
        $c[] = [(string) ($cat['nom'] ?? ''), array_map(static fn ($l) => [(string) ($l['id'] ?? ''), (string) ($l['cle'] ?? ''),
            (string) ($l['poste'] ?? ''), (string) ($l['description'] ?? ''), (string) ($l['gestion'] ?? ''), (string) ($l['pcmn'] ?? ''),
            round((float) ($l['pct'] ?? 0), 2), round((float) ($l['pctTheo'] ?? 0), 2)], (array) ($cat['lignes'] ?? []))];
    }
    return [$c, array_map(static fn ($p) => [(string) ($p['nom'] ?? ''), (string) ($p['gauche'] ?? ''), (string) ($p['droite'] ?? ''),
        (string) ($p['apres'] ?? '')], (array) ($m['paliers'] ?? [])), (string) ($m['source'] ?? '')];
}

function bcEcrireEtat(array $e): void
{
    Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
        ['budgetChargesCrm', json_encode($e, JSON_UNESCAPED_UNICODE)]);
}

/**
 * Enregistrement du modèle quand il vient du CRM : seul le « % validé » (`pct`) de chaque ligne change ; structure,
 * libellés, % réseau et étapes restent ceux du CRM. Rend le modèle à écrire.
 */
function bcGarderStructure(array $stocke, array $recu): array
{
    $pct = [];
    foreach ((array) ($recu['categories'] ?? []) as $c) {
        foreach ((array) ($c['lignes'] ?? []) as $l) {
            $id = preg_replace('/[^a-z0-9]+/', '', strtolower((string) ($l['id'] ?? '')));
            if ($id !== '') { $pct[$id] = max(0, min(100, (float) ($l['pct'] ?? 0))); }
        }
    }
    foreach ($stocke['categories'] as $i => $c) {
        foreach ((array) ($c['lignes'] ?? []) as $j => $l) {
            $id = (string) ($l['id'] ?? '');
            if (isset($pct[$id])) { $stocke['categories'][$i]['lignes'][$j]['pct'] = $pct[$id]; }
        }
    }
    return $stocke;
}
