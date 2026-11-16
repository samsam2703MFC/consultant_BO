<?php
declare(strict_types=1);

/**
 * Dashboard magasin › Paramètres › Mails et automatisations (16/11/2026, maquette v3.1 validée par Sam : « code: ok »,
 * docs/maquettes/parametres-mails/). Les envois que le magasin programme vers l'extérieur.
 *
 * Première automatisation : LE RAPPEL LA VEILLE DU RETRAIT. Chaque commande client du panel à retirer demain
 * (`/shops/{id}/client-orders`, ni remise, ni annulée) : son client reçoit, à l'heure choisie (17:00 par défaut), un
 * e-mail depuis la boîte du magasin et/ou un SMS par l'API SMS du cockpit (src/sms.php) — « e-mail », « SMS » ou « les
 * deux » ; un client sans e-mail reçoit le SMS seul, et l'inverse. Une commande passée moins de 24 h avant le retrait
 * n'a pas de rappel (option). Chaque envoi part une seule fois (le journal de la boîte, clé unique) et s'y lit.
 * Le panel ne joint pas les articles à la commande (mesuré le 03/10/2026, src/production_plan.php) : le rappel dit le
 * jour, l'heure, le montant et ce qui reste à payer, pas le détail des articles.
 *
 * À venir (maquette) : le dossier comptable du mois ; « Commande prête » (marquée prête sur la tablette, en gris).
 *
 * LE VERROU. Ces envois partent de la boîte du magasin : leurs routes sont celles de la boîte (src/franchise/boite_mail.php),
 * réglables seulement boîte ouverte sur l'appareil (son mot de passe). L'API du cockpit étant ouverte, personne d'autre
 * ne peut changer le texte qui part aux clients. Les commandes de demain s'affichent avec le prénom et l'initiale du
 * nom, jamais l'e-mail ni le téléphone.
 *
 *   GET  /dashboard/boite/automatisations?shop=          les automatisations, le réglage du rappel, les envois de demain, le journal
 *   POST /dashboard/boite/automatisations {shop, auto: rappel_retrait, actif, canal: email|sms|les2, heure: HH:MM,
 *        recent: bool, objet, corps, sms}
 *   POST /dashboard/boite/automatisations/essai {shop, auto, tel?}  un essai : l'e-mail à la boîte du magasin, le SMS au numéro donné
 * L'horloge : bmCron (bin/boite_cron.sh, toutes les 10 minutes) appelle auCron().
 */

const AU_AUTOS = [
    'rappel_retrait' => ['nom' => 'Rappel la veille du retrait', 'pour' => 'Clients'],
];

/** Le réglage de départ du rappel : éteint, les deux canaux, 17:00. */
const AU_RAPPEL = [
    'actif' => false, 'canal' => 'les2', 'heure' => '17:00', 'recent' => true,
    'objet' => 'Votre commande de demain, {heure} · {magasin}',
    'corps' => "Bonjour {prenom},\n\nVotre commande vous attend demain, {jour}, à partir de {heure}.\n{paiement}\n\nUn changement ? Répondez simplement à cet e-mail.\n\nÀ demain,\n{magasin}",
    'sms' => '{magasin} : votre commande vous attend demain, {jour}, à {heure}. {paiement}',
];

/** Les variables des messages, et ce qu'elles valent. */
const AU_VARIABLES = [
    '{prenom}' => 'le prénom du client', '{nom}' => 'son nom', '{magasin}' => 'le nom du magasin', '{jour}' => 'le jour du retrait (mardi 17 novembre)',
    '{heure}' => 'l’heure du retrait', '{total}' => 'le montant de la commande', '{paiement}' => '« Elle est déjà réglée. » ou « Reste à payer au retrait : … »',
    '{commande}' => 'le n° de la commande',
];
const AU_HEURE_MIN = '12:00';
const AU_HEURE_MAX = '21:00';
const AU_SMS_MAX = 306;        // deux SMS au plus (153 signes chacun en GSM)

