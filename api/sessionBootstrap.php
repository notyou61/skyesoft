<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — auth.php
// Version: 1.3.0
// Last Updated: 2026-04-01
// Codex Tier: 4 — Session Mutation Endpoint
//
// Description:
// Handles authentication lifecycle actions including:
// • Login (with geolocation capture)
// • Logout (manual + idle timeout)
// • Session validation (check)
//
// Responsibilities:
// • Establish and mutate authenticated session state
// • Enforce login/logout sequencing (no duplicate transitions)
// • Capture and persist geo (lat/lon), IP, and user agent
// • Log all auth actions to tblActions (authoritative audit trail)
//
// Dependencies:
// • sessionBootstrap.php (single session authority)
// • dbConnect.php (PDO connection)
// • utils/actions.php (logAuthAction, helpers)
//
// Notes:
// • This is the ONLY endpoint allowed to mutate auth state
// • SSE and client are read-only observers of session state
// • Cookie/session configuration must NOT be duplicated here
// ======================================================================
// 🔒 SINGLE SOURCE OF TRUTH

session_name('SKYESOFTSESSID');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',      // 🔥 CRITICAL
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}