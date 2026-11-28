#!/usr/bin/env php
<?php
declare(strict_types=1);
ob_start(); // http_response_code() des routes, sans en-têtes déjà partis

/**
 * Tests des SMS des formulaires bureaux (panel/src/sms_formulaires.php) — sans base, sans réseau : le texte du SMS
 * (ASCII, 160 caractères, rien du prospect que son nom), les destinataires (éteints, invalides, en double), l'envoi et son
 * plafond de 10 par jour et par magasin, les réglages (numéro valide, numéro du siège réservé au siège).
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
function accesIdentite(): ?array { return $GLOBALS['identite'] ?? null; }
function accesMode(): string { return 'session'; }
function accesNomMagasin($shop): string { return 'Atelier by Berlo - Corbais'; }

require __DIR__ . '/../../shared/src/sms.php';
require __DIR__ . '/../src/sms_formulaires.php';

$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; } else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
$sql = static fn (string $mot) => array_values(array_filter(Db::$exec, static fn ($x) => str_contains($x[0], $mot)));
/** La base simulée : les réglages du magasin 2 et du réseau, et le compte des envois du jour. */
function base(array $magasin, array $siege, int $aujourdhui = 0): void
{
    Db::$base = static function (string $q, array $a) use ($magasin, $siege, $aujourdhui) {
        if (str_contains($q, 'COUNT(*)')) { return ['n' => $aujourdhui]; }
        if (str_contains($q, 'FROM panel_sms_formulaire WHERE')) { return ($a[0] ?? '') === 'reseau' ? $siege : $magasin; }
        return null;
    };
}

echo "Le texte du SMS\n";
$d = ['nom' => 'Imprimerie du Val', 'cp' => '1370', 'ville' => 'Jodoigne', 'personnes' => 22];
$t = smsfTexte('Atelier by Berlo - Corbais', 'inscription', $d);
verifie('une inscription', $t === 'Atelier by Berlo - Corbais : nouvelle inscription bureau - Imprimerie du Val, 1370 Jodoigne, 22 pers. Dashboard > Clients > Bureaux', $t);
$t = smsfTexte('Atelier by Berlo - Corbais', 'hors_zone', ['nom' => 'Crèche « Les Lutins » – Ça', 'cp' => '5000', 'ville' => 'Namûr', 'personnes' => 0]);
verifie('une demande hors zone, en ASCII, sans effectif inconnu', $t === 'Atelier by Berlo - Corbais : demande hors zone - Creche "Les Lutins" - Ca, 5000 Namur. Dashboard > Clients > Bureaux', $t);
$t = smsfTexte('Atelier by Berlo - Corbais', 'inscription', ['nom' => str_repeat('Société très longue ', 12), 'cp' => '1370', 'ville' => '', 'personnes' => 5]);
verifie('un nom trop long : 160 caractères au plus, la fin gardée', strlen($t) <= 160 && str_ends_with($t, 'Dashboard > Clients > Bureaux') && preg_match('/^[\x20-\x7E]+$/', $t) === 1, strlen($t) . ' ' . $t);
$t = smsfTexte('Atelier by Berlo - Corbais', 'essai', []);
verifie('l’essai', $t === 'Atelier by Berlo - Corbais : SMS d\'essai des formulaires bureaux. Dashboard > Parametres', $t);

echo "Les destinataires\n";
verifie('le franchisé et le siège, au format international', smsfDestinataires(['actif' => true, 'numero' => '0470 12 34 56'], ['actif' => true, 'numero' => '+32 499 11 22 33']) === ['+32470123456', '+32499112233']);
verifie('un même numéro, une fois', smsfDestinataires(['actif' => true, 'numero' => '0470123456'], ['actif' => true, 'numero' => '+32470123456']) === ['+32470123456']);
verifie('éteint ou invalide : personne', smsfDestinataires(['actif' => false, 'numero' => '0470123456'], ['actif' => true, 'numero' => '12']) === []);

echo "L'envoi\n";
$envois = [];
$faux = static function (string $n, string $t) use (&$envois) { $envois[] = [$n, $t]; return $n === '+32499112233' ? ['ok' => false, 'erreur' => 'refusé'] : ['ok' => true, 'erreur' => null]; };
Db::$exec = []; $GLOBALS['journal'] = []; base(['actif' => 1, 'numero' => '+32470123456'], ['actif' => 1, 'numero' => '+32499112233']);
$r = smsfEnvoyerMaintenant('2', 'inscription', $d, $faux);
$ins = $sql('INSERT INTO panel_sms_formulaire_envoi');
verifie('un SMS par destinataire, l’échec compté', $r === ['envoyes' => 1, 'echecs' => 1, 'plafond' => false] && count($envois) === 2 && $envois[0][1] === smsfTexte('Atelier by Berlo - Corbais', 'inscription', $d), json_encode($r));
verifie('la trace de l’envoi (sans numéro), le journal (sans numéro)', count($ins) === 1 && $ins[0][1] === ['2', 'inscription', 1, 1, 'refusé']
    && count($GLOBALS['journal']) === 1 && !str_contains(json_encode($GLOBALS['journal'], JSON_UNESCAPED_UNICODE), '470'), json_encode([$ins, $GLOBALS['journal']], JSON_UNESCAPED_UNICODE));
