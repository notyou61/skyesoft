<?php
declare(strict_types=1);

/**
 * Test Harness for commitProposal.php
 * Creates a mock snapshot and calls the commit engine.
 */

session_start();
$_SESSION['contactId'] = 999; // Mock logged-in actor

// ====================== CONFIG ======================
$proposalId   = "PROP-123456";
$baseUrl      = 'http://localhost/skyesoft/api/commitProposal.php';   // ← CHANGE THIS TO YOUR ACTUAL URL
$snapshotDir  = __DIR__ . '/../data/runtimeEphemeral/proposals';
// ===================================================

if (!is_dir($snapshotDir)) {
    mkdir($snapshotDir, 0777, true);
}

// === Create Rich Mock Snapshot ===
$mockSnapshot = [
    'proposalId'        => $proposalId,
    'activitySessionId' => 'SESS-ABC-789',
    'pcm' => [
        'pc' => 'PC-1',
        'rs' => ['RS-0']
    ],
    'commitPlan' => [
        'canCommit' => true,
        'actions'   => ['insert_entity', 'insert_location', 'insert_contact']
    ],
    'data' => [
        'entity' => [
            'entityName'    => 'Test Corp',
            'entityNameRaw' => 'Test Corp, LLC'
        ],
        'location' => [
            'locationName'    => 'HQ',
            'locationAddress' => '123 Test St',
            'locationCity'    => 'Phoenix',
            'locationState'   => 'AZ',
            'locationZip'     => '85001',
            'parcelDetails'   => [['parcelNumber' => '111-22-333']]
        ],
        'contact' => [
            'contactFirstName' => 'Jane',
            'contactLastName'  => 'Doe',
            'contactEmail'     => 'jane.doe@example.com'
        ]
    ],
    'databaseResolution' => [
        'entity'   => ['entityId' => null],
        'location' => ['locationId' => null],
        'contact'  => ['contactId' => null]
    ],
    'reportArtifacts' => []
];

$snapshotPath = "{$snapshotDir}/{$proposalId}.json";
file_put_contents($snapshotPath, json_encode($mockSnapshot, JSON_PRETTY_PRINT));

echo "✓ Mock snapshot created: {$proposalId}.json\n";
echo "  Path: {$snapshotPath}\n\n";

// === Execute Commit Engine ===
echo "Calling commitProposal.php...\n";

$payload = json_encode([
    'proposalId' => $proposalId,
    'actionId'   => null
]);

$ch = curl_init($baseUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$output   = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

echo "HTTP Status: {$httpCode}\n";

if ($error) {
    echo "cURL Error: {$error}\n";
} else {
    echo "Response:\n";
    echo $output . "\n";
    
    // Pretty-print JSON response if possible
    $json = json_decode($output, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "\nFormatted Response:\n";
        print_r($json);
    }
}