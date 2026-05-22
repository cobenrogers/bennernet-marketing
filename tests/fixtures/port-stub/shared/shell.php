<?php
/**
 * Minimal Port shell stub for marketing unit tests.
 *
 * Provides the subset of shell.php functions that tile.php and index.php call.
 * Returns predictable values without hitting the database or emitting output.
 */

if (!function_exists('h')) {
    function h(string $str): string
    {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('requireModuleAccess')) {
    function requireModuleAccess(string $module, string $minRole): ?array
    {
        // CLI SAPI: no redirect. Return stub user from global or null.
        return $GLOBALS['_stub_auth_user'] ?? null;
    }
}

if (!function_exists('renderHeader')) {
    function renderHeader(string $title, array $options = []): void {}
}

if (!function_exists('renderFooter')) {
    function renderFooter(array $options = []): void {}
}

if (!function_exists('getPortDb')) {
    function getPortDb(): ?PDO
    {
        return null;
    }
}

if (!function_exists('logInfo')) {
    function logInfo(string $module, string $msg, array $context = []): void {}
}

if (!function_exists('logWarning')) {
    function logWarning(string $module, string $msg, array $context = []): void {}
}

if (!function_exists('logError')) {
    function logError(string $module, string $msg, array $context = []): void {}
}
