#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Tests de la médiathèque (formation/src/mediatheque.php, 26/11/2026) — sans MySQL : une base SQLite en mémoire tient lieu
 * de Db (le SQL du module y est traduit), les fichiers vont dans un dossier temporaire.
 *
 *   php formation/bin/tests_mediatheque.php
 *
 * Ce qui doit rester vrai : seuls les liens YouTube, Vimeo, Google Drive et les fichiers vidéo https sont acceptés ; un
 * envoi en morceaux se reconstitue à l'octet près, refuse ce qui n'est pas une vidéo et un morceau hors de sa place ; une
 * vidéo s'ajoute, se modifie, se retire (fichier et affiche effacés) ; /liste ne rend que les vidéos publiées ; les
 * écritures sont au siège.
 */

$racineTmp = sys_get_temp_dir() . '/tests-mediatheque-' . bin2hex(random_bytes(4));
@mkdir($racineTmp . '/public', 0775, true);

// --- doublures ----------------------------------------------------------------------------------------------------
final class Db
{
    public static ?PDO $p = null;
    public static function pdo(): PDO
    {
        if (self::$p === null) { self::$p = new PDO('sqlite::memory:'); self::$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); self::$p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); }
        return self::$p;
    }
    private static function sql(string $q): string
    {
        $q = str_replace(['INT AUTO_INCREMENT PRIMARY KEY', 'NOW()'], ['INTEGER PRIMARY KEY AUTOINCREMENT', "datetime('now')"], $q);
        $q = preg_replace('/\) ENGINE=.*$/', ')', $q);
        return (string) preg_replace('/,KEY \w+ \([^)]*\)/', '', $q);
    }
    public static function exec(string $q, array $p = []): int { $s = self::pdo()->prepare(self::sql($q)); $s->execute($p); return $s->rowCount(); }
    public static function rows(string $q, array $p = []): array { $s = self::pdo()->prepare(self::sql($q)); $s->execute($p); return $s->fetchAll(); }
    public static function row(string $q, array $p = []): ?array { $r = self::rows($q, $p); return $r[0] ?? null; }
}
$GLOBALS['CORPS'] = [];
$GLOBALS['ROLE'] = 'siege';
$GLOBALS['JOURNAL'] = [];
function body(): array { return $GLOBALS['CORPS']; }
function foRefus(int $code, string $msg): array { return ['ok' => false, 'code' => $code, 'error' => $msg]; }
function depotPublic(): string { return $GLOBALS['racineTmp']; }
function accesMode(): string { return 'console'; }
function accesIdentite(): ?array { return $GLOBALS['ROLE'] ? ['role' => $GLOBALS['ROLE'], 'nom' => 'Test'] : null; }
function journalAdd(string $a, string $k, ?string $p, string $m): void { $GLOBALS['JOURNAL'][] = $m; }
$GLOBALS['racineTmp'] = $racineTmp . '/public';

require __DIR__ . '/../src/mediatheque.php';

$ok = 0; $ko = 0;
$v = function (string $n, bool $c, string $d = '') use (&$ok, &$ko): void {
    if ($c) { $ok++; echo "  ok  $n\n"; } else { $ko++; echo "  KO  $n" . ($d !== '' ? " — $d" : '') . "\n"; }
};
$appel = function (string $m, string $chemin, array $corps = []): ?array { $GLOBALS['CORPS'] = $corps; return mtRoute($m, $chemin); };

// --- les liens ----------------------------------------------------------------------------------------------------
echo "Les liens\n";
$v('YouTube (watch)', mtLien('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10') === ['type' => 'youtube', 'id' => 'dQw4w9WgXcQ']);
$v('YouTube (youtu.be, shorts)', mtLien('https://youtu.be/dQw4w9WgXcQ')['id'] === 'dQw4w9WgXcQ' && mtLien('https://youtube.com/shorts/dQw4w9WgXcQ')['type'] === 'youtube');
$v('Vimeo (avec et sans clé de partage)', mtLien('https://vimeo.com/123456789') === ['type' => 'vimeo', 'id' => '123456789'] && mtLien('https://vimeo.com/123456789/abcdef1234')['id'] === '123456789/abcdef1234');
$v('Google Drive', mtLien('https://drive.google.com/file/d/1AbCdEfGhIjKlMnOp/view?usp=sharing') === ['type' => 'drive', 'id' => '1AbCdEfGhIjKlMnOp']);
$v('un fichier .mp4 en https', mtLien('https://exemple.be/v/croissant.mp4')['type'] === 'fichier');
$v('refusés : http, une page quelconque, du script', mtLien('http://youtu.be/dQw4w9WgXcQ') === null && mtLien('https://exemple.be/page') === null
    && mtLien('https://www.youtube.com/watch?v=<script>') === null && mtLien('javascript:alert(1)') === null);
