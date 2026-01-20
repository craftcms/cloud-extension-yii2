<?php

namespace craft\cloud\db;

use Illuminate\Support\Collection;

class Command extends \craft\db\Command
{
    public function setSql($sql): Command
    {
        $sql = $sql ? self::addComment($sql) : $sql;
        return parent::setSql($sql);
    }

    public static function addComment(string $sql): string
    {
        $url = parse_url($_SERVER['REQUEST_URI'] ?? '');
        $fields = Collection::make(PHP_SAPI === 'cli' ? [
            'args' => implode(' ', $_SERVER['argv'] ?? []),
        ] : [
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'path' => $url['path'],
            'query' => $url['query'] ?? null,
        ])->map(function($value, $key) {
            $key = urlencode($key);

            // allow un-encoded forward slashes and spaces
            $value = str_replace(['%2F', '+'], ['/', ' '], urlencode($value));

            $maxLength = 100;
            $value = strlen($value) > $maxLength
                ? substr($value, 0, $maxLength) . '...[truncated]'
                : $value;

            return $value
                ? sprintf("%s='%s'", $key, $value)
                : null;
        })->filter();
        $comment = $fields->join(',');

        return "/*$comment*/ $sql";
    }
}
