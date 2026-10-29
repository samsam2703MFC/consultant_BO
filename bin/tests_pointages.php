#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de l'e-mail « pointages suspects » de fin de journée (src/pointages_mail.php) — base SQLite en mémoire,
 * panel et SMTP en doublure.
 *
 *   php bin/tests_pointages.php
 *
 * La détection (seule en vente + tickets au compte d'un collègue parti, d'une caisse hors planning ou sans vendeur ;
 * dix minutes de grâce ; trois tickets au moins ; deux personnes en vente = rien), la phrase, et l'envoi : magasins
 * concernés seulement, carnet puis affiche, récapitulatif réseau, une fois par jour, panel muet = rien.
 */
$ok = 0; $ko = 0;
function verifie(string $nom, bool $cond, string $detail = ''): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok  $nom\n"; }
    else { $ko++; echo "  KO  $nom" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
final class Db {
  static ?PDO $p = null;
  static function pdo(): PDO { if (!self::$p) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } return self::$p; }
  static function fx(string $q): string { return str_replace('ON DUPLICATE KEY UPDATE value = VALUES(value)', 'ON CONFLICT(key) DO UPDATE SET value = excluded.value', $q); }
  static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
  static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
  static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function setting(string $k, $d = null) { $r = Db::row('SELECT value FROM ceo_app_setting WHERE key = ?', [$k]); return $r ? json_decode($r['value'], true) : $d; }
function journalAdd(...$a) {}
function analyseListe($x) { return $x; }
final class Smtp { static $lastError = null; static $env = [];
  static function configured(): bool { return true; }
  static function envoyer(string $a, string $s, string $h, array $p = []): bool { self::$env[] = [$a, $s, $h]; return true; } }
final class PanelApi {
  static array $listes = [];
  static function configured() { return true; }
  static function getParallele(array $ch, int $f = 4): array { $o = []; foreach ($ch as $k => $p) { $o[$k] = self::$listes[(string) $k] ?? null; } return $o; }
}
function venteEmployes(): array { return [11 => ['nom' => 'Zoé M.'], 12 => ['nom' => 'Hugo P.'], 13 => ['nom' => 'Inès K.'], 99 => ['nom' => 'Caisse 2'], 21 => ['nom' => 'Lina B.'], 22 => ['nom' => 'Tom R.']]; }
function rapAppel(callable|string $ep, array $get = []) { return $ep(); }
function rapLogoDataUri(): string { return ''; }
function rapBaseUrl(): string { return 'https://185.180.206.46/consulant_bo'; }
require __DIR__ . '/../src/ca_mail.php';
require __DIR__ . '/../src/cq_mail.php';
require __DIR__ . '/../src/pointages_mail.php';

// --- La détection, seule.
$pl = [['nom' => 'Zoé M.', 'debut' => '04:00', 'fin' => '12:00', 'postes' => ['Production Boulangerie', 'Vente'], 'idEmp' => 11],
       ['nom' => 'Hugo P.', 'debut' => '06:00', 'fin' => '13:00', 'postes' => ['Vente', 'Nettoyage'], 'idEmp' => 12],
       ['nom' => 'Inès K.', 'debut' => '13:00', 'fin' => '18:30', 'postes' => ['Vente'], 'idEmp' => 13],
       ['nom' => 'Marc F.', 'debut' => '14:00', 'fin' => '17:00', 'postes' => ['Production Traiteur'], 'idEmp' => 14]];
$tk = fn (string $hm, int $id, float $eur) => [psMin($hm), $id, venteEmployes()[$id]['nom'] ?? '', $eur];
$T1 = [$tk('07:10', 12, 10), $tk('12:50', 12, 9), $tk('13:05', 12, 8), $tk('13:20', 12, 12), $tk('14:10', 12, 15.5), $tk('15:00', 12, 9), $tk('16:30', 12, 20), $tk('18:20', 12, 11),
       $tk('16:10', 99, 9), $tk('16:20', 99, 11), [psMin('07:30'), 0, '', 5.0]];
$C = psDetecter($pl, $T1);
verifie('un cas : Inès seule en vente, au compte de Hugo parti', count($C) === 1 && $C[0]['vendeuse'] === 'Inès K.' && $C[0]['compte'] === 'Hugo P.' && $C[0]['type'] === 'collegue', json_encode($C));
verifie('dix minutes de grâce : le ticket de 13:05 reste à Hugo', ($C[0]['tickets'] ?? 0) === 5 && ($C[0]['premier'] ?? '') === '13:20' && ($C[0]['dernier'] ?? '') === '18:20');
verifie('le montant et « n’a pas pointé »', ($C[0]['ca'] ?? 0) === 67.5 && ($C[0]['propres'] ?? -1) === 0);
verifie('deux tickets d’une caisse hors planning : pas un cas', count(array_filter($C, fn ($x) => $x['type'] === 'hors-planning')) === 0);
verifie('sans vendeur pendant que deux personnes vendent : pas un cas', count(array_filter($C, fn ($x) => $x['type'] === 'sans')) === 0);
$ph = psPhrase($C[0]);
verifie('la phrase', str_contains($ph, '<b>Inès K.</b> (planning 13:00–18:30) était la seule personne en vente, mais n’a pas pointé à la caisse : de 13:20 à 18:20, <b>5 tickets (67,50 €)</b> ont été encaissés au compte de <b>Hugo P.</b>, dont le poste se terminait à 13:00.'), strip_tags($ph));
$C2 = psDetecter($pl, array_merge($T1, [$tk('15:30', 13, 7)]));
verifie('quelques tickets à son nom : « n’a pas toujours pointé »', ($C2[0]['propres'] ?? 0) === 1 && str_contains(psPhrase($C2[0]), 'n’a pas toujours pointé'));
$pl2 = [['nom' => 'Lina B.', 'debut' => '06:00', 'fin' => '12:00', 'postes' => ['Vente'], 'idEmp' => 21], ['nom' => 'Tom R.', 'debut' => '07:00', 'fin' => '14:00', 'postes' => ['Vente'], 'idEmp' => 22]];
$C3 = psDetecter($pl2, [$tk('06:05', 22, 5), $tk('06:20', 22, 6), $tk('06:40', 22, 7), $tk('09:00', 22, 8)]);
verifie('avant l’arrivée du collègue : « qui ne commençait qu’à »', count($C3) === 1 && $C3[0]['vendeuse'] === 'Lina B.' && str_contains(psPhrase($C3[0]), 'qui ne commençait qu’à 07:00'), json_encode($C3));
$C4 = psDetecter([['nom' => 'Inès K.', 'debut' => '13:00', 'fin' => '18:30', 'postes' => []]], [[psMin('14:00'), 0, '', 5.0], [psMin('14:30'), 0, '', 6.0], [psMin('15:00'), 0, '', 7.0], [psMin('15:10'), 99, 'Caisse 2', 4.0]]);
verifie('reconnue par son nom, sans poste au planning : sans vendeur', count($C4) === 1 && $C4[0]['type'] === 'sans' && str_contains(psPhrase($C4[0]), 'sans nom de vendeur'));

// --- L'envoi.
$P = Db::pdo();
$P->exec('CREATE TABLE ceo_app_setting (key TEXT PRIMARY KEY, value TEXT)');
$P->exec('CREATE TABLE ceo_rapport (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, nom TEXT, poste TEXT, frequence TEXT, heure INT, jour INT, blocs TEXT, destinataires TEXT, par_magasin INT, actif INT, envoi_mode TEXT, dest_par_magasin TEXT, jours TEXT)');
$P->exec('CREATE TABLE ceo_rapport_run (id INTEGER PRIMARY KEY AUTOINCREMENT, rapport_id INT, genere_le TEXT, periode_du TEXT, periode_au TEXT, statut TEXT, resume TEXT, html TEXT, contexte TEXT)');
psSemer();
$rep = Db::row('SELECT * FROM ceo_rapport WHERE code = ?', ['pointages-suspects']);
verifie('la ligne du reporting est semée (quotidien, 21 h, par magasin)', $rep !== null && (int) $rep['heure'] === 21 && $rep['envoi_mode'] === 'par-magasin');
Db::exec('UPDATE ceo_rapport SET dest_par_magasin = ?, destinataires = ? WHERE id = ?', [json_encode(['Atelier by - Corbais' => ['corbais@x.be']]), json_encode(['sam@x.be']), $rep['id']]);
Db::exec('INSERT INTO ceo_rapport (code, nom, dest_par_magasin) VALUES (?,?,?)', ['affiche-vente-mois', 'Affiche', json_encode(['Atelier by - Halle' => ['halle@x.be']])]);
$rep = Db::row('SELECT * FROM ceo_rapport WHERE code = ?', ['pointages-suspects']);
$GLOBALS['JOUR'] = null;
function ep_exploitation_jour(): array { return $GLOBALS['JOUR'] ?? ['magasins' => []]; }
$b = psDistribuer($rep);
verifie('sans planning lu : aucun envoi', Smtp::$env === [] && str_contains($b['resume'], 'aucun envoi'));
$GLOBALS['JOUR'] = ['magasins' => [
    ['shopId' => '1', 'magasin' => 'Atelier by - Corbais', 'planning' => $pl],
    ['shopId' => '2', 'magasin' => 'Atelier by - Bierges', 'planning' => $pl2],
    ['shopId' => '3', 'magasin' => 'Atelier by - Halle', 'planning' => [['nom' => 'Inès K.', 'debut' => '13:00', 'fin' => '18:30', 'postes' => []]]],
    ['shopId' => '4', 'magasin' => 'Atelier by - Limal', 'planning' => $pl2],
    ['shopId' => '5', 'magasin' => 'Atelier by - Wavre', 'planning' => $pl]]];
$liste = fn (array $T) => array_map(fn ($t) => ['id' => 1, 'id_employee' => $t[1], 'insert_timestamp' => date('Y-m-d') . ' ' . psHm($t[0]) . ':00', 'total_gross_amount_after_discount' => $t[3]], $T);
PanelApi::$listes = ['1' => $liste($T1), '2' => $liste([$tk('09:00', 21, 5), $tk('10:00', 22, 6)]), '3' => $liste([[psMin('14:00'), 0, '', 5.0], [psMin('14:30'), 0, '', 6.0], [psMin('15:00'), 0, '', 7.0]]),
    '4' => $liste([$tk('06:05', 22, 5), $tk('06:20', 22, 6), $tk('06:40', 22, 7)])];   // Wavre (5) : tickets muets
$b = psDistribuer($rep, true);
verifie('aperçu : rien ne part, le run garde le récapitulatif', Smtp::$env === [] && str_contains((string) Db::row('SELECT html FROM ceo_rapport_run WHERE id = ?', [$b['runId']])['html'], 'Pointages suspects'));
$b = psDistribuer($rep);
$st = []; foreach ($b['magasins'] as $m) { $st[$m['magasin']] = $m['statut']; }
verifie('Corbais prévenu à son adresse', ($st['Atelier by - Corbais'] ?? '') === 'envoye');
verifie('Bierges : rien de suspect, rien d’envoyé', ($st['Atelier by - Bierges'] ?? '') === 'rien');
verifie('Halle : l’adresse de l’affiche', ($st['Atelier by - Halle'] ?? '') === 'envoye' && in_array('halle@x.be', array_column(Smtp::$env, 0), true));
verifie('Limal : un cas, pas d’adresse', ($st['Atelier by - Limal'] ?? '') === 'sans-adresse');
verifie('Wavre : tickets muets, rien', ($st['Atelier by - Wavre'] ?? '') === 'tickets-muets');
$dest = array_column(Smtp::$env, 0);
verifie('trois e-mails : deux magasins et le récapitulatif réseau', count(Smtp::$env) === 3 && in_array('sam@x.be', $dest, true), json_encode($dest));
$mC = Smtp::$env[array_search('corbais@x.be', $dest, true)] ?? ['', '', ''];
verifie('le sujet et le mot', str_starts_with($mC[1], 'Pointages suspects — Corbais, ') && str_contains($mC[2], 'Nous avons détecté des pointages suspects aujourd’hui au magasin de Corbais'));
verifie('le lien vers l’équipe du jour', str_contains($mC[2], 'href="https://185.180.206.46/consulant_bo/dashboard/?shop=1&amp;vue=ops&amp;date=' . date('Y-m-d') . '"'));
$mR = Smtp::$env[array_search('sam@x.be', $dest, true)] ?? ['', '', ''];
verifie('le récapitulatif nomme les trois magasins concernés', str_starts_with($mR[1], 'Pointages suspects — 3 magasins, ') && str_contains($mR[2], 'Limal') && str_contains($mR[2], 'Halle'));
$n = count(Smtp::$env); psDistribuer($rep);
verifie('second passage : personne n’est servi deux fois', count(Smtp::$env) === $n);
echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
