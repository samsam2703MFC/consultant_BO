<?php
declare(strict_types=1);

/*
 * L'audit des outils de gestion, pendant la visite (17/11/2026, Sam : « dans la tablette et le suivi des franchisés, il
 * me faut un moment pour faire un audit sur le respect des bons process de gestion : plan de trésorerie, les commandes
 * dans l'ordinateur, les étiquettes sur les commandes, étiquettes sur produits, suivi des tâches, revue du soir…
 * Rappeler qu'en ne suivant pas les outils mis en place, aucun effort ne sera fait de la part de la marque en cas de
 * problèmes ou manquements de gestion — dans le mail de rapport et en (i) dans la tablette »).
 * Maquette : docs/maquettes/audit-outils-gestion/.
 *
 * La tablette pose, en tête du temps « Analyse », sept outils : pour chacun ce que le cockpit lit tout seul
 * (GET /visites/outils/{shop}), puis le verdict du consultant (suivi, en partie, pas suivi, sans objet), sa remarque et
 * ses photos. L'audit se garde sur la visite (con_visite.outils, viOutils) et part dans le rapport, avec le rappel.
 * Les fonctions pures (liste, contrôle, score, textes) servent aussi à suivi.php et à bin/tests_suivi.php, sans base.
 */

/** Les sept outils : clé, libellés du rapport dans les quatre langues, ce que le consultant regarde, lu tout seul ou vu sur place. */
function viOutilsListe(): array
{
    return [
        ['k' => 'tresorerie', 'auto' => true, 'fr' => 'Plan de trésorerie', 'nl' => 'Thesaurieplan', 'en' => 'Cash-flow plan', 'de' => 'Liquiditätsplan',
            'aide' => 'Dashboard magasin › Trésorerie : tenu chaque semaine, fournisseurs et paiements saisis, solde réel du dimanche'],
        ['k' => 'commandes', 'auto' => true, 'fr' => 'Les commandes dans l’ordinateur', 'nl' => 'De bestellingen in de computer', 'en' => 'Orders entered in the computer', 'de' => 'Die Bestellungen im Computer',
            'aide' => 'Chaque commande client encodée au panel le jour où elle est prise ; aucun carnet, aucune feuille volante'],
        ['k' => 'etiq_commandes', 'auto' => false, 'fr' => 'Les étiquettes sur les commandes', 'nl' => 'De etiketten op de bestellingen', 'en' => 'Labels on orders', 'de' => 'Die Etiketten auf den Bestellungen',
            'aide' => 'Chaque commande préparée porte son étiquette : nom du client, jour et heure de retrait'],
        ['k' => 'etiq_produits', 'auto' => false, 'fr' => 'Les étiquettes sur les produits', 'nl' => 'De etiketten op de producten', 'en' => 'Labels on products', 'de' => 'Die Etiketten auf den Produkten',
            'aide' => 'Date de production, DLC et allergènes, en vitrine, en stock et au congélateur'],
        ['k' => 'taches', 'auto' => true, 'fr' => 'Le suivi des tâches et des checklists', 'nl' => 'De opvolging van taken en checklists', 'en' => 'Tasks and checklists', 'de' => 'Aufgaben und Checklisten',
            'aide' => 'Tâches du jour faites et photographiées dans l’application, checklists d’ouverture et de fermeture'],
        ['k' => 'soir', 'auto' => true, 'fr' => 'La revue du soir', 'nl' => 'De avondcontrole', 'en' => 'The evening review', 'de' => 'Die Abendkontrolle',
            'aide' => 'Dashboard magasin › Revue du soir : faite et clôturée chaque soir avant la fermeture'],
        ['k' => 'fournisseurs', 'auto' => true, 'fr' => 'Les commandes fournisseurs au panel', 'nl' => 'De leveranciersbestellingen via het panel', 'en' => 'Supplier orders through the panel', 'de' => 'Die Lieferantenbestellungen über das Panel',
            'aide' => 'Matières et emballages commandés par le panel, pas par téléphone ni par courriel'],
    ];
}

