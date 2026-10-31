<?php
namespace App\Exceptions;

use Exception;

class DatabaseException extends Exception {
    public function __construct($message = "Database error", $code = 500, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}