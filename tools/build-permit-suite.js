// build-permit-suite.js

const fs = require('fs');
const path = require('path');
const { marked } = require('marked');

// Define the correct paths
const markdownPath = path.resolve(__dirname, 'docs', 'skyesoftBlueprint', 'components', 'permit_management_suite.md');
const outputPath = path.resolve(__dirname, 'output', 'permit_management_suite.html');
const assetsRelativePath = path.relative(path.dirname(outputPath), path.resolve(__dirname, 'docs', 'assets'));

// Read Markdown file
if (!fs.existsSync(markdownPath)) {
  console.error(`❌ Markdown file not found at ${markdownPath}`);
  process.exit(1);
}

const markdownContent = fs.readFileSync(markdownPath, 'utf8');
const htmlContent = marked(markdownContent);

// Wrap HTML inside a simple styled template
const fullHtml = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Permit Management Suite</title>
  <link rel="stylesheet" href="${assetsRelativePath}/github-markdown.css">
  <link rel="stylesheet" href="${assetsRelativePath}/style.css">
  <style>
    body {
      padding: 2rem;
    }
    .markdown-body {
      box-sizing: border-box;
      min-width: 200px;
      max-width: 980px;
      margin: 0 auto;
    }
  </style>
</head>
<body>
  <article class="markdown-body">
    ${htmlContent}
  </article>
</body>
</html>`;

// Save the final HTML
fs.writeFileSync(outputPath, fullHtml, 'utf8');
console.log(`✅ Rendered HTML saved to: ${outputPath}`);
