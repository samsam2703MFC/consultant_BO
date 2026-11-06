#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests des documents d'un franchisé (src/documents.php, onglet Documents de la fiche) — base SQLite en mémoire.
 *
 *   php bin/tests_documents.php
 *
 * Le rangement (nom tiré au hasard, accès direct fermé), la liste du magasin (par date du document), le retrait (la
 * ligne et le fichier), la limite d'un envoi. Le dépôt HTTP lui-même (multipart) passe par docGarder.
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
  static function fx(string $q): string {
    $q = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $q);
    $q = (string) preg_replace('/,KEY \w+ \([^)]*\)/', '', $q); return (string) preg_replace('/\) ENGINE=.*$/', ')', $q);
  }
  static function exec(string $q, array $a = []): int { $s = self::pdo()->prepare(self::fx($q)); $s->execute($a); return $s->rowCount(); }
  static function rows(string $q, array $a = []): array { $s = self::pdo()->prepare($q); $s->execute($a); return $s->fetchAll(PDO::FETCH_ASSOC); }
  static function row(string $q, array $a = []): ?array { $r = self::rows($q, $a); return $r[0] ?? null; }
}
function journalAdd(...$a) {}
function setting(string $k, $d = null) { return $d; }
function body(): array { return $GLOBALS['corps'] ?? []; }
final class Smtp { static $lastError = null; static $env = [];
  static function configured(): bool { return true; }
  static function envoyer(string $a, string $s, string $h, array $p = []): bool { self::$env[] = [$a, $s, $h, $p]; return true; } }
require __DIR__ . '/../src/ca_mail.php';
require __DIR__ . '/../src/cq_mail.php';
require __DIR__ . '/../src/documents.php';

