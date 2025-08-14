<?php
// generateReport.php

// Config
$templatePath = __DIR__ . "/../report_template.html";
$reportsDir   = __DIR__ . "/../reports";
if (!is_dir($reportsDir)) {
    mkdir($reportsDir, 0775, true);
}

// Get incoming request (JSON POST)
$input = json_decode(file_get_contents("php://input"), true);
if (!$input || !isset($input['reportType'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing or invalid report data"]);
    exit;
}

// Load template
if (!file_exists($templatePath)) {
    http_response_code(500);
    echo json_encode(["error" => "Report template not found"]);
    exit;
}
$templateHtml = file_get_contents($templatePath);

// Switch by report type
switch ($input['reportType']) {
    case "zoning":
        $title = "Zoning Report – {$input['projectName']}";
        $content = "
            <h2>Project Information</h2>
            <p><strong>Project Name:</strong> {$input['projectName']}</p>
            <p><strong>Address:</strong> {$input['address']}</p>
            <p><strong>Parcel:</strong> {$input['parcel']}</p>
            <p><strong>Jurisdiction:</strong> {$input['jurisdiction']}</p>
            <p><strong>Sign Restrictions:</strong> {$input['signRestrictions']}</p>
        ";
        break;

    case "sign_ordinance":
        $title = "Sign Ordinance – {$input['projectName']}";
        $content = "
            <h2>Ordinance Details</h2>
            <p><strong>Project Name:</strong> {$input['projectName']}</p>
            <p><strong>Address:</strong> {$input['address']}</p>
            <p><strong>Jurisdiction:</strong> {$input['jurisdiction']}</p>
            <p><strong>Restrictions:</strong> {$input['restrictions']}</p>
        ";
        break;

    case "custom":
        $title = $input['title'];
        $content = "<h2>{$input['data']['summary']}</h2><ul>";
        foreach ($input['data']['items'] as $item) {
            $content .= "<li>{$item}</li>";
        }
        $content .= "</ul>";
        break;

    default:
        http_response_code(400);
        echo json_encode(["error" => "Unknown report type"]);
        exit;
}

// Merge into template (assumes {{title}} and {{content}} placeholders in template)
$finalHtml = str_replace(
    ["{{title}}", "{{content}}"],
    [$title, $content],
    $templateHtml
);

// Save to file
$fileName = "{$input['reportType']}_" . date("Ymd_His") . ".html";
$filePath = $reportsDir . "/" . $fileName;
file_put_contents($filePath, $finalHtml);

// Return success + path
echo json_encode([
    "status" => "success",
    "file" => $fileName,
    "path" => $filePath
]);
