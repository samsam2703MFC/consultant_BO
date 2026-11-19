<?php
namespace App\Employee\core\Exceptions;

use Exception;

class DataNotFoundException extends Exception
{
    public function __construct($message = "Nie znaleziono wymaganych danych", $code = 404, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}