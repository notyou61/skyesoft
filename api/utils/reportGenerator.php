<?php
declare(strict_types=1);

// ======================================================================
//  🧠 Skyesoft — reportGenerator.php
//  📄 Universal Operational PDF Engine
//  🧩 mPDF Constitutional Renderer
//  📍 Christy Signs | Phoenix, Arizona
//  Version: 3.0.1
// ======================================================================

#region SECTION 0 — Dependencies + Imports

require_once __DIR__ . '/../../vendor/autoload.php';

use Mpdf\Mpdf;

#endregion

#region SECTION 1 — ReportGenerator Class

class ReportGenerator
{
    // =====================================================
    // 🧾 Internal State
    // =====================================================

    private ?string $baseTemplatePath = null;

    private ?string $templatePath = null;

    private array $payload = [];

    // =====================================================
    // 🏗️ Constructor
    // =====================================================

    public function __construct(array $config = [])
    {
        $this->baseTemplatePath =
            $config['baseTemplatePath']
            ?? __DIR__ . '/../../reports/templates/base.html';
    }

    // =====================================================
    // 📄 Set Template
    // =====================================================

    public function setTemplate(
        string $templateName
    ): bool
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
    // 📦 Set Payload
    // =====================================================

    public function setPayload(
        array $payload = []
    ): bool
    {
        $this->payload = $payload;

        return true;
    }

    // =====================================================
    // 📥 Load Templates
    // =====================================================

    private function loadTemplates(): array
    {
        $baseHtml =
            file_get_contents(
                $this->baseTemplatePath
            );

        $bodyHtml =
            file_get_contents(
                $this->templatePath
            );

        return [
            $baseHtml,
            $bodyHtml
        ];
    }

    // =====================================================
    // 🔄 Replace Tokens
    // =====================================================

    private function replaceTokens(
        string $html
    ): string
    {
        foreach (
            $this->payload as $key => $value
        ) {

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
        // Generated Date
        // -------------------------------------------------

        if (
            strpos(
                $html,
                '{{generatedDate}}'
            ) !== false
        ) {
            $html = str_replace(
                '{{generatedDate}}',
                date('m/d/Y g:i A'),
                $html
            );
        }

        // -------------------------------------------------
        // Static Google Map
        // -------------------------------------------------

        if (
            !empty(
                $this->payload['locationLatitude']
            )
            &&
            !empty(
                $this->payload['locationLongitude']
            )
        ) {

            $lat =
                $this->payload['locationLatitude'];

            $lng =
                $this->payload['locationLongitude'];

            $mapUrl =
                'https://maps.googleapis.com/maps/api/staticmap'
                . '?center=' . $lat . ',' . $lng
                . '&zoom=17'
                . '&size=700x320'
                . '&maptype=roadmap'
                . '&markers=color:red%7C'
                . $lat . ',' . $lng;

            $mapHtml =
                '<img src="'
                . $mapUrl
                . '" '
                . 'style="width:100%; '
                . 'max-width:700px; '
                . 'border:1px solid #ccc;" '
                . 'alt="Location Map">';

            $html = str_replace(
                '{{mapImage}}',
                $mapHtml,
                $html
            );
        }

        return $html;
    }

    // =====================================================
    // 🧱 Build Final HTML
    // =====================================================

    private function buildHtml(): string
    {
        [
            $baseHtml,
            $bodyHtml
        ] = $this->loadTemplates();

        $html = str_replace(
            '{{documentBody}}',
            $bodyHtml,
            $baseHtml
        );

        $html = $this->replaceTokens(
            $html
        );

        return $html;
    }

    // =====================================================
    // 📄 Generate PDF Binary
    // =====================================================

    public function generatePdfBinary(): string
    {
        // -------------------------------------------------
        // Create mPDF Instance
        // -------------------------------------------------

        $mpdf = new Mpdf([

            'mode' => 'utf-8',

            'format' => 'Letter',

            'margin_left'   => 10,
            'margin_right'  => 10,
            'margin_top'    => 10,
            'margin_bottom' => 10,

            'tempDir' =>
                __DIR__ . '/../../tmp',

            'default_font' => 'helvetica'
        ]);

        // -------------------------------------------------
        // Stability Settings
        // -------------------------------------------------

        $mpdf->shrink_tables_to_fit = 1;

        $mpdf->keep_table_proportions = true;

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
        // Render HTML
        // -------------------------------------------------

        $mpdf->WriteHTML(
            $html
        );

        // -------------------------------------------------
        // Return PDF Binary
        // -------------------------------------------------

        return $mpdf->Output(
            '',
            'S'
        );
    }

    // =====================================================
    // 🌐 Stream PDF
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
    // 💾 Save PDF
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