<?php

/**
 * Optional-column checks, so code written for a newer migration keeps
 * working (with safe defaults) on a database that hasn't run it yet --
 * e.g. migration 062 (offers_home_service, return_travel_minutes,
 * needs_followup). Cached per request.
 */
final class Schema
{
    private static array $cache = [];

    public static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $key = "$table.$column";
        if (!array_key_exists($key, self::$cache)) {
            try {
                $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
                $stmt->execute([$table, $column]);
                self::$cache[$key] = (bool) $stmt->fetchColumn();
            } catch (PDOException $e) {
                self::$cache[$key] = false;
            }
        }
        return self::$cache[$key];
    }

    /** "{$expr} AS {$alias}" when the column exists, else "{$fallback} AS {$alias}". */
    public static function select(PDO $pdo, string $table, string $column, string $expr, string $alias, string $fallback = 'NULL'): string
    {
        return (self::hasColumn($pdo, $table, $column) ? $expr : $fallback) . " AS {$alias}";
    }
}
