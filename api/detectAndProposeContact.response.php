<?php
/**
 * Skyesoft — detectAndProposeContact.response.php
 * Final Response Building + Output
 * Version: 1.5.7
 */

// =====================================================
// GLOBAL DECLARATIONS + DEFENSIVE DEFAULTS
// =====================================================

#region GLOBAL SCOPE & DEFAULTS

global $pcm, $duplicate, $locationDuplicate, $dataIntegrityStatus, $locationValidation,
       $parsed, $data, $meta, $aiData, $rawInputOriginal, $activitySessionId, $pcmNarratives;

// === ULTRA-DEFENSIVE DEFAULTS ===
$pcm = $pcm ?? ['status' => 'incomplete', 'action' => null, 'readyForCommit' => false, 'blocksCommit' => true];
$parsed = $parsed ?? ['entity' => [], 'contact' => [], 'location' => []];
$data = $data ?? ['entity' => [], 'location' => [], 'contact' => []];
$meta = $meta ?? ['inferences' => [], 'enrichments' => [], 'flags' => []];
$locationValidation = $locationValidation ?? ['status' => 'invalid', 'parcelStatus' => 'unknown', 'isMaricopa' => false];
$duplicate = $duplicate ?? ['status' => 'none'];
$locationDuplicate = $locationDuplicate ?? ['status' => 'none'];

$rawInputOriginal = $rawInputOriginal ?? null;
$activitySessionId = $activitySessionId ?? '';
$pcmNarratives = $pcmNarratives ?? [];

#endregion

// =====================================================
// BUILD CORE DATA OBJECTS
// =====================================================

#region BUILD ENTITY + CONTACT

$data['entity'] = ['entityName' => $parsed['entity']['name'] ?? ''];

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

#endregion

#region BUILD LOCATION + PARCEL RESOLUTION

// Selected parcel logic
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

$data['location'] = [
    'locationName'         => $parsed['location']['locationName'] ?? '',
    'locationPlaceId'      => $parsed['location']['locationPlaceId'] ?? null,
    'locationLatitude'     => $parsed['location']['latitude'] ?? null,
    'locationLongitude'    => $parsed['location']['longitude'] ?? null,
    'locationAddress'      => $parsed['location']['address'] ?? '',
    'locationAddressSuite' => $parsed['location']['locationAddressSuite'] ?? '',
    'locationCity'         => $parsed['location']['city'] ?? '',
    'locationState'        => $parsed['location']['state'] ?? '',
    'locationZip'          => $parsed['location']['zip'] ?? '',
    'locationCounty'       => $parsed['location']['county'] ?? '',
    'locationCountyFips'   => $parsed['location']['countyFips'] ?? '',
    'locationJurisdiction' => $parsed['location']['locationJurisdiction'] 
                           ?? $parsed['location']['jurisdiction'] 
                           ?? ($selectedParcel['jurisdiction'] ?? ''),

    'parcelDetails' => $parsed['location']['parcelDetails'] ?? [],
    'parcelResolution' => [
        'status'                => $locationValidation['parcelStatus'] ?? 'unknown',
        'requiresUserSelection' => ($locationValidation['parcelStatus'] ?? '') === 'multiple_matches',
        'selectedApn'           => $selectedParcel['apnRaw'] ?? null,
        'candidateCount'        => count($parsed['location']['parcelDetails'] ?? []),
        'resolutionMethod'      => $selectedParcel ? ($selectedParcel['resolutionSource'] ?? 'automatic') : 'automatic',
        'bestMatchConfidence'   => $selectedParcel['confidence'] ?? null
    ],

    'locationIsBilling'  => 0,
    'locationNote'       => '',
    'locationZone'       => '',
    'locationIsNotValid' => 0
];

#endregion

#region BUILD META + ENRICHMENTS

$meta['inferences'] = [
    'salutationInferred'   => $parsed['contact']['salutationInferred'] ?? false,
    'locationNameInferred' => $parsed['location']['locationNameInferred'] ?? false,
    'entityNameInferred'   => $parsed['entity']['nameInferred'] ?? false
];

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

$meta['enrichments'] = array_values(array_filter([
    'google_geocode',
    !empty($parsed['location']['county']) ? 'census_county' : null,
    'maricopa_parcel',
    ($meta['flags']['uspsValidated'] ?? false) ? 'smarty_usps' : null
]));

#endregion

#region RESOLUTION OBJECT + NARRATIVES

$pcmStatus = $pcm['status'] ?? 'incomplete';

$resolution = [
    'pcmStatus' => $pcmStatus,
    'classification' => [
        'status' => ($pcm['blocksCommit'] ?? false) 
            ? 'unacceptable' 
            : (($pcm['readyForCommit'] ?? false) ? 'accepted' : 'review')
    ],
    'decision' => [
        'actionTypeId'   => match($pcm['action'] ?? '') {
            'insert_new'       => 9,
            'reject_duplicate' => 10,
            default            => 8
        },
        'actionName'     => $pcm['action'] ?? null,
        'readyForCommit' => $pcm['readyForCommit'] ?? false
    ],
    'issues' => [
        'blocking'      => [],
        'review'        => [],
        'informational' => $meta['enrichments'] ?? []
    ],
    'narratives' => [
        'decision'      => [],
        'blocking'      => [],
        'review'        => [],
        'informational' => []
    ]
];

// Populate issues based on PCM
if ($pcmStatus === 'existing_location') {
    $resolution['issues']['blocking'][] = 'existing_location';
} elseif ($pcmStatus === 'multiple_parcels') {
    $resolution['issues']['review'][] = 'multiple_parcels';
} elseif (in_array($pcmStatus, ['unresolved_parcel', 'incomplete_address', 'invalid_location', 'duplicate_contact'])) {
    $resolution['issues']['review'][] = $pcmStatus;   // or 'blocking' for hard rejects
}

// -------------------------------------------------
// AI Narrative + Strong PCM-Aware Fallback
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
            'uspsValidated'   => $meta['flags']['uspsValidated'] ?? false,
            'parcelResolved'  => $meta['flags']['apnResolved'] ?? false,
        ]
    ]
];

$resolvedNarrative = buildOperationalNarratives($aiNarrativeContext);

if (!is_array($resolvedNarrative) || empty($resolvedNarrative['decision'] ?? [])) {
    error_log("[operational-narrative] Falling back to static for pcmStatus: {$pcmStatus}");
    
    // Strong PCM-driven static fallbacks
    $staticNarrative = $pcmNarratives[$pcmStatus] ?? $pcmNarratives['new_elc'] ?? [];
    
    // Override for duplicate cases to prevent optimistic language
    if (in_array($pcmStatus, ['duplicate_contact', 'existing_location'])) {
        $staticNarrative = [
            'decision' => ["A duplicate contact was detected. Proposal rejected by governance rules."],
            'blocking' => ["An existing matching contact already exists in the system."],
            'review'   => ["Review the existing contact record before proceeding."]
        ];
    }
    
    $resolvedNarrative = $staticNarrative;
}

$resolution['narratives'] = array_merge([
    'decision'      => [],
    'blocking'      => [],
    'review'        => [],
    'informational' => []
], $resolvedNarrative);

#endregion

#region FINAL OUTPUT

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

#endregion