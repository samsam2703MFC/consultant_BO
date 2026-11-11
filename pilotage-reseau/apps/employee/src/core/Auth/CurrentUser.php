<?php

class CurrentUser {
    private static $storage = [];

    public static function set($key, $value) {
        self::$storage[$key] = $value;
    }

    public static function get($key) {
        return self::$storage[$key] ?? null;
    }
}