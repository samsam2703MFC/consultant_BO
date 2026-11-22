<?php
namespace App\Employee\core\Exceptions;

use Exception;

class ProtectedResourceException extends Exception
{
    public function __construct($message = "Element nie może zostać usunięty", $code = 404, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}