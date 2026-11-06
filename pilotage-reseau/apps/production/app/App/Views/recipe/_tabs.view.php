<?php
/** Receptury : jedna pozycja w railu, trzy typy jako zakładki (lista produktów, półproduktów, kompozycji). */
$recipeTabs = ['product' => ['/recipes', 'Produkty gotowe'], 'semi' => ['/semi-products', 'Półprodukty'], 'composition' => ['/compositions', 'Kompozycje']];
?>
<ul class="nav nav-tabs mb-3" role="tablist" aria-label="Typ receptury">
    <?php foreach ($recipeTabs as $tabKind => [$tabUrl, $tabLabel]): ?>
        <li class="nav-item"><a class="nav-link <?= $tabKind === $recipeTab ? 'active' : '' ?>" <?= $tabKind === $recipeTab ? 'aria-current="page"' : '' ?> href="<?= ROOT . $tabUrl ?>"><?= $tabLabel ?></a></li>
    <?php endforeach; ?>
</ul>
