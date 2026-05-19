<?php
declare(strict_types=1);

// ======================================================================
//  🧠 Skyesoft — reportGeneratorMPDF.php
//  📄 Universal PDF Report Engine (mPDF)
//  🧩 Reusable Operational Intelligence Renderer
//  📍 Christy Signs | Phoenix, Arizona
//  Version: 1.0.0
// ======================================================================

#region 📦 AUTOLOAD

require_once __DIR__ . '/../../vendor/autoload.php';

#endregion

#region 📚 IMPORTS

use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

#endregion

#region 🧠 REPORT GENERATOR CLASS

class ReportGeneratorMPDF
{
    // =====================================================
    // 🧾 PATHS / STATE
    // =====================================================

    private ?string $baseTemplatePath = null;

    private ?string $templatePath = null;

    private array $payload = [];

    // =====================================================
    // 🏗️ CONSTRUCTOR
    // =====================================================

    public function __construct(array $config = [])
    {
        $this->baseTemplatePath =
            $config['baseTemplatePath']
            ?? __DIR__ . '/../../reports/templates/base.html';
    }

    // =====================================================
    // 📄 SET TEMPLATE
    // =====================================================

    public function setTemplate(string $templateName): bool
    {
        $this->templatePath =
            __DIR__
            . '/../../reports/templates/'
            . $templateName
            . '.html';

        if (!file_exists($this->templatePath)) {
            throw new Exception(
                'Template not found: '
                . $this->templatePath
            );
        }

        return true;
    }

    // =====================================================
    // 📦 SET PAYLOAD
    // =====================================================

    public function setPayload(array $payload = []): bool
    {
        $this->payload = $payload;

        return true;
    }

    // =====================================================
    // 📥 LOAD TEMPLATES
    // =====================================================

    private function loadTemplates(): array
    {
        $baseHtml =
            file_get_contents($this->baseTemplatePath);

        $bodyHtml =
            file_get_contents($this->templatePath);

        return [$baseHtml, $bodyHtml];
    }

    // =====================================================
    // 🔄 TOKEN REPLACEMENT
    // =====================================================

    private function replaceTokens(string $html): string
    {
        foreach ($this->payload as $key => $value) {

            if (is_array($value)) {
                $value = json_encode(
                    $value,
                    JSON_PRETTY_PRINT
                );
            }

            $html = str_replace(
                '{{' . $key . '}}',
                (string)$value,
                $html
            );
        }

        // -------------------------------------------------
        // Default generated date
        // -------------------------------------------------

        if (
            strpos($html, '{{generatedDate}}') !== false
        ) {
            $html = str_replace(
                '{{generatedDate}}',
                date('m/d/Y g:i A'),
                $html
            );
        }

        // -------------------------------------------------
        // Static Google map
        // -------------------------------------------------

        if (
            !empty($this->payload['locationLatitude'])
            && !empty($this->payload['locationLongitude'])
        ) {
            $lat =
                $this->payload['locationLatitude'];

            $lng =
                $this->payload['locationLongitude'];

            $mapUrl =
                'https://maps.googleapis.com/maps/api/staticmap'
                . '?center=' . $lat . ',' . $lng
                . '&zoom=18'
                . '&size=650x320'
                . '&maptype=roadmap'
                . '&markers=color:red%7C'
                . $lat . ',' . $lng;

            $mapHtml =
                '<img src="'
                . $mapUrl
                . '" '
                . 'style="width:100%; max-width:650px; '
                . 'border:1px solid #ccc;" '
                . 'alt="Map">';

            $html = str_replace(
                '{{mapImage}}',
                $mapHtml,
                $html
            );
        }

        return $html;
    }

    // =====================================================
    // 🧱 BUILD FINAL HTML
    // =====================================================

    private function buildHtml(): string
    {
        [$baseHtml, $bodyHtml] =
            $this->loadTemplates();

        $html = str_replace(
            '{{documentBody}}',
            $bodyHtml,
            $baseHtml
        );

        $html = $this->replaceTokens($html);

        return $html;
    }

    // =====================================================
    // 📄 GENERATE PDF BINARY
    // =====================================================

    public function generatePdfBinary(): string
    {
        // -------------------------------------------------
        // Font Configuration
        // -------------------------------------------------

        $defaultConfig =
            (new ConfigVariables())->getDefaults();

        $fontDirs =
            $defaultConfig['fontDir'];

        $defaultFontConfig =
            (new FontVariables())->getDefaults();

        $fontData =
            $defaultFontConfig['fontdata'];

        // -------------------------------------------------
        // Create mPDF Instance
        // -------------------------------------------------

        $mpdf = new Mpdf([

            'mode' => 'utf-8',

            'format' => 'Letter',

            'margin_top' => 12,
            'margin_bottom' => 12,
            'margin_left' => 12,
            'margin_right' => 12,

            'tempDir' =>
                __DIR__ . '/../../tmp',

            'fontDir' =>
                array_merge(
                    $fontDirs,
                    []
                ),

            'fontdata' =>
                $fontData,

            'default_font' => 'helvetica'
        ]);

        // -------------------------------------------------
        // Metadata
        // -------------------------------------------------

        $mpdf->SetTitle(
            $this->payload['reportTitle']
            ?? 'Operational Report'
        );

        $mpdf->SetAuthor(
            'Skyesoft'
        );

        $mpdf->SetCreator(
            'Skyesoft Operational Intelligence'
        );

        // -------------------------------------------------
        // Build HTML
        // -------------------------------------------------

        $html = $this->buildHtml();

        // -------------------------------------------------
        // Render
        // -------------------------------------------------

        $mpdf->WriteHTML($html);

        // -------------------------------------------------
        // Return PDF Binary
        // -------------------------------------------------

        return $mpdf->Output(
            '',
            'S'
        );
    }

    // =====================================================
    // 🌐 STREAM PDF TO BROWSER
    // =====================================================

    public function streamPdf(
        string $filename =
            'Operational_Report.pdf'
    ): void
    {
        $pdfBinary =
            $this->generatePdfBinary();

        header(
            'Content-Type: application/pdf'
        );

        header(
            'Content-Disposition: inline; filename="'
            . $filename
            . '"'
        );

        header(
            'Content-Length: '
            . strlen($pdfBinary)
        );

        echo $pdfBinary;

        exit;
    }

    // =====================================================
    // 💾 SAVE PDF TO FILE
    // =====================================================

    public function savePdf(
        string $path
    ): bool
    {
        $pdfBinary =
            $this->generatePdfBinary();

        return (
            file_put_contents(
                $path,
                $pdfBinary
            ) !== false
        );
    }
}

#endregion