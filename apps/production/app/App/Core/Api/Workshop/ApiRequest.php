<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

use Symfony\Component\HttpFoundation\Request;

/** Requête vue par un contrôleur du module : paramètres de route, query, corps JSON, contexte. */
final class ApiRequest
{
    private ?array $body = null;

    /** @param array<string,string> $vars */
    public function __construct(
        public readonly Request $http,
        public readonly array $vars,
        public readonly ApiContext $context,
    ) {
    }

    public function id(string $name = 'id'): int
    {
        $value = $this->vars[$name] ?? '';
        if (!ctype_digit((string) $value)) {
            throw new ApiException('ROUTE_NOT_FOUND', 404);
        }
        return (int) $value;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        $value = $this->http->query->get($name);
        return is_scalar($value) ? (string) $value : $default;
    }

    /** Corps JSON (objet). Un corps vide vaut {}. */
    public function body(): array
    {
        if ($this->body === null) {
            $raw = trim((string) $this->http->getContent());
            if ($raw === '') {
                $this->body = [];
            } else {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                    throw new ApiException('INVALID_JSON', 400);
                }
                $this->body = $decoded;
            }
        }
        return $this->body;
    }
}
