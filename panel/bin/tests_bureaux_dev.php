#!/usr/bin/env php
<?php
declare(strict_types=1);
ob_start(); // http_response_code() des routes, sans en-têtes déjà partis

/**
 * Tests des bureaux en développement (panel/src/bureaux_dev.php) — sans base, sans réseau : la réponse au questionnaire
 * rendue propre (obligatoires, TVA, téléphone, jours, accord), la ligne d'une demande, l'id d'un client créé, le bon de
 * bienvenue (code commun ou code personnel), et les routes publiques qui refusent un jeton mal formé.
 */

final class Db
{
    public static array $exec = [];
    /** La ligne que rend row() (null par défaut : aucune ligne en base). */
    public static ?array $ligne = null;
    /** Une base simulée : si posée, row() lui demande la ligne (sql, paramètres) au lieu de rendre $ligne. */
    public static $base = null;
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return self::$base ? (self::$base)($sql, $a) : self::$ligne; }
    public static function pdo(): object { return new class { public function lastInsertId(): string { return '77'; } }; }
    public static function exec(string $sql, array $a = []): int { self::$exec[] = [$sql, $a]; return 1; }
}
final class ErpApi
{
    public static array $vus = [];
    public static function disponible(): bool { return true; }
    public static function ecrire(string $m, string $p, ?array $b = null): array { self::$vus[] = [$m, $p, $b]; return ['code' => 200, 'corps' => ['id' => 5], 'erreur' => null]; }
}
$GLOBALS['cible'] = 'clients';
function pmMienne(int $shop, int $id): ?array { return $id === 321 ? ['panelId' => 321, 'nom' => '−20 % le premier lunch', 'description' => 'une fois', 'statut' => 'en_caisse', 'bon' => ['campagne' => 900]] : null; }
function pmMiennes(int $shop): array { return [[pmMienne($shop, 321), ['panelId' => 4, 'nom' => 'Sans bon', 'statut' => 'en_caisse', 'bon' => null]], null]; }
function pbLire(int $camp, array $c = []): ?array { return ['campagne' => $camp, 'cible' => $GLOBALS['cible'], 'code' => $GLOBALS['cible'] === 'code' ? 'BUREAU10' : null]; }
function body(): array { return $GLOBALS['corps'] ?? []; }
/** Les SMS des formulaires (sms_formulaires.php) : notés, jamais envoyés. */
$GLOBALS['sms'] = [];
function smsfNotifier(string $shop, string $quoi, array $d): void { $GLOBALS['sms'][] = [$shop, $quoi, $d]; }

require __DIR__ . '/../src/bureaux_dev.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; } else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "La réponse rendue propre\n";
$bon = ['prenom' => ' Marie ', 'nom' => 'Dupont', 'email' => 'Marie.Dupont@Horizon.be', 'tel' => '+32 470 12 34 56', 'societe' => 'Centre Horizon', 'tva' => 'be 0789.114.256',
    'factRue' => 'Rue de l’Industrie', 'factNum' => '22', 'factCp' => '6041', 'factVille' => 'Gosselies', 'secteur' => 'corporate', 'paiement' => 'company',
    'adresse' => 'Rue de la Station 14', 'cp' => '6040', 'ville' => 'Jumet', 'personnes' => '18', 'jours' => ['5', '1', '9', '1'], 'de' => '11:30', 'a' => '12:15', 'remise' => 'qr',
    'services' => ['RH', 'RH', 'Direction'], 'collegues' => [['nom' => 'Tom', 'email' => 'tom@horizon.be'], ['nom' => 'x', 'email' => 'pas-un-mail'], ['email' => 'TOM@horizon.be']],
    'accord' => true, 'remarque' => str_repeat('x', 900), 'logo' => 'data:text/html;base64,AAAA', 'motdepasse' => 'secret'];
