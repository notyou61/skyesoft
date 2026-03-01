<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — violationActionResolver.php
//  Version: 1.0.0
//  Last Updated: 2026-03-01
//  Codex Tier: 4 — Governance / Structural Integrity Resolver
//
//  Role:
//  JSON API endpoint that evaluates current structural integrity state
//  and returns authoritative violation metadata for governance surfaces.
//
//  Responsibilities:
//   • Load unresolved structural violations via sentinelLoader
//   • Return structured JSON response for UI consumption
//   • Never render HTML
//   • Never perform automatic remediation
//
//  Inputs:
//   • No required GET/POST parameters (read-only evaluation)
//   • Dependency: sentinelLoader.php
//
//  Outputs:
//   • application/json
//     {
//       status: clean | violations_detected | error,
//       message: string,
//       data: { ... }
//     }
//
//  Forbidden:
//   • No HTML rendering
//   • No file mutation
//   • No Codex mutation
//   • No side-effect remediation actions
//
//  Notes:
//   • Intended to be called by index.js uiActionRegistry
//   • Structural repair actions should be routed separately
//   • Must always return valid JSON
// ======================================================================

require_once __DIR__ . "/sentinelLoader.php";

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

try {

    $violations = loadUnresolvedStructuralViolations();

    if (!$violations) {
        echo json_encode([
            "status"  => "clean",
            "message" => "No structural deviations detected.",
            "data"    => null
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $response = [
        "status" => "violations_detected",
        "message" => "Structural deviations present.",
        "data" => [
            "merkleIntegrity"     => !empty($violations["merkleIntegrity"]),
            "repositoryInventory" => $violations["repositoryInventory"] ?? []
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    exit;

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode([
        "status"  => "error",
        "message" => "Violation resolver failed.",
        "error"   => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES);

    exit;
}