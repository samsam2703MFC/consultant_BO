<?php

namespace App\Employee\core\Support;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Route
{
    public function __construct(
        public string $method,
        public string $path
    )
    {
    }
}