/** Les verdicts : clé, poids dans le score (null : hors calcul), libellés du rapport. */
function viOutilsVerdicts(): array
{
    return [
        'ok' => ['poids' => 1.0, 'fr' => 'suivi', 'nl' => 'gevolgd', 'en' => 'followed', 'de' => 'eingehalten'],
        'part' => ['poids' => 0.5, 'fr' => 'en partie', 'nl' => 'gedeeltelijk', 'en' => 'partly', 'de' => 'teilweise'],
        'ko' => ['poids' => 0.0, 'fr' => 'pas suivi', 'nl' => 'niet gevolgd', 'en' => 'not followed', 'de' => 'nicht eingehalten'],
        'na' => ['poids' => null, 'fr' => 'sans objet', 'nl' => 'niet van toepassing', 'en' => 'not applicable', 'de' => 'entfällt'],
    ];
}

/**
 * Le rappel au franchisé, en (i) sur la tablette et dans chaque courriel de rapport, dans la langue du magasin. Le
 * texte français est celui de Sam ; il se règle dans Suivi › Qui fait quoi › Les courriels (modèle « Rapport de
 * visite », variable {rappel}).
 */
function viOutilsRappel(string $l): string
{
    $t = [
        'fr' => 'Les outils de gestion mis en place par la marque sont à suivre. En ne les suivant pas, aucun effort ne sera fait de la part de la marque en cas de problème ou de manquement de gestion.',
        'nl' => 'De beheertools die het merk heeft ingevoerd, moeten gevolgd worden. Wie ze niet volgt, kan bij problemen of tekortkomingen in het beheer geen enkele inspanning van het merk verwachten.',
        'en' => 'The management tools put in place by the brand must be followed. If they are not followed, the brand will make no effort in the event of management problems or failures.',
        'de' => 'Die von der Marke eingeführten Verwaltungswerkzeuge sind einzuhalten. Werden sie nicht befolgt, wird die Marke bei Problemen oder Versäumnissen in der Geschäftsführung keinerlei Entgegenkommen zeigen.',
    ];
    return $t[$l] ?? $t['fr'];
}

/** Le score sur 100 : suivi 1, en partie ½, pas suivi 0, sans objet hors calcul ; null sans verdict qui compte. */
function viOutilsScore(array $items): ?int
{
    $V = viOutilsVerdicts(); $s = 0.0; $n = 0;
    foreach ($items as $x) {
        $p = $V[(string) ($x['v'] ?? '')]['poids'] ?? null;
        if ($p === null) { continue; }
        $s += $p; $n++;
    }
    return $n > 0 ? (int) round($s / $n * 100) : null;
}

/**
 * L'audit reçu de la tablette : { items: { outil: { v: ok|part|ko|na, com, lu } }, rappelLu: 'AAAA-MM-JJ HH:MM:SS' },
 * les outils connus seulement ; null si aucun verdict n'est donné.
 */
function viOutils(mixed $x, string $qui): ?array
{
    if (!is_array($x)) { return null; }
    $src = is_array($x['items'] ?? null) ? $x['items'] : [];
    $V = viOutilsVerdicts(); $items = []; $n = 0;
    foreach (viOutilsListe() as $o) {
        $y = is_array($src[$o['k']] ?? null) ? $src[$o['k']] : [];
        $v = is_string($y['v'] ?? null) && isset($V[$y['v']]) ? $y['v'] : null;
        if ($v !== null) { $n++; }
        $items[$o['k']] = ['v' => $v, 'com' => mb_substr(trim((string) ($y['com'] ?? '')), 0, 500), 'lu' => mb_substr(trim((string) ($y['lu'] ?? '')), 0, 200)];
    }
    if ($n === 0) { return null; }
    $rl = is_string($x['rappelLu'] ?? null) && preg_match('/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d(:\d\d)?$/', $x['rappelLu']) ? str_replace('T', ' ', $x['rappelLu']) : null;
    return ['items' => $items, 'n' => $n, 'score' => viOutilsScore($items), 'rappelLu' => $rl, 'qui' => mb_substr($qui, 0, 120), 'le' => date('Y-m-d H:i:s')];
}

