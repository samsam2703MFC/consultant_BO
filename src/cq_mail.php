<?php
declare(strict_types=1);

/**
 * Contrôle qualité non réalisé — l'e-mail de 19:00 (20/10/2026, maquette validée par Sam).
 *
 * Chaque soir, un magasin qui n'a déposé AUCUNE photo de contrôle de la journée reçoit un mot : le
 * constat, les contrôles attendus, et son mois en calendrier (jours réalisés, partiels, sans aucun
 * contrôle). Un magasin qui en a rendu au moins une ne reçoit rien ; un magasin sans contrôle prévu
 * non plus.
 *
 * C'est une ligne du reporting automatisé (code `cq-non-realise`, quotidien, 19 h, par magasin) :
 * l'heure, l'activation et le carnet d'adresses s'éditent dans l'écran Rapports comme les autres.
 * Un magasin absent de son carnet prend les adresses du carnet de l'affiche du mois (les adresses
 * des magasins). Un magasin n'est servi qu'une fois par jour, envoi à la main compris
 * (`cqMailEnvoyes`). Panel muet = aucun envoi : on n'accuse pas un magasin sur une lecture ratée.
 *
 * Les contrôles du jour : /pwa/tasks (ep_pwa_tasks), les tâches obligatoires du magasin, comme le
 * dashboard. Une photo = une tâche rendue avec sa photo (notée ou à contrôler) ; cochée sans photo,
 * elle ne compte pas. Le mois : le relevé des journées closes (ceo_tache_jour), la journée en cours
 * lue en direct.
 */

const CQM_CODE = 'cq-non-realise';

/**
 * L'adresse publique du cockpit pour le bouton. Le cron appelle par 127.0.0.1 : sans `cockpitBase`,
 * le lien partirait vers la machine elle-même. L'adresse vue par un navigateur (écran Rapports) est
 * donc retenue, et resservie au cron.
 */
function cqMailBase(): string
{
    $b = function_exists('rapBaseUrl') ? rapBaseUrl() : '';
    $hote = (string) parse_url($b, PHP_URL_HOST);
    if ($b !== '' && !in_array($hote, ['127.0.0.1', 'localhost', '::1'], true)) {
        if (setting('cqMailBase') !== $b) {
            Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', ['cqMailBase', json_encode($b)]);
        }
        return $b;
    }
    return (string) setting('cqMailBase', '');
}

function cqMailSemer(): void
{
    try { cqMailBase(); } catch (Throwable $e) { /* le bouton attendra */ }
    if (Db::row('SELECT id FROM ceo_rapport WHERE code = ?', [CQM_CODE]) !== null) { return; }
    Db::exec('INSERT INTO ceo_rapport (code, nom, poste, frequence, heure, jour, blocs, destinataires, par_magasin, actif, envoi_mode)
              VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [CQM_CODE, 'Contrôle qualité non réalisé (aucune photo à 19:00)', 'Magasins', 'quotidien', 19, 1,
         json_encode([]), json_encode([]), 1, 1, 'par-magasin']);
}

/** Les adresses d'un magasin : son carnet, sinon celui de l'affiche du mois. */
function cqMailAdresses(array $rep, string $nom): array
{
    static $affiche = null;
    $ok = static fn ($l) => array_values(array_filter((array) $l, fn ($d) => is_string($d) && filter_var($d, FILTER_VALIDATE_EMAIL)));
    $carnet = json_decode((string) ($rep['dest_par_magasin'] ?? ''), true) ?: [];
    $a = $ok($carnet[$nom] ?? []);
    if ($a !== []) { return $a; }
    if ($affiche === null) {
        $r = Db::row('SELECT dest_par_magasin FROM ceo_rapport WHERE code = ?', ['affiche-vente-mois']);
        $affiche = json_decode((string) ($r['dest_par_magasin'] ?? ''), true) ?: [];
    }
    return $ok($affiche[$nom] ?? []);
}

