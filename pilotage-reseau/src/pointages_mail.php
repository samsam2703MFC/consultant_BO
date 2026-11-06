<?php
declare(strict_types=1);

/**
 * Pointages suspects — l'e-mail de fin de journée (20/10/2026, demandé par Sam).
 *
 * Une vendeuse seule au comptoir qui ne s'identifie pas à la caisse encaisse au compte de la session ouverte : celle
 * d'un collègue parti, d'une caisse générique, ou sans vendeur. Ses ventes, ses primes et son heure par heure
 * partent alors chez un autre. Le dashboard laisse chaque ticket où il est (Sam ne veut pas de réattribution) ;
 * le soir, le magasin reçoit le constat : « la vendeuse X, présente, n'a pas pointé, ses ventes sont passées au
 * compte de Y ».
 *
 * Un cas = des tickets encaissés au compte de quelqu'un qui n'est pas en poste à cette minute (parti, pas encore
 * arrivé, pas au planning, ou sans vendeur), pendant qu'une seule personne était en vente (poste vente, caisse ou
 * comptoir ; tout le monde si le planning ne dit pas de poste). Dix minutes de grâce autour d'un poste : le dernier
 * ticket encaissé en partant reste à son vendeur. Trois tickets au moins : un ticket égaré n'est pas un pointage.
 *
 * Ligne du reporting (code `pointages-suspects`, quotidien, 21 h, par magasin), comme le contrôle qualité de 19:00 :
 * carnet d'adresses de la ligne, sinon celui de l'affiche du mois ; les destinataires réseau de la ligne reçoivent
 * un récapitulatif de tous les magasins. Un magasin n'est servi qu'une fois par jour ; panel muet = aucun envoi.
 */

const PS_CODE = 'pointages-suspects';
const PS_MIN_TICKETS = 3;
const PS_GRACE_MIN = 10;

function psSemer(): void
{
    if (Db::row('SELECT id FROM ceo_rapport WHERE code = ?', [PS_CODE]) !== null) { return; }
    Db::exec('INSERT INTO ceo_rapport (code, nom, poste, frequence, heure, jour, blocs, destinataires, par_magasin, actif, envoi_mode)
              VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [PS_CODE, 'Pointages suspects (fin de journée)', 'Magasins', 'quotidien', 21, 1,
         json_encode([]), json_encode([]), 1, 1, 'par-magasin']);
}

/** « 13:05 » → 785. */
function psMin(string $hm): ?int
{
    return preg_match('/^(\d{1,2}):(\d{2})/', trim($hm), $m) === 1 ? 60 * (int) $m[1] + (int) $m[2] : null;
}

function psHm(int $mn): string
{
    return sprintf('%02d:%02d', intdiv($mn, 60), $mn % 60);
}

/**
 * Les cas d'un magasin pour une journée.
 *
 * @param array $planning [{nom, debut, fin, postes?, idEmp?}] — le planning du jour (ep_exploitation_jour)
 * @param array $tickets  [[minute du jour, id_employee (0 inconnu), nom du vendeur ('' sans vendeur), montant]]
 * @return array [{vendeuse, poste, compte, type (collegue|hors-planning|sans), compteFin, tickets, ca, premier, dernier, propres}]
 */
