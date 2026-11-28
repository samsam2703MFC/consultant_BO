#!/usr/bin/env php
<?php
declare(strict_types=1);
ob_start(); // http_response_code() des routes, sans en-têtes déjà partis

/**
 * Tests des SMS des formulaires bureaux (panel/src/sms_formulaires.php) — sans base, sans réseau : le texte du SMS
 * (alphabet des SMS, 160 caractères, rien du prospect que sa société, son lieu, son effectif ; ni e-mail, ni lien, ni
 * numéro tapés dans un champ), les destinataires (le franchisé ; le siège = les destinataires de « SMS au bureau » du
 * cockpit, jamais montrés ni réglés au dashboard), l'envoi par le fournisseur en place et son plafond de 10 par jour.
 */

final class Db
{
    public static array $exec = [];
    /** Une base simulée : row() lui demande la ligne (sql, paramètres). */
    public static $base = null;
    public static function rows(string $sql, array $a = []): array { return []; }
    public static function row(string $sql, array $a = []): ?array { return self::$base ? (self::$base)($sql, $a) : null; }
    public static function exec(string $sql, array $a = []): int { self::$exec[] = [$sql, $a]; return 1; }
}
$GLOBALS['journal'] = [];
function journalAdd(...$a): void { $GLOBALS['journal'][] = $a; }
function body(): array { return $GLOBALS['corps'] ?? []; }
/** La production : accès ouvert, personne n'est identifié (deploy.sh). */
function accesMode(): string { return 'ouvert'; }
function accesIdentite(): ?array { return null; }
function accesNomMagasin($shop): string { return 'Atelier by Berlo - Corbais'; }
/** Le réglage « SMS au bureau » du cockpit (consultant/src/helpdesk.php). */
$GLOBALS['bureau'] = ['actif' => true, 'destinataires' => [['nom' => 'Sam', 'tel' => '+48725038049'], ['nom' => 'Pierre Boseret', 'tel' => '']]];
function hdkSmsReglage(): array { return $GLOBALS['bureau']; }

require __DIR__ . '/../../shared/src/sms.php';
require __DIR__ . '/../src/sms_formulaires.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; } else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$sql = static fn (string $mot) => array_values(array_filter(Db::$exec, static fn ($x) => str_contains($x[0], $mot)));
/** La base simulée : le réglage du magasin, et le compte des envois du jour. */
function base(array $magasin, int $aujourdhui = 0): void
{
    Db::$base = static function (string $q, array $a) use ($magasin, $aujourdhui) {
        if (str_contains($q, 'COUNT(*)')) { return ['n' => $aujourdhui]; }
        if (str_contains($q, 'FROM panel_sms_formulaire WHERE')) { return $magasin; }
        return null;
    };
}
$gsm = static fn (string $t) => preg_match('/^[A-Za-z0-9 @$_!"#%&\'()*+,\-.\/:;<=>?\n]*$/', $t) === 1;