const AU_JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
const AU_MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/* --- le réglage (pil_auto_reglage) ------------------------------------------------------------------------------ */

function auTables(): void
{
    static $fait = false;
    if ($fait) { return; }
    $fait = true;
    Db::exec('CREATE TABLE IF NOT EXISTS pil_auto_reglage (shop_id VARCHAR(16) NOT NULL, auto VARCHAR(30) NOT NULL, actif TINYINT(1) NOT NULL DEFAULT 0,'
        . ' reglage TEXT NOT NULL, maj_le DATETIME NOT NULL, PRIMARY KEY (shop_id, auto), KEY k_actif (actif)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Le réglage d'une automatisation du magasin, le réglage de départ complété. */
function auReglage(string $shop, string $auto): array
{
    $r = Db::row('SELECT actif, reglage FROM pil_auto_reglage WHERE shop_id = ? AND auto = ?', [$shop, $auto]);
    $g = $r !== null ? json_decode((string) $r['reglage'], true) : null;
    $out = array_merge(AU_RAPPEL, is_array($g) ? array_intersect_key($g, AU_RAPPEL) : []);
    $out['actif'] = $r !== null && (int) $r['actif'] === 1;
    return $out;
}

/** Valide un réglage reçu. Rend [réglage, erreur|null]. */
function auValider(array $actuel, array $b): array
{
    $g = $actuel;
    if (array_key_exists('actif', $b)) { $g['actif'] = (bool) $b['actif']; }
    if (array_key_exists('canal', $b)) {
        if (!in_array($b['canal'], ['email', 'sms', 'les2'], true)) { return [null, 'Le canal : e-mail, SMS ou les deux.']; }
        $g['canal'] = $b['canal'];
    }
    if (array_key_exists('heure', $b)) {
        $h = (string) $b['heure'];
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h) || $h < AU_HEURE_MIN || $h > AU_HEURE_MAX) { return [null, 'L’heure d’envoi : entre ' . AU_HEURE_MIN . ' et ' . AU_HEURE_MAX . '.']; }
        $g['heure'] = $h;
    }
    if (array_key_exists('recent', $b)) { $g['recent'] = (bool) $b['recent']; }
    foreach (['objet' => 200, 'corps' => 5000, 'sms' => AU_SMS_MAX] as $k => $max) {
        if (!array_key_exists($k, $b)) { continue; }
        $v = trim(str_replace("\r", '', (string) $b[$k]));
        if (mb_strlen($v) > $max) { return [null, ['objet' => 'L’objet', 'corps' => 'Le texte de l’e-mail', 'sms' => 'Le SMS'][$k] . ' dépasse ' . $max . ' signes.']; }
        $g[$k] = $v;
    }
    if ($g['canal'] !== 'sms' && ($g['objet'] === '' || $g['corps'] === '')) { return [null, 'L’e-mail a besoin d’un objet et d’un texte.']; }
    if ($g['canal'] !== 'email' && $g['sms'] === '') { return [null, 'Le SMS est vide.']; }
    return [$g, null];
}