function psDetecter(array $planning, array $tickets, int $min = PS_MIN_TICKETS): array
{
    $cle = static fn (string $n) => mb_strtolower(trim($n));
    // Une personne du planning : ses services, ses postes ; reconnue par son identifiant, sinon par son nom.
    $P = []; $parId = []; $parNom = [];
    foreach ($planning as $p) {
        $d = psMin((string) ($p['debut'] ?? '')); $f = psMin((string) ($p['fin'] ?? ''));
        if ($d === null || $f === null) { continue; }
        if ($f <= $d) { $f += 24 * 60; }
        $ide = (int) ($p['idEmp'] ?? 0);
        $k = $ide > 0 ? 'i' . $ide : 'n' . $cle((string) ($p['nom'] ?? ''));
        if (!isset($P[$k])) { $P[$k] = ['nom' => (string) ($p['nom'] ?? ''), 'srv' => [], 'postes' => []]; }
        $P[$k]['srv'][] = [$d, $f];
        foreach ((array) ($p['postes'] ?? []) as $x) { $P[$k]['postes'][] = (string) $x; }
        if ($ide > 0) { $parId[$ide] = $k; }
        $parNom[$cle((string) ($p['nom'] ?? ''))] = $k;
    }
    $enPoste = static fn (string $k, int $mn, int $g) => isset($P[$k]) && array_filter($P[$k]['srv'], fn ($s) => $mn >= $s[0] - $g && $mn < $s[1] + $g) !== [];
    $vente = array_keys(array_filter($P, fn ($o) => $o['postes'] === [] || preg_grep('/vente|caisse|comptoir/i', $o['postes']) !== []));
    $cas = []; $propres = [];
    foreach ($tickets as $t) {
        [$mn, $ide, $nom, $ca] = [(int) $t[0], (int) ($t[1] ?? 0), trim((string) ($t[2] ?? '')), (float) ($t[3] ?? 0)];
        $kv = $ide > 0 && isset($parId[$ide]) ? $parId[$ide] : ($nom !== '' && isset($parNom[$cle($nom)]) ? $parNom[$cle($nom)] : null);
        if ($kv !== null) { $propres[$kv][] = $mn; }
        if ($kv !== null && $enPoste($kv, $mn, PS_GRACE_MIN)) { continue; }
        $seuls = array_values(array_filter($vente, fn ($k) => $enPoste($k, $mn, 0)));
        if (count($seuls) !== 1 || $seuls[0] === $kv) { continue; }
        $type = $kv !== null ? 'collegue' : ($nom !== '' ? 'hors-planning' : 'sans');
        $compte = $kv !== null ? $P[$kv]['nom'] : $nom;
        $c = $seuls[0] . '|' . $type . '|' . $cle($compte);
        if (!isset($cas[$c])) {
            $cas[$c] = ['k' => $seuls[0], 'vendeuse' => $P[$seuls[0]]['nom'], 'compte' => $compte, 'type' => $type,
                'compteFin' => $kv !== null ? psHm(max(array_map(fn ($s) => $s[1], $P[$kv]['srv'])) % (24 * 60)) : null,
                'compteDebut' => $kv !== null ? psHm(min(array_map(fn ($s) => $s[0], $P[$kv]['srv']))) : null,
                'tickets' => 0, 'ca' => 0.0, 'mn' => []];
        }
        $cas[$c]['tickets']++; $cas[$c]['ca'] += $ca; $cas[$c]['mn'][] = $mn;
    }
    $out = [];
    foreach ($cas as $c) {
        if ($c['tickets'] < $min) { continue; }
        $de = min($c['mn']); $a = max($c['mn']);
        $srv = $P[$c['k']]['srv'];
        $out[] = ['vendeuse' => $c['vendeuse'], 'compte' => $c['compte'], 'type' => $c['type'],
            'compteDebut' => $c['compteDebut'], 'compteFin' => $c['compteFin'],
            'poste' => implode(' · ', array_map(fn ($s) => psHm($s[0]) . '–' . psHm($s[1] % (24 * 60)), $srv)),
            'tickets' => $c['tickets'], 'ca' => round($c['ca'], 2), 'premier' => psHm($de), 'dernier' => psHm($a),
            // Ses propres tickets pendant ce temps : zéro, elle n'a jamais pointé ; sinon elle a pointé par moments.
            'propres' => count(array_filter($propres[$c['k']] ?? [], fn ($m) => $m >= $de && $m <= $a))];
    }
    usort($out, fn ($x, $y) => $y['ca'] <=> $x['ca']);
    return $out;
}

/** Le paragraphe d'un cas, en clair. */
function psPhrase(array $c): string
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $eur = number_format((float) $c['ca'], 2, ',', ' ') . ' €';
    $compte = match ($c['type']) {
        'collegue' => 'au compte de <b>' . $e($c['compte']) . '</b>, '
            . ($c['compteFin'] !== null && psMin($c['premier']) >= psMin($c['compteFin']) ? 'dont le poste se terminait à ' . $e($c['compteFin'])
                : ($c['compteDebut'] !== null && psMin($c['dernier']) < psMin($c['compteDebut']) ? 'qui ne commençait qu’à ' . $e($c['compteDebut']) : 'qui n’était pas en poste')),
        'hors-planning' => 'au compte « <b>' . $e($c['compte']) . '</b> », qui n’est pas au planning',
        default => '<b>sans nom de vendeur</b>',
    };
    return '<b>' . $e($c['vendeuse']) . '</b> (planning ' . $e($c['poste']) . ') était la seule personne en vente, mais '
        . ($c['propres'] === 0 ? 'n’a pas pointé à la caisse' : 'n’a pas toujours pointé à la caisse')
        . ' : de ' . $e($c['premier']) . ' à ' . $e($c['dernier']) . ', <b>' . (int) $c['tickets'] . ' tickets (' . $eur . ')</b> ont été encaissés ' . $compte . '.';
}

