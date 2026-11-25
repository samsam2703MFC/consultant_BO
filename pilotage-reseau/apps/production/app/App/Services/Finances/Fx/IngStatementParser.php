<?php
declare(strict_types=1);

namespace App\Services\Finances\Fx;

/**
 * [439] Relevé ING (PDF). EN ATTENTE : la mise en page réelle d'un relevé ING n'est pas connue (aucun exemple reçu) et
 * n'est pas devinée ici. Tant qu'un relevé réel n'a pas servi à écrire et tester la lecture, tout texte est refusé avec un
 * message clair : le PDF est enregistré chiffré et les paiements se saisissent à la main (« Dodaj wpłatę »).
 * À faire avec l'exemple : repérer l'en-tête (compte, devise, période), les lignes (date de comptabilisation, montant,
 * devise, contrepartie, titre) et, pour le compte PLN, le montant d'origine en devise et le cours appliqué par ING.
 */
final class IngStatementParser implements BankStatementParser
{
    public const MESSAGE = 'Format wyciągu ING nie jest jeszcze obsługiwany (czekamy na przykładowy wyciąg) — plik zapisany, wpisz wpłaty ręcznie (« Dodaj wpłatę »).';

    public function name(): string
    {
        return 'ing';
    }

    public function parse(string $text): array
    {
        throw new StatementFormatNotSupported(self::MESSAGE);
    }
}
