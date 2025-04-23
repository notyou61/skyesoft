const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const twemoji = require('twemoji');

(async () => {
  const inputMd = path.join(__dirname, 'docs/proposal/markdown/leadership_lead_or_sell_v1.1.md');
  const outputHtml = path.join(__dirname, 'docs/proposal/html/leadership_lead_or_sell_v1.1.html');
  const outputPdf = path.join(__dirname, 'docs/proposal/pdf/leadership_lead_or_sell_v1.1.pdf');

  // Step 1: Convert Markdown to basic HTML with Pandoc
  execSync(`pandoc -s "${inputMd}" --template=docs/viewer/proposal-template.html -o "${outputHtml}"`);

  // Step 2: Load the HTML and replace emoji with Twemoji images
  let html = fs.readFileSync(outputHtml, 'utf8');
  html = twemoji.parse(html, {
    folder: 'svg',
    ext: '.svg',
    base: 'https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/',
  });
  fs.writeFileSync(outputHtml, html);

  // Step 3: Launch Puppeteer and convert to PDF
  const browser = await puppeteer.launch();
  const page = await browser.newPage();
  await page.goto(`file://${outputHtml}`, { waitUntil: 'networkidle0' });
  await page.pdf({
    path: outputPdf,
    format: 'A4',
    margin: {
      top: '0.6in',
      bottom: '0.6in',
      left: '0.5in',
      right: '0.5in',
    },
    printBackground: true,
  });

  await browser.close();
  console.log(`✅ PDF generated with emojis: ${outputPdf}`);
})();
