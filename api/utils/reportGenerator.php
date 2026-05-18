<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — reportGenerator.php
//  Version: 2.1.1 — Fixed & Prototype Ready
//  Canonical Document Rendering Engine
// ======================================================================

#region SECTION 0 — Dependencies + imports

require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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

    private ?string $baseHtml = null;
    private ?string $templateHtml = null;
    private ?string $renderedHtml = null;

    // =====================================================
    // Constructor
    // =====================================================

    public function __construct(array $config = [])
    {
        $this->baseTemplatePath = $config['baseTemplatePath']
            ?? __DIR__ . '/../../reports/templates/base.html';
    }

    // =====================================================
    // Set Template
    // =====================================================

    public function setTemplate(string $templateName): bool
    {
        $this->templatePath = __DIR__ . '/../../reports/templates/' . $templateName . '.html';

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
    // Load Templates
    // =====================================================

    private function loadTemplates(): void
    {
        if (!file_exists($this->baseTemplatePath)) {
            throw new Exception('Base template missing: ' . $this->baseTemplatePath);
        }

        $this->baseHtml = file_get_contents($this->baseTemplatePath);
        $this->templateHtml = file_get_contents($this->templatePath);
    }

    // =====================================================
    // Replace Tokens + Map Generation
    // =====================================================

    private function replaceTokens(string $html): string
    {
        foreach ($this->payload as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_PRETTY_PRINT);
            }
            $html = str_replace('{{' . $key . '}}', (string)$value, $html);
        }

        // Auto-generate Google Static Map
        if (!empty($this->payload['locationLatitude']) && !empty($this->payload['locationLongitude'])) {
            $lat = $this->payload['locationLatitude'];
            $lng = $this->payload['locationLongitude'];
            $mapUrl = "https://maps.googleapis.com/maps/api/staticmap?center={$lat},{$lng}&zoom=18&size=650x320&maptype=roadmap&markers=color:red%7C{$lat},{$lng}";
            $mapHtml = '<img src="' . $mapUrl . '" style="max-width:100%; height:auto; border:1px solid #ccc;" alt="AI-Verified Location">';
            $html = str_replace('{{mapImage}}', $mapHtml, $html);
        }

        return $html;
    }

    // =====================================================
    // Render HTML
    // =====================================================

    public function renderHtml(): string
    {
        $this->loadTemplates();

        $html = str_replace('{{documentBody}}', $this->templateHtml, $this->baseHtml);
        $this->renderedHtml = $this->replaceTokens($html);

        return $this->renderedHtml;
    }

    // =====================================================
    // Stream PDF to Browser
    // =====================================================

    public function streamPdf(string $filename = 'Proposed_Contact_Report.pdf'): void
    {
        $html = $this->renderHtml();

        $options = new Options();
        $options->setIsRemoteEnabled(true);
        $options->setChroot(__DIR__ . '/../../');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        echo $dompdf->output();
        exit;
    }

    // =====================================================
    // Save HTML for debugging
    // =====================================================

    public function saveHtml(string $path): bool
    {
        file_put_contents($path, $this->renderHtml());
        return true;
    }
}

#endregion