/**
 * L'e-mail : un magasin, ou le récapitulatif réseau (plusieurs). $sections = [[sid, nom, cas[]]]. Rend [sujet, html].
 */
function psMailHtml(array $sections, string $date, bool $reseau = false): array
{
    $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $F = "font-family:'Segoe UI',Arial,sans-serif";
    $res = setting('reseau', []);
    $marque = is_array($res) ? ($res['nom'] ?? 'L’Atelier by') : 'L’Atelier by';
    $logoUri = function_exists('rapLogoDataUri') ? rapLogoDataUri() : '';
    $logo = $logoUri !== ''
        ? '<img src="' . $logoUri . '" height="30" style="display:block;height:30px" alt="' . $e($marque) . '">'
        : '<span style="' . $F . ';color:#221E1A;font-size:16px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase">' . $e($marque) . '</span>';
    $dFr = cqMailDateFr($date);
    $nCas = array_sum(array_map(fn ($s) => count($s[2]), $sections));
    $nTk = array_sum(array_map(fn ($s) => array_sum(array_column($s[2], 'tickets')), $sections));
    $deVille = static function (string $nom): string {
        $v = cqMailVille($nom);
        return (preg_match('/^[aeiouyhâàéèêëîïôöûüAEIOUYHÂÀÉÈÊËÎÏÔÖÛÜ]/u', $v) ? 'd’' : 'de ') . $v;
    };
    $titre = $reseau ? count($sections) . ' magasin' . (count($sections) > 1 ? 's' : '') : 'Magasin ' . $deVille($sections[0][1]);
    $c = '<tr><td class="pad" style="' . $F . ';padding:20px 26px 6px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="background:#FBEFE7;border-left:4px solid #A9521F;border-radius:8px;padding:14px 16px">'
        . '<div style="font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:#8A3F14;font-weight:700">Pointages suspects</div>'
        . '<div class="titre" style="font-size:19px;font-weight:700;color:#221E1A;margin-top:4px">' . $e($titre) . ' · ' . $e($dFr) . '</div>'
        . '<div style="font-size:13px;color:#5d554d;margin-top:3px">' . $nCas . ' cas · ' . $nTk . ' tickets encaissés au compte d’une autre personne</div>'
        . '</td></tr></table></td></tr>';
    $c .= '<tr><td class="pad" style="' . $F . ';font-size:14px;line-height:1.6;color:#221E1A;padding:14px 26px 4px">'
        . 'Bonjour,<br><br>Nous avons détecté des pointages suspects aujourd’hui' . ($reseau ? '' : ' au magasin ' . $e($deVille($sections[0][1]))) . ' :</td></tr>';
    $base = cqMailBase();
    foreach ($sections as [$sid, $nom, $cas]) {
        $bloc = '';
        if ($reseau) { $bloc .= '<div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#8b8177;font-weight:700;margin:6px 0 6px">' . $e(cqMailVille($nom)) . '</div>'; }
        foreach ($cas as $x) {
            $bloc .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px"><tr><td style="' . $F . ';border:1px solid #EFE9DF;border-radius:8px;padding:12px 14px;font-size:14px;line-height:1.55;color:#221E1A">'
                . psPhrase($x) . '</td></tr></table>';
        }
        if ($base !== '') {
            $bloc .= '<div style="margin:2px 0 8px"><a href="' . $e($base . '/dashboard/?shop=' . $sid . '&vue=ops&date=' . $date) . '" style="' . $F . ';font-size:13px;color:#8D1D2C;font-weight:700;text-decoration:none">Voir l’équipe du jour' . ($reseau ? ' — ' . $e(cqMailVille($nom)) : '') . ' ›</a></div>';
        }
        $c .= '<tr><td class="pad" style="' . $F . ';padding:8px 26px 0">' . $bloc . '</td></tr>';
    }
    $c .= '<tr><td class="pad" style="' . $F . ';font-size:14px;line-height:1.6;color:#221E1A;padding:10px 26px 4px">'
        . 'Merci de rappeler à l’équipe de s’identifier à la caisse en début de poste : les ventes, les primes et le suivi de chacun en dépendent.</td></tr>';
    $c .= '<tr><td class="pad" style="' . $F . ';font-size:11px;line-height:1.5;color:#8b8177;padding:10px 26px 0">'
        . 'Un cas : au moins ' . PS_MIN_TICKETS . ' tickets encaissés au compte de quelqu’un qui n’était pas en poste (parti, pas encore arrivé, pas au planning, ou sans vendeur) pendant qu’une seule personne était en vente, d’après le planning du jour.</td></tr>';
    $html = caMailSquelette('Équipe & caisse', 'message envoyé automatiquement en fin de journée, seulement quand des pointages suspects sont détectés.');
    $html = str_replace(['{{logo}}', '{{marque}}', '{{contenu}}'], [$logo, $e($marque), $c], $html);
    $sujet = 'Pointages suspects — ' . ($reseau ? count($sections) . ' magasin' . (count($sections) > 1 ? 's' : '') : cqMailVille($sections[0][1])) . ', ' . $dFr;
    return [$sujet, $html];
}