echo "Le texte du SMS\n";
$d = ['nom' => 'Imprimerie du Val', 'cp' => '1370', 'ville' => 'Jodoigne', 'personnes' => 22];
$t = smsfTexte('Atelier by Berlo - Corbais', 'inscription', $d);
verifie('une inscription', $t === 'Atelier by Berlo - Corbais : nouvelle inscription bureau - Imprimerie du Val, 1370 Jodoigne, 22 pers. Dashboard > Clients > Bureaux', $t);
$t = smsfTexte('Atelier by Berlo - Corbais', 'hors_zone', ['nom' => 'Crèche « Les Lutins » – Ça', 'cp' => '5000', 'ville' => 'Namûr', 'personnes' => 0]);
verifie('une demande hors zone, accents et guillemets simplifiés, sans effectif inconnu', $t === 'Atelier by Berlo - Corbais : demande hors zone - Creche "Les Lutins" - Ca, 5000 Namur. Dashboard > Clients > Bureaux', $t);
$t = smsfTexte('Atelier by Berlo - Corbais', 'inscription', ['nom' => str_repeat('Société très longue ', 12), 'cp' => '1370', 'ville' => '', 'personnes' => 5]);
verifie('un nom trop long : 160 caractères au plus, la fin gardée', strlen($t) <= 160 && str_ends_with($t, 'Dashboard > Clients > Bureaux'), strlen($t) . ' ' . $t);
$t = smsfTexte('Atelier by Berlo - Corbais', 'essai', []);
verifie('l’essai', $t === 'Atelier by Berlo - Corbais : SMS d\'essai des formulaires bureaux. Dashboard > Parametres', $t);
$t = smsfTexte('Atelier by Berlo - Corbais', 'hors_zone', ['nom' => 'Dupont - marie.dupont@horizon.be 0470 12 34 56', 'cp' => '1348', 'ville' => 'Voir https://exemple.invalid/x ou www.piege.be', 'personnes' => 3]);
verifie('ni e-mail, ni lien, ni numéro tapés dans un champ', !preg_match('/@|https?:|www\.|horizon\.be|piege|exemple\.invalid|\d{6}|12 34 56/', $t) && str_contains($t, 'Dupont') && str_contains($t, '1348'), $t);
$t = smsfTexte('Atelier by Berlo - Corbais', 'inscription', ['nom' => 'Łódź `Gdańsk` [Kraków] {x} ~|^\\', 'cp' => '', 'ville' => '', 'personnes' => 0]);
verifie('l’alphabet des SMS : lettres polonaises translittérées, caractères hors alphabet ou doubles retirés', $gsm($t) && str_contains($t, 'Lodz Gdansk Krakow x'), $t);

echo "Les destinataires\n";
verifie('le franchisé et le siège, au format international', smsfDestinataires(['actif' => true, 'numero' => '0470 12 34 56'], ['+48725038049']) === ['+32470123456', '+48725038049']);
verifie('un même numéro, une fois', smsfDestinataires(['actif' => true, 'numero' => '+48725038049'], ['+48 725 038 049']) === ['+48725038049']);
verifie('éteint ou invalide : personne', smsfDestinataires(['actif' => false, 'numero' => '0470123456'], ['12', '']) === []);
verifie('le siège : les numéros de « SMS au bureau », s’il est allumé', smsfSiegeNumeros() === ['+48725038049']);
$GLOBALS['bureau']['actif'] = false;
verifie('« SMS au bureau » coupé : le siège ne reçoit rien', smsfSiegeNumeros() === []);
$GLOBALS['bureau']['actif'] = true;

echo "L'envoi\n";
$envois = [];
$faux = static function (string $n, string $t) use (&$envois) { $envois[] = [$n, $t]; return $n === '+48725038049' ? ['ok' => false, 'erreur' => 'Invalid To +48725038049 (code 1234567)'] : ['ok' => true, 'erreur' => null]; };
Db::$exec = []; $GLOBALS['journal'] = []; base(['actif' => 1, 'numero' => '+32470123456']);
$r = smsfEnvoyerMaintenant('2', 'inscription', $d, $faux);
$ins = $sql('INSERT INTO panel_sms_formulaire_envoi');
verifie('un SMS par destinataire, l’échec compté', $r === ['envoyes' => 1, 'echecs' => 1, 'plafond' => false] && count($envois) === 2 && $envois[0][1] === smsfTexte('Atelier by Berlo - Corbais', 'inscription', $d), json_encode($r));
$trace = json_encode([$ins, $GLOBALS['journal']], JSON_UNESCAPED_UNICODE);
verifie('la trace et le journal, sans aucun numéro (même dans l’erreur du fournisseur)', count($ins) === 1 && $ins[0][1][0] === '2' && $ins[0][1][2] === 1 && $ins[0][1][3] === 1
    && count($GLOBALS['journal']) === 1 && !preg_match('/\d{6}/', $trace), $trace);
