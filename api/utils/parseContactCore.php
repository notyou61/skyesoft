<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — parseContactCore.php
//  Version: 1.4.0
//  Last Updated: 2026-04-17
//  Codex Tier: 3 — Structured Data Extraction
//
//  Role:
//  PURE LIGHT EXTRACTOR / NORMALIZER ONLY
//  • Trims input
//  • Extracts obvious raw candidates (email, phone)
//  • No decisions, no inference, no heuristics, no fallbacks
//  • Returns clean structured array contract
//
//  All business logic, name splitting, salutation resolution,
//  title/company/address inference moved to createContact.php
// ======================================================================

#region SECTION 0 — Environment Bootstrap (Optional)

if (!function_exists('skyesoftLoadEnv')) {
    require_once __DIR__ . '/envLoader.php';
}

skyesoftLoadEnv();

#endregion

#region SECTION 1 — Core Function

// ─────────────────────────────────────────────
// 🔤 Parse Contact — Minimal Extraction Only
// ─────────────────────────────────────────────
function parseContact(string $rawInput): array
{
    $rawInput = trim($rawInput);

    if ($rawInput === '') {
        throw new RuntimeException('parseContact: rawInput is empty.');
    }

    // Split into lines (preserves structure for downstream processing)
    $lines = preg_split('/\r\n|\r|\n/', $rawInput);
    $lines = array_map('trim', array_filter($lines));

    $data = [
        'entity' => [
            'name' => ''
        ],
        'location' => [
            'address' => '',
            'city'    => '',
            'state'   => '',
            'zip'     => ''
        ],
        'contact' => [
            'salutation' => '',
            'firstName'  => '',
            'lastName'   => '',
            'title'      => '',
            'phone'      => '',
            'email'      => ''
        ]
    ];

    // Minimal reliable extractions only (no heuristics or assumptions)
    foreach ($lines as $line) {
        // Email — very reliable pattern
        if (empty($data['contact']['email']) && 
            preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $line, $matches)) {
            $data['contact']['email'] = strtolower(trim($matches[0]));
        }

        // Phone — basic common formats (no complex normalization here)
        if (empty($data['contact']['phone']) && 
            preg_match('/(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $line, $matches)) {
            $data['contact']['phone'] = trim($matches[0]);
        }
    }

    // Final minimal normalization (only what was explicitly allowed)
    $data['contact']['email'] = strtolower(trim($data['contact']['email'] ?? ''));

    // Everything else remains untouched / empty for orchestration layer
    foreach (['salutation', 'firstName', 'lastName', 'title', 'phone'] as $key) {
        $data['contact'][$key] = trim($data['contact'][$key] ?? '');
    }

    $data['entity']['name'] = trim($data['entity']['name'] ?? '');

    // Location remains fully empty — let createContact.php decide how to handle

    return $data;
}

#endregion