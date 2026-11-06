<?php
namespace App\Controllers;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class _404
{
    public function index(): Response
    {
        // Wykrycie czy to API (np. URI zaczyna się od `/api`)
        $isApiRequest = isset($_SERVER['REQUEST_URI']) && str_starts_with($_SERVER['REQUEST_URI'], '/api');

        if ($isApiRequest) {
            return new JsonResponse(['message' => 'Endpoint not found'], 404);
        }

        // Web – domyślny fallback
        return new Response('<h1>404 - Page not found</h1>', 404);
    }
}