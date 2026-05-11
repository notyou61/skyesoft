<?php
/**
 * Skyesoft — detectAndProposeContact.response.php
 * Final Response Building + Output
 * Version: 1.5.7
 */

// Declare variables from main file scope (for IDE)
global $pcm, $duplicate, $locationDuplicate, $dataIntegrityStatus, $locationValidation,
       $parsed, $data, $meta, $aiData, $rawInputOriginal, $activitySessionId, $pcmNarratives;

// -------------------------------------------------
// BUILD FULL DATA + META
// -------------------------------------------------
$fullName = trim(($parsed['contact']['firstName'] ?? '') . ' ' . ($parsed['contact']['lastName'] ?? ''));

// ENTITY
$data['entity'] = [
    'entityName' => $parsed['entity']['name'] ?? ''
];

// Find selected parcel
$selectedParcel = null;
if (!empty($parsed['location']['parcelDetails'])) {
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

// Jurisdiction consensus for multiple parcels
$jurisdiction = $selectedParcel['jurisdiction'] ?? '';
if (empty($jurisdiction) && !empty($parsed['location']['parcelDetails'])) {
    $jurisdictions = array_unique(array_filter(array_column($parsed['location']['parcelDetails'], 'jurisdiction')));
    if (count($jurisdictions) === 1) {
        $jurisdiction = reset($jurisdictions);
    }
}

// LOCATION
$data['location'] = [
    'locationName'            => $parsed['location']['locationName'] ?? '',
    'locationPlaceId'         => $parsed['location']['locationPlaceId'] ?? null,
    'locationLatitude'        => $parsed['location']['latitude'] ?? null,
    'locationLongitude'       => $parsed['location']['longitude'] ?? null,
    'locationAddress'         => $parsed['location']['address'] ?? '',
    'locationAddressSuite'    => $parsed['location']['locationAddressSuite'] ?? '',
    'locationCity'            => $parsed['location']['city'] ?? '',
    'locationState'           => $parsed['location']['state'] ?? '',
    'locationZip'             => $parsed['location']['zip'] ?? '',
    'locationCounty'          => $parsed['location']['county'] ?? '',
    'locationCountyFips'      => $parsed['location']['countyFips'] ?? '',
    'locationJurisdiction'    => $parsed['location']['locationJurisdiction']
                                ?? $parsed['location']['jurisdiction']
                                ?? $jurisdiction,

    'parcelDetails'           => $parsed['location']['parcelDetails'] ?? [],
    'parcelResolution' => [
        'status'                => $locationValidation['parcelStatus'] ?? 'unknown',
        'requiresUserSelection' => ($locationValidation['parcelStatus'] ?? '') === 'multiple_matches',
        'selectedApn'           => $selectedParcel['apnRaw'] ?? null,
        'candidateCount'        => count($parsed['location']['parcelDetails'] ?? []),
        'resolutionMethod'      => $selectedParcel ? ($selectedParcel['resolutionSource'] ?? 'automatic') : 'automatic',
        'bestMatchConfidence'   => $selectedParcel['confidence'] ?? null
    ],

    'locationIsBilling'   => 0,
    'locationNote'        => '',
    'locationZone'        => '',
    'locationIsNotValid'  => 0
];

// CONTACT
$data['contact'] = [
    'contactSalutation'            => $parsed['contact']['salutation'] ?? '',
    'contactFirstName'             => $parsed['contact']['firstName'] ?? '',
    'contactLastName'              => $parsed['contact']['lastName'] ?? '',
    'contactTitle'                 => $parsed['contact']['title'] ?? '',
    'contactIsBilling'             => 0,
    'contactPrimaryPhone'          => $parsed['contact']['primaryPhone'] ?? '',
    'contactPrimaryPhoneRaw'       => $parsed['contact']['primaryPhoneRaw'] ?? '',
    'contactPrimaryPhoneExtension' => $parsed['contact']['primaryPhoneExtension'] ?? '',
    'contactSecondaryPhone'        => $parsed['contact']['secondaryPhone'] ?? '',
    'contactSecondaryPhoneRaw'     => $parsed['contact']['secondaryPhoneRaw'] ?? '',
    'contactEmail'                 => $parsed['contact']['email'] ?? '',
    'contactEmailNormalized'       => $parsed['contact']['emailNormalized'] ?? '',
    'contactEmailConfirmed'        => 0,
    'contactNote'                  => '',
    'contactIsNotValid'            => 0,
    'isActive'                     => 1
];

// META — Authoritative + Correct Order
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