[$d, $e] = bdvNettoyer($bon, true);
verifie('une réponse complète passe', $e === [], implode(', ', $e));
verifie('TVA, e-mail, téléphone, jours mis en forme', $d['tva'] === 'BE0789114256' && $d['email'] === 'marie.dupont@horizon.be' && $d['tel'] === '+32470123456' && $d['jours'] === [1, 5] && $d['prenom'] === 'Marie');
verifie('le nom du bureau : la société, la remarque coupée, la facturation à l’e-mail de la personne', $d['bureau'] === 'Centre Horizon' && mb_strlen($d['remarque']) === 600 && $d['personnes'] === 18 && $d['emailFact'] === 'marie.dupont@horizon.be');
verifie('services sans doublon, collègues aux e-mails valides et uniques', $d['services'] === ['RH', 'Direction'] && array_column($d['collegues'], 'email') === ['tom@horizon.be']);
verifie('aucun mot de passe gardé, un logo qui n’est pas une image refusé', !array_key_exists('motdepasse', $d) && $d['logo'] === '');
[, $e] = bdvNettoyer(['accord' => true], true);
verifie('vide : ce qui manque, dit en clair', in_array('un e-mail valide', $e, true) && in_array('le numéro de TVA de la société', $e, true) && in_array('au moins un jour de livraison', $e, true));
[, $e] = bdvNettoyer(['accord' => false] + $bon, true);
verifie('le formulaire exige l’accord, le dashboard non', $e === ['votre accord avec les conditions et la vie privée'] && bdvNettoyer(['accord' => false, 'de' => '', 'a' => ''] + $bon, false)[1] === []);
[, $e] = bdvNettoyer(['tva' => '12'] + $bon, true);
verifie('une TVA fausse est refusée ; absente : obligatoire au formulaire, pas au dashboard', $e === ['un numéro de TVA valide (BE0123456789)'] && bdvNettoyer(['tva' => ''] + $bon, true)[1] === ['le numéro de TVA de la société'] && bdvNettoyer(['tva' => ''] + $bon, false)[1] === []);
verifie('un créneau à l’envers est refusé', bdvNettoyer(['de' => '12:00', 'a' => '11:00'] + $bon, true)[1] === ['le créneau de livraison (de … à …)']);
verifie('un téléphone trop court ne passe pas', bdvTel('0470 12') === '' && bdvTel('0032 470 12 34 56') === '0032470123456');
verifie('le téléphone au format international', bdvE164('0470123456') === '+32470123456' && bdvE164('0032470123456') === '+32470123456' && bdvE164('+33612345678') === '+33612345678' && bdvE164('0612345678', 'FR') === '+33612345678');
$jwt = 'x.' . rtrim(strtr(base64_encode(json_encode(['app' => 'CLIENT', 'client_id' => 4567])), '+/', '-_'), '=') . '.y';
verifie('le client d’un jeton webshop', bdvJwtClient($jwt) === 4567 && bdvJwtClient('pas.un.jeton') === null && bdvJwtClient('x') === null);

echo "Le bureau pour TF Buddy\n";
$c = bdvCorpsOnboard($d, 'Horizon · Jumet', 'BE0789114256', 77, 'active', 5, 1999, 12);
verifie('le bureau : société, facturation, secteur, remise, créneau, jours', $c['office']['id_company_client'] === 77 && $c['office']['billing_mode'] === 'company' && $c['office']['segment'] === 'corporate'
    && $c['office']['delivery_validation'] === 'qr' && $c['office']['delivery_window_start'] === '11:30' && $c['office']['delivery_days'] === [1, 5] && !isset($c['office']['logo_data_url']));
verifie('le site, les services, les collègues invités', $c['sites'][0]['city'] === 'Jumet' && $c['sites'][0]['route_id'] === 5 && $c['departments'][1]['name'] === 'Direction'
    && $c['staff'] === [['name' => 'Tom', 'email' => 'tom@horizon.be']] && $c['send_membership'] === true && $c['request_key'] === 'bdv-12');
