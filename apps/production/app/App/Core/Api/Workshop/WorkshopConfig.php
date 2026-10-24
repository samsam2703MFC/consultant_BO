<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

/**
 * Réglages techniques de l'API du module atelier, lus dans .env (jamais en dur) :
 *   PRODUCTION_JWT_SECRET         secret de signature des jetons (≥ 32 caractères)
 *   API_BASE_URL                  adresse publique de l'API (OpenAPI « servers »)
 *   PRODUCTION_API_CORS_ORIGINS   origines autorisées, séparées par des virgules
 *   R2_PUBLIC_BASE_URL            adresse publique du bucket Cloudflare R2 des photos
 * Les valeurs métier, elles, sont dans n_prod_param.
 */
final class WorkshopConfig
{
    private array $values;

    public function __construct(?array $values = null)
    {
        $keys = ['PRODUCTION_JWT_SECRET', 'API_BASE_URL', 'PRODUCTION_API_CORS_ORIGINS', 'R2_PUBLIC_BASE_URL', 'APP_URL'];
        $this->values = [];
        foreach ($keys as $key) {
            $value = $values !== null ? ($values[$key] ?? '') : ($_ENV[$key] ?? getenv($key) ?: '');
            $this->values[$key] = trim((string) $value);
        }
    }

    public function jwtSecret(): string
    {
        return $this->values['PRODUCTION_JWT_SECRET'];
    }

    public function apiBaseUrl(): string
    {
        $base = $this->values['API_BASE_URL'] !== '' ? $this->values['API_BASE_URL']
            : ($this->values['APP_URL'] !== '' ? $this->values['APP_URL'] : (defined('ROOT') ? ROOT : ''));
        return rtrim($base, '/');
    }

    /** @return list<string> */
    public function corsOrigins(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->values['PRODUCTION_API_CORS_ORIGINS']))));
    }

    public function r2PublicBaseUrl(): string
    {
        return rtrim($this->values['R2_PUBLIC_BASE_URL'], '/');
    }
}