Db::$exec = []; $envois = []; base(['actif' => 1, 'numero' => '+32470123456'], ['actif' => 0, 'numero' => ''], 10);
$r = smsfEnvoyerMaintenant('2', 'hors_zone', $d, $faux);
verifie('le plafond de 10 par jour : rien ne part, rien n’est noté', $r === ['envoyes' => 0, 'echecs' => 0, 'plafond' => true] && $envois === [] && $sql('INSERT') === []);
Db::$exec = []; $envois = []; base(['actif' => 0, 'numero' => '+32470123456'], ['actif' => 0, 'numero' => '']);
$r = smsfEnvoyerMaintenant('2', 'hors_zone', $d, $faux);
verifie('personne d’allumé : rien ne part, rien n’est compté', $r === ['envoyes' => 0, 'echecs' => 0, 'plafond' => false] && $envois === [] && $sql('INSERT') === []);

echo "Les réglages\n";
Db::$exec = []; base(['actif' => 1, 'numero' => '+32470123456'], ['actif' => 1, 'numero' => '+32499112233'], 3);
$GLOBALS['identite'] = ['role' => 'franchise']; $_GET['shop'] = '2';
$r = smsfRoute('GET', '/dashboard/sms-formulaires');
verifie('le franchisé lit son réglage, pas le numéro du siège', ($r['magasin'] ?? null) === ['actif' => true, 'numero' => '+32470123456'] && ($r['siege'] ?? null) === ['actif' => true, 'numero' => null]
    && ($r['siegeModifiable'] ?? null) === false && ($r['aujourdhui'] ?? null) === 3 && ($r['max'] ?? null) === 10, json_encode($r));
$GLOBALS['identite'] = ['role' => 'siege'];
$r = smsfRoute('GET', '/dashboard/sms-formulaires');
verifie('le siège lit aussi son numéro', ($r['siege'] ?? null) === ['actif' => true, 'numero' => '+32499112233'] && ($r['siegeModifiable'] ?? null) === true, json_encode($r));
$GLOBALS['identite'] = ['role' => 'franchise'];
$GLOBALS['corps'] = ['shop' => '2', 'actif' => true, 'numero' => '0470 12'];
$r = smsfRoute('POST', '/dashboard/sms-formulaires');
verifie('allumé avec un numéro invalide : refusé, rien d’écrit', str_contains((string) ($r['error'] ?? ''), 'GSM du franchisé') && $sql('INSERT INTO panel_sms_formulaire ') === [], json_encode($r, JSON_UNESCAPED_UNICODE));
Db::$exec = [];
$GLOBALS['corps'] = ['shop' => '2', 'actif' => true, 'numero' => '0470 12 34 56', 'siege' => ['actif' => true, 'numero' => '0499112233']];
$r = smsfRoute('POST', '/dashboard/sms-formulaires');
verifie('le numéro du siège, réservé au siège : refusé, rien d’écrit', ($r['error'] ?? '') === 'Le numéro du siège se règle depuis un compte du siège.' && $sql('INSERT INTO panel_sms_formulaire ') === [], json_encode($r, JSON_UNESCAPED_UNICODE));
Db::$exec = [];
$GLOBALS['corps'] = ['shop' => '2', 'actif' => true, 'numero' => '0470 12 34 56'];
smsfRoute('POST', '/dashboard/sms-formulaires');
$w = $sql('INSERT INTO panel_sms_formulaire ');
verifie('le franchisé enregistre son GSM, au format international', count($w) === 1 && $w[0][1] === ['2', 1, '+32470123456'], json_encode($w));
Db::$exec = []; $GLOBALS['identite'] = ['role' => 'siege'];
$GLOBALS['corps'] = ['shop' => '2', 'actif' => false, 'numero' => '', 'siege' => ['actif' => true, 'numero' => '0499 11 22 33']];
smsfRoute('POST', '/dashboard/sms-formulaires');
$w = $sql('INSERT INTO panel_sms_formulaire ');
verifie('le siège enregistre le magasin et son numéro', count($w) === 2 && $w[0][1] === ['2', 0, ''] && $w[1][1] === ['reseau', 1, '+32499112233'], json_encode($w));
$GLOBALS['corps'] = [];

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
