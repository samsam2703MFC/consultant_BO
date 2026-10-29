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

    /**
     * Base de l'API pour les pages web du module (data-api-base) : l'API publique quand elle est sur un autre hôte ou
     * chemin, sinon la racine de l'ERP (même origine, pas de CORS), suivie du préfixe /api/v1/production.
     */
    public function webApiBase(string $root): string
    {
        $base = $this->apiBaseUrl();
        $root = rtrim($root, '/');
        if ($base === '' || strcasecmp((string) parse_url($base, PHP_URL_HOST), (string) parse_url($root, PHP_URL_HOST)) === 0
            && (string) parse_url($base, PHP_URL_PATH) === (string) parse_url($root, PHP_URL_PATH)) {
            $base = $root;
        }
        return $base . \App\Core\Routes\Api\WorkshopApiRoutes::PREFIX;
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