$c = bdvCorpsOnboard(['collegues' => []] + $d, 'H', 'BE0789114256', 77, 'pending', null, null, 13);
verifie('sans id de client : la personne invitée par son e-mail', $c['staff'] === [['name' => 'Marie Dupont', 'email' => 'marie.dupont@horizon.be']] && $c['office']['status'] === 'pending' && !isset($c['sites'][0]['route_id']));

echo "La demande et TF Buddy\n";
$l = bdvLigne(['id' => '7', 'source' => 'questionnaire', 'statut' => 'a_valider', 'cree_le' => '2026-11-26 06:55:12', 'donnees' => json_encode($d), 'client_id' => '1999', 'societe_id' => null,
    'bon_code' => 'BC2N54QRT', 'bon_texte' => '−20 %', 'erreurs' => "société non créée\n", 'office_id' => null, 'traite_le' => null]);
verifie('la ligne d’une demande', $l['id'] === 7 && $l['personne'] === 'Marie Dupont' && $l['clientId'] === 1999 && $l['societeId'] === null && $l['bon']['code'] === 'BC2N54QRT' && $l['erreurs'] === ['société non créée'] && $l['le'] === '2026-11-26 06:55');
verifie('l’id d’un client créé, quelle que soit l’enveloppe', bdvIdClient(['data' => ['id' => 12]]) === 12 && bdvIdClient(['client' => ['id' => '13']]) === 13 && bdvIdClient(['id' => 14]) === 14 && bdvIdClient(['success' => false]) === null);

echo "Le bon de bienvenue\n";
[$c, $t, $err] = bdvBon(3, 321, 1999);
$w = ErpApi::$vus[0] ?? null;
verifie('un bon à codes personnels : un code au nom du client, une utilisation', $c !== null && $err === null && $w[1] === '/admin/voucher-campaigns/900/codes' && $w[2]['id_customer'] === 1999 && $w[2]['usage_limit'] === 1 && $w[2]['code'] === $c, json_encode($w));
$GLOBALS['cible'] = 'code'; ErpApi::$vus = [];
[$c, $t] = bdvBon(3, 321, null);
verifie('un bon à code commun : le code tel quel, rien d’écrit', $c === 'BUREAU10' && ErpApi::$vus === [] && $t === '−20 % le premier lunch — une fois');
verifie('pas de bon choisi : rien', bdvBon(3, 0, 1) === [null, null, null]);
$GLOBALS['cible'] = 'clients';
verifie('un code personnel sans client : dit', bdvBon(3, 321, null)[2] === 'pas de client pour un code personnel');
verifie('les promotions à bon seulement', array_column(bdvPromosABon(3), 'id') === [321]);

echo "La route publique\n";
$r = bdvPublic('GET', 'pas-un-jeton');
verifie('un jeton mal formé : introuvable, sans lire la base', ($r['error'] ?? '') === 'questionnaire introuvable' && !array_filter(Db::$exec, static fn ($x) => str_contains($x[0], 'SELECT')));
$r = bdvPublic('GET', str_repeat('a', 32));
verifie('un jeton inconnu : fermé', ($r['error'] ?? '') === 'ce questionnaire n’est plus ouvert');

echo "Le lien court\n";
$M = ['2' => 'Corbais', '3' => 'Gosselies', '5' => 'Sombreffe'];
verifie('?m=2 → Corbais', bdvMagasinDuLien('2', $M) === '2' && bdvMagasinDuLien('5', $M) === '5');
verifie('un numéro qui n’est pas un magasin ouvert : rien', bdvMagasinDuLien('4', $M) === null && bdvMagasinDuLien('0', $M) === null && bdvMagasinDuLien('02', $M) === null);
verifie('pas un numéro : rien', bdvMagasinDuLien('corbais', $M) === null && bdvMagasinDuLien('', $M) === null && bdvMagasinDuLien('2 OR 1', $M) === null);

