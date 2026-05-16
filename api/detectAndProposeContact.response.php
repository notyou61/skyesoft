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

#region RESOLUTION OBJECT + PC/RS GOVERNANCE + PERSISTENCE

// =====================================================
// Extract PC / RS Governance State
// =====================================================

$pc         = $pcm['pc'] ?? null;
$pcStatus   = $pcm['pcStatus'] ?? 'unknown';

$rs         = $pcm['rs'] ?? [];
$rsStatuses = $pcm['rsStatuses'] ?? [];

$hasRs = function ($code) use ($rs) {
    return in_array($code, $rs, true);
};

// =====================================================
// Legacy Compatibility (Temporary)
// =====================================================

$pcmStatus = $pcStatus;

if ($hasRs('RS-1')) {
    $pcmStatus = 'incomplete';
}

if ($hasRs('RS-5')) {
    $pcmStatus = 'duplicate_contact';
}

if ($hasRs('RS-6')) {
    $pcmStatus = 'multiple_parcels';
}

if ($hasRs('RS-7')) {
    $pcmStatus = 'unresolved_parcel';
}

if ($hasRs('RS-8')) {
    $pcmStatus = 'invalid_location';
}

error_log('[DECISION DEBUG] pc=' . ($pc ?? 'null'));
error_log('[DECISION DEBUG] pcStatus=' . $pcStatus);
error_log('[DECISION DEBUG] rs=' . json_encode($rs));
error_log('[DECISION DEBUG] readyForCommit=' . var_export($pcm['readyForCommit'] ?? null, true));

// =====================================================
// 1. PERSISTENCE — PC Driven
// =====================================================

$persistence = [
    'entity' => [
        'action'   => 'none',
        'entityId' => null
    ],

    'location' => [
        'action'     => 'none',
        'locationId' => null
    ],

    'contact' => [
        'action'    => 'none',
        'contactId' => null
    ],

    'commitAllowed' => false
];

// -----------------------------------------------------
// PC-1 — New ELC
// -----------------------------------------------------

if ($pc === 'PC-1') {

    $persistence['entity']['action']   = 'create';
    $persistence['location']['action'] = 'create';
    $persistence['contact']['action']  = 'create';
}

// -----------------------------------------------------
// PC-2 — Existing Entity + New Location
// -----------------------------------------------------

elseif ($pc === 'PC-2') {

    $persistence['entity']['action']   = 'reuse';
    $persistence['location']['action'] = 'create';
    $persistence['contact']['action']  = 'create';
}

// -----------------------------------------------------
// PC-3 — Existing Location
// -----------------------------------------------------

elseif ($pc === 'PC-3') {

    $persistence['entity']['action']   = 'reuse';
    $persistence['location']['action'] = 'reuse';
    $persistence['contact']['action']  = 'create';
}

// -----------------------------------------------------
// PC-4 — Proposed Location
// -----------------------------------------------------

elseif ($pc === 'PC-4') {

    $persistence['entity']['action']   = 'reuse';
    $persistence['location']['action'] = 'create';
    $persistence['contact']['action']  = 'skip';
}

// =====================================================
// 2. RS Governance Overlays
// =====================================================

// -----------------------------------------------------
// RS-1 — Incomplete
// -----------------------------------------------------

if ($hasRs('RS-1')) {

    $persistence['entity']['action']   = 'reject';
    $persistence['location']['action'] = 'reject';
    $persistence['contact']['action']  = 'reject';
}

// -----------------------------------------------------
// RS-5 — Duplicate Contact
// -----------------------------------------------------

if ($hasRs('RS-5')) {

    $persistence['entity']['action']   = 'reject';
    $persistence['location']['action'] = 'reject';
    $persistence['contact']['action']  = 'reject';
}

// -----------------------------------------------------
// RS-6 — Multiple Parcels
// -----------------------------------------------------

if ($hasRs('RS-6')) {

    $persistence['entity']['action']   = 'hold';
    $persistence['location']['action'] = 'hold';
    $persistence['contact']['action']  = 'hold';
}

// -----------------------------------------------------
// RS-7 — Unresolved Parcel
// -----------------------------------------------------