/** POST /dashboard/boite/automatisations */
function auEcrire(string $shop, array $c, array $b): array
{
    auTables();
    $auto = (string) ($b['auto'] ?? '');
    if (!isset(AU_AUTOS[$auto])) { return bmErreur(422, 'automatisation inconnue'); }
    $actuel = auReglage($shop, $auto);
    [$g, $err] = auValider($actuel, $b);
    if ($err !== null) { return bmErreur(422, $err); }
    $actif = (bool) $g['actif'];
    unset($g['actif']);
    Db::exec('INSERT INTO pil_auto_reglage (shop_id, auto, actif, reglage, maj_le) VALUES (?, ?, ?, ?, NOW())
              ON DUPLICATE KEY UPDATE actif = VALUES(actif), reglage = VALUES(reglage), maj_le = NOW()',
        [$shop, $auto, $actif ? 1 : 0, json_encode($g, JSON_UNESCAPED_UNICODE)]);
    bmJeton();   // l'horloge (bin/boite_cron.sh) a besoin de son jeton
    $note = null;
    if ($auto === 'rappel_retrait' && $actif && !$actuel['actif']) {
        // Le rappel des offres converties (Boîte mail › Messages automatiques) ferait un second rappel : il s'éteint.
        $n = Db::exec('UPDATE pil_boite_auto SET actif = 0, maj_le = NOW() WHERE shop_id = ? AND regle = ? AND actif = 1', [$shop, 'rappel']);
        if ($n) { $note = 'Le « Rappel la veille de la commande » des offres (Boîte mail › Messages automatiques) est éteint : celui-ci couvre toutes les commandes.'; }
    }
    if ($actif !== $actuel['actif']) { journalAdd('Magasin ' . $shop, 'Automatisations', null, '« ' . AU_AUTOS[$auto]['nom'] . ' » ' . ($actif ? 'allumé' : 'éteint')); }
    return ['ok' => true, 'note' => $note] + auLire($shop, $c);
}

/* --- les commandes de demain ------------------------------------------------------------------------------------ */

/** Les commandes du panel à retirer ce jour-là, ni remises ni annulées. null : le panel ne répond pas. */
function auCommandes(int $sid, string $jour): ?array
{
    $r = PanelApi::sondeGet('/shops/' . $sid . '/client-orders?date_from=' . $jour, 25);
    // Sans commande, le panel répond {status: error, description: NO_ORDERS_FOR_PICKUP_DATE} : une liste vide.
    if (is_array($r['corps'] ?? null) && str_contains((string) ($r['corps']['description'] ?? ''), 'NO_ORDERS')) { return []; }
    if ((int) ($r['code'] ?? 0) !== 200 || !is_array($r['corps'] ?? null)) { return null; }
    $out = [];
    foreach ((function_exists('analyseListe') ? analyseListe($r['corps']) : $r['corps']) as $o) {
        if (!is_array($o) || substr((string) ($o['pick_up_datetime'] ?? ''), 0, 10) !== $jour) { continue; }
        if (($o['non_collection_id_reason'] ?? null) !== null || ($o['issuing_timestamp'] ?? null) !== null) { continue; }
        if (in_array(strtolower((string) ($o['order_status'] ?? '')), ['picked_up', 'delivered', 'cancelled', 'canceled'], true)) { continue; }
        $out[] = $o;
    }
    usort($out, static fn ($a, $b) => strcmp((string) $a['pick_up_datetime'], (string) $b['pick_up_datetime']));
    return $out;
}

/** « mardi 17 novembre » */
function auJourLong(string $j): string
{
    $t = strtotime($j . ' 12:00:00');
    return AU_JOURS[(int) date('w', $t)] . ' ' . (int) date('j', $t) . ' ' . AU_MOIS[(int) date('n', $t) - 1];
}

/** Les variables d'une commande. */
function auVars(array $o, string $magasin): array
{
    $cl = is_array($o['client'] ?? null) ? $o['client'] : [];
    $prenom = trim((string) ($cl['name'] ?? ''));
    $nom = trim((string) ($cl['surname'] ?? ''));
    if (!empty($cl['is_b2b']) && trim((string) ($cl['company_name'] ?? '')) !== '') { $prenom = $prenom !== '' ? $prenom : trim((string) $cl['company_name']); }
    $q = (string) ($o['pick_up_datetime'] ?? '');
    $total = round((float) ($o['total_value'] ?? 0), 2);
    $eur = number_format($total, 2, ',', ' ') . ' €';
    $paye = (int) ($o['id_transaction'] ?? 0) > 0;
    return ['prenom' => $prenom, 'nom' => $nom, 'magasin' => $magasin, 'jour' => auJourLong(substr($q, 0, 10)), 'heure' => substr($q, 11, 5),
        'total' => $eur, 'paiement' => $paye ? 'Elle est déjà réglée.' : ($total > 0 ? 'Reste à payer au retrait : ' . $eur . '.' : ''),
        'commande' => (string) ($o['id'] ?? '')];
}