/** Une tâche rendue avec sa photo (notée, ou en attente de contrôle). */
function cqMailPhoto(array $t): bool
{
    $st = (string) ($t['statut'] ?? '');
    return $st !== 'nonRendue' && $st !== 'sansPhoto';
}

/**
 * La journée de chaque magasin : ses contrôles obligatoires et combien portent une photo.
 * null si le panel n'a pas répondu.
 */
function cqMailJour(string $date): ?array
{
    $d = rapAppel('ep_pwa_tasks', ['date' => $date]);
    if (!is_array($d) || !empty($d['indispo'])) { return null; }
    $out = [];
    foreach ((array) ($d['shops'] ?? []) as $s) {
        // Le même tri que le dashboard : une tâche est obligatoire sauf si le panel dit le contraire.
        $T = array_values(array_filter((array) ($s['taches'] ?? []), fn ($t) => ($t['obligatoire'] ?? null) !== false));
        if ($T === []) { continue; }
        $out[(string) $s['shopId']] = ['taches' => $T, 'total' => count($T),
            'photos' => count(array_filter($T, 'cqMailPhoto'))];
    }
    return $out;
}

/**
 * Le mois d'un magasin jusqu'à $date : par jour, [attendus, avec photo] — null pour un jour pas
 * encore relevé, [0, 0] pour un jour relevé sans contrôle prévu. Le jour même vient de $auj.
 */
