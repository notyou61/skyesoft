<?php

// =====================================================
// Skyesoft™ Report Generator
// Core Rendering Engine
// =====================================================

class ReportGenerator
{

    // =====================================================
    // Properties
    // =====================================================

    private $templatePath;
    private $baseTemplatePath;

    private $templateHtml;
    private $baseHtml;

    private $payload = [];

    private $renderedHtml;

    // =====================================================
    // Constructor
    // =====================================================

    public function __construct($config = [])
    {

        // Base template path
        $this->baseTemplatePath =
            $config['baseTemplatePath']
            ?? __DIR__ . '/../../reports/templates/base.html';

    }

    // =====================================================
    // Set Template
    // =====================================================

    public function setTemplate($templateName)
    {

        // Build template path
        $this->templatePath =
            __DIR__
            . '/../../reports/templates/'
            . $templateName;

        // Validate existence
        if (!file_exists($this->templatePath)) {

            throw new Exception(
                'Template not found: ' . $this->templatePath
            );

        }

        return true;

    }

    // =====================================================
    // Set Payload
    // =====================================================

    public function setPayload($payload = [])
    {

        // Store payload
        $this->payload = $payload;

        return true;

    }

    // =====================================================
    // Load Templates
    // =====================================================

    private function loadTemplates()
    {

        // Validate base template
        if (!file_exists($this->baseTemplatePath)) {

            throw new Exception(
                'Base template missing.'
            );

        }

        // Load base HTML
        $this->baseHtml =
            file_get_contents($this->baseTemplatePath);

        // Load content template
        $this->templateHtml =
            file_get_contents($this->templatePath);

    }

    // =====================================================
    // Replace Tokens
    // =====================================================

    private function replaceTokens($html)
    {

        // Loop payload
        foreach ($this->payload as $key => $value) {

            // Convert arrays to JSON
            if (is_array($value)) {

                $value =
                    json_encode(
                        $value,
                        JSON_PRETTY_PRINT
                    );

            }

            // Replace token
            $html =
                str_replace(
                    '{{' . $key . '}}',
                    $value,
                    $html
                );

        }

        return $html;

    }

    // =====================================================
    // Render HTML
    // =====================================================

    public function renderHtml()
    {

        // Load templates
        $this->loadTemplates();

        // Inject content template
        $this->baseHtml =
            str_replace(
                '{{reportContent}}',
                $this->templateHtml,
                $this->baseHtml
            );

        // Replace tokens
        $this->renderedHtml =
            $this->replaceTokens(
                $this->baseHtml
            );

        return $this->renderedHtml;

    }

    // =====================================================
    // Get HTML
    // =====================================================

    public function getHtml()
    {

        // Render if needed
        if (!$this->renderedHtml) {

            $this->renderHtml();

        }

        return $this->renderedHtml;

    }

    // =====================================================
    // Save HTML Debug Copy
    // =====================================================

    public function saveHtml($path)
    {

        // Ensure rendered
        $html =
            $this->getHtml();

        // Save file
        file_put_contents(
            $path,
            $html
        );

        return true;

    }

    // =====================================================
    // Generate PDF
    // =====================================================

    public function generatePdf($outputPath = null)
    {

        // Ensure rendered
        $html =
            $this->getHtml();

        // Temp HTML
        $tempHtml =
            tempnam(
                sys_get_temp_dir(),
                'skyereport_'
            ) . '.html';

        // Save temp HTML
        file_put_contents(
            $tempHtml,
            $html
        );

        // Default output path
        if (!$outputPath) {

            $outputPath =
                tempnam(
                    sys_get_temp_dir(),
                    'skyepdf_'
                ) . '.pdf';

        }

        // =====================================================
        // wkhtmltopdf Command
        // =====================================================

        $command =
            'wkhtmltopdf '
            . '--enable-local-file-access '
            . '--margin-top 12mm '
            . '--margin-bottom 14mm '
            . '--margin-left 10mm '
            . '--margin-right 10mm '
            . escapeshellarg($tempHtml)
            . ' '
            . escapeshellarg($outputPath);

        // Execute command
        exec(
            $command,
            $output,
            $resultCode
        );

        // Cleanup temp file
        unlink($tempHtml);

        // Validate PDF generation
        if ($resultCode !== 0) {

            throw new Exception(
                'PDF generation failed.'
            );

        }

        return $outputPath;

    }

    // =====================================================
    // Stream PDF
    // =====================================================

    public function streamPdf($filename = 'report.pdf')
    {

        // Generate PDF
        $pdfPath =
            $this->generatePdf();

        // Output headers
        header('Content-Type: application/pdf');

        header(
            'Content-Disposition: inline; filename="'
            . $filename
            . '"'
        );

        header(
            'Content-Length: '
            . filesize($pdfPath)
        );

        // Stream PDF
        readfile($pdfPath);

        // Cleanup
        unlink($pdfPath);

        exit;

    }

}