/* --- Ce que le cockpit lit tout seul ----------------------------------------------------------------------------- */

/**
 * GET /visites/outils/{shop} — pour chaque outil qui laisse une trace, ce qu'on en lit : { lu: { outil: { etat, texte } } }.
 * `etat` : ok (en ordre), att (à regarder), ko (rien depuis longtemps), null (rien à conclure). Un outil sans lecture
 * possible (base absente, table vide) n'a pas de ligne : le consultant juge seul, comme à la revue du soir.
 * Tout se lit dans la base du cockpit, déjà alimentée : rien au panel, le cache est celui de la tablette (hors ligne).
 */
function ep_visites_outils(string $shop): array
{
    if (!isset(viMagasins()[$shop])) { http_response_code(404); return ['error' => 'magasin inconnu']; }
    $sid = (int) $shop; $auj = date('Y-m-d'); $lu = [];
    $dm = static fn (?string $d): string => $d ? date('d/m', strtotime($d)) : '';
    foreach (['tresorerie', 'commandes', 'taches', 'soir', 'fournisseurs'] as $k) {
        try { $x = viOutilLire($k, $sid, $shop, $auj, $dm); if ($x !== null) { $lu[$k] = $x; } }
        catch (Throwable $e) { /* table absente : le consultant juge seul */ }
    }
    return ['shop' => $shop, 'le' => date('Y-m-d H:i:s'), 'lu' => $lu, 'rappel' => viOutilsRappel('fr')];
}