$shop = 990001;   // un magasin qui n'existe pas : le dossier du banc s'efface à la fin
$tmp = sys_get_temp_dir() . '/doc-banc-' . getmypid();
@mkdir($tmp);
file_put_contents($tmp . '/contrat.pdf', '%PDF-1.4 contrat');
file_put_contents($tmp . '/reunion.docx', 'PK docx');
docTable();
$a = docGarder($shop, 'contrat', 'Contrat de mise à disposition', '2026-09-01', 'signé', 'contrat.pdf', $tmp . '/contrat.pdf', 16, false);
$b = docGarder($shop, 'reunion', 'Réunion du 21/10', '2026-10-21', null, 'reunion.docx', $tmp . '/reunion.docx', 7, false);
$r = Db::row('SELECT fichier FROM ceo_doc WHERE id = ?', [$a['id'] ?? 0]);
verifie('rangé sous un nom tiré au hasard, l’extension gardée', $r !== null && preg_match('/^[0-9a-f]{24}\.pdf$/', (string) $r['fichier']) === 1 && is_file(docDossier($shop) . '/' . $r['fichier']));
verifie('le dossier des documents est fermé à l’accès direct', str_contains((string) @file_get_contents(dirname(docDossier($shop)) . '/.htaccess'), 'Require all denied'));
verifie('la sorte et le type du fichier', ($a['sorteLib'] ?? '') === 'Contrat de mise à disposition' && ($a['mime'] ?? '') === 'application/pdf' && ($b['mime'] ?? '') === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
$_GET = ['shop' => (string) $shop]; $l = ep_franchises_documents();
verifie('la liste du magasin, la plus récente d’abord', array_column($l['documents'], 'titre') === ['Réunion du 21/10', 'Contrat de mise à disposition'] && count($l['sortes']) === 4 && in_array('pdf', $l['extensions'], true), json_encode(array_column($l['documents'], 'titre'), JSON_UNESCAPED_UNICODE));
$_GET = ['shop' => '990002']; $l2 = ep_franchises_documents();
verifie('un autre magasin ne les voit pas', $l2['documents'] === []);
verifie('la limite d’un envoi : la nôtre ou celle de PHP, la plus petite', docMaxOctets() > 0 && docMaxOctets() <= DOC_MAX_OCTETS);
$chemin = docDossier($shop) . '/' . $r['fichier'];
$_GET = ['id' => (string) $a['id']]; $d = wr_franchises_document_suppr();
verifie('retiré : la ligne et le fichier', ($d['ok'] ?? false) === true && !is_file($chemin) && Db::row('SELECT id FROM ceo_doc WHERE id = ?', [$a['id']]) === null);
$_GET = ['id' => '999999']; $d = @wr_franchises_document_suppr();
verifie('un document inconnu : 404', ($d['error'] ?? '') === 'document introuvable');
$_GET = ['shop' => '0']; $l3 = @ep_franchises_documents();
verifie('sans magasin : refusé', ($l3['error'] ?? '') === 'shop requis');
// Le mail « matériel mis à disposition » (22/10/2026).
Db::pdo()->exec('CREATE TABLE shops (id INT, name TEXT, active INT)');
Db::pdo()->exec('CREATE TABLE ceo_rapport (id INTEGER PRIMARY KEY, dest_par_magasin TEXT)');
Db::exec('INSERT INTO shops VALUES (?, ?, 1)', [$shop, 'Atelier by - Ottignies']);
Db::exec('INSERT INTO ceo_rapport (dest_par_magasin) VALUES (?)', [json_encode(['Atelier by - Ottignies' => ['Franchise@exemple.be', 'pas-une-adresse']])]);
file_put_contents($tmp . '/contrat2.pdf', '%PDF-1.4 contrat signé');
$k = docGarder($shop, 'contrat', 'Contrat matériel', '2026-09-01', null, 'contrat-ottignies.pdf', $tmp . '/contrat2.pdf', 22, false);
$_GET = ['shop' => (string) $shop, 'materiel' => 'Four ventilé', 'contrat' => (string) $k['id']]; $ap = ep_franchises_mail_materiel();
verifie('l’aperçu : le magasin, le texte validé, le contrat joint, les adresses des carnets', str_contains($ap['html'], 'Magasin d’Ottignies') && str_contains($ap['html'], 'mis à disposition à l’état neuf')
    && str_contains($ap['html'], 'Toutes les pièces qui cassent') && str_contains($ap['html'], 'Four ventilé') && str_contains($ap['html'], 'joint à ce message') && str_contains($ap['html'], 'contrat-ottignies.pdf')
    && $ap['adresses'] === ['franchise@exemple.be'] && count($ap['contrats']) === 1 && $ap['sujet'] === 'Matériel mis à disposition — magasin d’Ottignies', json_encode([$ap['adresses'], $ap['sujet']], JSON_UNESCAPED_UNICODE));
$_GET = ['shop' => (string) $shop]; $ap2 = ep_franchises_mail_materiel();
verifie('sans matériel ni contrat : ni liste, ni pièce annoncée', !str_contains($ap2['html'], 'Matériel concerné') && !str_contains($ap2['html'], 'joint à ce message'));
$GLOBALS['corps'] = ['shop' => $shop, 'a' => 'pas une adresse']; $r = @wr_franchises_mail_materiel();
verifie('une adresse invalide : refusée', ($r['error'] ?? '') === 'adresse du franchisé invalide' && Smtp::$env === []);
$GLOBALS['corps'] = ['shop' => $shop, 'a' => 'Franchise@exemple.be', 'materiel' => 'Four ventilé', 'contrat' => $k['id']]; $r = wr_franchises_mail_materiel();
$m = Smtp::$env[0] ?? null;
verifie('envoyé au franchisé, le contrat en pièce jointe', ($r['ok'] ?? false) && $m[0] === 'franchise@exemple.be' && ($m[3][0]['nom'] ?? '') === 'contrat-ottignies.pdf' && ($m[3][0]['contenu'] ?? '') === '%PDF-1.4 contrat signé' && ($m[3][0]['type'] ?? '') === 'application/pdf', json_encode($r, JSON_UNESCAPED_UNICODE));
$_GET = ['shop' => (string) $shop]; $l = ep_franchises_documents();
$cop = array_values(array_filter($l['documents'], static fn ($x) => $x['sorte'] === 'courrier'));
verifie('la copie rangée dans « Courrier envoyé » (HTML sans moteur PDF), avec le destinataire', count($cop) === 1 && $cop[0]['mime'] === 'text/html' && str_contains((string) $cop[0]['note'], 'franchise@exemple.be') && str_contains((string) $cop[0]['note'], 'contrat-ottignies.pdf'), json_encode($cop, JSON_UNESCAPED_UNICODE));
$GLOBALS['corps'] = ['shop' => $shop, 'a' => 'franchise@exemple.be', 'contrat' => 999999]; $n = count(Smtp::$env); $r = wr_franchises_mail_materiel();
verifie('un contrat d’un autre magasin ou inconnu : le mail part sans pièce jointe', ($r['ok'] ?? false) && (Smtp::$env[$n][3] ?? null) === []);
// Le ménage du banc.
foreach (glob(docDossier($shop) . '/*') ?: [] as $f) { @unlink($f); }
@rmdir(docDossier($shop));
foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($tmp);
$racine = dirname(docDossier($shop));
@rmdir(docDossier($shop));
if (glob($racine . '/*') === []) { @unlink($racine . '/.htaccess'); @rmdir($racine); }
echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
