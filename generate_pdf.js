const puppeteer = require('puppeteer');
const path = require('path');

(async () => {
  const browser = await puppeteer.launch();
  const page = await browser.newPage();

  // Construct the local path to your rendered HTML
  const filePath = path.resolve(__dirname, 'lead_or_sell_rendered.html');
  const fileUrl = `file://${filePath}`;

  console.log(`Opening ${fileUrl}...`);
  await page.goto(fileUrl, { waitUntil: 'networkidle0' });

  // Output PDF
  const outputPath = path.resolve(__dirname, 'lead_or_sell.pdf');
  await page.pdf({
    path: outputPath,
    format: 'A4',
    printBackground: true,
    margin: {
      top: '1in',
      bottom: '1in',
      left: '0.75in',
      right: '0.75in'
    }
  });

  console.log(`PDF saved to ${outputPath}`);
  await browser.close();
})();