echo "Les demandes hors zone\n";
[$d, $e] = bdvHorsZoneNettoyer(['cp' => ' 1348 ', 'commune' => 'Louvain-la-Neuve', 'societe' => 'Horizon', 'effectif' => '25', 'email' => 'Ana@Horizon.BE',
    'origine' => 'webshop', 'distance' => '9.46', 'tfbuddy' => 'ok', 'request_key' => 'a1b2c3d4e5f60718', 'langue' => 'nl', 'prenom' => 'Ana']);
verifie('une demande complète passe, mise en forme', $e === [] && $d['cp'] === '1348' && $d['email'] === 'ana@horizon.be' && $d['personnes'] === 25 && $d['distance'] === 9.5
    && $d['origine'] === 'webshop' && $d['_tfbuddy'] === 'ok' && $d['request_key'] === 'a1b2c3d4e5f60718' && $d['langue'] === 'nl' && !array_key_exists('prenom', $d), json_encode($d));
[$d2] = bdvHorsZoneNettoyer(['postalCode' => '1300', 'city' => 'Wavre', 'company' => 'X', 'headcount' => 12, 'email' => 'a@b.be']);
verifie('les noms du webshop sont admis ; origine landing par défaut', $d2['cp'] === '1300' && $d2['ville'] === 'Wavre' && $d2['societe'] === 'X' && $d2['personnes'] === 12 && $d2['origine'] === 'landing');
[$d3, $e3] = bdvHorsZoneNettoyer(['email' => 'pas-un-mail', 'request_key' => 'a_b%c', 'effectif' => '-3', 'tfbuddy' => 'peut-être']);
verifie('vide : le code postal et l’e-mail, dits en clair', $e3 === ['le code postal', 'un e-mail valide']);
verifie('une clé avec joker remplacée, un effectif faux à zéro, un état inconnu vide', preg_match('/^[a-f0-9]{32}$/', $d3['request_key']) === 1 && $d3['personnes'] === 0 && $d3['_tfbuddy'] === '' && $d3['distance'] === null);
$c = bdvHorsZoneCorpsTfb($d);
verifie('le corps de TF Buddy : celui de la landing du webshop', $c === ['request_key' => 'a1b2c3d4e5f60718', 'postalCode' => '1348', 'city' => 'Louvain-la-Neuve', 'company' => 'Horizon', 'headcount' => 25, 'email' => 'ana@horizon.be']);
verifie('sans effectif : headcount nul', bdvHorsZoneCorpsTfb($d2 + ['personnes' => 0])['headcount'] === 12 && bdvHorsZoneCorpsTfb(['personnes' => 0] + $d2)['headcount'] === null);
verifie('TF Buddy : reçue (saved vrai ou absent), sinon refusée avec le code', bdvHorsZoneLireTfb(200, ['saved' => true])[0] === 'ok' && bdvHorsZoneLireTfb(201, null)[0] === 'ok'
    && bdvHorsZoneLireTfb(200, ['saved' => false])[0] === 'refus' && bdvHorsZoneLireTfb(429, ['description' => 'DELIVERY_INTEREST_RATE_LIMIT'])[1] === 'TF Buddy n’a pas pris la demande (HTTP 429 DELIVERY_INTEREST_RATE_LIMIT)'
    && bdvHorsZoneLireTfb(0, null) === ['refus', 'TF Buddy ne répond pas']);
Db::$exec = [];
$GLOBALS['corps'] = ['site_web' => 'http://spam', 'shop' => '2', 'cp' => '1348', 'email' => 'a@b.be'];
verifie('le champ piège : réponse polie, rien d’écrit', bdvPublicHorsZone() === ['ok' => true, 'saved' => true] && !array_filter(Db::$exec, static fn ($x) => str_contains($x[0], 'INSERT')));
$GLOBALS['corps'] = ['shop' => '99', 'cp' => '1348', 'email' => 'a@b.be'];
$r = bdvPublicHorsZone();
verifie('un magasin inconnu : refusé, rien d’écrit', ($r['manque'] ?? null) === ['la boutique'] && !array_filter(Db::$exec, static fn ($x) => str_contains($x[0], 'INSERT')));
$GLOBALS['corps'] = [];

