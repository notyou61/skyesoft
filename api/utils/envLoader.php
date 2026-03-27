<?php

function skyesoftLoadEnv(): void {

    $basePath = dirname(__DIR__, 3) . '/secure';

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

            putenv("$key=$value");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }

        error_log("[env-loader] Loaded: $envPath");
    }
}