<?php
/** Kreator « Nowe zamówienie » (audyt 2, P4) : pasek czterech kroków ; $wizardStep = bieżący krok (1–4), wcześniejsze zrobione. */
$wizardLabels = [1 => 'Klient i typ', 2 => 'Produkty z cennika', 3 => 'Proforma', 4 => 'Wysyłka do klienta'];
?>
<ol class="at-steps mb-3" aria-label="Kroki nowego zamówienia">
    <?php foreach ($wizardLabels as $n => $label): ?>
        <li class="<?= $n < $wizardStep ? 'is-done' : ($n === $wizardStep ? 'is-active' : '') ?>" <?= $n === $wizardStep ? 'aria-current="step"' : '' ?>><span><?= $label ?></span></li>
    <?php endforeach; ?>
</ol>