/** Les tickets du jour de chaque magasin : [sid => [[minute, id_employee, nom, montant], …]] ; un magasin muet est absent. */
function psTickets(array $sids, string $date): array
{
    $chemins = [];
    foreach ($sids as $sid) { $chemins[(string) $sid] = '/shops/' . $sid . '/transactions?date=' . $date; }
    $noms = function_exists('venteEmployes') ? venteEmployes() : [];
    $out = [];
    foreach (PanelApi::getParallele($chemins, 4) as $sid => $liste) {
        if (!is_array($liste)) { continue; }
        $T = [];
        foreach (analyseListe($liste) as $t) {
            if (!is_array($t)) { continue; }
            $ts = (string) ($t['insert_timestamp'] ?? '');
            if (strlen($ts) < 16) { continue; }
            $ide = (int) ($t['id_employee'] ?? 0);
            $T[] = [60 * (int) substr($ts, 11, 2) + (int) substr($ts, 14, 2), $ide,
                $ide > 0 ? ($noms[$ide]['nom'] ?? ('Employé ' . $ide)) : '',
                (float) ($t['total_gross_amount_after_discount'] ?? $t['total_value_after_discount'] ?? 0)];
        }
        $out[(string) $sid] = $T;
    }
    return $out;
}

/**
 * Le passage de fin de journée (ou l'envoi à la main depuis Rapports). `$apercu` : rien ne part, le run garde
 * l'e-mail récapitulatif.
 */
