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

// Determine authoritative resolution method
$parcelStatus = $locationValidation['parcelStatus'] ?? 'unknown';
$resolutionMethod = match ($parcelStatus) {
    'resolved'          => 'automatic',
    'multiple_matches'  => 'user_selection_required',
    'not_found'         => 'not_resolved',
    default             => 'unresolved'
};

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
        'status'                => $parcelStatus,
        'requiresUserSelection' => $parcelStatus === 'multiple_matches',
        'selectedApn'           => $selectedParcel['apnRaw'] ?? null,
        'candidateCount'        => count($parsed['location']['parcelDetails'] ?? []),
        'resolutionMethod'      => $resolutionMethod,
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

#region RESOLUTION OBJECT + STRONG PCM-DRIVEN NARRATIVES

$pcmStatus = $pcm['status'] ?? 'incomplete';

$resolution = [
    'pcmStatus' => $pcmStatus,
    'classification' => [
        'status' => match($pcmStatus) {
            'existing_location' => 'accepted',           // Relational success - allow linking
            default => ($pcm['blocksCommit'] ?? false) 
                ? 'unacceptable' 
                : (($pcm['readyForCommit'] ?? false) ? 'accepted' : 'review')
        }
    ],
    'decision' => [
        'actionTypeId'   => match($pcm['action'] ?? '') {
            'insert_new'       => 9,
            'reject_duplicate' => 10,
            default            => 8
        },
        'actionName'     => $pcm['action'] ?? null,
        'readyForCommit' => match($pcmStatus) {
            'existing_location' => true,                 // Allow commit for linking
            default             => $pcm['readyForCommit'] ?? false
        }
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

// Populate issues
if (in_array($pcmStatus, ['duplicate_contact'])) {
    $resolution['issues']['blocking'][] = $pcmStatus;
} elseif (in_array($pcmStatus, ['existing_location', 'multiple_parcels', 'unresolved_parcel', 'incomplete_address', 'invalid_location', 'possible_duplicate_contact', 'possible_location_duplicate', 'incomplete'])) {
    $resolution['issues']['review'][] = $pcmStatus;
}

// =====================================================
// STRONG PCM-DRIVEN HUMAN NARRATIVES
// =====================================================
$resolvedNarrative = buildOperationalNarratives($aiNarrativeContext ?? []);

if (!is_array($resolvedNarrative) || empty($resolvedNarrative['decision'] ?? [])) {
    error_log("[narrative] PCM-driven static fallback for: {$pcmStatus}");

    switch ($pcmStatus) {
        case 'duplicate_contact':
            $resolvedNarrative = [
                'decision' => ['This proposed contact is a duplicate and cannot be accepted.'],
                'blocking' => ['A contact with matching name, phone, and/or email already exists in the system.'],
                'review'   => ['Review the existing contact record before proceeding.']
            ];
            break;

        case 'existing_location':
            $resolvedNarrative = [
                'decision' => ['This proposal references an existing location record.'],
                'review'   => ['This new contact will be linked to the existing location.']
            ];
            break;

        case 'incomplete':
            $resolvedNarrative = [
                'decision' => ['This proposal is missing required information and cannot be inserted.'],
                'blocking' => ['Required fields such as company name, full contact identity, phone, or email were not provided.'],
                'review'   => ['Complete the missing fields before continuing.']
            ];
            break;

        case 'invalid_location':
            $resolvedNarrative = [
                'decision' => ['The proposed address could not be validated.'],
                'blocking' => ['The proposal cannot proceed until the location is corrected.'],
                'review'   => ['Provide a valid street address.']
            ];
            break;

        case 'incomplete_address':
            $resolvedNarrative = [
                'decision' => ['The proposal contains an incomplete or overly vague address.'],
                'blocking' => ['A complete street address is required.'],
                'review'   => ['Provide a full street address for this location.']
            ];
            break;

        case 'multiple_parcels':
            $resolvedNarrative = [
                'decision' => ['Multiple parcel records were identified for this address.'],
                'review'   => ['Select the correct parcel before continuing.']
            ];
            break;

        case 'unresolved_parcel':
            $resolvedNarrative = [
                'decision' => ['This proposal has a valid address but could not resolve an authoritative parcel record.'],
                'blocking' => ['Authoritative parcel resolution is required before this proposal can proceed.'],
                'review'   => ['Operator review is required to verify or manually resolve the parcel.']
            ];
            break;

        case 'new_elc':
        default:
            $resolvedNarrative = [
                'decision' => ['The proposal is eligible for insertion as a new entity, location, and contact.'],
                'informational' => [
                    'The address was successfully validated and linked to a Maricopa County parcel.',
                    'Parcel resolution completed automatically.'
                ]
            ];
            break;
    }
}

$resolution['narratives'] = array_merge([
    'decision' => [], 
    'blocking' => [], 
    'review'   => [], 
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