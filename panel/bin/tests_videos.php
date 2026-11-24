<?php
declare(strict_types=1);

/**
 * Les vidéos d'instruction du dashboard magasin (23/11/2026) : le registre panel/public/dashboard/videos/videos.json,
 * lu par la bulle (tutos.js).
 *   php panel/bin/tests_videos.php
 * Chaque vidéo : un identifiant unique, un genre connu, des pages (`vues`) que le dashboard sait ouvrir, un MP4 et
 * une affiche présents, une date. La page charge la bulle ; sa feuille suit le design system (aucune pilule, deux
 * graisses).
 */

$dash = dirname(__DIR__) . '/public/dashboard';
$ok = 0; $ko = 0;
$v = function (string $n, bool $c, string $d = '') use (&$ok, &$ko): void {
    if ($c) { $ok++; echo "  ok  $n\n"; } else { $ko++; echo "  KO  $n" . ($d !== '' ? " — $d" : '') . "\n"; }
};

$j = json_decode((string) @file_get_contents("$dash/videos/videos.json"), true);
$v('le registre se lit', is_array($j) && isset($j['videos']) && is_array($j['videos']) && count($j['videos']) > 0);
$videos = is_array($j) ? ($j['videos'] ?? []) : [];

// Les pages que le dashboard sait ouvrir : la liste de ?vue= dans dashboard.js (S.vue), plus « * » (partout).
$js = (string) file_get_contents("$dash/dashboard.js");
$pages = [];
if (preg_match("/\\[('ops', 'taches'[^\\]]*)\\]\\.includes\\(q\\.get\\('vue'\\)\\)/", $js, $m)) {
    preg_match_all("/'([a-z]+)'/", $m[1], $mm);
    $pages = $mm[1];
}
$v('la liste des pages du dashboard est trouvée', in_array('equipe', $pages, true) && in_array('ops', $pages, true), implode(',', $pages));
$pages[] = '*';

$ids = [];
foreach ($videos as $i => $x) {
    $id = (string) ($x['id'] ?? '');
    $v("vidéo $i : un identifiant", $id !== '' && preg_match('/^[a-z0-9-]+$/', $id) === 1, $id);
    $v("$id : identifiant unique", !in_array($id, $ids, true));
    $ids[] = $id;
    $v("$id : genre connu", in_array($x['genre'] ?? '', ['nouveau', 'tuto', 'formation'], true), (string) ($x['genre'] ?? ''));
    $v("$id : un titre", trim((string) ($x['titre'] ?? '')) !== '');
    $vues = $x['vues'] ?? null;
    $v("$id : des pages connues", is_array($vues) && $vues !== [] && array_diff($vues, $pages) === [], json_encode($vues));
    $f = (string) ($x['fichier'] ?? '');
    $v("$id : le MP4 est là", preg_match('/^[a-z0-9-]+\.mp4$/', $f) === 1 && is_file("$dash/videos/$f") && filesize("$dash/videos/$f") > 10000, $f);
    $a = (string) ($x['affiche'] ?? '');
    $v("$id : l'affiche est là", $a === '' || (preg_match('/^[a-z0-9-]+\.jpg$/', $a) === 1 && is_file("$dash/videos/$a")), $a);
    $src = $x['sources'] ?? null;
    $racine = dirname(__DIR__, 2);
    $v("$id : des sources qui existent", is_array($src) && $src !== [] && array_filter($src, static fn ($f) => !is_file("$racine/$f")) === [], json_encode($src));
    $v("$id : le commit du tournage", preg_match('/^[0-9a-f]{7,40}$/', (string) ($x['tourne_sur'] ?? '')) === 1, (string) ($x['tourne_sur'] ?? ''));
    $v("$id : le jour du tournage", preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($x['tourne_le'] ?? '')) === 1);
    $d = (string) ($x['depuis'] ?? '');
    $v("$id : une date", preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)), $d);
}

// Pas de vidéo orpheline dans le dossier.
$fichiers = array_merge(...array_map(static fn ($x) => array_filter([$x['fichier'] ?? '', $x['affiche'] ?? '']), $videos));
$orphelins = array_values(array_diff(array_map('basename', glob("$dash/videos/*.{mp4,jpg}", GLOB_BRACE) ?: []), $fichiers));
$v('aucun fichier orphelin dans videos/', $orphelins === [], implode(', ', $orphelins));

$html = (string) file_get_contents("$dash/index.html");
$v('la page charge la bulle', str_contains($html, '<script src="tutos.js"></script>') && str_contains($html, 'href="tutos.css"'));
$v('dashboard.js dit la page ouverte (dashboard:vue)', str_contains($js, "new CustomEvent('dashboard:vue'"));
$v('le rail pose la place du carré, à côté d’Assistant IA ou de la Boîte mail', substr_count($js, 'railTuto()') >= 2 && str_contains($js, 'db-tu-slot') && str_contains($js, 'db-rail-ia db-rail-tu'));
$tu = (string) file_get_contents("$dash/tutos.js");
$v('tutos.js rend le carré et son annonce (plus de bulle en bas à droite)', str_contains($tu, 'carre()') && str_contains($tu, 'tu-annonce') && !str_contains($tu, 'tu-bulle'));
$cat = (string) @file_get_contents(dirname(__DIR__, 2) . '/formation/public/formation/catalogue/catalogue.js');
$v('la vidéothèque du catalogue de formation lit le même registre (#/videos)', str_contains($cat, "r.ecran === 'videos'") && str_contains($cat, 'dashboard/videos/') && str_contains($cat, 'carteVideotheque()'));
$v('le lecteur du dashboard mène à la vidéothèque', str_contains($tu, '../formation/catalogue/#/videos'));
$css = (string) file_get_contents("$dash/tutos.css");
$v('tutos.css : aucune pilule', !preg_match('/border-radius:\s*(999|9999)px/', $css));
$v('tutos.css : ni 500 ni 600', !preg_match('/font-weight:\s*[56]00|font:\s*[56]00/', $css));

echo "\n$ok ok, $ko KO\n";
exit($ko > 0 ? 1 : 0);