/** Un modèle rempli ; une variable inconnue reste telle quelle, une variable vide s'efface (et l'espace double avec). */
function auRemplir(string $t, array $v): string
{
    $s = (string) preg_replace_callback('/\{(prenom|nom|magasin|jour|heure|total|paiement|commande)\}/', static fn ($m) => (string) ($v[$m[1]] ?? ''), $t);
    $s = (string) preg_replace('/[ \t]{2,}/', ' ', $s);
    return trim((string) preg_replace('/ +([,.])/', '$1', $s));
}

/** Où joindre le client d'une commande : [e-mail|null, téléphone international|null]. */
function auContacts(array $o): array
{
    $cl = is_array($o['client'] ?? null) ? $o['client'] : [];
    $mail = strtolower(trim((string) ($cl['email'] ?? '')));
    $mail = filter_var($mail, FILTER_VALIDATE_EMAIL) ? $mail : null;
    $tel = trim((string) ($cl['phone'] ?? ''));
    $tel = $tel !== '' && function_exists('smsNorme') ? smsNorme($tel) : null;
    return [$mail, $tel];
}

/** Les canaux d'un envoi : ce que le réglage demande, ce que la fiche permet. */
function auCanaux(string $canal, ?string $mail, ?string $tel): array
{
    $m = $canal !== 'sms' && $mail !== null;
    $s = $canal !== 'email' && $tel !== null;
    // « Les deux » : un client sans e-mail reçoit le SMS seul, et l'inverse — ce que fait déjà le filtre ci-dessus.
    // « E-mail » seul, client sans e-mail : le SMS le remplace ; « SMS » seul, sans téléphone : l'e-mail.
    if ($canal === 'email' && $mail === null && $tel !== null) { $s = true; }
    if ($canal === 'sms' && $tel === null && $mail !== null) { $m = true; }
    return ['email' => $m, 'sms' => $s];
}

/** La commande passée moins de 24 h avant son retrait : la confirmation suffit. */
function auTropRecente(array $o): bool
{
    $a = strtotime((string) ($o['accepting_timestamp'] ?? ''));
    $p = strtotime((string) ($o['pick_up_datetime'] ?? ''));
    return $a !== false && $p !== false && $p - $a < 86400;
}

/** Le journal d'une commande : [canal => {le, erreur}]. */
function auJournalCommande(string $shop, string $cleBase): array
{
    $out = [];
    foreach (Db::rows('SELECT cle, envoye_le, erreur FROM pil_boite_journal WHERE shop_id = ? AND cle LIKE ?', [$shop, $cleBase . ':%']) as $r) {
        $out[substr((string) $r['cle'], strlen($cleBase) + 1)] = ['le' => $r['envoye_le'], 'erreur' => $r['erreur']];
    }
    return $out;
}

/** Envoie le rappel d'une commande, canal par canal, une seule fois chacun. Rend [canal => envoyé|déjà|erreur]. */
function auEnvoyerRappel(string $shop, array $c, array $o, array $g): array
{
    [$mail, $tel] = auContacts($o);
    $k = auCanaux($g['canal'], $mail, $tel);
    $v = auVars($o, $c['nom']);
    $base = 'rappel_retrait:' . (int) ($o['id'] ?? 0) . ':' . substr((string) $o['pick_up_datetime'], 0, 10);
    $out = [];
    foreach (['email' => $mail, 'sms' => $tel] as $canal => $a) {
        if (!$k[$canal]) { continue; }
        $cle = $base . ':' . $canal;
        if (Db::row('SELECT id FROM pil_boite_journal WHERE shop_id = ? AND cle = ?', [$shop, $cle]) !== null) { $out[$canal] = 'déjà'; continue; }
        $objet = $canal === 'email' ? auRemplir($g['objet'], $v) : 'SMS';
        Db::exec('INSERT INTO pil_boite_journal (shop_id, regle, cle, destinataire, objet, envoye_le) VALUES (?, ?, ?, ?, ?, NOW())',
            [$shop, 'rappel_retrait', $cle, (string) $a, mb_substr($objet, 0, 255)]);
        $err = null;
        try {
            if ($canal === 'email') {
                bmEnvoyer($shop, $c, [['nom' => trim($v['prenom'] . ' ' . $v['nom']), 'adresse' => (string) $mail]], [], $objet, auRemplir($g['corps'], $v));
            } else {
                $r = smsPartir((string) $tel, auRemplir($g['sms'], $v));
                if (!$r['ok']) { $err = (string) $r['erreur']; }
            }
        } catch (Throwable $e) { $err = $e->getMessage(); }
        if ($err !== null) { Db::exec('UPDATE pil_boite_journal SET erreur = ? WHERE shop_id = ? AND cle = ?', [mb_substr($err, 0, 255), $shop, $cle]); }
        $out[$canal] = $err === null ? 'envoyé' : 'erreur';
    }
    return $out;
}

