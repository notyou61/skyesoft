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
            st.applicationStatusDescription,
            (
                SELECT COUNT(*)
                FROM tblApplicationFees f
                WHERE f.applicationID = a.applicationID
                  AND (
                      f.feeVoidedUnix IS NULL OR
                      f.feeVoidedUnix <= 0
                  )
            ) AS applicationFeeCount,
            COALESCE((
                SELECT SUM(f.feeAmount)
                FROM tblApplicationFees f
                WHERE f.applicationID = a.applicationID
                  AND (
                      f.feeVoidedUnix IS NULL OR
                      f.feeVoidedUnix <= 0
                  )
            ), 0) AS applicationFeeTotalAssessed,
            COALESCE((
                SELECT SUM(f.feeAmount)
                FROM tblApplicationFees f
                WHERE f.applicationID = a.applicationID
                  AND f.feePaidUnix IS NOT NULL
                  AND f.feePaidUnix > 0
                  AND (
                      f.feeVoidedUnix IS NULL OR
                      f.feeVoidedUnix <= 0
                  )
            ), 0) AS applicationFeeTotalPaid,
            (
                SELECT COUNT(*)
                FROM tblApplicationSpecialRequirements r
                INNER JOIN tblApplicationSpecialRequirementStatuses rs
                    ON rs.applicationSpecialRequirementStatusID =
                       r.applicationSpecialRequirementStatusID
                WHERE r.applicationID = a.applicationID
                  AND r.applicationSpecialRequirementIsNotValid = 0
                  AND rs.applicationSpecialRequirementStatusIsClosed = 0
            ) AS applicationActiveRequirementCount
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

    // Normalize authoritative Fee totals and status
    foreach ($applications as &$application) {
        $feeCount = (int)(
            $application['applicationFeeCount'] ?? 0
        );

        $totalAssessed = round((float)(
            $application['applicationFeeTotalAssessed'] ?? 0
        ), 2);

        $totalPaid = round((float)(
            $application['applicationFeeTotalPaid'] ?? 0
        ), 2);

        $totalOutstanding = max(
            0.00,
            round($totalAssessed - $totalPaid, 2)
        );

        if ($feeCount <= 0) {
            $feeStatus = 'No Fees';
        } elseif ($totalOutstanding <= 0) {
            $feeStatus = 'Paid';
        } elseif ($totalPaid > 0) {
            $feeStatus = 'Partially Paid';
        } else {
            $feeStatus = 'Awaiting Payment';
        }

        $application['applicationFeeCount'] = $feeCount;
        $application['applicationFeeTotalAssessed'] =
            $totalAssessed;
        $application['applicationFeeTotalPaid'] =
            $totalPaid;
        $application['applicationFeeTotalOutstanding'] =
            $totalOutstanding;
        $application['applicationFeeStatus'] = $feeStatus;
        $application['applicationActiveRequirementCount'] =
            (int)(
                $application[
                    'applicationActiveRequirementCount'
                ] ?? 0
            );
    }
    unset($application);

    return is_array($applications) ? $applications : [];
}

// #endregion

// #region SECTION II — Structured AI Context

