<?php
/**
 * Handle AI report output
 */
function handleReportRequest($prompt, $reportTypes, &$conversation) {
    // í´ Detect requested report type
    $detectedReportType = null;
    $p = strtolower($prompt);

    // Check Codex-defined reportTypes first
    foreach ($reportTypes as $key => $type) {
        $candidate = is_array($type) ? $key : $type;
        if (is_string($candidate) && strpos($p, strtolower($candidate)) !== false) {
            $detectedReportType = $candidate;
            break;
        }
    }

    // If still not found, fallback keyword mapping
    if ($detectedReportType === null) {
        if (strpos($p, "zoning") !== false) {
            $detectedReportType = "Zoning Report";
        } elseif (strpos($p, "sign") !== false) {
            $detectedReportType = "Sign Ordinance Report";
        } elseif (strpos($p, "photo") !== false) {
            $detectedReportType = "Photo Survey Report";
        } elseif (strpos($p, "custom") !== false) {
            $detectedReportType = "Custom Report";
        }
    }

    // íº¦ Dispatch
    switch ($detectedReportType) {
        case "Zoning Report":
            $report = generateZoningReport($prompt, $conversation);
            break;

        case "Sign Ordinance Report":
            $report = generateSignOrdinanceReport($prompt, $conversation);
            break;

        case "Photo Survey Report":
            $report = generatePhotoSurveyReport($prompt, $conversation);
            break;

        case "Custom Report":
            $report = generateCustomReport($prompt, $conversation);
            break;

        default:
            $report = array(
                "error"    => true,
                "response" => "âš ï¸ Unknown or unsupported report type.",
                "inputs"   => array("prompt" => $prompt)
            );
            break;
    }

    // âœ… Output JSON
    header('Content-Type: application/json');
    echo json_encode($report, JSON_PRETTY_PRINT);
    exit;
}
