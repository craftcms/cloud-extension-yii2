<?php

namespace craft\cloud\runtime\variables;

class VariablesLoader
{
    public const FILENAME = '/opt/craft-cloud/.env.php';

    public static function load(): void
    {
        if (!file_exists(self::FILENAME)) {
            echo 'No variables file found';
            return;
        }

        $vars = include self::FILENAME;

        if (!is_array($vars)) {
            echo 'The variables file should be an array';
            return;
        }

        foreach ($vars as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $_SERVER[$key] = $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}
