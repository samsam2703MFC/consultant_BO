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
verifie('la liste du magasin, la plus récente d’abord', array_column($l['documents'], 'titre') === ['Réunion du 21/10', 'Contrat de mise à disposition'] && count($l['sortes']) === 3 && in_array('pdf', $l['extensions'], true), json_encode(array_column($l['documents'], 'titre'), JSON_UNESCAPED_UNICODE));
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
