#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de l'e-mail « contrôle qualité non réalisé » de 19:00 (src/cq_mail.php) — base SQLite en mémoire, panel
 * et SMTP en doublure.
 *
 *   php bin/tests_cq_mail.php
 *
 * Seuls les magasins à zéro photo reçoivent l'e-mail, une fois par jour ; panel muet = aucun envoi ; l'aperçu
 * n'envoie rien ; le carnet de l'affiche sert d'adresses de secours ; le lien garde l'adresse publique quand le cron
 * appelle par 127.0.0.1 ; le calendrier du mois compte les tâches notées (relevées « facultatives » avant le
 * 20/10/2026) et écarte les facultatives ; au-delà de huit contrôles, la liste se range par checklist.
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
function journalAdd(...$a) { $GLOBALS['JOURNAL'][] = $a; }
final class Smtp { static $lastError = null; static $env = [];
  static function configured(): bool { return true; }
  static function envoyer(string $a, string $s, string $h, array $p = []): bool { if ($a === 'ko@x.be') { self::$lastError = 'refusé'; return false; } self::$env[] = [$a, $s, $h]; return true; } }
class PanelApi { static function configured() { return true; } }
require __DIR__ . '/../src/ca_mail.php';
function rapAppel(callable|string $ep, array $get = []) { return $ep(); }
function rapLogoDataUri(): string { return 'data:image/png;base64,' . base64_encode((string) file_get_contents(__DIR__ . '/../public/assets/img/logo.png')); }
function rapBaseUrl(): string { return $GLOBALS['BASE']; }
$GLOBALS['BASE'] = 'https://185.180.206.46/consulant_bo';
require __DIR__ . '/../src/cq_mail.php';

$P = Db::pdo();
$P->exec('CREATE TABLE ceo_app_setting (key TEXT PRIMARY KEY, value TEXT)');
$P->exec('CREATE TABLE shops (id INT, name TEXT, active INT)');
$P->exec('CREATE TABLE ceo_rapport (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE, nom TEXT, poste TEXT, frequence TEXT, heure INT, jour INT, blocs TEXT, destinataires TEXT, par_magasin INT, actif INT, envoi_mode TEXT, dest_par_magasin TEXT, jours TEXT)');
$P->exec('CREATE TABLE ceo_rapport_run (id INTEGER PRIMARY KEY AUTOINCREMENT, rapport_id INT, genere_le TEXT, periode_du TEXT, periode_au TEXT, statut TEXT, resume TEXT, html TEXT, contexte TEXT)');
$P->exec('CREATE TABLE con_tache_jour (jour TEXT, id_shop INT, id_task INT, nom TEXT, fait INT, statut TEXT, note INT, obligatoire INT)');
$P->exec('CREATE TABLE con_tache_jour_etat (jour TEXT PRIMARY KEY)');
foreach ([[1, 'Atelier by - Florival'], [2, 'Atelier by - Bierges'], [3, 'Atelier by - Rixensart'], [4, 'Atelier by - Ottignies'], [5, 'Atelier by - Limal']] as $s) { Db::exec('INSERT INTO shops VALUES (?,?,1)', $s); }
// Les relevés du mois pour Florival (1) et Ottignies (4).
$auj = date('Y-m-d'); $d0 = substr($auj, 0, 8);
for ($j = 1; $j < min(20, (int) date('j')); $j++) {
  $jour = $d0 . str_pad((string) $j, 2, '0', STR_PAD_LEFT);
  if ($j === 5) { continue; }                       // jour pas relevé
  Db::exec('INSERT INTO con_tache_jour_etat VALUES (?)', [$jour]);
  if ($j === 12) { continue; }                      // relevé, rien de prévu (fermé)
  $faits = [6 => 0, 13 => 0, 9 => 3, 16 => 4, 19 => 2][$j] ?? 6;
  for ($t = 1; $t <= 6; $t++) {
    $st = $t <= $faits ? ($t % 2 ? 'notee' : 'aControler') : ($t === 6 ? 'sansPhoto' : 'nonRendue');
    // une tâche notée relevée à 0 (l'ancien défaut) : elle doit compter
    Db::exec('INSERT INTO con_tache_jour VALUES (?,?,?,?,?,?,?,?)', [$jour, 1, $t, 'T' . $t, $st === 'nonRendue' ? 0 : 1, $st, null, $st === 'notee' ? 0 : 1]);
  }
  // une tâche facultative faite ne compte pas
  Db::exec('INSERT INTO con_tache_jour VALUES (?,?,?,?,?,?,?,?)', [$jour, 1, 99, 'Fac', 1, 'aControler', null, 0]);
}
// Le jour vivant.
function ep_pwa_tasks(): array {
  if (!empty($GLOBALS['MUET'])) { return ['indispo' => true]; }
  $t = fn ($n, $st, $cl = null, $ob = true) => ['tache' => $n, 'statut' => $st, 'checklist' => $cl, 'obligatoire' => $ob];
  return ['shops' => [
    ['shopId' => '1', 'taches' => [$t('Contrôle Qualité – Vitrine des viennoiseries', 'nonRendue'), $t('Contrôle Qualité – Comptoir des pâtisseries', 'nonRendue'), $t('Photo du comptoir - fermeture', 'sansPhoto'), $t('Présentoir à pains', 'nonRendue'), $t('Étiquettes de prix', 'nonRendue'), $t('Propreté du labo', 'nonRendue'), $t('Facultative faite', 'aControler', null, false)]],
    ['shopId' => '2', 'taches' => [$t('A', 'nonRendue'), $t('B', 'aControler')]],
    ['shopId' => '4', 'taches' => array_merge(array_map(fn ($i) => $t('Ouv ' . $i, 'nonRendue', 'CO-01 — Ouverture.'), range(1, 6)), array_map(fn ($i) => $t('CQ ' . $i, 'nonRendue', 'CQ — Vitrines'), range(1, 4)), [$t('Ferm', 'nonRendue', 'CO-02 — Fermeture')])],
    ['shopId' => '5', 'taches' => [$t('Z', 'nonRendue')]],
  ]];
}
cqMailSemer();
$rep = Db::row('SELECT * FROM ceo_rapport WHERE code = ?', ['cq-non-realise']);
verifie('la ligne du reporting est semée (quotidien, 19 h, par magasin)', $rep !== null && $rep['frequence'] === 'quotidien' && (int) $rep['heure'] === 19 && $rep['envoi_mode'] === 'par-magasin');
verifie('l’adresse publique vue par un navigateur est retenue', setting('cqMailBase') === 'https://185.180.206.46/consulant_bo');
Db::exec('UPDATE ceo_rapport SET dest_par_magasin = ? WHERE id = ?', [json_encode(['Atelier by - Florival' => ['florival@x.be', 'pas-une-adresse'], 'Atelier by - Limal' => ['ko@x.be']]), $rep['id']]);
Db::exec('INSERT INTO ceo_rapport (code, nom, dest_par_magasin) VALUES (?,?,?)', ['affiche-vente-mois', 'Affiche', json_encode(['Atelier by - Ottignies' => ['ottignies@x.be']])]);
$rep = Db::row('SELECT * FROM ceo_rapport WHERE code = ?', ['cq-non-realise']);
$GLOBALS['BASE'] = 'http://127.0.0.1/consulant_bo';

