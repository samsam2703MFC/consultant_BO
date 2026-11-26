<?php
declare(strict_types=1);

namespace App\Services\Finances\Fx;

/**
 * [439] Lecture d'un relevé bancaire (texte extrait du PDF par pdftotext) → lignes. Une implémentation par banque et par
 * format ; un format inconnu lève StatementFormatNotSupported (le PDF reste enregistré, les lignes se saisissent à la main).
 * Liaison : App/Core/bootstrap.php (IngStatementParser) ; les tests injectent leur propre lecteur.
 */
interface BankStatementParser
{
    /** Nom court enregistré avec le relevé (n_bank_statement.parser). */
    public function name(): string;

    /**
     * [444] En-tête du relevé, sans lever d'exception (null = pas lu) : devise du compte (contrôle du compte choisi à
     * l'envoi), période, numéro.
     * @return array{account_currency?: ?string, period_from?: ?string, period_to?: ?string, number?: ?string}
     */
    public function header(string $text): array;

    /**
     * @return list<array{booked_on: string, amount: float, currency: string, amount_pln: ?float, bank_rate: ?float,
     *     payer: ?string, title: ?string, incoming: bool}> toutes les lignes du relevé (crédits et débits) ; seuls les
     *     crédits en devise sont gardés comme paiements de clients, les autres sont comptés (« inne linie »)
     * @throws StatementFormatNotSupported format pas encore pris en charge
     */
    public function parse(string $text): array;
}
