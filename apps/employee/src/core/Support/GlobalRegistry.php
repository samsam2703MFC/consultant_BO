<?php
namespace App\Employee\core\Support;

class GlobalRegistry {
    private static $storage = [];

    public static function set($key, $value) {
        self::$storage[$key] = $value;
    }

    public static function get($key) {
        return self::$storage[$key] ?? null;
    }
}