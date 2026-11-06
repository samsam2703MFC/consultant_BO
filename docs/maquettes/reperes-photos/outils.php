<?php
/*
 * Le rapport de la maquette, avec le code de la branche (src/suivi.php), sans base :
 *   php outils.php <apercu.json> <fr|nl> <photo-a-retirer.jpg> <sortie.html>
 * L'aperçu en ligne du rapport (lu tel quel) ; dans sa section Photos, la photo annotée (suPhotoReperesHtml, les repères
 * de reperes.json) passe en tête, et la photo d'essai qu'elle remplace est retirée. Le tout dans l'enveloppe du PDF.
 */
// Sans base ni réglages : le barème des repères est celui livré (comme bin/tests_suivi.php).
if (!function_exists('setting')) { function setting(string $k, mixed $d = null): mixed { return $d; } }
require_once __DIR__ . '/../../../src/installer.php';
$src = __DIR__ . '/../../../src/';
require $src . 'Db.php';
foreach (['visites.php', 'suivi.php', 'rapports.php'] as $f) { require_once $src . $f; }
[, $json, $l, $retirer, $sortie] = $argv;
$R = json_decode((string) file_get_contents(__DIR__ . '/reperes.json'), true);
$rep = array_map(static fn ($r, $i) => $r + ['n' => $i + 1], $R['reperes'], array_keys($R['reperes']));
$html = (string) (json_decode((string) file_get_contents($json), true)['html'] ?? '');
$html = str_replace('<img src="data:image/jpeg;base64,' . base64_encode((string) file_get_contents($retirer)) . '" style="width:31%;margin:0 1.5% 8px 0;border-radius:6px;vertical-align:top" alt="">', '', $html);
$bloc = suPhotoReperesHtml('data:image/jpeg;base64,' . base64_encode((string) file_get_contents(__DIR__ . '/' . $R['photo'])), $rep, $l);
$titre = '>' . htmlspecialchars(suL('photos', $l), ENT_QUOTES, 'UTF-8') . '</div><div>';
$pos = strpos($html, $titre);
$html = $pos === false ? $html . $bloc : substr($html, 0, $pos + strlen($titre)) . $bloc . substr($html, $pos + strlen($titre));
file_put_contents($sortie, '<!doctype html><html><head><meta charset="utf-8"></head><body>' . rapPdfHtml($html) . '</body></html>');