Db::$exec = []; $envois = []; base(['actif' => 1, 'numero' => '+32470123456'], 10);
$r = smsfEnvoyerMaintenant('2', 'hors_zone', $d, $faux);
verifie('le plafond de 10 par jour : rien ne part, rien n’est noté', $r === ['envoyes' => 0, 'echecs' => 0, 'plafond' => true] && $envois === [] && $sql('INSERT') === []);
Db::$exec = []; $envois = []; base(['actif' => 0, 'numero' => '+32470123456']); $GLOBALS['bureau']['actif'] = false;
$r = smsfEnvoyerMaintenant('2', 'hors_zone', $d, $faux);
verifie('personne d’allumé : rien ne part, rien n’est compté', $r === ['envoyes' => 0, 'echecs' => 0, 'plafond' => false] && $envois === [] && $sql('INSERT') === []);
$GLOBALS['bureau']['actif'] = true;
Db::$exec = []; $GLOBALS['journal'] = []; base(['actif' => 1, 'numero' => '+32470123456']);
$r = smsfEnvoyerMaintenant('2', 'inscription', $d);
verifie('sans fournisseur SMS en place : rien ne part, rien n’est noté ni compté', $r === ['envoyes' => 0, 'echecs' => 0, 'plafond' => false] && $sql('INSERT') === [] && $GLOBALS['journal'] === [], json_encode($r));

echo "Les réglages (accès ouvert, personne d'identifié)\n";
Db::$exec = []; base(['actif' => 1, 'numero' => '+32470123456'], 3); $_GET['shop'] = '2';
$r = smsfRoute('GET', '/dashboard/sms-formulaires');
verifie('le dashboard lit le réglage du magasin ; du siège, seulement qu’il reçoit aussi', ($r['magasin'] ?? null) === ['actif' => true, 'numero' => '+32470123456'] && ($r['siege'] ?? null) === ['actif' => true]
    && ($r['aujourdhui'] ?? null) === 3 && ($r['max'] ?? null) === 10 && ($r['fournisseur'] ?? null) === false, json_encode($r));
verifie('aucun numéro du siège ne sort du dashboard', !str_contains(json_encode($r), '725038049'), json_encode($r));
$GLOBALS['corps'] = ['shop' => '2', 'actif' => true, 'numero' => '0470 12'];
$r = smsfRoute('POST', '/dashboard/sms-formulaires');
verifie('allumé avec un numéro invalide : refusé, rien d’écrit', str_contains((string) ($r['error'] ?? ''), 'GSM du franchisé') && $sql('INSERT INTO panel_sms_formulaire ') === [], json_encode($r, JSON_UNESCAPED_UNICODE));
Db::$exec = [];
$GLOBALS['corps'] = ['shop' => '2', 'actif' => true, 'numero' => '0470 12 34 56', 'siege' => ['actif' => true, 'numero' => '0499112233']];
$r = smsfRoute('POST', '/dashboard/sms-formulaires');
verifie('un numéro du siège envoyé au dashboard : refusé, rien d’écrit', str_contains((string) ($r['error'] ?? ''), 'SMS au bureau') && Db::$exec === [], json_encode($r, JSON_UNESCAPED_UNICODE));
Db::$exec = [];
$GLOBALS['corps'] = ['shop' => '2', 'actif' => true, 'numero' => '0470 12 34 56'];
smsfRoute('POST', '/dashboard/sms-formulaires');
$w = $sql('INSERT INTO panel_sms_formulaire ');
verifie('le franchisé enregistre son GSM, au format international', count($w) === 1 && $w[0][1] === ['2', 1, '+32470123456'], json_encode($w));
Db::$exec = [];
$GLOBALS['corps'] = ['shop' => '2'];
$r = smsfRoute('POST', '/dashboard/sms-formulaires/essai');
verifie('l’essai sans fournisseur SMS en place : refusé, rien d’envoyé', str_contains((string) ($r['error'] ?? ''), 'fournisseur') && Db::$exec === [], json_encode($r, JSON_UNESCAPED_UNICODE));
$GLOBALS['corps'] = [];

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
