<?php
declare(strict_types=1);

namespace App\Services\Finances\Fx;

/**
 * [438] Accès HTTP à l'API publique des cours NBP (lecture seule, sans clé). Interface pour que les tests injectent un
 * faux client : aucun test n'appelle le réseau. Implémentation : CurlNbpHttpClient (liaison dans App/Core/bootstrap.php).
 */
interface NbpHttpClient
{
    /**
     * GET $url (JSON attendu).
     * @return array{0: int, 1: string} [code HTTP, corps] — 404 = « Brak danych » (aucune table dans la période)
     * @throws \RuntimeException réseau indisponible (pas de réponse HTTP)
     */
    public function get(string $url): array;
}
