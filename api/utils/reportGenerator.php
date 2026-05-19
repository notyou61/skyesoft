<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — reportGenerator.php
//  Version: 3.0.0 — mPDF Edition
//  Canonical Document Rendering Engine
// ======================================================================

#region SECTION 0 — Dependencies + imports

require_once __DIR__ . '/../../vendor/autoload.php';

use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

#endregion

#region SECTION 1 — Report Generator Class

class ReportGenerator
{
    // =====================================================
    // Properties
    // =====================================================

    private ?string $baseTemplatePath = null;
    private ?string $templatePath = null;
    private array $payload = [];
    private ?string $renderedHtml = null;

    // =====================================================
    // Constructor
    // =====================================================

    public function __construct(array $config = [])
    {
        $this->baseTemplatePath =
            $config['baseTemplatePath']
            ?? __DIR__ . '/../../reports/templates/base.html';
    }

    // =====================================================
    // Set Template
    // =====================================================

    public function setTemplate(string $templateName): bool
    {
        $this->templatePath =
            __DIR__ . '/../../reports/templates/' . $templateName . '.html';

        if (!file_exists($this->templatePath)) {
            throw new Exception('Template not found: ' . $this->templatePath);
        }

        return true;
    }

    // =====================================================
    // Set Payload
    // =====================================================

    public function setPayload(array $payload = []): bool
    {
        $this->payload = $payload;
        return true;
    }

    // =====================================================
    // Get Payload
    // =====================================================

    public function getPayload(): array
    {
        return $this->payload;
    }

#endregion

#region SECTION 2 — Template Loading + Rendering

    // =====================================================
    // Load Templates
    // =====================================================

    private function loadTemplates(): array
    {
        if (!file_exists($this->baseTemplatePath)) {
            throw new Exception('Base template missing: ' . $this->baseTemplatePath);
        }

        if (!file_exists($this->templatePath)) {
            throw new Exception('Report template missing: ' . $this->templatePath);
        }

        $base = file_get_contents($this->baseTemplatePath);
        $body = file_get_contents($this->templatePath);

        return [$base, $body];
    }

    // =====================================================
    // Replace Tokens
    // =====================================================

    private function replaceTokens(string $html): string
    {
        foreach ($this->payload as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_PRETTY_PRINT);
            }

            $html = str_replace('{{' . $key . '}}', (string)$value, $html);
        }

        // Auto Map Injection
        if (
            !empty($this->payload['locationLatitude']) &&
            !empty($this->payload['locationLongitude'])
        ) {
            $lat = $this->payload['locationLatitude'];
            $lng = $this->payload['locationLongitude'];

            $mapUrl = "https://maps.googleapis.com/maps/api/staticmap"
                . "?center={$lat},{$lng}&zoom=18&size=650x320"
                . "&maptype=roadmap&markers=color:red%7C{$lat},{$lng}";

            $mapHtml = '<img src="' . $mapUrl . '" style="max-width:100%; height:auto; border:1px solid #ccc;" alt="Map">';
            $html = str_replace('{{mapImage}}', $mapHtml, $html);
        }

        // Remove unresolved tokens
        $html = preg_replace('/{{.*?}}/', '', $html);

        return $html;
    }

    // =====================================================
    // Render HTML
    // =====================================================

    public function renderHtml(): string
    {
        [$base, $body] = $this->loadTemplates();

        $html = str_replace('{{documentBody}}', $body, $base);
        $html = $this->replaceTokens($html);

        $this->renderedHtml = $html;

        return $html;
    }

#endregion

#region SECTION 3 — PDF Generation (mPDF)

    // =====================================================
    // Generate PDF Binary
    // =====================================================

    public function generatePdfBinary(): string
    {
        $html = $this->renderHtml();

        // mPDF Configuration
        $defaultConfig = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaultConfig['fontDir'];

        $defaultFontConfig = (new FontVariables())->getDefaults();
        $fontData = $defaultFontConfig['fontdata'];

        $mpdf = new Mpdf([
            'fontDir' => array_merge($fontDirs, [__DIR__ . '/../../fonts']),
            'fontdata' => $fontData + [
                'arial' => [
                    'R' => 'arial.ttf',
                ]
            ],
            'default_font' => 'arial',
            'margin_left'   => 15,
            'margin_right'  => 15,
            'margin_top'    => 18,
            'margin_bottom' => 18,
        ]);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S'); // Return PDF as string
    }

    // =====================================================
    // Save PDF
    // =====================================================

    public function savePdf(string $outputPath): bool
    {
        $pdfBinary = $this->generatePdfBinary();
        file_put_contents($outputPath, $pdfBinary);

        return true;
    }

    // =====================================================
    // Stream PDF
    // =====================================================

    public function streamPdf(string $filename = 'report.pdf'): void
    {
        $pdfBinary = $this->generatePdfBinary();

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        echo $pdfBinary;

        exit;
    }

#endregion

#region SECTION 4 — Future Utility Expansion

    /*
        Future Expansion Candidates:

        - lineage injection
        - payload validation
        - schema enforcement
        - token auditing
        - asset management
        - image normalization
        - QR generation
        - watermarking
        - lifecycle stamping
        - digital signatures
        - page numbering
        - metadata embedding
        - operational trace injection
    */


}
#endregion