/** Le rappel d'un magasin, une fois : à partir de l'heure choisie et jusqu'à 22:00, les commandes de demain. */
function auTournerRappel(string $shop, array $c, array $g, ?int $maintenant = null): array
{
    $t = $maintenant ?? time();
    $h = date('H:i', $t);
    if ($h < $g['heure'] || $h >= '22:00') { return ['attente' => $g['heure']]; }
    $demain = date('Y-m-d', strtotime('+1 day', $t));
    $L = auCommandes((int) $shop, $demain);
    if ($L === null) { return ['erreur' => 'les commandes ne se lisent pas au panel']; }
    $out = [];
    foreach ($L as $o) {
        if ($g['recent'] && auTropRecente($o)) { continue; }
        $out[(string) ($o['id'] ?? '?')] = auEnvoyerRappel($shop, $c, $o, $g);
    }
    return $out;
}

/** L'horloge : chaque magasin qui a une automatisation allumée. Appelée par bmCron. */
function auCron(): array
{
    auTables();
    $rapport = [];
    foreach (Db::rows('SELECT shop_id, auto FROM pil_auto_reglage WHERE actif = 1') as $r) {
        $shop = (string) $r['shop_id'];
        $c = bmCompte($shop);
        if ($c === null) { $rapport[$shop][$r['auto']] = 'boîte non reliée'; continue; }
        try {
            if ($r['auto'] === 'rappel_retrait') { $rapport[$shop][$r['auto']] = auTournerRappel($shop, $c, auReglage($shop, 'rappel_retrait')); }
        } catch (Throwable $e) { $rapport[$shop][$r['auto']] = 'erreur : ' . $e->getMessage(); }
    }
    return $rapport;
}

/* --- la lecture de l'écran -------------------------------------------------------------------------------------- */

