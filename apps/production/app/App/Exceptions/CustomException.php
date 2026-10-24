<?php
namespace App\Exceptions;

use Exception;

class CustomException extends Exception {
    private array $errors; // Przechowujemy błędy jako tablicę
    private string $module; // Przechowujemy błędy jako tablicę

    public function __construct(
        string $description = "Error",
        string $module = "errors",
        int $code = 404,
        array $errors = [],
        Exception $previous = null
    ) {
        $this->errors = $errors;
        $this->module = $module;
        parent::__construct($description, $code, $previous);
    }

    public function getErrors(): array {
        return $this->errors;
    }
    public function getModule(): string {
        return $this->module;
    }
}