<?php
/**
 * Skyesoft — detectAndProposeContact.response.php
 * Final Response Building + Output
 * Version: 1.5.7
 */

// Declare variables from main file scope (for IDE)
global $pcm, $duplicate, $locationDuplicate, $dataIntegrityStatus, $locationValidation,
       $parsed, $data, $meta, $aiData, $rawInputOriginal, $activitySessionId, $pcmNarratives;

// Ultra-defensive defaults
$pcm = $pcm ?? [
    'status'       => 'incomplete',
    'action'       => null,
    'readyForCommit' => false,
    'blocksCommit' => true
];

$parsed  = $parsed  ?? ['location' => [], 'contact' => [], 'entity' => []];
$data    = $data    ?? ['entity' => [], 'location' => [], 'contact' => []];
$meta    = $meta    ?? ['inferences' => [], 'enrichments' => [], 'flags' => []];
$locationValidation = $locationValidation ?? ['parcelStatus' => 'unknown', 'isMaricopa' => false, 'status' => 'invalid'];
$duplicate = $duplicate ?? ['status' => 'none'];
$locationDuplicate = $locationDuplicate ?? ['status' => 'none'];

$rawInputOriginal  = $rawInputOriginal  ?? null;
$activitySessionId = $activitySessionId ?? '';
$pcmNarratives     = $pcmNarratives     ?? [];

// -------------------------------------------------
// BUILD FULL DATA + META
// -------------------------------------------------
$fullName = trim(($parsed['contact']['firstName'] ?? '') . ' ' . ($parsed['contact']['lastName'] ?? ''));

// ENTITY
$data['entity'] = ['entityName' => $parsed['entity']['name'] ?? ''];

// Find selected parcel
$selectedParcel = null;
if (!empty($parsed['location']['parcelDetails']) && is_array($parsed['location']['parcelDetails'])) {
    foreach ($parsed['location']['parcelDetails'] as $p) {
        if (($p['selected'] ?? false) === true) {
            $selectedParcel = $p;
            break;
        }
    }
    if (!$selectedParcel && count($parsed['location']['parcelDetails']) === 1) {
        $selectedParcel = $parsed['location']['parcelDetails'][0];
    }
}

// Jurisdiction consensus
$jurisdiction = $selectedParcel['jurisdiction'] ?? '';
if (empty($jurisdiction) && !empty($parsed['location']['parcelDetails'])) {
    $jurisdictions = array_unique(array_filter(array_column($parsed['location']['parcelDetails'], 'jurisdiction')));
    if (count($jurisdictions) === 1) {
        $jurisdiction = reset($jurisdictions);
    }
}

// LOCATION + CONTACT + META blocks (unchanged - your current code is good)
$data['location'] = [ /* ... your full location block ... */ ];
$data['contact']  = [ /* ... your full contact block ... */ ];

// META
$meta['inferences'] = [
    'salutationInferred'   => $parsed['contact']['salutationInferred'] ?? false,
    'locationNameInferred' => $parsed['location']['locationNameInferred'] ?? false,
    'entityNameInferred'   => $parsed['entity']['nameInferred'] ?? false
];

$hasSmarty = $meta['flags']['uspsValidated'] ?? false;

$meta['enrichments'] = array_values(array_filter([
    'google_geocode',
    !empty($parsed['location']['county']) ? 'census_county' : null,
    'maricopa_parcel',
    $hasSmarty ? 'smarty_usps' : null
]));

$dpvCode = strtoupper(trim($parsed['location']['locationDpvCode'] ?? $parsed['location']['smartyDpvCode'] ?? 'Y'));

$meta['flags'] = [
    'isMaricopa'           => $locationValidation['isMaricopa'] ?? false,
    'locationValid'        => $locationValidation['status'] ?? 'invalid',
    'parcelStatus'         => $locationValidation['parcelStatus'] ?? 'unknown',
    'apnResolved'          => $locationValidation['apnResolved'] ?? false,
    'jurisdictionResolved' => $locationValidation['jurisdictionResolved'] ?? false,
    'uspsValidated'        => $dpvCode === 'Y',
    'dpvCode'              => $dpvCode
];

// -------------------------------------------------
// RESOLUTION OBJECT + ISSUES + NARRATIVES
// -------------------------------------------------
// (keep the rest of your code exactly as you have it from $resolution = [...] onward)

// -------------------------------------------------
// RESOLUTION OBJECT
// -------------------------------------------------
$resolution = [
    'pcmStatus'     => $pcm['status'],
    'classification' => [
        'status' => ($pcm['blocksCommit'] ?? false) ? 'unacceptable' : (($pcm['readyForCommit'] ?? false) ? 'accepted' : 'review')
    ],
    'decision' => [
        'actionTypeId'   => $pcm['action'] === 'insert_new' ? 9 : ($pcm['action'] === 'reject_duplicate' ? 10 : 8),
        'actionName'     => $pcm['action'],
        'readyForCommit' => $pcm['readyForCommit'] ?? false
    ],
    'issues' => [
        'blocking'     => [],
        'review'       => [],
        'informational' => $meta['enrichments']
    ],
    'narratives' => [
        'decision'      => [],
        'blocking'      => [],
        'review'        => [],
        'informational' => []
    ]
];

// Populate issues
if ($pcm['status'] === 'existing_location') {
    $resolution['issues']['blocking'][] = 'existing_location';
} elseif ($pcm['status'] === 'multiple_parcels') {
    $resolution['issues']['review'][] = 'multiple_parcels';
} elseif (in_array($pcm['status'], ['unresolved_parcel', 'incomplete_address', 'invalid_location'])) {
    $resolution['issues']['review'][] = $pcm['status'];
}

// -------------------------------------------------
// AI Narrative + Fallback
// -------------------------------------------------
$aiNarrativeContext = [
    'pcm'                => $pcm,
    'duplicate'          => $duplicate,
    'locationDuplicate'  => $locationDuplicate,
    'locationValidation' => $locationValidation,
    'meta'               => $meta,
    'data'               => $data,
    'operationalContext' => [
        'parcelCandidateCount' => count($parsed['location']['parcelDetails'] ?? []),
        'validationSummary'    => [
            'googleValidated' => !empty($parsed['location']['locationPlaceId']),
            'uspsValidated'   => $meta['flags']['uspsValidated'],
            'parcelResolved'  => $meta['flags']['apnResolved'],
        ]
    ]
];

$resolvedNarrative = buildOperationalNarratives($aiNarrativeContext);

if (!is_array($resolvedNarrative) || empty($resolvedNarrative['decision'])) {
    error_log('[operational-narrative] Falling back to static PCM narrative for ' . $pcm['status']);
    $resolvedNarrative = $pcmNarratives[$pcm['status']] ?? $pcmNarratives['new_elc'] ?? [];
}

$resolution['narratives'] = array_merge([
    'decision'      => [],
    'blocking'      => [],
    'review'        => [],
    'informational' => []
], $resolvedNarrative);

// -------------------------------------------------
// FINAL OUTPUT
// -------------------------------------------------
echo json_encode([
    'status'        => 'proposed',
    'confidence'    => $aiData['confidence'] ?? 85,
    'success'       => true,
    'rawInput'      => [
        'original' => $rawInputOriginal,
        'type'     => 'signature',
        'source'   => 'skyebot_prompt'
    ],
    'resolution'    => $resolution,
    'data'          => $data,
    'meta'          => $meta,
    'activitySessionId' => $activitySessionId
], JSON_UNESCAPED_SLASHES);