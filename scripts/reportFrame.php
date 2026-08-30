<?php
declare(strict_types=1);

/* =====================================================================
 *  Skyesoft - reportFrame.php
 *  Canonical Skyesoft Report Header, Footer & Page Geometry
 *  Codex-Governed Shared Presentation Module - PHP 8.3
 * ===================================================================== */

#region SECTION I - Shared Helpers

function escapeSkyesoftReportFrameValue(mixed $value): string
{
    return htmlspecialchars(
        trim((string)($value ?? '')),
        ENT_QUOTES,
        'UTF-8'
    );
}

#endregion

#region SECTION II - Canonical Page Geometry

function getSkyesoftReportMpdfConfig(string $tempDir): array
{
    return [
        'mode' => 'utf-8',
        'format' => 'Letter',
        'orientation' => 'P',
        'margin_left' => 9.65,
        'margin_right' => 9.65,
        'margin_top' => 27,
        'margin_bottom' => 15,
        'margin_header' => 4,
        'margin_footer' => 5,
        'tempDir' => $tempDir
    ];
}

#endregion

#region SECTION III - Canonical Header

function getSkyesoftReportFrameStyles(): string
{
    return <<<'CSS'
        .skyesoft-report-header {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
        }

        .skyesoft-report-header td {
            border: 0;
            vertical-align: middle;
        }

        .skyesoft-report-header-logo-cell {
            width: 18%;
            padding: 0 4mm 8px 0;
            text-align: left;
            vertical-align: middle;
        }

        .skyesoft-report-header-logo {
            display: block;
            width: 30mm;
            height: auto;
            margin: 0;
            border: 0;
        }

        .skyesoft-report-header-logo-fallback {
            color: #14377c;
            font-size: 20px;
            font-weight: bold;
        }

        .skyesoft-report-header-details-cell {
            width: 82%;
            padding: 0 0 8px;
            text-align: left;
            vertical-align: middle;
        }

        .skyesoft-report-header-title {
            margin: 0;
            color: #14377c;
            font-size: 21px;
            font-weight: bold;
            line-height: 1;
        }

        .skyesoft-report-header-subtitle {
            margin: 2px 0 0;
            color: #333;
            font-size: 13px;
            font-weight: bold;
            line-height: 1.05;
        }

        .skyesoft-report-header-date {
            margin: 1px 0 0;
            color: #666;
            font-size: 10px;
            line-height: 1.05;
        }

        .skyesoft-report-header-divider {
            width: 100%;
            height: 0;
            margin: 0;
            padding: 0;
            border-top: 3px solid #14377c;
        }
CSS;
}

function renderSkyesoftReportHeader(array $context): string
{
    $title = escapeSkyesoftReportFrameValue($context['title'] ?? '');
    $subtitle = escapeSkyesoftReportFrameValue(
        $context['subtitle'] ?? ''
    );
    $reportLine = escapeSkyesoftReportFrameValue(
        $context['reportLine'] ?? ''
    );
    $logoSource = escapeSkyesoftReportFrameValue(
        $context['logoSource'] ?? ''
    );
    $logoAvailable = ($context['logoAvailable'] ?? false) === true;

    $logoHtml = $logoAvailable && $logoSource !== ''
        ? '<img src="' . $logoSource . '" ' .
            'class="skyesoft-report-header-logo" ' .
            'alt="Christy Signs">'
        : '<div class="skyesoft-report-header-logo-fallback">' .
            'Christy Signs</div>';

    return '<table class="skyesoft-report-header">' .
        '<tr>' .
        '<td class="skyesoft-report-header-logo-cell" ' .
        'style="width:18%;">' .
        $logoHtml .
        '</td>' .
        '<td class="skyesoft-report-header-details-cell" ' .
        'style="width:82%;">' .
        '<div class="skyesoft-report-header-title">' .
        $title .
        '</div>' .
        '<div class="skyesoft-report-header-subtitle">' .
        $subtitle .
        '</div>' .
        '<div class="skyesoft-report-header-date">' .
        $reportLine .
        '</div>' .
        '</td>' .
        '</tr>' .
        '</table>' .
        '<div class="skyesoft-report-header-divider"></div>';
}

#endregion

#region SECTION IV - Canonical Footer

function renderSkyesoftReportFooter(array $context): string
{
    $preparedBy = escapeSkyesoftReportFrameValue(
        $context['preparedBy'] ?? ''
    );
    $reportName = escapeSkyesoftReportFrameValue(
        $context['reportName'] ?? 'Skyesoft Report'
    );

    return '<table style="width:100%; border-top:1px solid #ccc; ' .
        'color:#666; font-family:Arial,Helvetica,sans-serif; ' .
        'font-size:9px;">' .
        '<tr>' .
        '<td style="padding-top:5px; text-align:left;">' .
        'Prepared by ' . $preparedBy . ' | Christy Signs' .
        '</td>' .
        '<td style="padding-top:5px; text-align:right;">' .
        $reportName . ' | Page {PAGENO} of {nbpg}' .
        '</td>' .
        '</tr>' .
        '</table>';
}

#endregion