function formatOpenApplicationsPayloadDate(
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

function buildOpenApplicationReviewItems(
    array $application
): array {
    $applicationId = (int)(
        $application['applicationID'] ?? 0
    );

    $workOrderNumber = trim((string)(
        $application['orderChristyNumber'] ?? ''
    ));

    $stage = trim((string)(
        $application['applicationStageName'] ?? ''
    ));

    $stageKey = strtolower($stage);
    $reviewItems = [];

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

    $resolvedMilestones = [];
    $previousMilestone = null;

    // Normalize and compare recorded milestones
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
            'date' => formatOpenApplicationsPayloadDate(
                $milestone['unix']
            )
        ];

        $resolvedMilestones[
            $resolvedMilestone['label']
        ] = $resolvedMilestone;

        if (
            $previousMilestone !== null &&
            $resolvedMilestone['unix'] <
                $previousMilestone['unix']
        ) {
            $reviewItems[] = [
                'applicationID' => $applicationId,
                'workOrderNumber' => $workOrderNumber,
                'type' => 'chronological_inconsistency',
                'message' => sprintf(
                    'Application #%d has a recorded date inconsistency: %s date %s precedes %s date %s.',
                    $applicationId,
                    $resolvedMilestone['label'],
                    $resolvedMilestone['date'],
                    $previousMilestone['label'],
                    $previousMilestone['date']
                )
            ];
        }

        $previousMilestone = $resolvedMilestone;
    }

    $stageConflictLabels = [];

    // Identify milestone records beyond the current stage
    if ($stageKey === 'pre-submittal') {
        $stageConflictLabels = [
            'Submitted',
            'Approved',
            'Issued',
            'Finaled'
        ];
    } elseif ($stageKey === 'submitted') {
        $stageConflictLabels = [
            'Approved',
            'Issued',
            'Finaled'
        ];
    } elseif ($stageKey === 'approved') {
        $stageConflictLabels = [
            'Issued',
            'Finaled'
        ];
    } elseif ($stageKey === 'issued') {
        $stageConflictLabels = [
            'Finaled'
        ];
    }

    foreach ($stageConflictLabels as $stageConflictLabel) {
        if (!isset($resolvedMilestones[$stageConflictLabel])) {
            continue;
        }

        $reviewItems[] = [
            'applicationID' => $applicationId,
            'workOrderNumber' => $workOrderNumber,
            'type' => 'stage_milestone_conflict',
            'message' => sprintf(
                'Application #%d is in %s stage while a %s date of %s is recorded.',
                $applicationId,
                $stage !== '' ? $stage : 'an unspecified',
                $stageConflictLabel,
                $resolvedMilestones[
                    $stageConflictLabel
                ]['date']
            )
        ];
    }

    $scope = trim((string)(
        $application['applicationScope'] ?? ''
    ));

    if (
        $scope === '' ||
        strtolower($scope) === 'none'
    ) {
        $reviewItems[] = [
            'applicationID' => $applicationId,
            'workOrderNumber' => $workOrderNumber,
            'type' => 'missing_scope',
            'message' => sprintf(
                'Application #%d does not have a recorded scope.',
                $applicationId
            )
        ];
    }

    $applicationNumber = trim((string)(
        $application['applicationNumber'] ?? ''
    ));

    if (
        isset($resolvedMilestones['Submitted']) &&
        $applicationNumber === ''
    ) {
        $reviewItems[] = [
            'applicationID' => $applicationId,
            'workOrderNumber' => $workOrderNumber,
            'type' => 'missing_jurisdiction_application_number',
            'message' => sprintf(
                'Application #%d has a Submitted date but no jurisdiction Application number.',
                $applicationId
            )
        ];
    }

    $permitNumber = trim((string)(
        $application['applicationPermitNumber'] ?? ''
    ));

    if (
        (
            isset($resolvedMilestones['Approved']) ||
            isset($resolvedMilestones['Issued'])
        ) &&
        $permitNumber === ''
    ) {
        $reviewItems[] = [
            'applicationID' => $applicationId,
            'workOrderNumber' => $workOrderNumber,
            'type' => 'missing_permit_number',
            'message' => sprintf(
                'Application #%d has an Approved or Issued date but no permit number.',
                $applicationId
            )
        ];
    }

    $feeStatus = trim((string)(
        $application['applicationFeeStatus'] ?? ''
    ));

    $feeOutstanding = round((float)(
        $application['applicationFeeTotalOutstanding'] ?? 0
    ), 2);

    if ($feeOutstanding > 0) {
        $reviewItems[] = [
            'applicationID' => $applicationId,
            'workOrderNumber' => $workOrderNumber,
            'type' => 'fee_payment_required',
            'message' => sprintf(
                'Application #%d has a Fee Status of %s with $%s awaiting payment.',
                $applicationId,
                $feeStatus !== ''
                    ? $feeStatus
                    : 'Awaiting Payment',
                number_format($feeOutstanding, 2)
            )
        ];
    }

    $activeRequirementCount = (int)(
        $application['applicationActiveRequirementCount'] ?? 0
    );

    if ($activeRequirementCount > 0) {
        $reviewItems[] = [
            'applicationID' => $applicationId,
            'workOrderNumber' => $workOrderNumber,
            'type' => 'active_special_requirements',
            'message' => sprintf(
                'Application #%d has %d active Special Requirement%s recorded.',
                $applicationId,
                $activeRequirementCount,
                $activeRequirementCount === 1 ? '' : 's'
            )
        ];
    }

    return $reviewItems;
}

