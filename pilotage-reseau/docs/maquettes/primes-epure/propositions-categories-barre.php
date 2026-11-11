<?php
/**
 * Maquettes (16/10/2026) : les catégories avec la même jauge que les targets (le « drapeau » : une barre, le trait
 * noir de l'objectif toujours à 75 %, la récompense posée dessus), sa place dans le magasin et les pièces qui lui
 * manquent pour être 1re. Quatre façons ; l'objectif est la 1re + 1 pièce, ou, quand elle mène, la 2e (à garder).
 *
 *   php docs/maquettes/primes-epure/propositions-categories-barre.php → propositions-categories-barre.html
 */

// Trois catégories d'une vendeuse au 16 du mois (données de démonstration) : 2e, 1re, 5e.
$C = [
    ['lib' => 'Queen of Tartes', 'ic' => 'demo/uploads/primes/7a1e0c3b9f2d4e5a6b70.svg', 'm' => 50,
        'mag' => [['Léa', 51], ['Toi', 42], ['Manon', 37], ['Sarah', 29], ['Julie', 18], ['Camille', 12]]],
    ['lib' => 'Queen of Quiches', 'ic' => 'demo/uploads/primes/3c9d2b81e04f6a7c5d12.svg', 'm' => 50,
        'mag' => [['Toi', 27], ['Sarah', 24], ['Léa', 19], ['Manon', 11]]],
    ['lib' => 'Queen of Cookies', 'emo' => '🍪', 'm' => 50,
        'mag' => [['Inès', 64], ['Léa', 58], ['Manon', 41], ['Sarah', 33], ['Toi', 22], ['Julie', 9]]],
];
const OBJ = 75.0;
$e = static fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
foreach ($C as $i => $c) {
    $rang = 1 + (int) array_search('Toi', array_column($c['mag'], 0), true);
    $q = (float) $c['mag'][$rang - 1][1];
    $un = $c['mag'][0]; $deux = $c['mag'][1] ?? null;
    $tete = $rang === 1;
    $obj = $tete ? (float) $deux[1] : (float) $un[1] + 1;           // passer la 1re, ou garder la place devant la 2e
    $pos = static fn (float $x) => round(max(0, min(100, OBJ * $x / $obj)), 1);
    $C[$i] += ['rang' => $rang, 'sur' => count($c['mag']), 'q' => $q, 'tete' => $tete, 'obj' => $obj, 'p' => $pos($q),
        'manque' => $tete ? null : (int) ($obj - $q), 'avance' => $tete ? (int) ($q - (float) $deux[1]) : null,
        'un' => $un, 'deux' => $deux, 'pts' => array_map(static fn ($x) => ['nom' => $x[0], 'q' => $x[1], 'p' => $pos((float) $x[1]), 'moi' => $x[0] === 'Toi'], $c['mag'])];
}
$icone = static fn (array $c) => isset($c['ic']) ? '<img src="' . $c['ic'] . '" alt="">' : $c['emo'];
$rangTxt = static fn (array $c) => ($c['rang'] === 1 ? '👑 1re' : $c['rang'] . 'e') . ' / ' . $c['sur'];
$reste = static fn (array $c) => $c['tete'] ? '+' . $c['avance'] . ' pcs d’avance' : 'encore ' . $c['manque'] . ' pcs pour être 1re';
$autre = static fn (array $c) => $c['tete'] ? '2e ' . $c['deux'][0] . ' ' . $c['deux'][1] : '👑 ' . $c['un'][0] . ' ' . $c['un'][1];

// A — Le drapeau, comme les targets : ses pièces à droite, la barre, le trait, la récompense dessus ; dessous sa place
// et ce qui manque, la 1re (ou la 2e) à droite.
$A = '';
foreach ($C as $c) {
    $A .= '<div class="pl' . ($c['tete'] ? ' ok' : '') . '"><div class="pl-b"><div class="pl-h"><span class="emo">' . $icone($c) . '</span><b class="pl-t">' . $e($c['lib']) . '</b>'
        . '<span class="pl-v">' . (int) $c['q'] . '<small>pcs</small></span></div>'
        . '<div class="pl-j"><i style="width:' . $c['p'] . '%"></i><span class="ob"></span><span class="fl">' . ($c['tete'] ? '✓ ' : '') . $c['m'] . '&nbsp;€</span></div>'
        . '<div class="pl-s"><span class="' . ($c['tete'] ? 'ok' : '') . '"><b class="rg">' . $rangTxt($c) . '</b> · ' . $reste($c) . '</span><span>' . $e($autre($c)) . '</span></div></div></div>';
}