/** Une lecture : { etat, texte } ou null quand il n'y a rien à lire. */
function viOutilLire(string $k, int $sid, string $shop, string $auj, callable $dm): ?array
{
    $il30 = date('Y-m-d', strtotime($auj . ' -30 days'));
    switch ($k) {
        case 'tresorerie':
            // Le plan du dashboard magasin (src/franchise/tresorerie.php) : sa dernière mise à jour, et les quatre derniers
            // dimanches passés — le solde réel de chaque semaine est rangé sous son lundi (`reel`).
            $r = Db::row('SELECT donnees, maj_le FROM panel_tresorerie_plan WHERE shop_id = ?', [$shop]);
            if ($r === null) { return ['etat' => 'ko', 'texte' => 'aucun plan de trésorerie ouvert']; }
            $d = json_decode((string) $r['donnees'], true); $reel = is_array($d['reel'] ?? null) ? $d['reel'] : [];
            $lundi = date('Y-m-d', strtotime($auj . ' -' . ((int) date('N', strtotime($auj)) - 1) . ' days'));
            $n = 0;
            for ($i = 1; $i <= 4; $i++) { $lu = date('Y-m-d', strtotime($lundi . ' -' . (7 * $i) . ' days')); if (isset($reel[$lu]) && $reel[$lu] !== '' && $reel[$lu] !== null) { $n++; } }
            $maj = (string) $r['maj_le']; $age = (int) floor((strtotime($auj) - strtotime(substr($maj, 0, 10))) / 86400);
            return ['etat' => $age <= 8 && $n >= 3 ? 'ok' : ($age > 21 && $n === 0 ? 'ko' : 'att'),
                'texte' => 'dernière mise à jour le ' . $dm($maj) . ' · solde réel du dimanche saisi ' . $n . ' dimanche' . ($n > 1 ? 's' : '') . ' sur 4'];
        case 'commandes':
            // Les commandes clients : la lecture du panel gardée par Commandes et ventes (src/commandes.php, 15 min) si
            // elle est récente, sinon la base. Une boutique sans commande n'est pas en faute : rien à conclure.
            $m = setting('cmdApi:' . $sid);
            if (is_array($m) && isset($m['le'], $m['v']) && time() - (int) $m['le'] < 86400 && is_array($m['v'])) {
                $v = $m['v']; $ouv = (int) ($v['retard'] ?? 0) + (int) ($v['dormantes'] ?? 0);
                return ['etat' => $ouv > 0 ? 'att' : ((int) ($v['total'] ?? 0) > 0 ? 'ok' : null),
                    'texte' => (int) ($v['total'] ?? 0) . ' commande' . ((int) ($v['total'] ?? 0) > 1 ? 's' : '') . ' au panel sur ' . (int) ($v['fenetre'] ?? 8) . ' jours'
                        . ($ouv ? ' · ' . $ouv . ' passée' . ($ouv > 1 ? 's' : '') . ' encore ouverte' . ($ouv > 1 ? 's' : '') : '')
                        . (!empty($v['derniere']) ? ' · la dernière pour le ' . $dm((string) $v['derniere']) : '')];
            }
            $t = Db::row("SELECT SUM(pick_up_datetime >= ?) n30, MAX(pick_up_datetime) derniere,
                    SUM(issuing_timestamp IS NULL AND (order_status IS NULL OR order_status <> 'picked_up') AND non_collection_id_reason IS NULL AND pick_up_datetime < ?) ouvertes
                  FROM client_order WHERE id_shop = ?", [$il30, $auj, $sid]);
            if ($t === null || ($t['derniere'] ?? null) === null) { return null; }
            $n = (int) $t['n30']; $ouv = (int) $t['ouvertes'];
            return ['etat' => $ouv > 0 ? 'att' : ($n > 0 ? 'ok' : null),
                'texte' => $n . ' commande' . ($n > 1 ? 's' : '') . ' encodée' . ($n > 1 ? 's' : '') . ' sur 30 jours' . ($ouv ? ' · ' . $ouv . ' passée' . ($ouv > 1 ? 's' : '') . ' encore ouverte' . ($ouv > 1 ? 's' : '') : '')
                    . ' · la dernière pour le ' . $dm((string) $t['derniere'])];
        case 'taches':
            // Le relevé quotidien des tâches du panel (con_tache_jour, journées révolues) et les notes du contrôle qualité.
            if (function_exists('tachesSuiviTables')) { tachesSuiviTables(); }
            $t = Db::row('SELECT COUNT(*) t, SUM(fait) f, COUNT(DISTINCT jour) j FROM con_tache_jour WHERE id_shop = ? AND jour BETWEEN ? AND ?', [$sid, $il30, date('Y-m-d', strtotime($auj . ' -1 day'))]);
            if ($t === null || (int) $t['t'] === 0) { return null; }
            $pct = (int) round((int) $t['f'] / (int) $t['t'] * 100);
            $q = null;
            try { $q = Db::row('SELECT COUNT(rating) n, AVG(rating) m FROM mac_task_review WHERE id_shop = ? AND review_date >= ?', [$sid, $il30]); } catch (Throwable $e) { /* pas de contrôle qualité */ }
            return ['etat' => $pct >= 80 ? 'ok' : ($pct >= 50 ? 'att' : 'ko'),
                'texte' => 'tâches faites ' . $pct . ' % sur 30 jours (' . (int) $t['f'] . ' / ' . (int) $t['t'] . ', ' . (int) $t['j'] . ' jours relevés)'
                    . ($q && (int) $q['n'] > 0 ? ' · contrôle qualité ' . str_replace('.', ',', (string) round((float) $q['m'], 1)) . ' / 5' : '')];
        case 'soir':
            // La revue du soir du dashboard magasin (src/revue_soir.php) : les soirs clôturés sur les 30 derniers.
            $t = Db::row("SELECT SUM(statut = 'cloturee') n, MAX(CASE WHEN statut = 'cloturee' THEN jour END) derniere FROM panel_revue_soir WHERE id_shop = ? AND jour >= ?", [$sid, $il30]);
            $n = (int) ($t['n'] ?? 0);
            return ['etat' => $n >= 22 ? 'ok' : ($n >= 8 ? 'att' : 'ko'),
                'texte' => 'clôturée ' . $n . ' soir' . ($n > 1 ? 's' : '') . ' sur 30' . (!empty($t['derniere']) ? ' · la dernière le ' . $dm((string) $t['derniere']) : '')];
        case 'fournisseurs':
            // Les commandes fournisseurs du panel (material_order) : le panel n'a pas de route pour elles, la base est la
            // source (même lecture que Commandes et ventes, src/commandes.php).
            $t = Db::row('SELECT SUM(order_date >= ?) n, MAX(order_date) derniere FROM material_order WHERE id_shop = ?', [$il30, $sid]);
            if ($t === null) { return null; }
            $n = (int) ($t['n'] ?? 0);
            return ['etat' => $n > 0 ? 'ok' : (!empty($t['derniere']) ? 'ko' : null),
                'texte' => $n . ' commande' . ($n > 1 ? 's' : '') . ' au panel sur 30 jours' . (!empty($t['derniere']) ? ' · la dernière le ' . $dm((string) $t['derniere']) : ' · aucune au panel')];
    }
    return null;
}

/* --- Dans le rapport ----------------------------------------------------------------------------------------------- */

/** L'audit d'une visite et le score de la précédente qui en a un (même magasin), ou null. */
function suOutils(array $v): ?array
{
    $a = json_decode((string) ($v['outils'] ?? ''), true);
    if (!is_array($a) || !is_array($a['items'] ?? null)) { return null; }
    $prec = null;
    try {
        foreach (Db::rows('SELECT prevu_le, outils FROM con_visite WHERE shop_id = ? AND statut = \'terminee\' AND id <> ? AND prevu_le <= ? AND outils IS NOT NULL
            ORDER BY prevu_le DESC, id DESC LIMIT 6', [(string) $v['shop_id'], (int) $v['id'], (string) $v['prevu_le']]) as $x) {
            $p = json_decode((string) $x['outils'], true);
            if (is_array($p['items'] ?? null)) { $prec = ['le' => (string) $x['prevu_le'], 'score' => viOutilsScore($p['items'])]; break; }
        }
    } catch (Throwable $e) { /* pas de précédente */ }
    return ['items' => $a['items'], 'score' => viOutilsScore($a['items']), 'rappelLu' => $a['rappelLu'] ?? null, 'precedent' => $prec];
}

/** Les lignes du rapport, dans la langue du magasin : { k, nom, v, verdict, com, lu }, les outils sans verdict laissés de côté. */
function suOutilsLignes(array $o, string $l): array
{
    $V = viOutilsVerdicts(); $out = [];
    foreach (viOutilsListe() as $x) {
        $y = $o['items'][$x['k']] ?? null; $v = is_array($y) ? (string) ($y['v'] ?? '') : '';
        if (!isset($V[$v])) { continue; }
        $out[] = ['k' => $x['k'], 'nom' => (string) ($x[$l] ?? $x['fr']), 'v' => $v, 'verdict' => (string) ($V[$v][$l] ?? $V[$v]['fr']),
            'com' => (string) ($y['com'] ?? ''), 'lu' => (string) ($y['lu'] ?? '')];
    }
    return $out;
}

/** Le titre du bloc : « Le respect des outils de gestion : 57 / 100 (visite précédente : 71) ». */
function suOutilsTitre(array $o, string $l): string
{
    $t = ['fr' => 'Le respect des outils de gestion', 'nl' => 'Het gebruik van de beheertools', 'en' => 'Use of the management tools', 'de' => 'Einhaltung der Verwaltungswerkzeuge'];
    $p = ['fr' => 'visite précédente', 'nl' => 'vorig bezoek', 'en' => 'previous visit', 'de' => 'letzter Besuch'];
    $s = $o['score'] ?? null; $ps = $o['precedent']['score'] ?? null;
    return ($t[$l] ?? $t['fr']) . ($s !== null ? ' : ' . $s . ' / 100' : '') . ($ps !== null ? ' (' . ($p[$l] ?? $p['fr']) . ' : ' . $ps . ')' : '');
}

/** Les lignes du courriel : « Plan de trésorerie : en partie — plus tenu depuis 15 jours ». */
function suOutilsCourriel(array $o, string $l): array
{
    $sym = ['ok' => '✓', 'part' => '◐', 'ko' => '✗', 'na' => '–'];
    return array_map(static fn ($x) => $sym[$x['v']] . ' ' . $x['nom'] . ' : ' . $x['verdict'] . ($x['com'] !== '' ? ' — ' . $x['com'] : ''), suOutilsLignes($o, $l));
}

/** Le bloc du rapport (PDF, « Voir le rapport ») : le score, chaque outil avec son verdict et sa remarque, le rappel encadré. */
function suOutilsHtml(?array $o, string $l, callable $e): string
{
    if ($o === null) { return suRappelHtml($l, $e); }
    $coul = ['ok' => '#3f7a52', 'part' => '#8a5200', 'ko' => '#A32014', 'na' => '#7a736a'];
    $sym = ['ok' => '✓', 'part' => '◐', 'ko' => '✗', 'na' => '–'];
    $lignes = '';
    foreach (suOutilsLignes($o, $l) as $x) {
        $lignes .= '<tr style="border-top:1px solid #EAE3DA"><td style="padding:5px 10px 5px 0;vertical-align:top">' . $e($x['nom']) . '</td>'
            . '<td style="padding:5px 10px 5px 0;vertical-align:top;white-space:nowrap;color:' . $coul[$x['v']] . '">' . $sym[$x['v']] . ' ' . $e($x['verdict']) . '</td>'
            . '<td style="padding:5px 0;vertical-align:top;color:#5c5852;width:100%">' . nl2br($e($x['com'])) . '</td></tr>';
    }
    $s = $o['score'] ?? null;
    $cs = $s === null ? '#7a736a' : ($s >= 80 ? '#3f7a52' : ($s >= 50 ? '#8a5200' : '#A32014'));
    $t = ['fr' => 'Les outils de gestion', 'nl' => 'De beheertools', 'en' => 'The management tools', 'de' => 'Die Verwaltungswerkzeuge'];
    $p = ['fr' => 'visite précédente', 'nl' => 'vorig bezoek', 'en' => 'previous visit', 'de' => 'letzter Besuch'];
    $ps = $o['precedent']['score'] ?? null;
    return '<div style="margin:0 0 14px;padding:12px 14px;border:1px solid #EAE3DA;border-radius:10px;page-break-inside:avoid">'
        . '<div style="font-weight:400;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#7a736a;margin:0 0 4px">' . $e($t[$l] ?? $t['fr']) . '</div>'
        . ($s !== null ? '<div style="margin:0 0 6px"><span style="font-size:34px;color:' . $cs . '">' . $s . '</span><span style="color:#7a736a"> / 100</span>'
            . ($ps !== null ? '<span style="color:#7a736a;font-size:12px;margin-left:12px">' . $e($p[$l] ?? $p['fr']) . ' (' . date('d/m', strtotime((string) $o['precedent']['le'])) . ') : ' . $ps . '</span>' : '') . '</div>' : '')
        . '<table style="border-collapse:collapse;width:100%;font-size:12px">' . $lignes . '</table>'
        . suRappelHtml($l, $e) . '</div>';
}

/** Le rappel encadré, tel qu'il est dans le rapport et le courriel. */
function suRappelHtml(string $l, callable $e): string
{
    $t = ['fr' => 'Rappel', 'nl' => 'Herinnering', 'en' => 'Reminder', 'de' => 'Hinweis'];
    return '<div style="margin:12px 0 0;padding:10px 12px;border-left:4px solid #8D1D2C;background:#FBF3F3;border-radius:4px;page-break-inside:avoid">'
        . '<div style="font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;color:#8D1D2C;margin:0 0 3px"><span style="text-transform:none;letter-spacing:0;font-family:Georgia,serif;font-style:italic;font-weight:700">(i)</span> ' . $e($t[$l] ?? $t['fr']) . '</div>'
        . '<div style="font-weight:700">' . $e(viOutilsRappel($l)) . '</div></div>';
}

/** L'audit pour l'écran du bureau et la page du franchisé : le score, la précédente, les lignes, le rappel. */
function suOutilsVue(array $v, string $l): ?array
{
    $o = suOutils($v);
    if ($o === null) { return null; }
    return ['score' => $o['score'], 'precedent' => $o['precedent'], 'rappelLu' => $o['rappelLu'], 'lignes' => suOutilsLignes($o, $l), 'rappel' => viOutilsRappel($l), 'titre' => suOutilsTitre($o, $l)];
}
