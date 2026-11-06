<?php
/**
 * Maquettes (16/10/2026) : cinq façons de montrer les targets, une par ligne, en couleur d'office, avec une jauge
 * et une barre verticale à l'objectif : la passer active la prime. L'objectif est toujours au même endroit (75 % de
 * la jauge), les barres verticales s'alignent d'une ligne à l'autre.
 *
 *   php docs/maquettes/primes-epure/propositions-targets.php   → propositions-targets.html (servir depuis la racine)
 */

// Les cinq targets d'une vendeuse au 16 du mois (données de démonstration).
$T = [
    ['emo' => '🛍️', 'lib' => 'Ventes croisées', 'val' => 56, 'obj' => 55, 'bas' => 0, 'u' => ' %', 'dec' => 0, 'm' => 50, 'reste' => null, 'teinte' => 1],
    ['emo' => '🚀', 'lib' => 'Bats ton record', 'val' => 2.74, 'obj' => 3.0, 'bas' => 0, 'u' => '', 'dec' => 2, 'm' => 100, 'reste' => 'encore 0,26', 'teinte' => 2],
    ['emo' => '🏪', 'lib' => 'Objectif magasin', 'val' => 55, 'obj' => 100, 'bas' => 0, 'u' => ' %', 'dec' => 0, 'm' => 50, 'reste' => 'projeté 108 %', 'jour' => 52, 'jlib' => 'J16', 'teinte' => 3],
    ['emo' => '⭐', 'lib' => 'Note Google', 'val' => 4.6, 'obj' => 4.5, 'bas' => 3.5, 'u' => ' ★', 'dec' => 1, 'm' => 25, 'reste' => null, 'teinte' => 5],
    ['emo' => '🏆', 'lib' => 'Meilleure vendeuse', 'val' => 59, 'obj' => 62, 'bas' => 0, 'u' => ' pts', 'dec' => 0, 'm' => 75, 'reste' => 'encore 3 pts', 'teinte' => 6],
];
const OBJ = 75.0; // l'objectif, toujours à 75 % de la jauge
$pos = static fn (float $v, array $t) => round(max(0, min(100, OBJ * ($v - $t['bas']) / ($t['obj'] - $t['bas']))), 1);
$n = static fn (float $v, int $d) => number_format($v, $d, ',', ' ');
foreach ($T as $i => $t) {
    $T[$i]['p'] = $pos($t['val'], $t);
    $T[$i]['ok'] = $t['val'] >= $t['obj'];
    $T[$i]['v'] = $n($t['val'], $t['dec']) . $t['u'];
    $T[$i]['vh'] = $n($t['val'], $t['dec']) . ($t['u'] !== '' ? '<small>' . trim($t['u']) . '</small>' : '');
    $T[$i]['o'] = $n($t['obj'], $t['dec']) . $t['u'];
    $T[$i]['pj'] = isset($t['jour']) ? $pos($t['jour'], $t) : null;
}
$e = static fn (string $s) => htmlspecialchars($s, ENT_QUOTES);

// A — La ligne d'arrivée : la valeur à gauche, une barre fine, un trait vertical à l'objectif.
$A = '';
foreach ($T as $t) {
    $A .= '<div class="carte ta ' . ($t['ok'] ? 'ok' : '') . '"><div class="ta-h"><span class="emo">' . $t['emo'] . '</span><b>' . $e($t['lib']) . '</b>'
        . '<span class="ta-m">' . ($t['ok'] ? '✓ ' : '') . $t['m'] . '&nbsp;€</span></div>'
        . '<div class="ta-b"><span class="ta-v">' . $t['vh'] . '</span><div class="jg"><i style="width:' . $t['p'] . '%"></i>'
        . ($t['pj'] !== null ? '<span class="jr" style="left:' . $t['pj'] . '%"></span>' : '')
        . '<span class="ob" style="left:' . OBJ . '%"><small>' . $e($t['o']) . '</small></span></div></div>'
        . '<div class="ta-s">' . ($t['ok'] ? 'Prime activée' : $e($t['reste'] ?? '')) . (isset($t['jlib']) ? ' · <span class="jr-l"></span>' . $t['jlib'] . ' : ' . $t['jour'] . '&nbsp;% attendu' : '') . '</div></div>';
}