// B — Le peloton : toutes les vendeuses du magasin sur la barre (des points), elle en rubis, la 1re au trait.
$B = '';
foreach ($C as $c) {
    $pts = '';
    foreach (array_reverse($c['pts']) as $x) {
        if ($x['moi']) { continue; }
        $pts .= '<span class="pk' . ($x['nom'] === $c['un'][0] ? ' un' : '') . '" style="left:' . $x['p'] . '%" title="' . $e($x['nom']) . '"></span>';
    }
    $B .= '<div class="pl' . ($c['tete'] ? ' ok' : '') . '"><div class="pl-b"><div class="pl-h"><span class="emo">' . $icone($c) . '</span><b class="pl-t">' . $e($c['lib']) . '</b>'
        . '<span class="pl-v">' . ($c['tete'] ? '👑 1re' : $c['rang'] . '<small>e / ' . $c['sur'] . '</small>') . '</span></div>'
        . '<div class="pl-j pel"><span class="ob"></span><span class="fl">' . ($c['tete'] ? '✓ ' : '') . $c['m'] . '&nbsp;€</span>' . $pts
        . '<span class="pk moi" style="left:' . $c['p'] . '%"><em>Toi ' . (int) $c['q'] . '</em></span></div>'
        . '<div class="pl-s"><span class="' . ($c['tete'] ? 'ok' : '') . '">' . $reste($c) . '</span><span>' . $e($autre($c)) . '</span></div></div></div>';
}

// C — Le rang en grand : sa place à gauche, en grand ; à droite le nom, la barre, ce qui manque.
$Cc = '';
foreach ($C as $c) {
    $Cc .= '<div class="pl rg-l' . ($c['tete'] ? ' ok' : '') . '"><div class="pl-b"><div class="rg-g"><b>' . ($c['tete'] ? '1' : $c['rang']) . '<small>' . ($c['rang'] === 1 ? 're' : 'e') . '</small></b><span>/ ' . $c['sur'] . '</span></div>'
        . '<div class="rg-d"><div class="pl-h"><span class="emo">' . $icone($c) . '</span><b class="pl-t">' . $e($c['lib']) . '</b><span class="rg-m">' . ($c['tete'] ? '+' . $c['avance'] : '−' . $c['manque']) . '<small>pcs</small></span></div>'
        . '<div class="pl-j"><i style="width:' . $c['p'] . '%"></i><span class="ob"></span><span class="fl">' . ($c['tete'] ? '✓ ' : '') . $c['m'] . '&nbsp;€</span></div>'
        . '<div class="pl-s"><span>Toi ' . (int) $c['q'] . ' pcs</span><span>' . $e($autre($c)) . '</span></div></div></div></div>';
}

// D — La liste compacte : une ligne courte par catégorie, la mini-barre, l'écart en pastille.
$D = '';
foreach ($C as $c) {
    $D .= '<div class="cd' . ($c['tete'] ? ' ok' : '') . '"><span class="emo">' . $icone($c) . '</span><div class="cd-t"><b>' . $e($c['lib']) . '</b><small>' . $rangTxt($c) . ' · ' . (int) $c['q'] . ' pcs</small></div>'
        . '<div class="jg"><i style="width:' . $c['p'] . '%"></i><span class="ob"></span></div>'
        . '<span class="cd-e">' . ($c['tete'] ? '+' . $c['avance'] : '−' . $c['manque']) . ' pcs</span></div>';
}

