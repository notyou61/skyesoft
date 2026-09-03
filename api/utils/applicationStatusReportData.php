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

function buildApplicationStatusMilestoneReview(
    array $application
): array {
    // Define expected Application lifecycle order
    $milestones = [
        [
            'label' => 'Received',
            'unix' => $application[
                'applicationCreatedUnix'
            ] ?? null
        ],
        [
            'label' => 'Submitted',
            'unix' => $application[
                'applicationSubmittedUnix'
            ] ?? null
        ],
        [
            'label' => 'Approved',
            'unix' => $application[
                'applicationApprovedUnix'
            ] ?? null
        ],
        [
            'label' => 'Issued',
            'unix' => $application[
                'applicationIssuedUnix'
            ] ?? null
        ],
        [
            'label' => 'Finaled',
            'unix' => $application[
                'applicationFinaledUnix'
            ] ?? null
        ]
    ];

    $issues = [];
    $previousMilestone = null;

    // Compare recorded milestones chronologically
    foreach ($milestones as $milestone) {
        if (
            !is_numeric($milestone['unix']) ||
            (int)$milestone['unix'] <= 0
        ) {
            continue;
        }

        $resolvedMilestone = [
            'label' => $milestone['label'],
            'unix' => (int)$milestone['unix'],
            'date' => formatApplicationStatusPayloadDate(
                $milestone['unix']
            )
        ];

        if (
            $previousMilestone !== null &&
            $resolvedMilestone['unix'] <
                $previousMilestone['unix']
        ) {
            $issues[] = sprintf(
                '%s date %s precedes %s date %s.',
                $resolvedMilestone['label'],
                $resolvedMilestone['date'],
                $previousMilestone['label'],
                $previousMilestone['date']
            );
        }

        $previousMilestone = $resolvedMilestone;
    }

    return [
        'hasChronologicalInconsistency' =>
            count($issues) > 0,
        'issues' =>
            $issues
    ];
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

    // Calculate authoritative milestone validation
    $milestoneReview =
        buildApplicationStatusMilestoneReview(
            $application
        );

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
                'requiredDate' => formatApplicationStatusPayloadDate(
                    $requirement[
                        'applicationSpecialRequirementRequiredUnix'
                    ] ?? null
                ),
                'dueDate' => formatApplicationStatusPayloadDate(
                    $requirement[
                        'applicationSpecialRequirementDueUnix'
                    ] ?? null
                )
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
            $paidDate = formatApplicationStatusPayloadDate(
                $fee['feePaidUnix'] ?? null
            );

            return [
                'category' => trim((string)($fee['feeCategory'] ?? '')),
                'amount' => round((float)($fee['feeAmount'] ?? 0), 2),
                'description' => trim((string)($fee['feeNote'] ?? '')),
                'assessedDate' => formatApplicationStatusPayloadDate(
                    $fee['feeAssessedUnix'] ?? null
                ),
                'paidDate' => $paidDate,
                'status' => $paidDate !== null
                    ? 'Paid'
                    : 'Outstanding'
            ];
        },
        $activeFeeRows
    );

    // Include voided Fee rows separately
    $voidedFeeRows = array_values(array_filter(
        $feeRows,
        static function (array $fee): bool {
            return is_numeric(
                $fee['feeVoidedUnix'] ?? null
            ) &&
                (int)$fee['feeVoidedUnix'] > 0;
        }
    ));

    $normalizedVoidedFeeRows = array_map(
        static function (array $fee): array {
            return [
                'category' => trim((string)(
                    $fee['feeCategory'] ?? ''
                )),
                'amount' => round((float)(
                    $fee['feeAmount'] ?? 0
                ), 2),
                'description' => trim((string)(
                    $fee['feeNote'] ?? ''
                )),
                'voidedDate' =>
                    formatApplicationStatusPayloadDate(
                        $fee['feeVoidedUnix'] ?? null
                    ),
                'voidReason' => trim((string)(
                    $fee['feeVoidReason'] ?? ''
                )),
                'status' => 'Voided'
            ];
        },
        $voidedFeeRows
    );

    return [
        'schemaVersion' => '1.2.0',
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
            'receivedDate' => formatApplicationStatusPayloadDate(
                $application['applicationCreatedUnix'] ?? null
            ),
            'submittedDate' => formatApplicationStatusPayloadDate(
                $application['applicationSubmittedUnix'] ?? null
            ),
            'approvedDate' => formatApplicationStatusPayloadDate(
                $application['applicationApprovedUnix'] ?? null
            ),
            'issuedDate' => formatApplicationStatusPayloadDate(
                $application['applicationIssuedUnix'] ?? null
            ),
            'finaledDate' => formatApplicationStatusPayloadDate(
                $application['applicationFinaledUnix'] ?? null
            ),
            'updatedDate' => formatApplicationStatusPayloadDate(
                $application['applicationUpdatedUnix'] ?? null
            )
        ],
        'milestoneReview' =>
            $milestoneReview,
        'specialRequirements' => [
            'activeCount' => count($requirementRows),
            'rows' => $requirementRows
        ],
        'fees' => [
            'hasFeeRecords' =>
                count($feeRows) > 0,
            'recordCount' =>
                count($feeRows),
            'activeRecordCount' =>
                count($normalizedFeeRows),
            'voidedRecordCount' =>
                count($normalizedVoidedFeeRows),
            'activeRows' =>
                $normalizedFeeRows,
            'voidedRows' =>
                $normalizedVoidedFeeRows,
            'totalAssessed' => round((float)(
                $fees['totalAssessed'] ?? 0
            ), 2),
            'totalPaid' => round((float)(
                $fees['totalPaid'] ?? 0
            ), 2),
            'totalOutstanding' => round((float)(
                $fees['totalOutstanding'] ?? 0
            ), 2),
            'totalVoided' => round((float)(
                $fees['totalVoided'] ?? 0
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
        'milestoneReview' =>
            $payload['milestoneReview'] ?? [],
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

function buildApplicationStatusFallbackSummary(
    array $payload
): string {
    $application = is_array(
        $payload['application'] ?? null
    )
        ? $payload['application']
        : [];

    $requirements = is_array(
        $payload['specialRequirements'] ?? null
    )
        ? $payload['specialRequirements']
        : [];

    $fees = is_array(
        $payload['fees'] ?? null
    )
        ? $payload['fees']
        : [];

    $milestoneReview = is_array(
        $payload['milestoneReview'] ?? null
    )
        ? $payload['milestoneReview']
        : [];

    $location = trim((string)(
        $application['location'] ?? ''
    ));

    $customer = trim((string)(
        $application['customer'] ?? ''
    ));

    $subject = $location !== ''
        ? $location
        : (
            $customer !== ''
                ? $customer
                : 'This permit application'
        );

    $stage = trim((string)(
        $application['stage'] ?? ''
    ));

    $status = trim((string)(
        $application['status'] ?? ''
    ));

    $sentences = [];

    // Summarize current Application position
    if ($stage !== '' || $status !== '') {
        $sentences[] = sprintf(
            '%s is currently in %s with %s status.',
            $subject,
            $stage !== ''
                ? $stage
                : 'an unspecified stage',
            $status !== ''
                ? $status
                : 'an unspecified'
        );
    } else {
        $sentences[] = sprintf(
            'Christy Signs is coordinating the permit application for %s.',
            $subject
        );
    }

    $milestoneIssues = is_array(
        $milestoneReview['issues'] ?? null
    )
        ? $milestoneReview['issues']
        : [];

    // Report authoritative milestone inconsistencies
    foreach ($milestoneIssues as $milestoneIssue) {
        $resolvedIssue = trim(
            (string)$milestoneIssue
        );

        if ($resolvedIssue !== '') {
            $sentences[] = sprintf(
                'A recorded chronological inconsistency exists: %s',
                $resolvedIssue
            );
        }
    }

    // Report missing later lifecycle dates
    $missingMilestones = [];

    if (
        trim((string)(
            $application['approvedDate'] ?? ''
        )) === ''
    ) {
        $missingMilestones[] = 'approval';
    }

    if (
        trim((string)(
            $application['issuedDate'] ?? ''
        )) === ''
    ) {
        $missingMilestones[] = 'issuance';
    }

    if (
        trim((string)(
            $application['finaledDate'] ?? ''
        )) === ''
    ) {
        $missingMilestones[] = 'finalization';
    }

    if (count($missingMilestones) > 0) {
        $lastMilestone = array_pop(
            $missingMilestones
        );

        $milestoneList = count(
            $missingMilestones
        ) > 0
            ? implode(
                ', ',
                $missingMilestones
            ) . ' or ' . $lastMilestone
            : $lastMilestone;

        $sentences[] = sprintf(
            'No %s date%s recorded.',
            $milestoneList,
            str_contains(
                $milestoneList,
                ' or '
            )
                ? 's are'
                : ' is'
        );
    }

    $activeRequirementCount = (int)(
        $requirements['activeCount'] ?? 0
    );

    // Summarize active Special Requirements
    $sentences[] = $activeRequirementCount > 0
        ? sprintf(
            '%d active Special Requirement%s remain recorded.',
            $activeRequirementCount,
            $activeRequirementCount === 1
                ? ''
                : 's'
        )
        : 'No active Special Requirements are recorded.';

    if ((bool)(
        $fees['hasFeeRecords'] ?? false
    )) {
        $totalAssessed = round((float)(
            $fees['totalAssessed'] ?? 0
        ), 2);

        $totalPaid = round((float)(
            $fees['totalPaid'] ?? 0
        ), 2);

        $totalOutstanding = round((float)(
            $fees['totalOutstanding'] ?? 0
        ), 2);

        $totalVoided = round((float)(
            $fees['totalVoided'] ?? 0
        ), 2);

        // Summarize active Fee position
        if (
            $totalOutstanding === 0.00 &&
            $totalAssessed === $totalPaid
        ) {
            $sentences[] = sprintf(
                'Active fees total $%s and are paid in full, with no outstanding balance.',
                number_format(
                    $totalAssessed,
                    2
                )
            );
        } else {
            $sentences[] = sprintf(
                'Active fees total $%s assessed, $%s paid, and $%s outstanding.',
                number_format(
                    $totalAssessed,
                    2
                ),
                number_format(
                    $totalPaid,
                    2
                ),
                number_format(
                    $totalOutstanding,
                    2
                )
            );
        }

        if ($totalVoided > 0) {
            $sentences[] = sprintf(
                'A separate $%s in fees was voided.',
                number_format(
                    $totalVoided,
                    2
                )
            );
        }
    } else {
        $sentences[] =
            'No Application Fees are recorded.';
    }

    return implode(
        ' ',
        $sentences
    );
}

// #endregion