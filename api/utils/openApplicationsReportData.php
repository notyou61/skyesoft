<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft — openApplicationsReportData.php
 *  Shared Open Application Report Data & Summary Context
 *  Codex-Governed Shared Module • PHP 8.3
 * ===================================================================== */

// #region SECTION I — Authoritative Data Loader

function loadOpenApplicationsWorkflowData(PDO $db): array
{
    // Load active Stages in configured lifecycle order
    $stageStmt = $db->query("
        SELECT
            applicationStageID,
            applicationStageName,
            applicationStageDescription,
            applicationStageSortOrder
        FROM tblApplicationStages
        WHERE applicationStageIsActive = 1
        ORDER BY applicationStageSortOrder ASC
    ");

    $stages = $stageStmt
        ? $stageStmt->fetchAll(PDO::FETCH_ASSOC)
        : [];

    // Load active Statuses under their governing Stage
    $statusStmt = $db->query("
        SELECT
            applicationStatusID,
            applicationStageID,
            applicationStatusName,
            applicationStatusDescription,
            applicationStatusSortOrder
        FROM tblApplicationStatuses
        WHERE applicationStatusIsActive = 1
        ORDER BY
            applicationStageID ASC,
            applicationStatusSortOrder ASC
    ");

    $statuses = $statusStmt
        ? $statusStmt->fetchAll(PDO::FETCH_ASSOC)
        : [];

    $statusesByStage = [];

    // Normalize authoritative Status identifiers
    foreach ($statuses as &$status) {
        $status['applicationStatusID'] =
            (int)$status['applicationStatusID'];
        $status['applicationStageID'] =
            (int)$status['applicationStageID'];
        $status['applicationStatusSortOrder'] =
            (int)$status['applicationStatusSortOrder'];

        $statusesByStage[
            $status['applicationStageID']
        ][] = $status;
    }
    unset($status);

    // Normalize Stages and attach configured Statuses
    foreach ($stages as &$stage) {
        $stage['applicationStageID'] =
            (int)$stage['applicationStageID'];
        $stage['applicationStageSortOrder'] =
            (int)$stage['applicationStageSortOrder'];
        $stage['statuses'] = $statusesByStage[
            $stage['applicationStageID']
        ] ?? [];
    }
    unset($stage);

    return [
        'stages' => $stages,
        'statuses' => $statuses
    ];
}

function loadOpenApplicationsReportData(PDO $db): array
{
    $applicationsStmt = $db->prepare("
        SELECT
            a.applicationID,
            a.applicationStageID,
            a.applicationStatusID,
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
            s.applicationStageDescription,
            s.applicationStageSortOrder,
            st.applicationStatusName,
            st.applicationStatusDescription,
            st.applicationStatusSortOrder,
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

    if (!is_array($applications)) {
        return [];
    }

    $workflow = loadOpenApplicationsWorkflowData($db);
    $workflowStages = is_array($workflow['stages'] ?? null)
        ? $workflow['stages']
        : [];
    $workflowStageIndexes = [];

    // Index configured Stages for next-Stage resolution
    foreach ($workflowStages as $stageIndex => $workflowStage) {
        $workflowStageIndexes[
            (int)$workflowStage['applicationStageID']
        ] = (int)$stageIndex;
    }

    // Prepare reusable active Special Requirement query
    $requirementStmt = $db->prepare("
        SELECT
            r.applicationSpecialRequirementID,
            r.applicationSpecialRequirementDescription,
            r.applicationSpecialRequirementResponsibleParty,
            r.applicationSpecialRequirementRequiredUnix,
            r.applicationSpecialRequirementDueUnix,
            s.applicationSpecialRequirementStatusName,
            s.applicationSpecialRequirementStatusDescription
        FROM tblApplicationSpecialRequirements r
        INNER JOIN tblApplicationSpecialRequirementStatuses s
            ON s.applicationSpecialRequirementStatusID =
               r.applicationSpecialRequirementStatusID
        WHERE r.applicationID = :applicationId
          AND r.applicationSpecialRequirementIsNotValid = 0
          AND s.applicationSpecialRequirementStatusIsClosed = 0
        ORDER BY
            r.applicationSpecialRequirementDueUnix IS NULL ASC,
            r.applicationSpecialRequirementDueUnix ASC,
            r.applicationSpecialRequirementID ASC
    ");

    // Prepare reusable one-to-many Application Notes query
    $noteStmt = $db->prepare("
        SELECT
            n.noteID,
            n.noteApplicationSpecialRequirementID,
            n.noteText,
            n.noteCreatedUnix,
            n.noteUpdatedUnix,
            c.contactFirstName,
            c.contactLastName
        FROM tblNotes n
        INNER JOIN tblContacts c
            ON c.contactId = n.noteAuthorContactID
        WHERE n.noteApplicationID = :applicationId
          AND n.noteIsNotValid = 0
        ORDER BY
            n.noteCreatedUnix DESC,
            n.noteID DESC
    ");

    // Normalize and enrich each authoritative Application
    foreach ($applications as &$application) {
        $applicationId = (int)$application['applicationID'];
        $applicationStageId = (int)$application[
            'applicationStageID'
        ];

        $application['applicationID'] = $applicationId;
        $application['applicationStageID'] = $applicationStageId;
        $application['applicationStatusID'] =
            (int)$application['applicationStatusID'];
        $application['applicationStageSortOrder'] =
            (int)$application['applicationStageSortOrder'];
        $application['applicationStatusSortOrder'] =
            (int)$application['applicationStatusSortOrder'];

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

        // Load detailed active Special Requirements
        $requirementStmt->execute([
            'applicationId' => $applicationId
        ]);

        $requirements = $requirementStmt->fetchAll(
            PDO::FETCH_ASSOC
        );

        foreach ($requirements as &$requirement) {
            $requirement['applicationSpecialRequirementID'] =
                (int)$requirement[
                    'applicationSpecialRequirementID'
                ];
            $requirement[
                'applicationSpecialRequirementRequiredUnix'
            ] = is_numeric($requirement[
                'applicationSpecialRequirementRequiredUnix'
            ] ?? null)
                ? (int)$requirement[
                    'applicationSpecialRequirementRequiredUnix'
                ]
                : null;
            $requirement[
                'applicationSpecialRequirementDueUnix'
            ] = is_numeric($requirement[
                'applicationSpecialRequirementDueUnix'
            ] ?? null)
                ? (int)$requirement[
                    'applicationSpecialRequirementDueUnix'
                ]
                : null;
        }
        unset($requirement);

        $application['applicationSpecialRequirements'] =
            is_array($requirements) ? $requirements : [];

        // Load detailed one-to-many Application Notes
        $noteStmt->execute([
            'applicationId' => $applicationId
        ]);

        $notes = $noteStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($notes as &$note) {
            $note['noteID'] = (int)$note['noteID'];
            $note['noteApplicationSpecialRequirementID'] =
                is_numeric($note[
                    'noteApplicationSpecialRequirementID'
                ] ?? null)
                    ? (int)$note[
                        'noteApplicationSpecialRequirementID'
                    ]
                    : null;
            $note['noteCreatedUnix'] =
                (int)$note['noteCreatedUnix'];
            $note['noteUpdatedUnix'] = is_numeric(
                $note['noteUpdatedUnix'] ?? null
            )
                ? (int)$note['noteUpdatedUnix']
                : null;
        }
        unset($note);

        $application['applicationNotes'] =
            is_array($notes) ? $notes : [];
        $application['applicationNoteCount'] = count(
            $application['applicationNotes']
        );

        // Resolve the next configured Stage
        $stageIndex = $workflowStageIndexes[
            $applicationStageId
        ] ?? null;
        $nextStage = $stageIndex !== null
            ? ($workflowStages[$stageIndex + 1] ?? null)
            : null;

        $application['applicationNextStageID'] =
            is_array($nextStage)
                ? (int)$nextStage['applicationStageID']
                : null;
        $application['applicationNextStageName'] =
            is_array($nextStage)
                ? trim((string)$nextStage[
                    'applicationStageName'
                ])
                : null;
        $application['applicationNextStageDescription'] =
            is_array($nextStage)
                ? trim((string)($nextStage[
                    'applicationStageDescription'
                ] ?? ''))
                : null;
    }
    unset($application);

    return $applications;
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

function calculateOpenApplicationCalendarDays(
    mixed $receivedUnix,
    mixed $endUnix
): ?int {
    if (
        !is_numeric($receivedUnix) ||
        (int)$receivedUnix <= 0 ||
        !is_numeric($endUnix) ||
        (int)$endUnix <= 0
    ) {
        return null;
    }

    $timezone = new DateTimeZone('America/Phoenix');
    $receivedDate = (new DateTimeImmutable(
        '@' . (int)$receivedUnix
    ))
        ->setTimezone($timezone)
        ->setTime(0, 0);
    $endDate = (new DateTimeImmutable(
        '@' . (int)$endUnix
    ))
        ->setTimezone($timezone)
        ->setTime(0, 0);

    if ($endDate < $receivedDate) {
        return null;
    }

    return (int)$receivedDate->diff($endDate)->days;
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
    $statusKey = strtolower(trim((string)(
        $application['applicationStatusName'] ?? ''
    )));
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
    } elseif ($stageKey === 'jurisdiction review') {
        $stageConflictLabels = [
            'Approved',
            'Issued',
            'Finaled'
        ];
    } elseif ($stageKey === 'approval / issuance') {
        $stageConflictLabels = [
            'Finaled'
        ];
    } elseif ($stageKey === 'inspection') {
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

    $expectedMilestoneLabel = null;

    // Identify a missing milestone implied by the current Stage
    if ($stageKey === 'submitted') {
        $expectedMilestoneLabel = 'Submitted';
    } elseif ($stageKey === 'jurisdiction review') {
        $expectedMilestoneLabel = 'Submitted';
    } elseif (
        $stageKey === 'approval / issuance' &&
        str_contains($statusKey, 'issued')
    ) {
        $expectedMilestoneLabel = 'Issued';
    } elseif (
        $stageKey === 'approval / issuance' &&
        str_contains($statusKey, 'approved')
    ) {
        $expectedMilestoneLabel = 'Approved';
    } elseif ($stageKey === 'inspection') {
        $expectedMilestoneLabel = 'Issued';
    } elseif ($stageKey === 'finaled') {
        $expectedMilestoneLabel = 'Finaled';
    }

    if (
        $expectedMilestoneLabel !== null &&
        !isset($resolvedMilestones[$expectedMilestoneLabel])
    ) {
        $reviewItems[] = [
            'applicationID' => $applicationId,
            'workOrderNumber' => $workOrderNumber,
            'type' => 'missing_stage_milestone',
            'message' => sprintf(
                'Application #%d is in %s stage, but its %s date is not recorded.',
                $applicationId,
                $stage !== '' ? $stage : 'an unspecified',
                $expectedMilestoneLabel
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

        $finaledUnix = is_numeric(
            $application['applicationFinaledUnix'] ?? null
        ) && (int)$application['applicationFinaledUnix'] > 0
            ? (int)$application['applicationFinaledUnix']
            : null;

        $durationEndUnix = $finaledUnix
            ?? $generatedUnix;

        $calendarDays = calculateOpenApplicationCalendarDays(
            $application['applicationCreatedUnix'] ?? null,
            $durationEndUnix
        );

        $requirementRows = array_map(
            static function (array $requirement): array {
                return [
                    'requirementID' => (int)(
                        $requirement[
                            'applicationSpecialRequirementID'
                        ] ?? 0
                    ),
                    'description' => trim((string)(
                        $requirement[
                            'applicationSpecialRequirementDescription'
                        ] ?? ''
                    )),
                    'status' => trim((string)(
                        $requirement[
                            'applicationSpecialRequirementStatusName'
                        ] ?? ''
                    )),
                    'statusDescription' => trim((string)(
                        $requirement[
                            'applicationSpecialRequirementStatusDescription'
                        ] ?? ''
                    )),
                    'responsibleParty' => trim((string)(
                        $requirement[
                            'applicationSpecialRequirementResponsibleParty'
                        ] ?? ''
                    )),
                    'requiredDate' =>
                        formatOpenApplicationsPayloadDate(
                            $requirement[
                                'applicationSpecialRequirementRequiredUnix'
                            ] ?? null
                        ),
                    'dueDate' =>
                        formatOpenApplicationsPayloadDate(
                            $requirement[
                                'applicationSpecialRequirementDueUnix'
                            ] ?? null
                        )
                ];
            },
            is_array(
                $application['applicationSpecialRequirements'] ?? null
            )
                ? $application['applicationSpecialRequirements']
                : []
        );

        $noteRows = array_map(
            static function (array $note): array {
                $authorName = trim(
                    (string)($note['contactFirstName'] ?? '') . ' ' .
                    (string)($note['contactLastName'] ?? '')
                );
                $noteUnix = is_numeric(
                    $note['noteUpdatedUnix'] ?? null
                )
                    ? (int)$note['noteUpdatedUnix']
                    : ($note['noteCreatedUnix'] ?? null);

                return [
                    'noteID' => (int)($note['noteID'] ?? 0),
                    'specialRequirementID' => is_numeric(
                        $note[
                            'noteApplicationSpecialRequirementID'
                        ] ?? null
                    )
                        ? (int)$note[
                            'noteApplicationSpecialRequirementID'
                        ]
                        : null,
                    'text' => trim((string)(
                        $note['noteText'] ?? ''
                    )),
                    'authorName' => $authorName,
                    'recordedDate' =>
                        formatOpenApplicationsPayloadDate($noteUnix)
                ];
            },
            is_array($application['applicationNotes'] ?? null)
                ? $application['applicationNotes']
                : []
        );

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
            'applicationStageID' => (int)(
                $application['applicationStageID'] ?? 0
            ),
            'applicationStatusID' => (int)(
                $application['applicationStatusID'] ?? 0
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
            'stageDescription' => trim((string)(
                $application['applicationStageDescription'] ?? ''
            )),
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
                ),
                'items' => $requirementRows
            ],
            'notes' => [
                'count' => count($noteRows),
                'items' => $noteRows
            ],
            'duration' => [
                'basis' => $finaledUnix !== null
                    ? 'received_to_finaled'
                    : 'received_to_report',
                'calendarDays' => $calendarDays,
                'isCompleted' => $finaledUnix !== null
            ],
            'nextStage' => [
                'applicationStageID' => is_numeric(
                    $application['applicationNextStageID'] ?? null
                )
                    ? (int)$application['applicationNextStageID']
                    : null,
                'name' => trim((string)(
                    $application['applicationNextStageName'] ?? ''
                )),
                'description' => trim((string)(
                    $application[
                        'applicationNextStageDescription'
                    ] ?? ''
                ))
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
        'schemaVersion' => '1.4.0',
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