const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');

// Read the document key from the command line
const docKey = process.argv[2] || 'lead_or_sell';

// Define paths
const htmlPath = path.join(__dirname, 'output', `${docKey}.html`);
const pdfPath = path.join(__dirname, 'output', `${docKey}.pdf`);

// Check if HTML file exists first
if (!fs.existsSync(htmlPath)) {
  console.error(`❌ Cannot export: HTML file not found at ${htmlPath}`);
  process.exit(1);
}

(async () => {
  const browser = await puppeteer.launch();
  const page = await browser.newPage();

  await page.goto(`file://${htmlPath}`, { waitUntil: 'networkidle0' });

  await page.pdf({
    path: pdfPath,
    format: 'A4',
    printBackground: true,
    margin: { top: '1in', bottom: '1in', left: '0.75in', right: '0.75in' }
  });

  await browser.close();
  console.log(`✅ PDF exported: ${pdfPath}`);
})();