// B — Le drapeau : la récompense est posée sur la ligne d'objectif ; la franchir la débloque.
$B = '';
foreach ($T as $t) {
    $B .= '<div class="carte tf ' . ($t['ok'] ? 'ok' : '') . '"><div class="tf-h"><span class="emo">' . $t['emo'] . '</span><b>' . $e($t['lib']) . '</b><span class="tf-v">' . $t['vh'] . '</span></div>'
        . '<div class="jg"><i style="width:' . $t['p'] . '%"></i>'
        . ($t['pj'] !== null ? '<span class="jr" style="left:' . $t['pj'] . '%"></span>' : '')
        . '<span class="ob" style="left:' . OBJ . '%"></span><span class="fl" style="left:' . OBJ . '%">' . ($t['ok'] ? '✓ ' : '🔒 ') . $t['m'] . '&nbsp;€</span></div>'
        . '<div class="tf-s"><span>' . ($t['ok'] ? 'Prime activée' : $e($t['reste'] ?? '')) . '</span><span>objectif ' . $e($t['o']) . '</span></div></div>';
}

// C — Une couleur par prime : la barre épaisse, la valeur dedans, le trait d'objectif qui la traverse.
$C = '';
foreach ($T as $t) {
    $C .= '<div class="carte tc t' . $t['teinte'] . ' ' . ($t['ok'] ? 'ok' : '') . '"><div class="tc-h"><span class="emo">' . $t['emo'] . '</span><b>' . $e($t['lib']) . '</b>'
        . '<span class="tc-m">' . ($t['ok'] ? '<i class="bi bi-check-circle-fill"></i> ' : '') . $t['m'] . '&nbsp;€</span></div>'
        . '<div class="jg"><i style="width:' . $t['p'] . '%"><span>' . $e($t['v']) . '</span></i>'
        . ($t['pj'] !== null ? '<span class="jr" style="left:' . $t['pj'] . '%"></span>' : '')
        . '<span class="ob" style="left:' . OBJ . '%"></span></div>'
        . '<div class="tc-s"><span>' . ($t['ok'] ? 'Prime activée' : $e($t['reste'] ?? '')) . '</span><span style="left:' . OBJ . '%">' . $e($t['o']) . '</span></div></div>';
}

// D — La liste compacte : une ligne de 56 px par prime, la mini-jauge à droite.
$D = '';
foreach ($T as $t) {
    $D .= '<div class="td ' . ($t['ok'] ? 'ok' : '') . '"><span class="emo">' . $t['emo'] . '</span><div class="td-t"><b>' . $e($t['lib']) . '</b><small>' . $e($t['v']) . ' / ' . $e($t['o']) . '</small></div>'
        . '<div class="jg"><i style="width:' . $t['p'] . '%"></i>' . ($t['pj'] !== null ? '<span class="jr" style="left:' . $t['pj'] . '%"></span>' : '') . '<span class="ob" style="left:' . OBJ . '%"></span></div>'
        . '<span class="td-m">' . ($t['ok'] ? '✓ ' : '') . $t['m'] . '&nbsp;€</span></div>';
}

// E — La zone prime : au-delà du trait, la jauge entre dans une zone teintée qui porte la récompense.
$E = '';
foreach ($T as $t) {
    $E .= '<div class="carte te ' . ($t['ok'] ? 'ok' : '') . '"><div class="te-h"><span class="emo">' . $t['emo'] . '</span><b>' . $e($t['lib']) . '</b><span class="te-v">' . $t['vh'] . '</span></div>'
        . '<div class="jg"><span class="zone" style="left:' . OBJ . '%">+' . $t['m'] . '&nbsp;€</span><i style="width:' . $t['p'] . '%"></i>'
        . ($t['pj'] !== null ? '<span class="jr" style="left:' . $t['pj'] . '%"></span>' : '')
        . '<span class="ob" style="left:' . OBJ . '%"></span><span class="pt" style="left:' . $t['p'] . '%"></span></div>'
        . '<div class="te-s"><span>' . ($t['ok'] ? '✓ Prime activée' : $e($t['reste'] ?? '')) . '</span><span>objectif ' . $e($t['o']) . '</span></div></div>';
}

