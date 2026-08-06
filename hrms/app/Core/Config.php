<?php
namespace App\Core;

class Config
{
    private static array $data = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException(
                "Missing config: {$path}. Copy config/config.example.php to config/config.php."
            );
        }
        self::$data = require $path;
    }

    /** Dot-path getter: Config::get('db.host'). */
    public static function get(string $key, $default = null)
    {
        $parts = explode('.', $key);
        $val = self::$data;
        foreach ($parts as $p) {
            if (is_array($val) && array_key_exists($p, $val)) {
                $val = $val[$p];
            } else {
                return $default;
            }
        }
        return $val;
    }
}
