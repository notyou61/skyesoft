<?php
declare(strict_types=1);

/*
 |=====================================================================
 |  Skyesoft — getKPI.php
 |  Tier-2 KPI Data Provider (Codex Compliant)
 |  Provides normalized KPI blocks for the OfficeBoard KPI card.
 |=====================================================================
*/

header("Content-Type: application/json");
header("Cache-Control: no-cache");

// -------------------------------------------------------
// REQUIRED FILES
// -------------------------------------------------------
$root = dirname(__DIR__);
$kpiPath = $root . "/assets/data/kpi.json";

if (!file_exists($kpiPath)) {
    echo json_encode([
        "success" => false,
        "error"   => "❌ Missing KPI dataset (assets/data/kpi.json)."
    ]);
    exit;
}

$kpi = json_decode(file_get_contents($kpiPath), true);
if (!is_array($kpi)) {
    echo json_encode([
        "success" => false,
        "error"   => "❌ Invalid KPI JSON."
    ]);
    exit;
}

// -------------------------------------------------------
// NORMALIZATION (Codex: deterministic keys only)
// -------------------------------------------------------
function normalizeBlock(array $block, array $schema): array {
    $out = [];
    foreach ($schema as $key => $default) {
        $out[$key] = $block[$key] ?? $default;
    }
    return $out;
}

$schemaSales = [
    "newLeadsToday"       => 0,
    "quotesSentToday"     => 0,
    "quotesPending"       => 0,
    "jobsWonToday"        => 0,
    "jobsWonWeek"         => 0,
    "openQuotesValue"     => 0,
    "closedSalesMonthToDate" => 0,
    "depositsCollectedToday" => 0,
    "depositsPending"        => 0
];

$schemaOps = [
    "installsScheduledToday" => 0,
    "installsScheduledWeek"  => 0,
    "serviceCallsOpen"       => 0,
    "jobsCompletedToday"     => 0
];

$schemaPermits = [
    "totalActive" => 0,
    "awaitingPayment" => 0,
    "inReview" => 0,
    "readyToIssue" => 0,
    "issuedToday" => 0
];

$schemaFinancial = [
    "revenueWeekToDate"  => 0,
    "revenueMonthToDate" => 0,
    "pendingDeposits"    => 0
];

$schemaProduction = [
    "jobsInShop"            => 0,
    "rushOrders"            => 0,
    "fabricationBacklogDays"=> 0
];

// -------------------------------------------------------
// BUILD NORMALIZED RESPONSE
// -------------------------------------------------------
$response = [
    "success" => true,
    "meta"    => [
        "version"      => $kpi["meta"]["version"] ?? "unknown",
        "lastUpdated"  => $kpi["meta"]["lastUpdated"] ?? null,
        "source"       => $kpi["meta"]["source"] ?? "unknown"
    ],
    "sales"      => normalizeBlock($kpi["sales"] ?? [], $schemaSales),
    "operations" => normalizeBlock($kpi["operations"] ?? [], $schemaOps),
    "permits"    => normalizeBlock($kpi["permits"] ?? [], $schemaPermits),
    "financial"  => normalizeBlock($kpi["financial"] ?? [], $schemaFinancial),
    "production" => normalizeBlock($kpi["production"] ?? [], $schemaProduction)
];

echo json_encode($response, JSON_UNESCAPED_SLASHES);
exit;