if ($hasRs('RS-7')) {

    $persistence['entity']['action']   = 'reject';
    $persistence['location']['action'] = 'reject';
    $persistence['contact']['action']  = 'reject';
}

// -----------------------------------------------------
// RS-8 — Invalid Location
// -----------------------------------------------------

if ($hasRs('RS-8')) {

    $persistence['entity']['action']   = 'reject';
    $persistence['location']['action'] = 'reject';
    $persistence['contact']['action']  = 'reject';
}

// =====================================================
// Final Governance State
// =====================================================

$persistence['commitAllowed'] =
    $pcm['readyForCommit'] ?? false;

// =====================================================
// 3. RESOLUTION OBJECT
// =====================================================

$resolution = [

    // -------------------------------------------------
    // New PC / RS Governance Model
    // -------------------------------------------------

    'pc' => [
        'code'   => $pc,
        'status' => $pcStatus
    ],

    'rs' => [
        'codes'    => $rs,
        'statuses' => $rsStatuses
    ],

    // -------------------------------------------------
    // Legacy Compatibility (Temporary)
    // -------------------------------------------------

    'pcmStatus' => $pcmStatus,

    // -------------------------------------------------
    // Classification
    // -------------------------------------------------

    'classification' => [
        'status' => ($pcm['readyForCommit'] ?? false)
            ? 'accepted'
            : 'unacceptable'
    ],

    // -------------------------------------------------
    // Decision
    // -------------------------------------------------

    'decision' => [
        'actionTypeId' => $persistence['commitAllowed']
            ? 9
            : 10,

        'actionName' => $pcm['action'] ?? 'resolve',

        'readyForCommit' =>
            $persistence['commitAllowed']
    ],

    // -------------------------------------------------
    // Issues
    // -------------------------------------------------

    'issues' => [
        'blocking'      => [],
        'review'        => [],
        'informational' => $meta['enrichments'] ?? []
    ],

    // -------------------------------------------------
    // Narratives
    // -------------------------------------------------

    'narratives' => [
        'decision'      => [],
        'blocking'      => [],
        'review'        => [],
        'informational' => []
    ]
];

// =====================================================
// 4. PC Narratives
// =====================================================

switch ($pc) {

    case 'PC-1':

        $resolution['narratives']['decision'][] =
            'This proposal represents a new entity, location, and contact.';
        break;

    case 'PC-2':

        $resolution['narratives']['decision'][] =
            'This proposal references an existing entity and a new operational location.';
        break;

    case 'PC-3':

        $resolution['narratives']['decision'][] =
            'This proposal references an existing operational location.';
        break;

    case 'PC-4':

        $resolution['narratives']['decision'][] =
            'This proposal represents a location-only operational intake.';
        break;
}

// =====================================================
// 5. RS Narratives
// =====================================================

// RS-0 — Acceptable
if ($hasRs('RS-0')) {

    $resolution['narratives']['informational'][] =
        'No governance issues were detected.';
}

// RS-1 — Incomplete
if ($hasRs('RS-1')) {

    $resolution['issues']['review'][] = 'incomplete';

    $resolution['narratives']['blocking'][] =
        'Required information is incomplete or missing.';
}

// RS-5 — Duplicate
if ($hasRs('RS-5')) {

    $resolution['issues']['blocking'][] =
        'duplicate_contact';

    $resolution['narratives']['blocking'][] =
        'An exact duplicate contact already exists.';
}

// RS-6 — Multiple Parcels
if ($hasRs('RS-6')) {

    $resolution['issues']['review'][] =
        'multiple_parcels';

    $resolution['narratives']['review'][] =
        'Multiple parcel candidates were found and user selection is required.';
}

// RS-7 — Unresolved Parcel
if ($hasRs('RS-7')) {

    $resolution['issues']['blocking'][] =
        'unresolved_parcel';

    $resolution['narratives']['blocking'][] =
        'Parcel resolution failed and commit is blocked.';
}

// RS-8 — Invalid Location
if ($hasRs('RS-8')) {

    $resolution['issues']['blocking'][] =
        'invalid_location';

    $resolution['narratives']['blocking'][] =
        'The proposed location could not be validated.';
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