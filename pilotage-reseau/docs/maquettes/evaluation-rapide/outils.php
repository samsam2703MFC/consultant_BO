<?php
/*
 * Les morceaux PHP de la maquette, avec le code de la branche (src/visites.php, src/suivi.php, src/rapports.php), sans base :
 *   php outils.php vue                              → ce que suEvalVue donne à l'écran du bureau (JSON), pour les notes d'exemple
 *   php outils.php rapport <apercu.json> <fr|nl> <sortie.html>
 *       → l'aperçu en ligne du rapport (lu tel quel), le bloc de l'évaluation inséré là où suRapportHtml le pose (après
 *         le score, avant les sections), dans l'enveloppe du PDF (rapPdfHtml).
 */
$src = __DIR__ . '/../../../src/';
require $src . 'Db.php';
foreach (['visites.php', 'suivi.php', 'rapports.php'] as $f) { require_once $src . $f; }
$N = json_decode((string) file_get_contents(__DIR__ . '/notes.json'), true);
$notes = $N['notes'];
$prec = ['le' => $N['precedent']['le'], 'notes' => array_map(static fn ($n) => ['note' => $n, 'com' => ''], $N['precedent']['notes'])];
if (($argv[1] ?? '') === 'vue') {
    $axes = [];
    foreach (viEvalAxes() as $a) { $x = $notes[$a['k']]; $axes[] = ['k' => $a['k'], 'nom' => $a['fr'], 'note' => $x['note'], 'com' => $x['com'], 'avant' => $prec['notes'][$a['k']]['note']]; }
    echo json_encode(['axes' => $axes, 'moyenne' => suEvalMoyenne($notes), 'precedent' => ['le' => $prec['le'], 'moyenne' => suEvalMoyenne($prec['notes'])],
        'svg' => suEvalRadarSvg($notes, $prec, 'fr', 520, 17)], JSON_UNESCAPED_UNICODE);
    exit;
}
[, , $json, $l, $sortie] = $argv;
$html = (string) (json_decode((string) file_get_contents($json), true)['html'] ?? '');
$e = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$bloc = suEvalHtml(['notes' => $notes, 'precedent' => $prec], $l, $e);
$pos = strpos($html, '<div style="margin:0 0 12px">');
$html = $pos === false ? $html . $bloc : substr($html, 0, $pos) . $bloc . substr($html, $pos);
file_put_contents($sortie, '<!doctype html><html><head><meta charset="utf-8"></head><body>' . rapPdfHtml($html) . '</body></html>');
