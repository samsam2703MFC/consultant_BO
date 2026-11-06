<?php
namespace App\Core\Web;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\Translator;
use App\Exceptions\CustomException;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Réponse JSON d'erreur des appels ajax des pages web : les erreurs de validation du module
 * (ApiException) deviennent des messages polonais par champ, les erreurs métier gardent leur
 * message, le reste est journalisé et reste générique.
 */
trait JsonErrors
{
    protected function jsonError(\Throwable $e): JsonResponse
    {
        if ($e instanceof ApiException) {
            $t = new Translator('pl');
            $errors = array_map(fn($err) => [
                'field' => $err['field'],
                'message' => $t->fieldError(preg_replace('/^.*\./', '', $err['field']), $err['code'], $err['params'] ?? []),
            ], $e->fieldErrors());
            $message = $errors ? implode(' ', array_column($errors, 'message')) : $t->error($e->errorCode(), $e->params());
            return $this->json(['message' => $message, 'errors' => $errors], $e->httpStatus());
        }
        if ($e instanceof CustomException || $e instanceof \InvalidArgumentException) {
            $code = (int) $e->getCode();
            return $this->json(['message' => $e->getMessage()], $code >= 400 && $code < 500 ? $code : 422);
        }
        error_log('[web-ajax] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        return $this->json(['message' => 'Błąd serwera — operacja nie została wykonana.'], 500);
    }
}