echo "Effacer et reprendre une demande hors zone\n";
$hz = ['id' => 9, 'shop_id' => '2', 'source' => 'hors_zone', 'statut' => 'hors_zone', 'donnees' => '{"cp":"1370"}'];
$sql = static fn (string $mot) => array_values(array_filter(Db::$exec, static fn ($x) => str_contains($x[0], $mot)));
Db::$exec = []; Db::$ligne = null; $GLOBALS['corps'] = ['shop' => '2', 'id' => 9];
$r = wr_bureaux_dev_effacer();
verifie('effacer : inconnue → refusée, rien d’effacé', ($r['error'] ?? '') === 'demande hors zone introuvable' && $sql('DELETE') === []);
Db::$exec = []; Db::$ligne = ['source' => 'questionnaire', 'statut' => 'a_valider'] + $hz;
$r = wr_bureaux_dev_effacer();
verifie('effacer : une inscription du formulaire ne s’efface pas d’ici', ($r['error'] ?? '') === 'demande hors zone introuvable' && $sql('DELETE') === []);
Db::$exec = []; Db::$ligne = $hz;
$r = wr_bureaux_dev_effacer();
$del = $sql('DELETE');
verifie('effacer : la copie hors zone disparaît', $r === ['ok' => true] && count($del) === 1 && $del[0][1] === [9, '2'] && str_contains($del[0][0], 'source = "hors_zone"'), json_encode($del));
Db::$exec = []; Db::$ligne = $hz;
$maj = bdvHorsZoneReprendre('2', 9, 957);
$up = $sql('UPDATE');
verifie('reprise : passe « reprise », avec le bureau créé dans TF Buddy', $maj === true && count($up) === 1 && str_contains($up[0][0], 'statut = "reprise"')
    && str_contains($up[0][0], 'office_id = ?') && str_contains($up[0][0], 'AND statut = "hors_zone"') && $up[0][1] === [957, 9, '2'], json_encode($up, JSON_UNESCAPED_UNICODE));
Db::$exec = []; Db::$ligne = ['statut' => 'reprise'] + $hz;
$a = bdvHorsZoneReprendre('2', 9, 958);
Db::$ligne = null;
$b = bdvHorsZoneReprendre('2', 9, 958);
verifie('reprise : déjà reprise ou absente, rien ne bouge', $a === false && $b === false && $sql('UPDATE') === []);
Db::$exec = []; Db::$ligne = $hz; $GLOBALS['corps'] = ['shop' => '2', 'id' => 9];
$r = wr_bureaux_dev_reprendre();
verifie('reprendre : sans le numéro du bureau créé, refusé', ($r['error'] ?? '') === 'le numéro du bureau créé manque' && $sql('UPDATE') === []);
Db::$exec = []; Db::$ligne = ['statut' => 'reprise'] + $hz; $GLOBALS['corps'] = ['shop' => '2', 'id' => 9, 'office_id' => 957];
$r = wr_bureaux_dev_reprendre();
verifie('reprendre : une demande déjà reprise, refusée', ($r['error'] ?? '') === 'demande hors zone introuvable ou déjà reprise' && $sql('UPDATE') === []);
Db::$exec = []; Db::$ligne = $hz;
$r = wr_bureaux_dev_reprendre();
verifie('reprendre : la demande ouverte passe « reprise » avec son bureau', $r === ['ok' => true, 'officeId' => 957] && count($sql('UPDATE')) === 1);
// Un visiteur renvoie la même demande (même request_key) après que le magasin l'a reprise : la copie reprise garde sa
// trace et son magasin ; le nouvel envoi arrive comme une demande ouverte. La base simulée ne rend la copie reprise
// qu'à une recherche qui ne la filtre pas sur le statut ouvert.
$repris = ['statut' => 'reprise', 'donnees' => '{"cp":"1370","request_key":"cle12345","_reprise":15}'] + $hz;
Db::$exec = []; Db::$ligne = null;
Db::$base = static fn (string $q, array $a) => str_contains($q, 'donnees LIKE') ? (str_contains($q, 'statut = "hors_zone"') ? null : $repris)
    : (str_starts_with($q, 'SELECT *') ? ['id' => 77, 'shop_id' => '3', 'client_id' => null, 'societe_id' => null, 'bon_code' => null, 'office_id' => null, 'erreurs' => null, 'cree_le' => '2026-11-27 20:00:00', 'traite_le' => '2026-11-27 20:00:00'] + $hz : null);
