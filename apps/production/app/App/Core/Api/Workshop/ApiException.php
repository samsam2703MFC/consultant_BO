<?php
declare(strict_types=1);

namespace App\Core\Api\Workshop;

/**
 * Erreur métier renvoyée au client : un code stable (ex. VALIDATION_FAILED), un statut HTTP
 * et, pour la validation, la liste des champs en défaut. Le message lisible est traduit
 * (polonais ou français) au moment de la réponse.
 */
final class ApiException extends \RuntimeException
{
    /**
     * @param list<array{field:string,code:string,params?:array<string,scalar>}> $fieldErrors
     * @param array<string,scalar> $params
     */
    public function __construct(
        private string $errorCode,
        private int $httpStatus,
        private array $fieldErrors = [],
        private array $params = [],
    ) {
        parent::__construct($errorCode);
    }

    public static function validation(array $fieldErrors): self
    {
        return new self('VALIDATION_FAILED', 422, $fieldErrors);
    }

    public static function notFound(string $entity, int|string $id): self
    {
        return new self('NOT_FOUND', 404, [], ['entity' => $entity, 'id' => (string) $id]);
    }

    public function errorCode(): string { return $this->errorCode; }
    public function httpStatus(): int { return $this->httpStatus; }
    public function fieldErrors(): array { return $this->fieldErrors; }
    public function params(): array { return $this->params; }
}
