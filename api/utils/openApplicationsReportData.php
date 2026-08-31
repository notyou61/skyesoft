<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — openApplicationsReportData.php
 *  Shared Open Application Report Data & Summary Context
 *  Codex-Governed Shared Module • PHP 8.3
 * ===================================================================== */

// #region SECTION I — Authoritative Data Loader

function loadOpenApplicationsReportData(PDO $db): array
{
    $applicationsStmt = $db->prepare("
        SELECT
            a.applicationID,
            a.applicationTitle,
            a.applicationJurisdiction,
            a.applicationNumber,
            a.applicationPermitNumber,
            a.applicationScope,
            a.applicationSubmittedUnix,
            a.applicationApprovedUnix,
            a.applicationIssuedUnix,
            a.applicationFinaledUnix,
            a.applicationCreatedUnix,
            a.applicationUpdatedUnix,
            o.orderID,
            o.orderChristyNumber,
            e.entityName,
            l.locationName,
            l.locationAddress,
            l.locationAddressSuite,
            l.locationCity,
            l.locationState,
            l.locationZip,
            s.applicationStageName,
            st.applicationStatusName,
            st.applicationStatusDescription
        FROM tblApplications a
        INNER JOIN tblOrders o
            ON o.orderID = a.applicationOrderID
        INNER JOIN tblEntities e
            ON e.entityId = a.applicationEntityID
        INNER JOIN tblLocations l
            ON l.locationId = a.applicationLocationID
        INNER JOIN tblApplicationStages s
            ON s.applicationStageID = a.applicationStageID
        INNER JOIN tblApplicationStatuses st
            ON st.applicationStageID = a.applicationStageID
           AND st.applicationStatusID = a.applicationStatusID
        WHERE a.applicationIsNotValid = 0
          AND a.applicationStageID <> 6
        ORDER BY
            a.applicationCreatedUnix ASC,
            a.applicationID ASC
    ");
    $applicationsStmt->execute();
    $applications = $applicationsStmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($applications) ? $applications : [];
}

// #endregion

// #region SECTION II — Structured AI Context

function buildOpenApplicationsReportPayload(
    array $applications,
    int $generatedUnix
): array {
    $applicationRows = array_map(
        static function (array $application): array {
            return [
                'applicationID' => (int)$application['applicationID'],
                'applicationTitle' => trim((string)(
                    $application['applicationTitle'] ?? ''
                )),
                'workOrderNumber' => trim((string)(
                    $application['orderChristyNumber'] ?? ''
                )),
                'customer' => trim((string)(
                    $application['entityName'] ?? ''
                )),
                'location' => trim((string)(
                    $application['locationName'] ?? ''
                )),
                'jurisdiction' => trim((string)(
                    $application['applicationJurisdiction'] ?? ''
                )),
                'jurisdictionApplicationNumber' => trim((string)(
                    $application['applicationNumber'] ?? ''
                )),
                'permitNumber' => trim((string)(
                    $application['applicationPermitNumber'] ?? ''
                )),
                'scope' => trim((string)(
                    $application['applicationScope'] ?? ''
                )),
                'stage' => trim((string)(
                    $application['applicationStageName'] ?? ''
                )),
                'status' => trim((string)(
                    $application['applicationStatusName'] ?? ''
                )),
                'statusDescription' => trim((string)(
                    $application['applicationStatusDescription'] ?? ''
                )),
                'createdUnix' => is_numeric(
                    $application['applicationCreatedUnix'] ?? null
                ) ? (int)$application['applicationCreatedUnix'] : null,
                'submittedUnix' => is_numeric(
                    $application['applicationSubmittedUnix'] ?? null
                ) ? (int)$application['applicationSubmittedUnix'] : null,
                'approvedUnix' => is_numeric(
                    $application['applicationApprovedUnix'] ?? null
                ) ? (int)$application['applicationApprovedUnix'] : null,
                'issuedUnix' => is_numeric(
                    $application['applicationIssuedUnix'] ?? null
                ) ? (int)$application['applicationIssuedUnix'] : null
            ];
        },
        $applications
    );

    return [
        'schemaVersion' => '1.0.0',
        'reportType' => 'open_applications_status',
        'audience' => 'internal_operations',
        'generatedUnix' => $generatedUnix,
        'applicationCount' => count($applicationRows),
        'sortOrder' => 'applicationCreatedUnix.asc',
        'applications' => $applicationRows
    ];
}

function fingerprintOpenApplicationsReportPayload(array $payload): string
{
    $fingerprintPayload = [
        'schemaVersion' => $payload['schemaVersion'] ?? null,
        'reportType' => $payload['reportType'] ?? null,
        'applicationCount' => $payload['applicationCount'] ?? 0,
        'sortOrder' => $payload['sortOrder'] ?? null,
        'applications' => $payload['applications'] ?? []
    ];
    $encodedPayload = json_encode(
        $fingerprintPayload,
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    return hash(
        'sha256',
        $encodedPayload !== false ? $encodedPayload : '{}'
    );
}

// #endregion

// #region SECTION III — Deterministic Summary Fallback

function buildOpenApplicationsFallbackSummary(array $payload): string
{
    $applications = is_array($payload['applications'] ?? null)
        ? $payload['applications']
        : [];
    $applicationCount = count($applications);

    if ($applicationCount === 0) {
        return 'No open permit Applications are currently recorded. This internal report contains no Applications awaiting preparation, jurisdiction processing, issuance, or inspection activity.';
    }

    $stageCounts = [];

    foreach ($applications as $application) {
        $stageName = trim((string)($application['stage'] ?? ''));
        $stageName = $stageName !== '' ? $stageName : 'Unspecified Stage';
        $stageCounts[$stageName] = ($stageCounts[$stageName] ?? 0) + 1;
    }

    $stageParts = [];

    foreach ($stageCounts as $stageName => $stageCount) {
        $stageParts[] = sprintf('%d in %s', $stageCount, $stageName);
    }

    return sprintf(
        'This internal report summarizes %d open permit Application%s, ordered by creation date from oldest to newest. Current lifecycle distribution: %s. Review each Application block for its scope, jurisdiction identifiers, current status, and recorded milestone dates.',
        $applicationCount,
        $applicationCount === 1 ? '' : 's',
        implode('; ', $stageParts)
    );
}

// #endregion