[$dr] = bdvHorsZoneNettoyer(['cp' => '1370', 'email' => 'a@b.be', 'origine' => 'webshop', 'tfbuddy' => 'ok', 'request_key' => 'cle12345']);
bdvHorsZoneEnregistrer('3', $dr, 'ip');
Db::$base = null;
verifie('renvoyée après reprise : la copie reprise ne bouge pas, une demande ouverte arrive', $sql('UPDATE') === [] && count($sql('INSERT')) === 1,
    json_encode(Db::$exec, JSON_UNESCAPED_UNICODE));
Db::$exec = []; Db::$ligne = null; $GLOBALS['corps'] = [];

echo "Le SMS des formulaires\n";
verifie('les données du SMS : la société, le lieu, l’effectif, rien d’autre', bdvSmsDonnees(['bureau' => 'Siège Horizon', 'societe' => 'Horizon SRL', 'cp' => '1348', 'ville' => 'LLN', 'personnes' => 18, 'email' => 'a@b.be', 'tel' => '0470'])
    === ['nom' => 'Siège Horizon', 'cp' => '1348', 'ville' => 'LLN', 'personnes' => 18] && bdvSmsDonnees(['societe' => 'Horizon SRL'])['nom'] === 'Horizon SRL');
Db::$exec = []; $GLOBALS['sms'] = []; Db::$ligne = null;
[$dz] = bdvHorsZoneNettoyer(['cp' => '1370', 'commune' => 'Jodoigne', 'societe' => 'Imprimerie du Val', 'effectif' => 22, 'email' => 'a@b.be', 'origine' => 'webshop', 'tfbuddy' => 'ok', 'request_key' => 'cleSms001']);
Db::$base = static fn (string $q, array $a) => str_starts_with($q, 'SELECT *') ? ['id' => 78, 'shop_id' => '2', 'source' => 'hors_zone', 'statut' => 'hors_zone', 'donnees' => '{}', 'client_id' => null, 'societe_id' => null,
    'bon_code' => null, 'office_id' => null, 'erreurs' => null, 'cree_le' => '2026-11-28 09:00:00', 'traite_le' => '2026-11-28 09:00:00'] : null;