/** GET /dashboard/boite/automatisations */
function auLire(string $shop, array $c): array
{
    auTables();
    $g = auReglage($shop, 'rappel_retrait');
    $demain = date('Y-m-d', strtotime('+1 day'));
    $L = auCommandes((int) $shop, $demain);
    $cmd = [];
    foreach ($L ?? [] as $o) {
        [$mail, $tel] = auContacts($o);
        $k = auCanaux($g['canal'], $mail, $tel);
        $v = auVars($o, $c['nom']);
        $j = auJournalCommande($shop, 'rappel_retrait:' . (int) ($o['id'] ?? 0) . ':' . $demain);
        $recente = $g['recent'] && auTropRecente($o);
        $etat = $recente ? 'recente' : (!$k['email'] && !$k['sms'] ? 'injoignable' : 'prevu');
        foreach ($j as $x) { $etat = $x['erreur'] !== null ? 'erreur' : 'envoye'; if ($x['erreur'] !== null) { break; } }
        $cmd[] = ['id' => (int) ($o['id'] ?? 0), 'heure' => $v['heure'], 'client' => trim($v['prenom'] . ' ' . ($v['nom'] !== '' ? mb_substr($v['nom'], 0, 1) . '.' : '')),
            'total' => round((float) ($o['total_value'] ?? 0), 2), 'paye' => (int) ($o['id_transaction'] ?? 0) > 0, 'webshop' => !empty($o['is_webshop']),
            'email' => $mail !== null, 'tel' => $tel !== null, 'canaux' => $k, 'etat' => $etat,
            'envois' => array_map(static fn ($x) => ['le' => $x['le'], 'erreur' => $x['erreur']], $j)];
    }
    $journal = array_map(static fn ($r) => ['le' => $r['envoye_le'], 'canal' => str_ends_with((string) $r['cle'], ':sms') ? 'sms' : 'email',
        'commande' => (int) (explode(':', (string) $r['cle'])[1] ?? 0), 'objet' => $r['objet'], 'erreur' => $r['erreur']],
        Db::rows('SELECT cle, objet, envoye_le, erreur FROM pil_boite_journal WHERE shop_id = ? AND regle = ? ORDER BY envoye_le DESC, id DESC LIMIT 30', [$shop, 'rappel_retrait']));
    $h = setting('boiteHorloge', []);
    $offres = Db::row('SELECT actif FROM pil_boite_auto WHERE shop_id = ? AND regle = ?', [$shop, 'rappel']);
    return [
        'shop' => $shop, 'boite' => $c['adresse'], 'magasin' => $c['nom'], 'horloge' => is_array($h) ? ($h['le'] ?? null) : null,
        'sms' => function_exists('smsFournisseur') ? smsFournisseur() : null,
        'rappel' => ['reglage' => $g, 'variables' => AU_VARIABLES, 'smsMax' => AU_SMS_MAX, 'heures' => [AU_HEURE_MIN, AU_HEURE_MAX],
            'offresAllume' => $offres !== null && (int) $offres['actif'] === 1,
            'demain' => ['jour' => $demain, 'lib' => auJourLong($demain), 'lu' => $L !== null, 'commandes' => $cmd], 'journal' => $journal],
    ];
}

/** POST /dashboard/boite/automatisations/essai {shop, auto, tel?} : l'e-mail à la boîte du magasin, le SMS au numéro donné. */
function auEssai(string $shop, array $c, array $b): array
{
    auTables();
    if ((string) ($b['auto'] ?? '') !== 'rappel_retrait') { return bmErreur(422, 'automatisation inconnue'); }
    [$g, $err] = auValider(auReglage($shop, 'rappel_retrait'), $b);
    if ($err !== null) { return bmErreur(422, $err); }
    $demain = date('Y-m-d', strtotime('+1 day'));
    $exemple = ['id' => 0, 'pick_up_datetime' => $demain . ' 08:30:00', 'total_value' => 68.4, 'id_transaction' => 0, 'client' => ['name' => 'Camille', 'surname' => 'Exemple']];
    $v = auVars($exemple, $c['nom']);
    $out = ['ok' => true, 'email' => null, 'sms' => null];
    if ($g['canal'] !== 'sms') {
        try { bmEnvoyer($shop, $c, [['nom' => $c['nom'], 'adresse' => $c['adresse']]], [], '[Essai] ' . auRemplir($g['objet'], $v), auRemplir($g['corps'], $v)); $out['email'] = 'envoyé à ' . $c['adresse']; }
        catch (Throwable $e) { $out['email'] = 'échec : ' . $e->getMessage(); $out['ok'] = false; }
    }
    $tel = trim((string) ($b['tel'] ?? ''));
    if ($g['canal'] !== 'email' && $tel !== '') {
        $r = smsPartir($tel, auRemplir($g['sms'], $v));
        $out['sms'] = $r['ok'] ? 'envoyé au ' . (function_exists('smsLisible') ? smsLisible($r['a']) : (string) $r['a']) : 'échec : ' . $r['erreur'];
        if (!$r['ok']) { $out['ok'] = false; }
    }
    return $out;
}