$v('signatures : MP4, MOV, WebM ; un JPEG refusé', mtSignature("\0\0\0\x18ftypisom\0\0\0\0") === 'mp4' && mtSignature("\0\0\0\x14ftypqt  \0\0\0\0") === 'mov'
    && mtSignature("\x1A\x45\xDF\xA3\0\0\0\0\0\0\0\0") === 'webm' && mtSignature("\xFF\xD8\xFF\xE0\0\0\0\0\0\0\0\0") === null);
$v('durées lisibles', mtDuree(45) === '45 s' && mtDuree(125) === '2 min 05' && mtDuree(3720) === '1 h 02');

// --- l'envoi en morceaux ------------------------------------------------------------------------------------------
echo "L'envoi\n";
$video = "\0\0\0\x18ftypisom" . str_repeat('x', 3000) . random_bytes(2000);
$d = $appel('POST', '/formation/mediatheque/envoi', ['nom' => 'croissant.MOV', 'taille' => strlen($video)]);
$v('un envoi s’ouvre', ($d['ok'] ?? false) && preg_match('/^[0-9a-f]{32}$/', (string) ($d['jeton'] ?? '')) === 1);
$j = (string) $d['jeton'];
$morceau = function (int $depuis, string $octets) use ($j): array {
    $f = tempnam(sys_get_temp_dir(), 'mt'); file_put_contents($f, $octets); $GLOBALS['MT_ENTREE'] = $f; $_GET['depuis'] = (string) $depuis;
    $r = mtRoute('PUT', '/formation/mediatheque/envoi/' . $j); unlink($f); return $r;
};
$r1 = $morceau(0, substr($video, 0, 2000));
$r2 = $morceau(0, substr($video, 0, 2000));   // renvoyé après une coupure
$v('un morceau à sa place s’écrit ; renvoyé, il ne s’écrit pas deux fois', $r1['recu'] === 2000 && $r2['recu'] === 2000 && !empty($r2['decale']));
$r3 = $morceau(2000, substr($video, 2000));
$v('le dernier morceau complète le fichier', $r3['recu'] === strlen($video));
$fin = $appel('POST', "/formation/mediatheque/envoi/$j/fin");
$v('le fichier est rangé, à l’octet près', ($fin['ok'] ?? false) && preg_match('/^\d{8}-[0-9a-f]{12}\.mp4$/', (string) $fin['fichier']) === 1
    && file_get_contents(mtDossier() . '/' . $fin['fichier']) === $video);
$d2 = $appel('POST', '/formation/mediatheque/envoi', ['nom' => 'photo.mp4', 'taille' => 20]);
$GLOBALS['MT_ENTREE'] = 'data://text/plain;base64,' . base64_encode("\xFF\xD8\xFF\xE0" . str_repeat('a', 16)); $_GET['depuis'] = '0';
mtRoute('PUT', '/formation/mediatheque/envoi/' . $d2['jeton']);
$v('un fichier qui n’est pas une vidéo est refusé', ($appel('POST', '/formation/mediatheque/envoi/' . $d2['jeton'] . '/fin')['code'] ?? 0) === 422);
$v('une extension qui n’est pas vidéo est refusée d’entrée', ($appel('POST', '/formation/mediatheque/envoi', ['nom' => 'x.exe', 'taille' => 10])['code'] ?? 0) === 422);
$v('un jeton inconnu : 404', ($appel('POST', '/formation/mediatheque/envoi/' . str_repeat('a', 32) . '/fin')['code'] ?? 0) === 404);

