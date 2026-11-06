<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiException;
use App\Core\Api\Workshop\ApiRequest;

/** Lecture des filtres de liste (?active=1&group_id=2…), refusés s'ils sont mal formés. */
trait QueryFilters
{
    private function boolFilter(ApiRequest $request, string $name): ?bool
    {
        $value = $request->query($name);
        if ($value === null || $value === '') return null;
        return match (strtolower($value)) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw ApiException::validation([['field' => $name, 'code' => 'NOT_BOOLEAN', 'params' => []]]),
        };
    }

    private function intFilter(ApiRequest $request, string $name): ?int
    {
        $value = $request->query($name);
        if ($value === null || $value === '') return null;
        if (!ctype_digit($value)) {
            throw ApiException::validation([['field' => $name, 'code' => 'NOT_INTEGER', 'params' => []]]);
        }
        return (int) $value;
    }

    private function choiceFilter(ApiRequest $request, string $name, array $allowed): ?string
    {
        $value = $request->query($name);
        if ($value === null || $value === '') return null;
        if (!in_array($value, $allowed, true)) {
            throw ApiException::validation([['field' => $name, 'code' => 'INVALID_CHOICE', 'params' => ['allowed' => implode(', ', $allowed)]]]);
        }
        return $value;
    }
}