function psDistribuer(array $rep, bool $apercu = false): array
{
    $date = date('Y-m-d');
    $bilan = ['magasins' => []];
    if (!$apercu && !Smtp::configured()) { return $bilan + ['resume' => 'SMTP non configuré (Paramètres) — aucun envoi']; }
    $J = rapAppel('ep_exploitation_jour', ['date' => $date]);
    $mags = is_array($J) ? (array) ($J['magasins'] ?? []) : [];
    $mags = array_values(array_filter($mags, fn ($m) => !empty($m['planning'])));
    if ($mags === []) { return $bilan + ['resume' => 'pas de planning lu pour aujourd’hui — aucun envoi']; }
    $TK = psTickets(array_map(fn ($m) => (int) $m['shopId'], $mags), $date);
    $deja = setting('psMailEnvoyes');
    $deja = is_array($deja) && ($deja['date'] ?? '') === $date ? array_map('strval', (array) ($deja['shops'] ?? [])) : [];
    $sections = [];
    foreach ($mags as $m) {
        $sid = (string) $m['shopId']; $nom = (string) ($m['magasin'] ?? ('Magasin ' . $sid));
        if (!isset($TK[$sid])) { $bilan['magasins'][] = ['magasin' => $nom, 'statut' => 'tickets-muets']; continue; }
        $cas = psDetecter((array) $m['planning'], $TK[$sid]);
        if ($cas === []) { $bilan['magasins'][] = ['magasin' => $nom, 'statut' => 'rien']; continue; }
        $sections[] = [(int) $sid, $nom, $cas];
        $resumeCas = implode(' ; ', array_map(fn ($x) => $x['vendeuse'] . ' → ' . ($x['compte'] ?: 'sans vendeur') . ' (' . $x['tickets'] . ' tickets)', $cas));
        if ($apercu) { $bilan['magasins'][] = ['magasin' => $nom, 'statut' => 'a-envoyer', 'note' => $resumeCas]; continue; }
        if (in_array($sid, $deja, true)) { $bilan['magasins'][] = ['magasin' => $nom, 'statut' => 'deja-envoye', 'note' => $resumeCas]; continue; }
        $dests = cqMailAdresses($rep, $nom);
        if ($dests === []) { $bilan['magasins'][] = ['magasin' => $nom, 'statut' => 'sans-adresse', 'note' => $resumeCas]; continue; }
        [$sujet, $html] = psMailHtml([[(int) $sid, $nom, $cas]], $date);
        $envoyes = []; $rate = null;
        foreach ($dests as $d) { if (Smtp::envoyer($d, $sujet, $html)) { $envoyes[] = $d; } else { $rate = (string) Smtp::$lastError; } }
        if ($envoyes !== []) { $deja[] = $sid; }
        $bilan['magasins'][] = ['magasin' => $nom, 'statut' => $envoyes !== [] ? 'envoye' : 'echec', 'envoyes' => $envoyes, 'note' => $rate ?? $resumeCas];
    }
    // Le récapitulatif réseau : les destinataires de la ligne, tous les magasins concernés dans un seul e-mail.
    $recap = $sections !== [] ? psMailHtml($sections, $date, true) : null;
    $reseauEnv = [];
    if (!$apercu && $recap !== null && !in_array('reseau', $deja, true)) {
        foreach (array_filter((array) (json_decode((string) ($rep['destinataires'] ?? '[]'), true) ?: []), fn ($d) => is_string($d) && filter_var($d, FILTER_VALIDATE_EMAIL)) as $d) {
            if (Smtp::envoyer($d, $recap[0], $recap[1])) { $reseauEnv[] = $d; }
        }
        if ($reseauEnv !== []) { $deja[] = 'reseau'; }
    }
    if (!$apercu) {
        Db::exec('INSERT INTO ceo_app_setting VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            ['psMailEnvoyes', json_encode(['date' => $date, 'shops' => array_values(array_unique($deja))])]);
    }
    $cpt = static fn (string $st) => count(array_filter($bilan['magasins'], fn ($m2) => $m2['statut'] === $st));
    $resume = ($apercu ? 'Aperçu ' : 'Pointages suspects ') . date('H:i') . ' — ' . count($sections) . ' magasin(s) concerné(s)'
        . ($apercu ? '' : ', ' . $cpt('envoye') . ' prévenu(s), ' . $cpt('sans-adresse') . ' sans adresse' . ($cpt('echec') ? ', ' . $cpt('echec') . ' échec(s)' : '')
            . ($reseauEnv !== [] ? ', récapitulatif à ' . implode(', ', $reseauEnv) : ''));
    $lst = '<div style="font-family:Helvetica,Arial,sans-serif;font-size:13px">' . htmlspecialchars($resume) . '<ul>'
        . implode('', array_map(fn ($m2) => '<li>' . htmlspecialchars($m2['magasin'] . ' — ' . $m2['statut']
            . (!empty($m2['envoyes']) ? ' (' . implode(', ', $m2['envoyes']) . ')' : '') . (!empty($m2['note']) ? ' · ' . $m2['note'] : '')) . '</li>', $bilan['magasins']))
        . '</ul></div>';
    Db::exec('INSERT INTO ceo_rapport_run (rapport_id, genere_le, periode_du, periode_au, statut, resume, html, contexte)
              VALUES (?,?,?,?,?,?,?,?)',
        [(int) $rep['id'], date('Y-m-d H:i:s'), $date, $date,
         $apercu ? 'genere' : ($cpt('envoye') > 0 || $reseauEnv !== [] ? 'envoye' : 'vide'), $resume,
         $apercu && $recap !== null ? $recap[1] : $lst, json_encode([], JSON_UNESCAPED_UNICODE)]);
    $bilan['runId'] = (int) Db::pdo()->lastInsertId();
    $bilan['resume'] = $resume;
    $bilan['statut'] = $apercu ? 'genere' : 'distribue';
    if (!$apercu) { journalAdd('CEO', 'Rapport', (string) $rep['nom'], $resume); }
    return $bilan;
}
