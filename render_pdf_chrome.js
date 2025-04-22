const puppeteer = require('puppeteer');
const path = require('path');
const { execSync } = require('child_process');

(async () => {
  try {
    const inputPath = path.join(__dirname, 'docs/proposal/markdown/leadership_lead_or_sell_v1.1.md');
    const htmlPath = path.join(__dirname, 'docs/proposal/html/leadership_lead_or_sell_v1.1.html');
    const outputPath = path.join(__dirname, 'docs/proposal/pdf/leadership_lead_or_sell_v1.1.pdf');
    const templatePath = path.join(__dirname, 'docs/viewer/proposal-template.html');

    // Step 1: Markdown → HTML
    console.log('📝 Converting Markdown to HTML...');
    execSync(`pandoc -s "${inputPath}" --from markdown+emoji --template="${templatePath}" -o "${htmlPath}"`, { stdio: 'inherit' });

    // Step 2: HTML → PDF
    console.log('🚀 Launching Puppeteer...');
    const browser = await puppeteer.launch({ headless: true });
    const page = await browser.newPage();
    await page.setViewport({ width: 1200, height: 1600 });

    console.log('📄 Loading HTML...');
    await page.goto(`file://${htmlPath}`, { waitUntil: 'networkidle0' });

    console.log('📸 Capturing debug screenshot...');
    await page.screenshot({ path: 'docs/proposal/debug.png', fullPage: true });

    console.log('🖨️ Generating PDF...');
    await page.pdf({
      path: outputPath,
      format: 'A4',
      printBackground: true,
      margin: {
        top: '0.6in',
        bottom: '0.6in',
        left: '0.5in',
        right: '0.5in'
      }
    });

    await browser.close();
    console.log(`✅ PDF generated at: ${outputPath}`);
  } catch (error) {
    console.error('❌ Error:', error.message);
  }
})();