bdvHorsZoneEnregistrer('2', $dz, 'ip');
verifie('une nouvelle demande hors zone : un SMS', $GLOBALS['sms'] === [['2', 'hors_zone', ['nom' => 'Imprimerie du Val', 'cp' => '1370', 'ville' => 'Jodoigne', 'personnes' => 22]]], json_encode($GLOBALS['sms'], JSON_UNESCAPED_UNICODE));
$GLOBALS['sms'] = [];
$prev = Db::$base;
Db::$base = static fn (string $q, array $a) => str_contains($q, 'donnees LIKE') ? ['id' => 78] : $prev($q, $a);
bdvHorsZoneEnregistrer('2', $dz, 'ip');
verifie('la même demande renvoyée (un nouvel essai) : pas de second SMS', $GLOBALS['sms'] === [], json_encode($GLOBALS['sms'], JSON_UNESCAPED_UNICODE));
Db::$base = null; Db::$exec = []; $GLOBALS['sms'] = [];
// Le formulaire en six étapes : une inscription acceptée envoie un SMS ; une réponse refusée (incomplète), aucun.
$jeton = str_repeat('a1', 16);
Db::$base = static fn (string $q, array $a) => str_contains($q, 'FROM panel_bureau_questionnaire WHERE jeton') ? ['shop_id' => '2', 'actif' => 1, 'promo' => null, 'titre' => '', 'message' => '']
    : (str_contains($q, 'COUNT(*)') ? ['n' => 0] : (str_starts_with($q, 'SELECT * FROM panel_bureau_dev') ? ['id' => 79, 'shop_id' => '2', 'source' => 'questionnaire', 'statut' => 'a_valider', 'donnees' => '{}',
        'client_id' => null, 'societe_id' => null, 'bon_code' => null, 'office_id' => null, 'erreurs' => null, 'cree_le' => '2026-11-28 09:00:00', 'traite_le' => null] : null));
$GLOBALS['corps'] = ['prenom' => 'Marie', 'nom' => 'Dupont', 'email' => 'marie@horizon.be', 'tel' => '+32 470 12 34 56', 'societe' => 'Centre Horizon', 'tva' => 'BE0789114256',
    'factRue' => 'Rue A', 'factNum' => '1', 'factCp' => '6041', 'factVille' => 'Gosselies', 'adresse' => 'Rue de la Station 14', 'cp' => '6040', 'ville' => 'Jumet', 'personnes' => '18',
    'jours' => [1, 5], 'de' => '11:30', 'a' => '12:15', 'accord' => true];
$r = bdvPublic('POST', $jeton);
verifie('une inscription par le formulaire : un SMS', ($r['ok'] ?? false) === true && $GLOBALS['sms'] === [['2', 'inscription', ['nom' => 'Centre Horizon', 'cp' => '6040', 'ville' => 'Jumet', 'personnes' => 18]]],
    json_encode([$r, $GLOBALS['sms']], JSON_UNESCAPED_UNICODE));
$GLOBALS['sms'] = []; $GLOBALS['corps'] = ['prenom' => 'Marie', 'accord' => true];
$r = bdvPublic('POST', $jeton);
verifie('une réponse incomplète, refusée : pas de SMS', isset($r['manque']) && $GLOBALS['sms'] === [], json_encode($r, JSON_UNESCAPED_UNICODE));
Db::$base = null; Db::$exec = []; $GLOBALS['sms'] = []; $GLOBALS['corps'] = [];

echo "Les tournées publiées\n";
$t = bdvTourneeLigne(['id' => 1, 'tour' => 'LLN', 'zoneSecondary' => '', 'zonePrincipal' => 'Atelier by Berlo - Corbais'],
    ['tour' => 'LLN', 'delivery_hours_start' => '11:30:00', 'delivery_hours_end' => '12:30:00', 'delivery_cutoff_hour' => 10, 'delivery_cutoff_minute' => 30,
        'delivery_lead_hours' => 0, 'days' => [1, 2, 3, 4, 5, 9], 'slots' => ['11:30–12:30']]);
verifie('la tournée de Corbais telle que le webshop la publie', $t === ['id' => 1, 'nom' => 'LLN', 'zone' => '', 'principale' => 'Atelier by Berlo - Corbais', 'detail' => true,
    'jours' => [1, 2, 3, 4, 5], 'de' => '11:30', 'a' => '12:30', 'limite' => '10:30', 'preavis' => 0, 'creneaux' => ['11:30–12:30']], json_encode($t, JSON_UNESCAPED_UNICODE));
$t = bdvTourneeLigne(['id' => 4, 'tour' => 'Halle'], null);
verifie('sans détail : le nom seulement, rien d’inventé', $t['detail'] === false && $t['jours'] === [] && $t['limite'] === '' && $t['de'] === '' && $t['creneaux'] === []);

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
