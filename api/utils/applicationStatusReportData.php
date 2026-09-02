<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — applicationStatusReportData.php
 *  Shared Application Status Report Summary Context
 *  Codex-Governed Shared Module • PHP 8.3
 * ===================================================================== */

// #region SECTION I — Structured AI Context

function formatApplicationStatusPayloadDate(
    mixed $unix
): ?string {
    if (!is_numeric($unix) || (int)$unix <= 0) {
        return null;
    }

    // Format authoritative date in Phoenix time
    $date = new DateTimeImmutable(
        '@' . (int)$unix
    );

    $date = $date->setTimezone(
        new DateTimeZone(
            'America/Phoenix'
        )
    );

    return $date->format(
        'F j, Y'
    );
}

function buildApplicationStatusReportPayload(
    array $application,
    int $generatedUnix
): array {
    $fees = is_array($application['applicationFees'] ?? null)
        ? $application['applicationFees']
        : [];
    $feeRows = is_array($fees['rows'] ?? null)
        ? $fees['rows']
        : [];
    $requirements = is_array(
        $application['applicationSpecialRequirements'] ?? null
    )
        ? $application['applicationSpecialRequirements']
        : [];

    // Include only active, valid Special Requirements
    $activeRequirements = array_values(array_filter(
        $requirements,
        static function (array $requirement): bool {
            return (int)($requirement[
                'applicationSpecialRequirementIsNotValid'
            ] ?? 0) === 0 &&
                (int)($requirement[
                    'applicationSpecialRequirementStatusIsClosed'
                ] ?? 0) === 0;
        }
    ));

    $requirementRows = array_map(
        static function (array $requirement): array {
            return [
                'status' => trim((string)($requirement[
                    'applicationSpecialRequirementStatusName'
                ] ?? '')),
                'description' => trim((string)($requirement[
                    'applicationSpecialRequirementDescription'
                ] ?? '')),
                'responsibleParty' => trim((string)($requirement[
                    'applicationSpecialRequirementResponsibleParty'
                ] ?? '')),
                'requiredUnix' => is_numeric($requirement[
                    'applicationSpecialRequirementRequiredUnix'
                ] ?? null)
                    ? (int)$requirement[
                        'applicationSpecialRequirementRequiredUnix'
                    ]
                    : null,
                'dueUnix' => is_numeric($requirement[
                    'applicationSpecialRequirementDueUnix'
                ] ?? null)
                    ? (int)$requirement[
                        'applicationSpecialRequirementDueUnix'
                    ]
                    : null
            ];
        },
        $activeRequirements
    );

    // Include active Fee rows only
    $activeFeeRows = array_values(array_filter(
        $feeRows,
        static function (array $fee): bool {
            return !is_numeric($fee['feeVoidedUnix'] ?? null) ||
                (int)$fee['feeVoidedUnix'] <= 0;
        }
    ));

    $normalizedFeeRows = array_map(
        static function (array $fee): array {
            $paidUnix = is_numeric($fee['feePaidUnix'] ?? null)
                ? (int)$fee['feePaidUnix']
                : null;

            return [
                'category' => trim((string)($fee['feeCategory'] ?? '')),
                'amount' => round((float)($fee['feeAmount'] ?? 0), 2),
                'description' => trim((string)($fee['feeNote'] ?? '')),
                'assessedUnix' => is_numeric(
                    $fee['feeAssessedUnix'] ?? null
                )
                    ? (int)$fee['feeAssessedUnix']
                    : null,
                'paidUnix' => $paidUnix,
                'status' => $paidUnix !== null
                    ? 'Paid'
                    : 'Outstanding'
            ];
        },
        $activeFeeRows
    );

    return [
        'schemaVersion' => '1.0.0',
        'reportType' => 'application_status',
        'audience' => 'external',
        'generatedDate' =>
        formatApplicationStatusPayloadDate(
            $generatedUnix
        ),
        'application' => [
            'title' => trim((string)(
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
            'address' => array_values(array_filter([
                trim((string)($application['locationAddress'] ?? '')),
                trim((string)($application['locationAddressSuite'] ?? '')),
                trim((string)($application['locationCity'] ?? '')),
                trim((string)($application['locationState'] ?? '')),
                trim((string)($application['locationZip'] ?? ''))
            ], static fn (string $value): bool => $value !== '')),
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
            'stageDescription' => trim((string)(
                $application['applicationStageDescription'] ?? ''
            )),
            'status' => trim((string)(
                $application['applicationStatusName'] ?? ''
            )),
            'statusDescription' => trim((string)(
                $application['applicationStatusDescription'] ?? ''
            )),
            'receivedUnix' => is_numeric(
                $application['applicationCreatedUnix'] ?? null
            )
                ? (int)$application['applicationCreatedUnix']
                : null,
            'submittedUnix' => is_numeric(
                $application['applicationSubmittedUnix'] ?? null
            )
                ? (int)$application['applicationSubmittedUnix']
                : null,
            'approvedUnix' => is_numeric(
                $application['applicationApprovedUnix'] ?? null
            )
                ? (int)$application['applicationApprovedUnix']
                : null,
            'issuedUnix' => is_numeric(
                $application['applicationIssuedUnix'] ?? null
            )
                ? (int)$application['applicationIssuedUnix']
                : null,
            'finaledUnix' => is_numeric(
                $application['applicationFinaledUnix'] ?? null
            )
                ? (int)$application['applicationFinaledUnix']
                : null,
            'updatedUnix' => is_numeric(
                $application['applicationUpdatedUnix'] ?? null
            )
                ? (int)$application['applicationUpdatedUnix']
                : null
        ],
        'specialRequirements' => [
            'activeCount' => count($requirementRows),
            'rows' => $requirementRows
        ],
        'fees' => [
            'hasFeeRecords' => count($feeRows) > 0,
            'activeRows' => $normalizedFeeRows,
            'totalAssessed' => round((float)(
                $fees['totalAssessed'] ?? 0
            ), 2),
            'totalPaid' => round((float)(
                $fees['totalPaid'] ?? 0
            ), 2),
            'totalOutstanding' => round((float)(
                $fees['totalOutstanding'] ?? 0
            ), 2)
        ]
    ];
}

function fingerprintApplicationStatusReportPayload(array $payload): string
{
    $fingerprintPayload = [
        'schemaVersion' => $payload['schemaVersion'] ?? null,
        'reportType' => $payload['reportType'] ?? null,
        'audience' => $payload['audience'] ?? null,
        'application' => $payload['application'] ?? [],
        'specialRequirements' => $payload['specialRequirements'] ?? [],
        'fees' => $payload['fees'] ?? []
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

// #region SECTION II — Deterministic Summary Fallback

function buildApplicationStatusFallbackSummary(array $payload): string
{
    $application = is_array($payload['application'] ?? null)
        ? $payload['application']
        : [];
    $requirements = is_array($payload['specialRequirements'] ?? null)
        ? $payload['specialRequirements']
        : [];
    $fees = is_array($payload['fees'] ?? null)
        ? $payload['fees']
        : [];
    $location = trim((string)($application['location'] ?? ''));
    $customer = trim((string)($application['customer'] ?? ''));
    $subject = $location !== ''
        ? $location
        : ($customer !== '' ? $customer : 'This permit application');
    $receivedUnix = is_numeric($application['receivedUnix'] ?? null)
        ? (int)$application['receivedUnix']
        : null;
    $submittedUnix = is_numeric($application['submittedUnix'] ?? null)
        ? (int)$application['submittedUnix']
        : null;
    $stage = trim((string)($application['stage'] ?? ''));
    $status = trim((string)($application['status'] ?? ''));
    $sentences = [];

    $sentences[] = $receivedUnix !== null
        ? sprintf(
            'The permit application for %s was received by Christy Signs on %s.',
            $subject,
            date('F j, Y', $receivedUnix)
        )
        : sprintf(
            'Christy Signs is coordinating the permit application for %s.',
            $subject
        );

    if ($stage !== '' || $status !== '') {
        $sentences[] = sprintf(
            'The application is currently in %s with a status of %s.',
            $stage !== '' ? $stage : 'an unspecified stage',
            $status !== '' ? $status : 'Not Available'
        );
    }

    $sentences[] = $submittedUnix !== null
        ? sprintf(
            'It was submitted to the jurisdiction on %s.',
            date('F j, Y', $submittedUnix)
        )
        : 'It has not yet been submitted to the jurisdiction.';

    $activeRequirementCount = (int)(
        $requirements['activeCount'] ?? 0
    );

    if ($activeRequirementCount > 0) {
        $sentences[] = sprintf(
            '%d active Special Requirement%s remain recorded for coordination.',
            $activeRequirementCount,
            $activeRequirementCount === 1 ? '' : 's'
        );
    }

    if ((bool)($fees['hasFeeRecords'] ?? false)) {
        $sentences[] = sprintf(
            'Active fees total $%s assessed, $%s paid, and $%s outstanding.',
            number_format((float)($fees['totalAssessed'] ?? 0), 2),
            number_format((float)($fees['totalPaid'] ?? 0), 2),
            number_format((float)($fees['totalOutstanding'] ?? 0), 2)
        );
    }

    return implode(' ', $sentences);
}

// #endregion