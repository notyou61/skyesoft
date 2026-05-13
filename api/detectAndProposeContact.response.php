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

#region RESOLUTION OBJECT + STRONG PCM-DRIVEN NARRATIVES + PERSISTENCE

$pcmStatus = $pcm['status'] ?? 'incomplete';

error_log('[DECISION DEBUG] pcmStatus=' . ($pcm['status'] ?? 'NULL'));
error_log('[DECISION DEBUG] readyForCommit=' . var_export($pcm['readyForCommit'] ?? null, true));
error_log('[DECISION DEBUG] blocksCommit=' . var_export($pcm['blocksCommit'] ?? null, true));

$resolution = [
    'pcmStatus' => $pcmStatus,
    'classification' => [
        'status' => match($pcmStatus) {
            'existing_location' => 'accepted',
            default => ($pcm['blocksCommit'] ?? false) 
                ? 'unacceptable' 
                : (($pcm['readyForCommit'] ?? false) ? 'accepted' : 'review')
        }
    ],
    'decision' => [
        'actionTypeId'   => ($pcm['readyForCommit'] ?? false) ? 9 : 
                           (($pcm['blocksCommit'] ?? false) ? 10 : 8),
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

// Populate issues
if (in_array($pcmStatus, ['duplicate_contact'])) {
    $resolution['issues']['blocking'][] = $pcmStatus;
} elseif (in_array($pcmStatus, ['existing_location', 'multiple_parcels', 'unresolved_parcel', 'incomplete_address', 'invalid_location', 'possible_duplicate_contact', 'possible_location_duplicate', 'incomplete'])) {
    $resolution['issues']['review'][] = $pcmStatus;
}

// =====================================================
// STRONG PCM-DRIVEN HUMAN NARRATIVES (Force Override)
// =====================================================
$resolvedNarrative = [];

error_log("[narrative] Forcing PCM-driven narrative for: " . $pcmStatus);

// Always use PCM-first for known blocking / relational states
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
            'decision' => [
                'This proposal references an existing entity and a new operational location.'
            ],
            'review' => [
                'A new location and contact will be linked to the existing entity.'
            ],
            'informational' => [
                'No new entity record will be created.'
            ]
        ];
        break;

    case 'incomplete':
        $resolvedNarrative = [
            'decision'  => ['This proposal is missing required information and cannot be inserted.'],
            'blocking'  => ['Required fields such as company name, full contact identity, phone, or email were not provided.'],
            'review'    => ['Complete the missing fields before continuing.']
        ];
        break;

    case 'invalid_location':
    case 'incomplete_address':
        $resolvedNarrative = [
            'decision'      => ['The address could not be properly validated.'],
            'review'        => ['Please review and correct the address details.'],
            'informational' => ['Google/USPS validation returned errors or ambiguous results.']
        ];
        break;

    case 'unresolved_parcel':
        $resolvedNarrative = [
            'decision'      => ['We could not resolve this address to a Maricopa County parcel.'],
            'review'        => ['Please verify the address or provide additional location details.'],
            'informational' => ['Parcel lookup returned no matches.']
        ];
        break;

    case 'multiple_parcels':
        $resolvedNarrative = [
            'decision'      => ['Multiple parcels match this address.'],
            'review'        => ['Please select the correct parcel from the candidates shown.'],
            'informational' => ['User selection is required before proceeding.']
        ];
        break;

    case 'possible_duplicate_contact':
    case 'possible_location_duplicate':
        $resolvedNarrative = [
            'decision'  => ['Possible duplicate detected.'],
            'review'    => ['Please review potential matching records before continuing.'],
            'informational' => ['System found similar existing records.']
        ];
        break;

    // ==================== NEW / SUCCESS CASE ====================
    case 'new_elc':
    default:
        // Let AI generate rich operational narrative first
        $resolvedNarrative = buildOperationalNarratives($aiNarrativeContext ?? []);

        // Static fallback only if AI returned nothing useful
        if (empty($resolvedNarrative['decision'] ?? [])) {
            error_log("[narrative] AI narrative empty → using static fallback for {$pcmStatus}");
            $resolvedNarrative = $pcmNarratives['new_elc'] ?? [
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

// =====================================================
// PERSISTENCE ORCHESTRATION
// =====================================================
$persistence = [
    'entity'        => ['action' => 'none', 'entityId' => null],
    'location'      => ['action' => 'none', 'locationId' => null],
    'contact'       => ['action' => 'none', 'contactId' => null],
    'commitAllowed' => $resolution['decision']['readyForCommit'] ?? false
];

switch ($pcmStatus) {

    // =====================================================
    // PCM-01 — New Entity + New Location + New Contact
    // =====================================================
    case 'new_elc':
        $persistence['entity']['action']   = 'create';
        $persistence['location']['action'] = 'create';
        $persistence['contact']['action']  = 'create';
        break;

    // =====================================================
    // PCM-05 — Existing Location + New Contact
    // =====================================================
    case 'existing_location':
        $persistence['entity']['action']   = 'reuse';
        $persistence['entity']['entityId'] = isset($locationDuplicate['entityId'])
            ? (int)$locationDuplicate['entityId']
            : null;

        $persistence['location']['action'] = 'reuse';
        $persistence['location']['locationId'] = isset($locationDuplicate['locationId'])
            ? (int)$locationDuplicate['locationId']
            : null;

        $persistence['contact']['action'] = 'create';
        break;

    // =====================================================
    // PCM-06 — Existing Entity + New Location + New Contact
    // =====================================================
    case 'existing_entity_new_location':
        $persistence['entity']['action']   = 'reuse';
        $persistence['entity']['entityId'] = isset($entityDuplicate['entityId'])
            ? (int)$entityDuplicate['entityId']
            : null;

        $persistence['location']['action'] = 'create';
        $persistence['location']['locationId'] = null;

        $persistence['contact']['action'] = 'create';
        $persistence['contact']['contactId'] = null;

        $persistence['commitAllowed'] = true;
        break;

    // =====================================================
    // PCM-07 — Proposed Location (Location-Only)
    // =====================================================
    case 'location_only':
    case 'proposed_location':           // support both names during transition
        // Reuse Existing Entity
        $persistence['entity']['action']   = 'reuse';
        $persistence['entity']['entityId'] = isset($entityDuplicate['entityId'])
            ? (int)$entityDuplicate['entityId']
            : null;

        // Create New Location
        $persistence['location']['action'] = 'create';
        $persistence['location']['locationId'] = null;

        // Mark as Location-Only (important for downstream logic)
        $data['location']['locationIsLocationOnly'] = 1;

        // No Contact Creation
        $persistence['contact']['action']  = 'skip';
        $persistence['contact']['contactId'] = null;

        // Semantically clean output for frontend
        $data['contact'] = null;

        $persistence['commitAllowed'] = true;
        break;

    // =====================================================
    // PCM-02 — Duplicate Contact
    // =====================================================
    case 'duplicate_contact':
        $persistence['entity']['action']   = 'reuse';
        $persistence['entity']['entityId'] = isset($duplicate['entityId'])
            ? (int)$duplicate['entityId']
            : null;

        $persistence['location']['action'] = 'reuse';
        $persistence['location']['locationId'] = isset($duplicate['locationId'])
            ? (int)$duplicate['locationId']
            : null;

        $persistence['contact']['action']  = 'reject';
        $persistence['contact']['contactId'] = isset($duplicate['contactId'])
            ? (int)$duplicate['contactId']
            : null;

        $persistence['commitAllowed'] = false;
        break;

    // =====================================================
    // Rejected / Invalid Governance States
    // =====================================================
    case 'multiple_parcels':
    case 'incomplete':
    case 'invalid_location':
    case 'unresolved_parcel':
    case 'incomplete_address':
        $persistence['entity']['action']   = 'reject';
        $persistence['location']['action'] = 'reject';
        $persistence['contact']['action']  = 'reject';
        $persistence['commitAllowed']      = false;
        break;

    // Default safety net
    default:
        $persistence['commitAllowed'] = false;
        break;
}

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