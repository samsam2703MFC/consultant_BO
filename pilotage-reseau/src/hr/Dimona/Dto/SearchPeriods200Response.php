<?php

declare(strict_types=1);

namespace Hr\Dimona\Dto;

/**
 * Schéma « searchPeriods_200_response » — docs/dimona/openapi_dimona_v2.yaml, ligne 2606.
 *
 * Fichier GÉNÉRÉ par tools/hr-dimona/generate.py : ne pas modifier à la main.
 */
final class SearchPeriods200Response extends AbstractDto
{
    public const SCHEMA = 'searchPeriods_200_response';

    /** Any absolute URI that is using http or https protocol */
    public ?string $self = null;
    /** Previous page in a paged resource collection */
    public ?string $prev = null;
    /** Next page in a paged resource collection */
    public ?string $next = null;
    /** Current page */
    public ?int $page = null;
    /** Number of items retrieved */
    public ?int $pageSize = null;
    /** Total number of workers found */
    public ?int $total = null;
    public ?array $items = null;

    public static function fields(): array
    {
        return [
            'self' => ['type' => 'string', 'format' => 'uri'],
            'prev' => ['type' => 'string', 'format' => 'uri'],
            'next' => ['type' => 'string', 'format' => 'uri'],
            'page' => ['type' => 'integer'],
            'pageSize' => ['type' => 'integer'],
            'total' => ['type' => 'integer'],
            'items' => ['type' => 'array', 'items' => ['ref' => SearchPeriods200ResponseAllOfItemsInner::class]],
        ];
    }

    public static function required(): array
    {
        return ['next', 'page', 'pageSize', 'prev', 'total', 'items'];
    }
}
