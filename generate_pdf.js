
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

(async () => {
  const artifactId = process.argv[2];

  if (!artifactId) {
    console.error('❌ Usage: node generate_pdf.js <artifact_id>');
    process.exit(1);
  }

  const inputPath = path.resolve(__dirname, 'output', `${artifactId}.html`);
  const outputPath = path.resolve(__dirname, 'output', `${artifactId}.pdf`);

  if (!fs.existsSync(inputPath)) {
    console.error(`❌ HTML file not found at ${inputPath}`);
    process.exit(1);
  }

  const browser = await puppeteer.launch();
  const page = await browser.newPage();

  await page.goto('file://' + inputPath, {
    waitUntil: 'networkidle0'
  });

  await page.pdf({
    path: outputPath,
    format: 'A4',
    printBackground: true,
    margin: {
      top: '1in',
      right: '0.75in',
      bottom: '1in',
      left: '0.75in'
    }
  });

  await browser.close();
  console.log(`✅ PDF generated at ${outputPath}`);
})();
