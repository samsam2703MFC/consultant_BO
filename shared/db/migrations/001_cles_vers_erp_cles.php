<?php
declare(strict_types=1);

// Réorganisation, étape 1 : les champs-clés des réglages du cockpit passent dans erp_cles (= erp_keys), le seul accès
// aux clés (shared/src/erp_cles.php). Rien n'est écrasé : une clé déjà présente avec une autre valeur laisse le champ
// dans son réglage, avec sa priorité. Le bilan ne contient que des noms.
return [
    'quoi' => 'Clés des réglages du cockpit → erp_cles',
    'up' => static fn (): array => erpClesReprise(),
    'down' => static fn (): array => ['rendues' => erpClesRendre()],
];