function cqMailMois(int $sid, string $date, ?array $auj): array
{
    $deb = substr($date, 0, 8) . '01';
    $jours = [];
    try {
        if (function_exists('tachesSuiviTables')) { tachesSuiviTables(); }
        foreach (Db::rows('SELECT jour FROM ceo_tache_jour_etat WHERE jour BETWEEN ? AND ?', [$deb, $date]) as $r) {
            $jours[(string) $r['jour']] = [0, 0];
        }
        // Une tâche notée est obligatoire tant que le panel ne dit pas le contraire (le relevé en
        // gardait le drapeau à 0 avant le 20/10/2026, faute de l'avoir lu).
        foreach (Db::rows("SELECT jour, COUNT(*) t,
                                  SUM(CASE WHEN statut NOT IN ('nonRendue', 'sansPhoto') AND (statut <> '' OR fait = 1) THEN 1 ELSE 0 END) f
                             FROM ceo_tache_jour
                            WHERE id_shop = ? AND jour BETWEEN ? AND ? AND (obligatoire IS NULL OR obligatoire = 1 OR statut = 'notee')
                            GROUP BY jour", [$sid, $deb, $date]) as $r) {
            $jours[(string) $r['jour']] = [(int) $r['t'], (int) $r['f']];
        }
    } catch (PDOException $e) { /* pas de relevé : les jours passés restent « ? » */ }
    if ($auj !== null) { $jours[$date] = [(int) $auj['total'], (int) $auj['photos']]; }
    $out = [];
    for ($ts = strtotime($deb); $ts <= strtotime(date('Y-m-t', strtotime($date))); $ts += 86400) {
        $j = date('Y-m-d', $ts);
        $out[$j] = $j <= $date ? ($jours[$j] ?? null) : 'avenir';
    }
    return $out;
}

/** « Corbais » pour « Atelier by - Corbais » ; le nom entier s'il ne se découpe pas. */
function cqMailVille(string $nom): string
{
    $v = trim((string) preg_replace('/^(L[\'’]\s*)?Atelier\s+by\s*[-–—:]?\s*/iu', '', $nom));
    return $v !== '' ? $v : $nom;
}

function cqMailDateFr(string $date): string
{
    $J = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $M = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $ts = strtotime($date);
    return $J[(int) date('N', $ts) - 1] . ' ' . (int) date('j', $ts) . ' ' . $M[(int) date('n', $ts) - 1];
}

/** L'e-mail d'un magasin : [sujet, html]. */
function cqMailHtml(int $sid, string $nom, string $date, array $jour, array $mois, string $heure): array
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $F = "font-family:'Segoe UI',Arial,sans-serif";
    $reseau = setting('reseau', []);
    $marque = is_array($reseau) ? ($reseau['nom'] ?? 'L’Atelier by') : 'L’Atelier by';
    $logoUri = function_exists('rapLogoDataUri') ? rapLogoDataUri() : '';
    $logo = $logoUri !== ''
        ? '<img src="' . $logoUri . '" height="30" style="display:block;height:30px" alt="' . $e($marque) . '">'
        : '<span style="' . $F . ';color:#221E1A;font-size:16px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase">' . $e($marque) . '</span>';
    $ville = cqMailVille($nom);
    $deVille = (preg_match('/^[aeiouyhâàéèêëîïôöûüAEIOUYHÂÀÉÈÊËÎÏÔÖÛÜ]/u', $ville) ? 'd’' : 'de ') . $ville;
    $dFr = cqMailDateFr($date);
    $n = (int) $jour['total'];
    $c = '';

    // 1. Le constat.
    $c .= '<tr><td class="pad" style="' . $F . ';padding:20px 26px 6px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:#FBEFE7;border-left:4px solid #A9521F;border-radius:8px;padding:14px 16px">'
        . '<div style="font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:#8A3F14;font-weight:700">Contrôle qualité non réalisé</div>'
        . '<div class="titre" style="font-size:19px;font-weight:700;color:#221E1A;margin-top:4px">Magasin ' . $e($deVille) . ' · ' . $e($dFr) . '</div>'
        . '<div style="font-size:13px;color:#5d554d;margin-top:3px">À ' . $e($heure) . ' : <b style="color:#8A3F14">0 photo</b> sur ' . $n . ' contrôle' . ($n > 1 ? 's' : '') . ' attendu' . ($n > 1 ? 's' : '') . '</div>'
        . '</td></tr></table></td></tr>';

    // 2. Le mot.
    $c .= '<tr><td class="pad" style="' . $F . ';font-size:14px;line-height:1.6;color:#221E1A;padding:14px 26px 4px">'
        . 'Bonjour,<br><br>Nous vous informons que les tâches de contrôle qualité n’ont pas été réalisées aujourd’hui au magasin ' . $e($deVille)
        . ' : à ' . $e($heure) . ', aucune photo de contrôle n’a été déposée dans l’application.<br><br>'
        . 'Merci de les réaliser avant la fermeture si c’est encore possible, et de veiller à ce qu’elles soient faites chaque jour.'
        . '</td></tr>';

    // 3. Ce qui était attendu : tâche par tâche jusqu'à huit, par checklist au-delà.
    $court = static fn (string $t) => trim((string) preg_replace(['/^Contrôle Qualité\s*[–—-]\s*/iu', '/^Photo du comptoir\s*[-–—]\s*/iu'], ['', 'Comptoir · '], $t));
    $lignes = [];
    if ($n <= 8) {
        foreach ($jour['taches'] as $t) {
            $lignes[] = [$court((string) ($t['tache'] ?? '')), ($t['statut'] ?? '') === 'sansPhoto' ? '✗ cochée sans photo' : '✗ pas de photo'];
        }
    } else {
        $par = [];
        foreach ($jour['taches'] as $t) {
            $cl = trim((string) preg_replace('/\.$/', '', (string) ($t['checklist'] ?? ''))) ?: 'Autres contrôles';
            $par[$cl] = ($par[$cl] ?? 0) + 1;
        }
        foreach ($par as $cl => $k) { $lignes[] = [$cl, '✗ 0 photo sur ' . $k]; }
    }
    $li = '';
    foreach (array_slice($lignes, 0, 12) as $i => $l) {
        $bd = $i ? 'border-top:1px solid #EFE9DF;' : '';
        $li .= '<tr><td style="' . $F . ';font-size:13px;color:#221E1A;padding:7px 8px 7px 0;' . $bd . '">' . $e($l[0]) . '</td>'
            . '<td align="right" style="' . $F . ';font-size:12px;color:#8A3F14;padding:7px 0;white-space:nowrap;' . $bd . '">' . $e($l[1]) . '</td></tr>';
    }
    if (count($lignes) > 12) {
        $li .= '<tr><td colspan="2" style="' . $F . ';font-size:12px;color:#8b8177;padding:7px 0;border-top:1px solid #EFE9DF">+ ' . (count($lignes) - 12) . ' autre(s)</td></tr>';
    }
    $c .= '<tr><td class="pad" style="' . $F . ';padding:16px 26px 0">'
        . '<div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#8b8177;font-weight:700;margin-bottom:4px">Les contrôles attendus aujourd’hui</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $li . '</table></td></tr>';

    // 4. Le mois, une case par jour.
    $nT = 0; $nP = 0; $nR = 0; $vus = ['ferme' => false, 'inconnu' => false];
    $cell = static function (string $j, $v) use ($date, $F, &$nT, &$nP, &$nR, &$vus) {
        $base = $F . ';text-align:center;vertical-align:middle;height:46px;border-radius:7px;';
        $num = (int) substr($j, 8, 2);
        $gris = static fn (string $s) => '<td style="' . $base . 'background:#F4F1EC;color:#7d746a"><div style="font-size:13px">' . $num . '</div><div style="font-size:10px">' . $s . '</div></td>';
        if ($v === 'avenir') { return $gris('&nbsp;'); }
        if ($v === null) { $vus['inconnu'] = true; return $gris('?'); }
        [$t, $f] = $v;
        if ($t <= 0) { $vus['ferme'] = true; return $gris('—'); }
        $cadre = $j === $date ? 'box-shadow:0 0 0 2px #221E1A;' : '';
        if ($f >= $t) { $nT++; $st = 'background:#2E6DB4;color:#ffffff'; $s = '✓ ' . $f . '/' . $t; }
        elseif ($f > 0) { $nP++; $st = 'background:#DCE7F5;color:#1F4E85'; $s = $f . '/' . $t; }
        else { $nR++; $st = 'background:#A9521F;color:#ffffff'; $s = '✗ 0/' . $t; }
        return '<td style="' . $base . $st . ';' . $cadre . '"><div style="font-size:13px;font-weight:700">' . $num . '</div><div style="font-size:10px">' . $s . '</div></td>';
    };
    $premier = (int) date('N', strtotime(array_key_first($mois)));
    $cal = '<tr>' . implode('', array_map(static fn ($d) => '<td style="' . $F . ';text-align:center;font-size:11px;color:#8b8177;padding-bottom:2px">' . $d . '</td>',
        ['lun', 'mar', 'mer', 'jeu', 'ven', 'sam', 'dim'])) . '</tr><tr>';
    for ($i = 1; $i < $premier; $i++) { $cal .= '<td></td>'; }
    $col = $premier; $k = 0; $nb = count($mois);
    foreach ($mois as $j => $v) {
        $k++;
        $cal .= $cell($j, $v);
        if ($col % 7 === 0 && $k < $nb) { $cal .= '</tr><tr>'; }
        $col++;
    }
    for ($i = ($col - 1) % 7; $i > 0 && $i < 7; $i++) { $cal .= '<td></td>'; }
    $cal .= '</tr>';
    $M = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $libM = $M[(int) substr($date, 5, 2) - 1];
    $pl = static fn (int $x, string $s) => $x . ' jour' . ($x > 1 ? 's' : '') . ' ' . $s . ($x > 1 && $s !== '' ? 's' : '');
    $leg = static fn ($bg, $t) => '<span style="display:inline-block;white-space:nowrap;margin:0 12px 4px 0"><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:' . $bg . ';vertical-align:-1px;margin-right:5px"></span>' . $t . '</span>';
    $c .= '<tr><td class="pad" style="' . $F . ';padding:22px 26px 0">'
        . '<div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#8b8177;font-weight:700">Vos contrôles qualité en ' . $libM . '</div>'
        . '<div style="font-size:13px;color:#221E1A;margin:4px 0 10px"><b>' . $pl($nT, 'réalisé') . '</b>, ' . $pl($nP, 'partiel')
        . ', <b style="color:#8A3F14">' . $nR . ' jour' . ($nR > 1 ? 's' : '') . ' sans aucun contrôle</b> (dont aujourd’hui).</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="4" style="table-layout:fixed">' . $cal . '</table>'
        . '<div style="font-size:12px;line-height:1.5;color:#221E1A;margin-top:8px">'
        . $leg('#2E6DB4', 'réalisé : toutes les photos') . $leg('#DCE7F5', 'partiel : une partie') . $leg('#A9521F', 'pas réalisé : aucune photo')
        . $leg('#F4F1EC', '<span style="color:#5d554d">' . ($vus['ferme'] ? '— aucun contrôle prévu · ' : '') . ($vus['inconnu'] ? '? pas encore relevé · ' : '') . 'à venir</span>')
        . '</div><div style="font-size:11px;color:#8b8177">Dans chaque case : les contrôles faits avec leur photo sur les contrôles attendus ce jour-là. Aujourd’hui est encadré.</div></td></tr>';

    // 5. Le bouton, quand l'adresse du cockpit est connue.
    $base = cqMailBase();
    if ($base !== '') {
        $c .= '<tr><td class="pad" align="left" style="' . $F . ';padding:22px 26px 6px">'
            . '<a href="' . $e($base . '/dashboard/?shop=' . $sid . '&vue=jour&date=' . $date) . '" style="display:inline-block;background:#8D1D2C;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 20px;border-radius:8px">Ouvrir les contrôles du jour</a>'
            . '</td></tr>';
    }

    $html = caMailSquelette('Contrôle qualité', 'message envoyé automatiquement à 19:00, seulement les jours où aucune photo de contrôle n’a été déposée.');
    $html = str_replace(['{{logo}}', '{{marque}}', '{{contenu}}'], [$logo, $e($marque), $c], $html);
    return ['Contrôle qualité non réalisé — ' . $ville . ', ' . $dFr, $html];
}