function buildOpenApplicationsReportPayload(
    array $applications,
    int $generatedUnix
): array {
    $applicationRows = [];
    $stageCounts = [];
    $statusCounts = [];
    $reviewItems = [];

    foreach ($applications as $application) {
        $stage = trim((string)(
            $application['applicationStageName'] ?? ''
        ));

        $status = trim((string)(
            $application['applicationStatusName'] ?? ''
        ));

        $stageLabel = $stage !== ''
            ? $stage
            : 'Unspecified Stage';

        $statusLabel = $status !== ''
            ? $status
            : 'Unspecified Status';

        $stageCounts[$stageLabel] =
            ($stageCounts[$stageLabel] ?? 0) + 1;

        $statusCounts[$statusLabel] =
            ($statusCounts[$statusLabel] ?? 0) + 1;

        $applicationReviewItems =
            buildOpenApplicationReviewItems(
                $application
            );

        $reviewItems = array_merge(
            $reviewItems,
            $applicationReviewItems
        );

        $applicationRows[] = [
            'applicationID' => (int)(
                $application['applicationID'] ?? 0
            ),
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
            'stage' => $stage,
            'status' => $status,
            'statusDescription' => trim((string)(
                $application['applicationStatusDescription'] ?? ''
            )),
            'fees' => [
                'status' => trim((string)(
                    $application['applicationFeeStatus'] ?? 'No Fees'
                )),
                'feeCount' => (int)(
                    $application['applicationFeeCount'] ?? 0
                ),
                'totalAssessed' => round((float)(
                    $application['applicationFeeTotalAssessed'] ?? 0
                ), 2),
                'totalPaid' => round((float)(
                    $application['applicationFeeTotalPaid'] ?? 0
                ), 2),
                'totalOutstanding' => round((float)(
                    $application[
                        'applicationFeeTotalOutstanding'
                    ] ?? 0
                ), 2)
            ],
            'specialRequirements' => [
                'activeCount' => (int)(
                    $application[
                        'applicationActiveRequirementCount'
                    ] ?? 0
                )
            ],
            'receivedDate' => formatOpenApplicationsPayloadDate(
                $application['applicationCreatedUnix'] ?? null
            ),
            'submittedDate' => formatOpenApplicationsPayloadDate(
                $application['applicationSubmittedUnix'] ?? null
            ),
            'approvedDate' => formatOpenApplicationsPayloadDate(
                $application['applicationApprovedUnix'] ?? null
            ),
            'issuedDate' => formatOpenApplicationsPayloadDate(
                $application['applicationIssuedUnix'] ?? null
            ),
            'finaledDate' => formatOpenApplicationsPayloadDate(
                $application['applicationFinaledUnix'] ?? null
            ),
            'reviewItems' => $applicationReviewItems
        ];
    }

    return [
        'schemaVersion' => '1.3.0',
        'reportType' => 'open_applications_status',
        'audience' => 'internal_operations',
        'generatedDate' =>
            formatOpenApplicationsPayloadDate(
                $generatedUnix
            ),
        'applicationCount' =>
            count($applicationRows),
        'sortOrder' =>
            'applicationCreatedUnix.asc',
        'lifecycleDistribution' => [
            'stageCounts' => $stageCounts,
            'statusCounts' => $statusCounts
        ],
        'reviewSummary' => [
            'hasReviewItems' =>
                count($reviewItems) > 0,
            'reviewItemCount' =>
                count($reviewItems),
            'applicationCountWithReviewItems' =>
                count(array_unique(array_map(
                    static function (array $item): int {
                        return (int)(
                            $item['applicationID'] ?? 0
                        );
                    },
                    $reviewItems
                ))),
            'items' => $reviewItems
        ],
        'applications' => $applicationRows
    ];
}

