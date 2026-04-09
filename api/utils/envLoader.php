<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — envLoader.php
//  Version: 1.0.0
//  Last Updated: 2026-04-09
//  Codex Tier: 2 — Environment Initialization Layer
//
//  Role:
//  Provides centralized environment configuration loading for Skyesoft.
//
//  Responsibilities:
//   • Load environment variables from secure .env files
//   • Normalize variables into $_ENV / getenv() scope
//   • Support multiple env sources (.env, db.env)
//   • Ensure idempotent loading (safe for repeated calls)
//
//  Behavior:
//   • Loads once per request (static guard)
//   • Skips missing or unreadable env files (non-fatal)
//   • Logs load activity for debugging visibility
//
//  Dependencies:
//   • /secure/.env
//   • /secure/db.env
//
//  Outputs:
//   • $_ENV populated with key/value pairs
//   • getenv() access available system-wide
//
//  Notes:
//   • This file defines skyesoftLoadEnv()
//   • Must be explicitly required by any module needing env access
//   • Does NOT start sessions or perform authentication
//   • Does NOT interact with database or external APIs
//
//  Constraints:
//   • No side effects beyond environment loading
//   • No output (silent operation except logging)
//   • Must remain lightweight and deterministic
//
//  Usage:
//   require_once __DIR__ . '/envLoader.php';
//   skyesoftLoadEnv();
//
// ======================================================================

function skyesoftLoadEnv(): void {

    // Prevent reloading (SSE-safe)
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    // Resolve base path (project root → /secure)
    $basePath = rtrim(dirname(__DIR__, 4), '/') . '/secure';

    error_log("[env-loader] basePath: $basePath");

    $envFiles = [
        $basePath . '/.env',
        $basePath . '/db.env'
    ];

    foreach ($envFiles as $envPath) {

        if (!file_exists($envPath) || !is_readable($envPath)) {
            error_log("[env-loader] Skipped missing: $envPath");
            continue;
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {

            $line = trim($line);

            // Skip comments / invalid lines
            if (
                $line === '' ||
                str_starts_with($line, '#') ||
                !str_contains($line, '=')
            ) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key   = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            // Set environment
            putenv("$key=$value");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }

        error_log("[env-loader] Loaded: $envPath");
    }
}
function skyesoftGetEnv(string $key): ?string {
    return $_ENV[$key] ?? getenv($key) ?? null;
}