/**
 * Le passage de 19:00 (ou l'envoi à la main depuis Rapports) : chaque magasin à zéro photo reçoit son
 * e-mail, une fois par jour. `$apercu` : rien ne part, le run garde l'e-mail du premier magasin concerné.
 */
function cqMailDistribuer(array $rep, bool $apercu = false): array
{
    $date = date('Y-m-d'); $heure = date('H:i');
    $bilan = ['magasins' => []];
    if (!$apercu && !Smtp::configured()) { return $bilan + ['resume' => 'SMTP non configuré (Paramètres) — aucun envoi']; }
    $J = cqMailJour($date);
    if ($J === null) { return $bilan + ['resume' => 'le panel n’a pas rendu les tâches du jour — aucun envoi']; }
    $deja = setting('cqMailEnvoyes');
    $deja = is_array($deja) && ($deja['date'] ?? '') === $date ? array_map('strval', (array) ($deja['shops'] ?? [])) : [];
    try { $shops = Db::rows('SELECT id, name FROM shops WHERE active = 1 ORDER BY name'); }
    catch (PDOException $e) { $shops = []; }
    $exemple = null;
    foreach ($shops as $s) {
        $sid = (string) $s['id']; $nom = (string) $s['name'];
        $jr = $J[$sid] ?? null;
        if ($jr === null) { $bilan['magasins'][] = ['magasin' => $nom, 'statut' => 'rien-prevu']; continue; }
        if ($jr['photos'] > 0) { $bilan['magasins'][] = ['magasin' => $nom, 'statut' => 'fait', 'note' => $jr['photos'] . '/' . $jr['total'] . ' photo(s)']; continue; }
        if (in_array($sid, $deja, true)) { $bilan['magasins'][] = ['magasin' => $nom, 'statut' => 'deja-envoye']; continue; }
        [$sujet, $html] = cqMailHtml((int) $sid, $nom, $date, $jr, cqMailMois((int) $sid, $date, $jr), $heure);
        if ($apercu) { $exemple = $exemple ?? ['magasin' => $nom, 'sujet' => $sujet, 'html' => $html]; $bilan['magasins'][] = ['magasin' => $nom, 'statut' => 'a-envoyer']; continue; }
        $dests = cqMailAdresses($rep, $nom);
        if ($dests === []) { $bilan['magasins'][] = ['magasin' => $nom, 'statut' => 'sans-adresse', 'note' => 'aucune adresse dans le carnet']; continue; }
        $envoyes = []; $rate = null;
        foreach ($dests as $d) {
            if (Smtp::envoyer($d, $sujet, $html)) { $envoyes[] = $d; } else { $rate = (string) Smtp::$lastError; }
        }
        if ($envoyes !== []) { $deja[] = $sid; }
        $bilan['magasins'][] = ['magasin' => $nom, 'statut' => $envoyes !== [] ? 'envoye' : 'echec', 'envoyes' => $envoyes, 'note' => $rate];
    }
    if (!$apercu) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['cqMailEnvoyes', json_encode(['date' => $date, 'shops' => array_values(array_unique($deja))])]);
    }
    $cpt = static fn (string $st) => count(array_filter($bilan['magasins'], fn ($m2) => $m2['statut'] === $st));
    $resume = $apercu
        ? 'Aperçu ' . $heure . ' — ' . $cpt('a-envoyer') . ' magasin(s) sans aucune photo à cette heure, ' . $cpt('fait') . ' avec photo(s)'
            . ($exemple ? ' — e-mail de ' . $exemple['magasin'] : ' — aucun e-mail à montrer')
        : 'Contrôle qualité ' . $heure . ' — ' . $cpt('envoye') . ' magasin(s) prévenu(s), ' . $cpt('fait') . ' avec photo(s), '
            . $cpt('sans-adresse') . ' sans adresse' . ($cpt('echec') ? ', ' . $cpt('echec') . ' échec(s)' : '');
    $lst = '<div style="font-family:Helvetica,Arial,sans-serif;font-size:13px">' . htmlspecialchars($resume) . '<ul>'
        . implode('', array_map(fn ($m2) => '<li>' . htmlspecialchars($m2['magasin'] . ' — ' . $m2['statut']
            . (!empty($m2['envoyes']) ? ' (' . implode(', ', $m2['envoyes']) . ')' : '') . (!empty($m2['note']) ? ' · ' . $m2['note'] : '')) . '</li>', $bilan['magasins']))
        . '</ul></div>';
    Db::exec('INSERT INTO ceo_rapport_run (rapport_id, genere_le, periode_du, periode_au, statut, resume, html, contexte)
              VALUES (?,?,?,?,?,?,?,?)',
        [(int) $rep['id'], date('Y-m-d H:i:s'), $date, $date,
         $apercu ? 'genere' : ($cpt('envoye') > 0 ? 'envoye' : 'vide'), $resume, $exemple ? $exemple['html'] : $lst,
         json_encode($exemple ? ['magasin' => $exemple['magasin'], 'rapport' => $exemple['sujet']] : [], JSON_UNESCAPED_UNICODE)]);
    $bilan['runId'] = (int) Db::pdo()->lastInsertId();
    $bilan['resume'] = $resume;
    $bilan['statut'] = $apercu ? 'genere' : 'distribue';
    if (!$apercu) { journalAdd('CEO', 'Rapport', (string) $rep['nom'], $resume); }
    return $bilan;
}
