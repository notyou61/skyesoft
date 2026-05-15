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

#region RESOLUTION OBJECT + STRONG PCM-DRIVEN NARRATIVES + PERSISTENCE SYNCHRONIZATION

$pcmStatus = $pcm['status'] ?? 'incomplete';

error_log('[DECISION DEBUG] pcmStatus=' . $pcmStatus);
error_log('[DECISION DEBUG] readyForCommit=' . var_export($pcm['readyForCommit'] ?? null, true));

// =====================================================
// 1. PERSISTENCE — Authoritative from PCM Status
// =====================================================
$persistence = [
    'entity'        => ['action' => 'none', 'entityId' => null],
    'location'      => ['action' => 'none', 'locationId' => null],
    'contact'       => ['action' => 'none', 'contactId' => null],
    'commitAllowed' => $pcm['readyForCommit'] ?? false
];

switch ($pcmStatus) {
    case 'new_elc':
        $persistence['entity']['action']   = 'create';
        $persistence['location']['action'] = 'create';
        $persistence['contact']['action']  = 'create';
        break;

    case 'existing_entity_new_location':
        $persistence['entity']['action']   = 'reuse';
        $persistence['location']['action'] = 'create';
        $persistence['contact']['action']  = 'create';
        break;

    case 'existing_location':
        $persistence['entity']['action']   = 'reuse';
        $persistence['location']['action'] = 'reuse';
        $persistence['contact']['action']  = 'create';
        break;

    case 'proposed_location':
    case 'location_only':
        $persistence['entity']['action']   = 'reuse';
        $persistence['location']['action'] = 'create';
        $persistence['contact']['action']  = 'skip';
        break;

    case 'duplicate_contact':
    case 'incomplete':
    case 'invalid_location':
    case 'incomplete_address':
    case 'unresolved_parcel':
    case 'multiple_parcels':
        $persistence['entity']['action']   = 'reject';
        $persistence['location']['action'] = 'reject';
        $persistence['contact']['action']  = 'reject';
        $persistence['commitAllowed']      = false;
        break;

    default:
        // Fallback for possible duplicates etc.
        $persistence['commitAllowed'] = $pcm['readyForCommit'] ?? false;
        break;
}

// =====================================================
// 2. RESOLUTION OBJECT
// =====================================================
$resolution = [
    'pcmStatus' => $pcmStatus,
    'classification' => [
        'status' => in_array($pcmStatus, ['existing_location', 'existing_entity_new_location', 'proposed_location', 'new_elc'], true) 
            ? 'accepted' 
            : ($persistence['commitAllowed'] ? 'accepted' : 'unacceptable')
    ],
    'decision' => [
        'actionTypeId'   => $persistence['commitAllowed'] ? 9 : 10,
        'actionName'     => $pcm['action'] ?? 'resolve',
        'readyForCommit' => $persistence['commitAllowed']
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
if ($pcmStatus === 'duplicate_contact') {
    $resolution['issues']['blocking'][] = $pcmStatus;
} elseif (in_array($pcmStatus, ['incomplete', 'invalid_location', 'incomplete_address', 'unresolved_parcel', 'multiple_parcels'])) {
    $resolution['issues']['review'][] = $pcmStatus;
}

// =====================================================
// 3. STRONG PCM-DRIVEN NARRATIVES
// =====================================================
$resolvedNarrative = [];

switch ($pcmStatus) {

    case 'duplicate_contact':
        $resolvedNarrative = [
            'decision'  => ['This proposed contact is a duplicate and cannot be accepted.'],
            'blocking'  => ['A contact with matching name, phone, and/or email already exists in the system.'],
            'review'    => ['Review the existing contact record before proceeding.']
        ];
        break;

    case 'existing_location':
        $resolvedNarrative = [
            'decision'      => ['This proposal references an existing entity and location record.'],
            'review'        => ['A new contact will be linked to the existing location.'],
            'informational' => ['No new entity or location record will be created.']
        ];
        break;

    case 'existing_entity_new_location':
        $resolvedNarrative = [
            'decision' => ['This proposal references an existing entity and a new operational location.'],
            'review'   => ['A new location and contact will be linked to the existing entity.'],
            'informational' => ['No new entity record will be created.']
        ];
        break;

    case 'proposed_location':
    case 'location_only':
        $resolvedNarrative = [
            'decision' => [
                'The proposal is operationally eligible for insertion as a new location associated with an existing entity.'
            ],
            'informational' => [
                'The submitted address was successfully geocoded and associated with a resolved Maricopa County parcel.',
                'A single parcel candidate was identified and automatically selected.',
                'All current operational validation requirements were satisfied.',
                'No contact relationship will be created for this proposal.'
            ]
        ];
        break;

    case 'unresolved_parcel':
        $resolvedNarrative = [
            'decision' => ['We could not resolve this address to a Maricopa County parcel.'],
            'review'   => ['Please verify the address or provide additional details such as an APN, lot number, or cross street.'],
            'informational' => ['Google and USPS validation succeeded.', 'Parcel lookup returned no matches.']
        ];
        break;

    case 'multiple_parcels':
        $resolvedNarrative = [
            'decision'      => ['Multiple parcels match this address.'],
            'review'        => ['Please select the correct parcel from the candidates shown.'],
            'informational' => ['User selection is required before proceeding.']
        ];
        break;

    case 'incomplete':
        $resolvedNarrative = [
            'decision'  => ['This proposal is missing required information and cannot be inserted.'],
            'blocking'  => ['Required fields such as company name, full contact identity, phone, or email were not provided.'],
            'review'    => ['Complete the missing fields before continuing.']
        ];
        break;

    // Default / new_elc
    default:
        $resolvedNarrative = buildOperationalNarratives($aiNarrativeContext ?? []);
        if (empty($resolvedNarrative['decision'] ?? [])) {
            $resolvedNarrative = [
                'decision'      => ['The proposal is eligible for insertion as a new entity, location, and contact.'],
                'informational' => ['The address was successfully validated and linked to a Maricopa County parcel.']
            ];
        }
        break;
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
    'persistence'   => $persistence,
    'data'          => $data,
    'meta'          => $meta,
    'activitySessionId' => $activitySessionId
], JSON_UNESCAPED_SLASHES);

#endregion