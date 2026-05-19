<?php
declare(strict_types=1);

// ======================================================================
//  Skyesoft — reportGenerator.php
//  Version: 3.1.0 — Clean mPDF Implementation
// ======================================================================

#region SECTION 0 — Dependencies

require_once __DIR__ . '/../../vendor/autoload.php';

use Mpdf\Mpdf;

#endregion

#region SECTION 1 — Report Generator Class

class ReportGenerator
{
    private ?string $baseTemplatePath = null;
    private ?string $templatePath = null;
    private array $payload = [];

    public function __construct(array $config = [])
    {
        $this->baseTemplatePath = $config['baseTemplatePath']
            ?? __DIR__ . '/../../reports/templates/base.html';
    }

    public function setTemplate(string $templateName): bool
    {
        $this->templatePath = __DIR__ . '/../../reports/templates/' . $templateName . '.html';

        if (!file_exists($this->templatePath)) {
            throw new Exception('Template not found: ' . $this->templatePath);
        }
        return true;
    }

    public function setPayload(array $payload = []): bool
    {
        $this->payload = $payload;
        return true;
    }

    private function loadTemplates(): array
    {
        if (!file_exists($this->baseTemplatePath)) {
            throw new Exception('Base template missing: ' . $this->baseTemplatePath);
        }
        if (!file_exists($this->templatePath)) {
            throw new Exception('Report template missing: ' . $this->templatePath);
        }

        return [
            file_get_contents($this->baseTemplatePath),
            file_get_contents($this->templatePath)
        ];
    }

    private function replaceTokens(string $html): string
    {
        foreach ($this->payload as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_PRETTY_PRINT);
            }
            $html = str_replace('{{' . $key . '}}', (string)$value, $html);
        }

        // Remove any remaining tokens
        $html = preg_replace('/{{.*?}}/', '', $html);

        return $html;
    }

    public function renderHtml(): string
    {
        [$base, $body] = $this->loadTemplates();
        $html = str_replace('{{documentBody}}', $body, $base);
        return $this->replaceTokens($html);
    }

    // =====================================================
    // Generate PDF (Clean mPDF version - like test_mpdf.php)
    // =====================================================
    public function generatePdfBinary(): string
    {
        $html = $this->renderHtml();

        // Clean mPDF initialization (similar to working test)
        $mpdf = new Mpdf([
            'margin_left'   => 18,
            'margin_right'  => 18,
            'margin_top'    => 20,
            'margin_bottom' => 20,
        ]);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S'); // Return as string
    }

    public function savePdf(string $outputPath): bool
    {
        $pdfBinary = $this->generatePdfBinary();
        file_put_contents($outputPath, $pdfBinary);
        return true;
    }

    public function streamPdf(string $filename = 'report.pdf'): void
    {
        $pdfBinary = $this->generatePdfBinary();

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        echo $pdfBinary;
        exit;
    }
}

#endregion