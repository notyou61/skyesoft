<?php
declare(strict_types=1);

// ======================================================================
// Skyesoft — sessionBootstrap.php
// Version: 1.3.1
// Last Updated: 2026-04-26
// Codex Tier: 4 — Session Bootstrap
//
// Description:
// Core session initialization — SINGLE SOURCE OF TRUTH for all session state.
//
// Responsibilities:
// • Initialize PHP session with secure cookie settings
// • Define $activitySessionId as the canonical session identifier
// • Enforce consistent session name and security parameters
//
// Notes:
// • This file must be included BEFORE any other auth/session logic
// • All other files should now use $activitySessionId
// • Cookie/session configuration must NOT be duplicated elsewhere
// ======================================================================
// 🔒 SINGLE SOURCE OF TRUTH

session_name('SKYESOFTSESSID');

session_set_cookie_params([
    'lifetime' => 0,        // until browser closes
    'path'     => '/',      // 🔥 CRITICAL — available site-wide
    'secure'   => true,     // HTTPS only
    'httponly' => true,     // No JS access
    'samesite' => 'Lax'
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// ====================== CANONICAL SESSION VARIABLE ======================
$activitySessionId = session_id();   // ← This is now the ONE variable to use everywhere

// Optional: Make it available as a constant for templates / JS bridging if needed
define('ACTIVITY_SESSION_ID', $activitySessionId);