function fingerprintOpenApplicationsReportPayload(
    array $payload
): string {
    $fingerprintPayload = [
        'schemaVersion' =>
            $payload['schemaVersion'] ?? null,
        'reportType' =>
            $payload['reportType'] ?? null,
        'applicationCount' =>
            $payload['applicationCount'] ?? 0,
        'sortOrder' =>
            $payload['sortOrder'] ?? null,
        'lifecycleDistribution' =>
            $payload['lifecycleDistribution'] ?? [],
        'reviewSummary' =>
            $payload['reviewSummary'] ?? [],
        'applications' =>
            $payload['applications'] ?? []
    ];

    $encodedPayload = json_encode(
        $fingerprintPayload,
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    return hash(
        'sha256',
        $encodedPayload !== false
            ? $encodedPayload
            : '{}'
    );
}

// #endregion

// #region SECTION III — Deterministic Summary Fallback

function buildOpenApplicationsFallbackSummary(
    array $payload
): string {
    $applications = is_array(
        $payload['applications'] ?? null
    )
        ? $payload['applications']
        : [];

    $applicationCount = count($applications);

    if ($applicationCount === 0) {
        return 'No open permit Applications are currently recorded.';
    }

    $distribution = is_array(
        $payload['lifecycleDistribution'] ?? null
    )
        ? $payload['lifecycleDistribution']
        : [];

    $stageCounts = is_array(
        $distribution['stageCounts'] ?? null
    )
        ? $distribution['stageCounts']
        : [];

    $reviewSummary = is_array(
        $payload['reviewSummary'] ?? null
    )
        ? $payload['reviewSummary']
        : [];

    $stageParts = [];

    foreach ($stageCounts as $stageName => $stageCount) {
        $stageParts[] = sprintf(
            '%d in %s',
            (int)$stageCount,
            (string)$stageName
        );
    }

    $sentences = [];

    $sentences[] = sprintf(
        '%d open permit Application%s are recorded.',
        $applicationCount,
        $applicationCount === 1 ? '' : 's'
    );

    if (count($stageParts) > 0) {
        $sentences[] = sprintf(
            'Current lifecycle distribution: %s.',
            implode('; ', $stageParts)
        );
    }

    $reviewItems = is_array(
        $reviewSummary['items'] ?? null
    )
        ? $reviewSummary['items']
        : [];

    if (count($reviewItems) > 0) {
        $applicationCountWithReviewItems = (int)(
            $reviewSummary[
                'applicationCountWithReviewItems'
            ] ?? 0
        );

        $sentences[] = sprintf(
            '%d Application%s have records requiring data review.',
            $applicationCountWithReviewItems,
            $applicationCountWithReviewItems === 1
                ? ''
                : 's'
        );

        foreach (array_slice($reviewItems, 0, 3) as $reviewItem) {
            $message = trim((string)(
                $reviewItem['message'] ?? ''
            ));

            if ($message !== '') {
                $sentences[] = $message;
            }
        }

        $remainingReviewItemCount =
            count($reviewItems) - 3;

        if ($remainingReviewItemCount > 0) {
            $sentences[] = sprintf(
                '%d additional review item%s are recorded.',
                $remainingReviewItemCount,
                $remainingReviewItemCount === 1
                    ? ''
                    : 's'
            );
        }
    } else {
        $sentences[] =
            'No deterministic data-review items were identified.';
    }

    return implode(
        ' ',
        $sentences
    );
}

// #endregion