// --- les vidéos ---------------------------------------------------------------------------------------------------
echo "Les vidéos\n";
$png = 'data:image/png;base64,' . base64_encode((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
$a = $appel('POST', '/formation/mediatheque/videos', ['collection' => 'production', 'categorie' => 'Viennoiseries', 'titre' => 'Façonner un croissant',
    'resume' => 'Le geste.', 'source' => 'fichier', 'fichier' => $fin['fichier'], 'affiche' => $png, 'dureeS' => 125.4]);
$va = $a['video'] ?? [];
$v('une vidéo filmée s’ajoute (fichier, affiche, durée)', ($a['ok'] ?? false) && $va['fichier'] === MT_ADRESSE . $fin['fichier'] && str_starts_with((string) $va['affiche'], MT_ADRESSE)
    && $va['duree'] === '2 min 05' && $va['taille'] === strlen($video) && is_file(mtDossier() . '/' . basename((string) $va['affiche'])));
$b = $appel('POST', '/formation/mediatheque/videos', ['collection' => 'hygiene', 'titre' => 'Nettoyer le four', 'source' => 'lien', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'publie' => false]);
$v('un lien s’ajoute, reconnu', ($b['ok'] ?? false) && $b['video']['lien'] === ['type' => 'youtube', 'id' => 'dQw4w9WgXcQ'] && $b['video']['publie'] === false);
$v('refusés : collection inconnue, titre vide, lien inconnu, fichier jamais envoyé',
    ($appel('POST', '/formation/mediatheque/videos', ['collection' => 'dashboard', 'titre' => 'x', 'source' => 'lien', 'url' => 'https://youtu.be/dQw4w9WgXcQ'])['code'] ?? 0) === 422
    && ($appel('POST', '/formation/mediatheque/videos', ['collection' => 'vente', 'titre' => ' ', 'source' => 'lien', 'url' => 'https://youtu.be/dQw4w9WgXcQ'])['code'] ?? 0) === 422
    && ($appel('POST', '/formation/mediatheque/videos', ['collection' => 'vente', 'titre' => 'x', 'source' => 'lien', 'url' => 'https://exemple.be'])['code'] ?? 0) === 422
    && ($appel('POST', '/formation/mediatheque/videos', ['collection' => 'vente', 'titre' => 'x', 'source' => 'fichier', 'fichier' => '20261126-aaaaaaaaaaaa.mp4'])['code'] ?? 0) === 422);
$liste = $appel('GET', '/formation/mediatheque/liste');
$tout = $appel('GET', '/formation/mediatheque');
$v('/liste ne rend que les publiées ; le siège voit tout', count($liste['videos']) === 1 && $liste['videos'][0]['titre'] === 'Façonner un croissant' && count($tout['videos']) === 2
    && count($liste['collections']) === 4);
$m = $appel('PUT', '/formation/mediatheque/videos/' . $va['id'], ['titre' => 'Façonner et rouler un croissant', 'affiche' => null]);
$v('une modification garde ce qu’on ne dit pas et retire l’ancienne affiche', ($m['ok'] ?? false) && $m['video']['titre'] === 'Façonner et rouler un croissant'
    && $m['video']['categorie'] === 'Viennoiseries' && $m['video']['fichier'] === $va['fichier'] && $m['video']['affiche'] === null && !is_file(mtDossier() . '/' . basename((string) $va['affiche'])));
$GLOBALS['ROLE'] = 'franchise';
$v('un magasin lit /liste, n’écrit rien', ($appel('GET', '/formation/mediatheque/liste')['ok'] ?? false)
    && ($appel('DELETE', '/formation/mediatheque/videos/' . $va['id'])['code'] ?? 0) === 403 && ($appel('GET', '/formation/mediatheque')['code'] ?? 0) === 403);
$GLOBALS['ROLE'] = 'siege';
$s = $appel('DELETE', '/formation/mediatheque/videos/' . $va['id']);
$v('supprimée : la ligne et le fichier', ($s['ok'] ?? false) && !is_file(mtDossier() . '/' . $fin['fichier']) && count($appel('GET', '/formation/mediatheque')['videos']) === 1);
$v('chaque écriture va au journal', count($GLOBALS['JOURNAL']) === 4);

// --- l'accès et le branchement --------------------------------------------------------------------------------------
echo "Le branchement\n";
$racine = dirname(__DIR__, 2);
$v('l’API charge le module et route /formation/mediatheque', str_contains((string) file_get_contents("$racine/shared/public/api/index.php"), "formation/src/mediatheque.php")
    && str_contains((string) file_get_contents("$racine/shared/public/api/index.php"), 'mtRoute($method, $path)'));
$v('les magasins lisent /liste (ACCES_FRANCHISE), rien d’autre', str_contains((string) file_get_contents("$racine/shared/src/acces.php"), "'/formation/mediatheque/liste'")
    && !str_contains((string) file_get_contents("$racine/shared/src/acces.php"), "'/formation/mediatheque'"));
$portail = (string) file_get_contents("$racine/formation/public/formation/portail.js");
$v('le rail du portail a sa section Médiathèque (gérer, et chaque collection)', str_contains($portail, "['Médiathèque', [['mediatheque', 'Gérer les vidéos'")
    && count(array_filter(array_keys(MT_COLLECTIONS), fn ($k) => str_contains($portail, "catalogue/#/videos/c/$k"))) === count(MT_COLLECTIONS) && str_contains($portail, 'catalogue/#/videos/c/dashboard'));
$html = (string) file_get_contents("$racine/formation/public/formation/index.html");
$v('le portail charge l’écran et sa feuille', str_contains($html, '<script src="mediatheque.js"></script>') && str_contains($html, 'href="mediatheque.css"'));
$cat = (string) file_get_contents("$racine/formation/public/formation/catalogue/catalogue.js");
$v('le catalogue a les mêmes collections que le serveur', count(array_filter(array_keys(MT_COLLECTIONS), fn ($k) => str_contains($cat, "['$k', { fr: '" . MT_COLLECTIONS[$k]['fr'] . "'"))) === count(MT_COLLECTIONS)
    && str_contains($cat, "'/formation/mediatheque/liste'"));
$css = (string) file_get_contents("$racine/formation/public/formation/mediatheque.css");
$v('mediatheque.css : aucune pilule, ni 500 ni 600', !preg_match('/border-radius:\s*(999|9999)px/', $css) && !preg_match('/font-weight:\s*[56]00/', $css));

// ménage
$suppr = function (string $d) use (&$suppr): void { foreach (glob("$d/{,.}*", GLOB_BRACE) ?: [] as $f) { if (in_array(basename($f), ['.', '..'], true)) { continue; } is_dir($f) ? $suppr($f) : unlink($f); } @rmdir($d); };
$suppr($racineTmp);
echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