$GLOBALS['MUET'] = true; $b = cqMailDistribuer($rep); $GLOBALS['MUET'] = false;
verifie('panel muet : aucun envoi', Smtp::$env === [] && str_contains($b['resume'], 'aucun envoi'));
$b = cqMailDistribuer($rep, true);
verifie('aperçu : rien ne part, le run garde l’e-mail', Smtp::$env === [] && $b['statut'] === 'genere'
    && str_contains((string) Db::row('SELECT html FROM ceo_rapport_run WHERE id = ?', [$b['runId']])['html'], 'Contrôle qualité non réalisé'));
$b = cqMailDistribuer($rep);
$st = []; foreach ($b['magasins'] as $m) { $st[$m['magasin']] = $m['statut']; }
verifie('un magasin avec une photo ne reçoit rien', ($st['Atelier by - Bierges'] ?? '') === 'fait');
verifie('un magasin sans contrôle prévu ne reçoit rien', ($st['Atelier by - Rixensart'] ?? '') === 'rien-prevu');
verifie('à zéro photo : envoyé aux adresses valides du carnet', ($st['Atelier by - Florival'] ?? '') === 'envoye' && (Smtp::$env[0][0] ?? '') === 'florival@x.be');
verifie('sans carnet : les adresses de l’affiche du mois', ($st['Atelier by - Ottignies'] ?? '') === 'envoye' && (Smtp::$env[1][0] ?? '') === 'ottignies@x.be');
verifie('un refus SMTP se dit échec', ($st['Atelier by - Limal'] ?? '') === 'echec');
verifie('deux e-mails, pas un de plus', count(Smtp::$env) === 2, (string) count(Smtp::$env));
$h1 = Smtp::$env[0][2] ?? ''; $h4 = Smtp::$env[1][2] ?? '';
verifie('le sujet nomme le magasin et le jour', str_starts_with(Smtp::$env[0][1] ?? '', 'Contrôle qualité non réalisé — Florival, '));
verifie('le lien garde l’adresse publique', str_contains($h1, 'href="https://185.180.206.46/consulant_bo/dashboard/?shop=1&amp;vue=jour&amp;date=' . date('Y-m-d') . '"'));
verifie('les facultatives ne comptent pas : 0 photo sur 6', str_contains($h1, '<b style="color:#8A3F14">0 photo</b> sur 6 contrôles attendus'));
verifie('cochée sans photo se dit', str_contains($h1, 'cochée sans photo'));
if ((int) date('j') >= 20) {
    verifie('le mois : les notées comptent, les facultatives non', str_contains($h1, '<b>12 jours réalisés</b>, 3 jours partiels') && str_contains($h1, '3 jours sans aucun contrôle'), strip_tags((string) (preg_match('/<b>\d+ jours? réalisés?.{0,200}/u', $h1, $m) ? $m[0] : '')));
}
verifie('au-delà de huit contrôles : par checklist', str_contains($h4, 'CO-01 — Ouverture') && str_contains($h4, '0 photo sur 6') && !str_contains($h4, 'Ouv 1'));
verifie('« d’ » devant une voyelle', str_contains($h4, 'Magasin d’Ottignies'));
$n = count(Smtp::$env); cqMailDistribuer($rep);
verifie('second passage du jour : personne n’est servi deux fois', count(Smtp::$env) === $n);
echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
