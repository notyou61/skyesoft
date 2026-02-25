<?php
declare(strict_types=1);

/**
 * Governance Violation Action Resolver
 *
 * Purely deterministic.
 * No output.
 * No mutation.
 * No execution.
 */

function resolveGovernanceIntent(string $intent, string $query): array
{
    $violations = loadCurrentViolations();

    if (empty($violations)) {
        return [
            "status" => "clean",
            "message" => "No unresolved violations detected.",
            "availableActions" => []
        ];
    }

    $violationTypes = classifyViolationTypes($violations);

    switch ($intent) {

        case "governance_inquiry":
            return [
                "status" => "violations_present",
                "violationCount" => count($violations),
                "violationTypes" => $violationTypes,
                "explanation" => buildExplanation($violations),
                "availableActions" => determineAvailableActions($violationTypes)
            ];

        case "governance_repair_request":
            return [
                "status" => "repair_possible",
                "violationTypes" => $violationTypes,
                "recommendedAction" => determineRepairPath($violationTypes),
                "availableActions" => determineAvailableActions($violationTypes)
            ];

        case "governance_execute":
            return [
                "status" => "execution_requires_confirmation",
                "warning" => "Execution requires explicit confirmation.",
                "availableActions" => determineAvailableActions($violationTypes)
            ];

        case "governance_amendment_request":
            return [
                "status" => "amendment_proposed",
                "warning" => "Formal amendment will regenerate Merkle snapshot.",
                "availableActions" => ["run_merkle_builder"]
            ];

        default:
            return [
                "status" => "unsupported_intent",
                "message" => "Intent not recognized for governance resolution."
            ];
    }
}