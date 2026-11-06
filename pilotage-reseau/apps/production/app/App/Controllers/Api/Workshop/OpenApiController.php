<?php
declare(strict_types=1);

namespace App\Controllers\Api\Workshop;

use App\Core\Api\Workshop\ApiRequest;
use App\Core\Api\Workshop\OpenApiSpec;
use App\Core\Api\Workshop\WorkshopConfig;
use Symfony\Component\HttpFoundation\JsonResponse;

final class OpenApiController
{
    public function __construct(private WorkshopConfig $config)
    {
    }

    public function spec(ApiRequest $request): JsonResponse
    {
        $response = new JsonResponse(null);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $response->setData(OpenApiSpec::build($this->config->apiBaseUrl()));
        return $response;
    }
}
