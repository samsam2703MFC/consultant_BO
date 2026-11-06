<?php
declare(strict_types=1);

/*
 * L'évaluation rapide de fin de visite (05/11/2026) : les six axes et le contrôle de ce que la tablette envoie.
 * Sans base : chargé par visites.php et suivi.php (le radar du rapport), et par bin/tests_suivi.php.
 */

/**
 * L'évaluation rapide de fin de visite (05/11/2026, Sam) : six axes notés de 1 à 5 avec le franchisé, chacun avec son
 * commentaire facultatif. Elle part dans le rapport, en radar (suivi.php, suEvalHtml). Les libellés sont ceux du rapport,
 * dans les quatre langues des magasins.
 */
function viEvalAxes(): array
{
    return [
        ['k' => 'proprete', 'fr' => 'Propreté', 'nl' => 'Netheid', 'en' => 'Cleanliness', 'de' => 'Sauberkeit'],
        ['k' => 'metier', 'fr' => 'Capacité métier', 'nl' => 'Vakkennis', 'en' => 'Craft skills', 'de' => 'Fachkönnen'],
        ['k' => 'management', 'fr' => 'Management', 'nl' => 'Management', 'en' => 'Management', 'de' => 'Führung'],
        ['k' => 'commercial', 'fr' => 'Développement commercial', 'nl' => 'Commerciële ontwikkeling', 'en' => 'Business development', 'de' => 'Geschäftsentwicklung'],
        ['k' => 'client', 'fr' => 'Contact client', 'nl' => 'Klantcontact', 'en' => 'Customer contact', 'de' => 'Kundenkontakt'],
        ['k' => 'normes', 'fr' => 'Respect des normes', 'nl' => 'Naleving van de normen', 'en' => 'Compliance with standards', 'de' => 'Einhaltung der Normen'],
    ];
}

/**
 * L'évaluation reçue de la tablette : { notes: { axe: { note: 1..5, com: '…' } } } (ou les axes directement), les axes
 * connus seulement ; null si rien n'est noté.
 */
function viEvaluation(mixed $x, string $qui): ?array
{
    if (!is_array($x)) { return null; }
    if (is_array($x['notes'] ?? null)) { $x = $x['notes']; }
    $notes = []; $n = 0;
    foreach (viEvalAxes() as $a) {
        $y = is_array($x[$a['k']] ?? null) ? $x[$a['k']] : [];
        $note = isset($y['note']) && is_numeric($y['note']) ? (int) $y['note'] : null;
        $note = $note !== null && $note >= 1 && $note <= 5 ? $note : null;
        $com = mb_substr(trim((string) ($y['com'] ?? '')), 0, 500);
        if ($note !== null) { $n++; }
        $notes[$a['k']] = ['note' => $note, 'com' => $com];
    }
    if ($n === 0) { return null; }
    return ['notes' => $notes, 'n' => $n, 'qui' => mb_substr($qui, 0, 120), 'le' => date('Y-m-d H:i:s')];
}