$props = [
    ['a', 'A', 'La ligne d’arrivée', 'Une barre fine, la valeur à gauche, un trait à l’objectif. La récompense en haut à droite s’allume en vert quand le trait est passé.', $A],
    ['b', 'B', 'Le drapeau', 'La récompense est posée sur le trait d’objectif, cadenassée ; la jauge qui la dépasse la débloque.', $B],
    ['c', 'C', 'Une couleur par prime', 'Chaque prime a sa couleur, la barre est épaisse avec la valeur dedans, le trait d’objectif la traverse.', $C],
    ['d', 'D', 'La liste compacte', 'Une ligne de 56 px par prime : le nom, la mini-jauge, la récompense. Le plus court.', $D],
    ['e', 'E', 'La zone prime', 'Après le trait, la jauge entre dans une zone verte qui porte la récompense ; y entrer active la prime.', $E],
];
ob_start(); ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Targets : propositions</title>
<!-- Généré par propositions-targets.php. Les vrais styles de l'app ; ce qui est propre aux propositions est ici, avec les variables du design system. -->
<link rel="stylesheet" href="/public/employee/assets/atelier/app.css">
<link rel="stylesheet" href="/public/employee/assets/bootstrap-icons/bootstrap-icons.css">
<link rel="stylesheet" href="/public/employee/assets/atelier/marque.css">
<link rel="stylesheet" href="/public/employee/assets/atelier/primes.css">
<style>
  body{margin:0}
  .page{max-width:600px;margin:0 auto;padding:6px 14px 30px}
  .prop-t{display:flex;align-items:baseline;gap:10px;margin:22px 4px 2px}
  .prop-t b{font:400 26px/1 var(--font-display);color:var(--la-rubis)}
  .prop-t h2{font:400 22px/1.1 var(--font-display);margin:0;color:var(--la-encre)}
  .prop-d{font:500 12.5px/1.45 var(--font-ui);color:var(--la-muet);margin:4px 4px 0}
  .prop .titre-section{margin-top:12px}
  .ta-v small,.tf-v small,.te-v small{font:600 12px var(--font-ui);color:var(--la-muet);margin-left:3px}
  .liste{display:flex;flex-direction:column;gap:8px}
  .emo{width:30px;height:30px;border-radius:var(--radius-md);background:var(--la-beige-doux);display:inline-flex;align-items:center;justify-content:center;font-size:16px;line-height:1;flex:0 0 auto}
  .carte{background:var(--la-blanc);border:.5px solid var(--la-trait);border-radius:var(--radius-xl);box-shadow:var(--la-ombre)}
  /* La jauge commune : la piste, le remplissage, le trait d'objectif, le repère du jour J en pointillé. */
  .jg{position:relative;height:10px;border-radius:var(--radius-pill);background:var(--la-beige)}
  .jg > i{position:absolute;left:0;top:0;bottom:0;border-radius:var(--radius-pill);background:var(--la-rubis)}
  .ok .jg > i{background:var(--la-ok)}
  .jg .ob{position:absolute;top:-6px;bottom:-6px;width:3px;margin-left:-1.5px;border-radius:2px;background:var(--la-encre)}
  .jg .jr{position:absolute;top:-4px;bottom:-4px;border-left:2px dashed var(--la-muet)}
  .jr-l{display:inline-block;width:10px;border-top:2px dashed var(--la-muet);vertical-align:middle;margin-right:3px}

  /* A */
  .ta{padding:11px 14px 10px}
  .ta-h{display:flex;align-items:center;gap:9px}
  .ta-h b{flex:1;font:600 14px var(--font-ui);color:var(--la-encre)}
  .ta-m{font:400 19px/1 var(--font-display);color:var(--la-rubis);white-space:nowrap}
  .ta.ok .ta-m{color:var(--la-ok)}
  .ta-b{display:flex;align-items:center;gap:10px;margin-top:12px}
  .ta-v{flex:0 0 64px;font:400 22px/1 var(--font-display);color:var(--la-encre);white-space:nowrap}
  .ta-b .jg{flex:1}
  .ta-b .ob small{position:absolute;bottom:100%;left:50%;transform:translateX(-50%);font:700 9.5px var(--font-ui);color:var(--la-encre);white-space:nowrap;margin-bottom:1px}
  .ta-s{font:500 11px var(--font-ui);color:var(--la-muet);margin:7px 0 0 74px}
  .ta.ok .ta-s{color:var(--la-ok);font-weight:700}

  /* B */
  .tf{padding:11px 14px 10px}
  .tf-h{display:flex;align-items:center;gap:9px}
  .tf-h b{flex:1;font:600 14px var(--font-ui)}
  .tf-v{font:400 21px/1 var(--font-display);color:var(--la-rubis)}
  .tf.ok .tf-v{color:var(--la-ok)}
  .tf .jg{margin:30px 0 0}
  .tf .fl{position:absolute;bottom:calc(100% + 7px);transform:translateX(-50%);font:700 11px var(--font-ui);padding:3px 8px;border-radius:var(--radius-pill);background:var(--la-encre);color:var(--la-blanc);white-space:nowrap}
  .tf.ok .fl{background:var(--la-ok)}
  .tf-s{display:flex;justify-content:space-between;font:500 11px var(--font-ui);color:var(--la-muet);margin-top:8px}
  .tf.ok .tf-s span:first-child{color:var(--la-ok);font-weight:700}

  /* C */
  .tc{padding:11px 14px 10px;--c:var(--la-teinte-1);background:color-mix(in srgb,var(--c) 9%,var(--la-blanc));border-color:color-mix(in srgb,var(--c) 30%,var(--la-blanc))}
  .tc.t2{--c:var(--la-teinte-2)}.tc.t3{--c:var(--la-teinte-3)}.tc.t5{--c:var(--la-teinte-5)}.tc.t6{--c:var(--la-teinte-6)}
  .tc .emo{background:color-mix(in srgb,var(--c) 22%,var(--la-blanc))}
  .tc-h{display:flex;align-items:center;gap:9px}
  .tc-h b{flex:1;font:600 14px var(--font-ui)}
  .tc-m{font:400 19px/1 var(--font-display);color:var(--c);white-space:nowrap}
  .tc.ok .tc-m{color:var(--la-ok)}
  .tc .jg{height:24px;margin-top:11px;background:color-mix(in srgb,var(--c) 16%,var(--la-blanc))}
  .tc .jg > i{background:var(--c);display:flex;align-items:center;justify-content:flex-end;min-width:58px}
  .tc .jg > i span{font:700 12px var(--font-ui);color:var(--la-blanc);padding-right:9px;white-space:nowrap}
  .tc .jg .ob{top:-5px;bottom:-5px}
  .tc-s{position:relative;display:flex;justify-content:space-between;font:500 11px var(--font-ui);color:var(--la-muet);margin-top:6px;height:14px}
  .tc-s span:last-child{position:absolute;transform:translateX(-50%);font-weight:700;color:var(--la-encre)}
  .tc.ok .tc-s span:first-child{color:var(--la-ok);font-weight:700}

  /* D */
  .td-liste{padding:4px 12px}
  .td{display:grid;grid-template-columns:30px minmax(0,1fr) 34% auto;align-items:center;gap:10px;height:56px;border-bottom:.5px solid var(--la-trait)}
  .td:last-child{border-bottom:0}
  .td-t b{display:block;font:600 13px/1.2 var(--font-ui);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .td-t small{display:block;font:500 11px var(--font-ui);color:var(--la-muet);margin-top:2px}
  .td .jg{height:8px}
  .td .jg .ob{top:-5px;bottom:-5px}
  .td-m{font:700 12px var(--font-ui);padding:4px 8px;border-radius:var(--radius-pill);background:var(--la-beige-doux);color:var(--la-encre);white-space:nowrap}
  .td.ok .td-m{background:var(--la-ok-doux);color:var(--la-ok)}

  /* E */
  .te{padding:11px 14px 10px}
  .te-h{display:flex;align-items:center;gap:9px}
  .te-h b{flex:1;font:600 14px var(--font-ui)}
  .te-v{font:400 21px/1 var(--font-display);color:var(--la-rubis)}
  .te.ok .te-v{color:var(--la-ok)}
  .te .jg{height:22px;margin-top:11px;overflow:visible}
  .te .zone{position:absolute;top:0;bottom:0;right:0;border-radius:0 var(--radius-pill) var(--radius-pill) 0;background:var(--la-ok-doux);display:flex;align-items:center;justify-content:flex-end;padding-right:12px;font:700 11px var(--font-ui);color:var(--la-ok)}
  .te .jg > i{background:var(--la-rubis);opacity:.9}
  .te.ok .jg > i{background:var(--la-ok);opacity:.35}
  .te.ok .zone{background:var(--la-ok);color:var(--la-blanc)}
  .te .pt{position:absolute;top:50%;width:14px;height:14px;margin:-7px 0 0 -7px;border-radius:50%;background:var(--la-blanc);border:3px solid var(--la-rubis)}
  .te.ok .pt{border-color:var(--la-ok)}
  .te-s{display:flex;justify-content:space-between;font:500 11px var(--font-ui);color:var(--la-muet);margin-top:7px}
  .te.ok .te-s span:first-child{color:var(--la-ok);font-weight:700}
</style>
</head>
<body>
<div class="page">
<?php foreach ($props as [$id, $L, $titre, $desc, $html]): ?>
<section class="prop" id="prop-<?= $id ?>">
  <div class="prop-t"><b><?= $L ?></b><h2><?= $e($titre) ?></h2></div>
  <p class="prop-d"><?= $e($desc) ?></p>
  <div class="titre-section"><h2>Targets</h2><small>jusqu’à 300 €</small></div>
  <?php if ($id === 'd'): ?><div class="carte td-liste"><?= $html ?></div>
  <?php else: ?><div class="liste"><?= $html ?></div><?php endif; ?>
</section>
<?php endforeach; ?>
</div>
</body>
</html>
<?php
file_put_contents(__DIR__ . '/propositions-targets.html', ob_get_clean());
echo "propositions-targets.html\n";
