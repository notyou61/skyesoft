<?php
declare(strict_types=1);

// 1. Mock the Session Context
session_start();
$_SESSION['contactId'] = 999; // Mock Actor

// 2. Generate a Mock Ephemeral Snapshot File
$proposalId = "PROP-123456";
$snapshotDir = __DIR__ . '/../data/runtimeEphemeral/proposals';
if (!is_dir($snapshotDir)) {
    mkdir($snapshotDir, 0777, true);
}

$mockSnapshot = [
    'activitySessionId' => 'SESS-ABC-789',
    'pcm' => [
        'pc' => 'PC-1',
        'rs' => ['RS-0']
    ],
    'commitPlan' => [
        'canCommit' => true,
        'actions' => ['insert_entity', 'insert_location', 'insert_contact']
    ],
    'data' => [
        'entity' => ['entityName' => 'Test Corp', 'entityNameRaw' => 'Test Corp, LLC'],
        'location' => [
            'locationName' => 'HQ',
            'locationAddress' => '123 Test St',
            'locationCity' => 'Phoenix',
            'locationState' => 'AZ',
            'locationZip' => '85001',
            'parcelDetails' => [['parcelNumber' => '111-22-333']]
        ],
        'contact' => [
            'contactFirstName' => 'Jane',
            'contactLastName' => 'Doe',
            'contactEmail' => 'jane.doe@example.com'
        ]
    ],
    'databaseResolution' => [
        'entity' => ['entityId' => null],
        'location' => ['locationId' => null],
        'contact' => ['contactId' => null]
    ],
    'reportArtifacts' => []
];

file_put_contents("{$snapshotDir}/{$proposalId}.json", json_encode($mockSnapshot));
echo "✓ Ephemeral proposal snapshot created.\n";

// 3. Execute the endpoint using an internal sub-request or curl simulation
echo "Executing commit Proposal engine...\n\n";

$ch = curl_init('http://localhost/path/to/commitProposal.php'); // Update this path to your local address
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'proposalId' => $proposalId,
    'actionId' => null
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

// Set session cookie if testing directly over local HTTP server
$cookieString = session_name() . '=' . session_id();
curl_setopt($ch, CURLOPT_COOKIE, $cookieString);

$output = curl_exec($ch);
curl_close($ch);

echo "Response from Engine:\n";
echo $output . "\n";