$props = [
    ['a', 'A', 'Le drapeau', 'Exactement comme les targets : ses pièces à droite, la barre, le trait de la 1re (+1 pièce) et la récompense posée dessus. Dessous, sa place et ce qui lui manque.', '<div class="liste">' . $A . '</div>'],
    ['b', 'B', 'Le peloton', 'Toutes les vendeuses du magasin sur la barre : un point chacune, la 1re au trait, elle en rubis. On voit qui est juste devant.', '<div class="liste">' . $B . '</div>'],
    ['c', 'C', 'Le rang en grand', 'Sa place en très grand à gauche, l’écart en pièces à droite, la barre entre les deux.', '<div class="liste">' . $Cc . '</div>'],
    ['d', 'D', 'La liste compacte', 'Une ligne courte par catégorie : sa place, la mini-barre, l’écart en pastille. Le plus court.', '<div class="carte cd-liste">' . $D . '</div>'],
];
ob_start(); ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Catégories en barre : propositions</title>
<!-- Généré par propositions-categories-barre.php. Les vrais styles de l'app (la barre .pl des targets) ; le reste ici, avec les variables du design system. -->
<link rel="stylesheet" href="/public/employee/assets/atelier/app.css">
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
  .liste{display:flex;flex-direction:column;gap:10px}
  .carte{background:var(--la-blanc);border:.5px solid var(--la-trait);border-radius:var(--radius-xl);box-shadow:var(--la-ombre)}
  .pl-b{cursor:default}
  .pl-s .rg{color:var(--la-encre)}
  .pl.ok .pl-s .rg{color:var(--la-ok)}

  /* B : le peloton */
  .pel{background:var(--la-beige)}
  .pel .pk{position:absolute;top:50%;width:10px;height:10px;margin:-5px 0 0 -5px;border-radius:50%;background:var(--la-gris);border:2px solid var(--la-blanc)}
  .pel .pk.un{background:var(--la-or)}
  .pel .pk.moi{width:18px;height:18px;margin:-9px 0 0 -9px;background:var(--la-rubis);border-width:3px;z-index:2;box-shadow:var(--la-ombre)}
  .pl.ok .pel .pk.moi{background:var(--la-ok)}
  .pel .pk.moi em{position:absolute;top:calc(100% + 3px);left:50%;transform:translateX(-50%);font:700 10px var(--font-ui);font-style:normal;color:var(--la-rubis);white-space:nowrap}
  .pl.ok .pel .pk.moi em{color:var(--la-ok)}
  .pel + .pl-s{margin-top:20px}

  /* C : le rang en grand */
  .rg-l .pl-b{display:flex;gap:12px;align-items:stretch}
  .rg-g{flex:0 0 58px;display:flex;flex-direction:column;align-items:center;justify-content:center;border-right:.5px solid var(--la-trait);padding-right:10px}
  .rg-g b{font:400 40px/1 var(--font-display);color:var(--la-rubis)}
  .rg-g b small{font:600 13px var(--font-ui);margin-left:1px;vertical-align:top}
  .rg-g span{font:600 11px var(--font-ui);color:var(--la-muet);margin-top:2px}
  .rg-l.ok .rg-g b{color:var(--la-ok)}
  .rg-d{flex:1;min-width:0}
  .rg-m{font:400 21px/1 var(--font-display);color:var(--la-abricot-encre);white-space:nowrap}
  .rg-m small{font:600 11px var(--font-ui);color:var(--la-muet);margin-left:3px}
  .rg-l.ok .rg-m{color:var(--la-ok)}

  /* D : la liste compacte */
  .cd-liste{padding:4px 12px}
  .cd{display:grid;grid-template-columns:30px minmax(0,1fr) 32% auto;align-items:center;gap:10px;height:58px;border-bottom:.5px solid var(--la-trait)}
  .cd:last-child{border-bottom:0}
  .cd .emo{width:30px;height:30px;border-radius:var(--radius-md);background:var(--la-beige-doux);display:flex;align-items:center;justify-content:center;font-size:16px;overflow:hidden}
  .cd .emo img{width:21px;height:21px}
  .cd-t b{display:block;font:600 13px/1.2 var(--font-ui);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .cd-t small{display:block;font:500 11px var(--font-ui);color:var(--la-muet);margin-top:2px}
  .cd .jg{position:relative;height:8px;border-radius:var(--radius-pill);background:var(--la-beige)}
  .cd .jg i{position:absolute;left:0;top:0;bottom:0;border-radius:var(--radius-pill);background:var(--la-rubis)}
  .cd.ok .jg i{background:var(--la-ok)}
  .cd .jg .ob{position:absolute;left:75%;top:-5px;bottom:-5px;width:3px;margin-left:-1.5px;border-radius:2px;background:var(--la-encre)}
  .cd-e{font:700 11.5px var(--font-ui);padding:4px 8px;border-radius:var(--radius-pill);background:var(--la-abricot);color:var(--la-abricot-encre);white-space:nowrap}
  .cd.ok .cd-e{background:var(--la-ok-doux);color:var(--la-ok)}
</style>
</head>
<body>
<div class="page">
<?php foreach ($props as [$id, $L, $titre, $desc, $html]): ?>
<section class="prop" id="prop-<?= $id ?>">
  <div class="prop-t"><b><?= $L ?></b><h2><?= $e($titre) ?></h2></div>
  <p class="prop-d"><?= $e($desc) ?></p>
  <div class="titre-section"><h2>Catégories</h2><small>jusqu’à 150 €</small></div>
  <?= $html ?>
</section>
<?php endforeach; ?>
</div>
</body>
</html>
<?php
file_put_contents(__DIR__ . '/propositions-categories-barre.html', ob_get_clean());
echo "propositions-categories-barre.html\n";
