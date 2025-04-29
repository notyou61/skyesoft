const fs = require('fs');
const path = require('path');
const markdownIt = require('markdown-it');
const mdContainer = require('markdown-it-container');

// Initialize markdown-it with container plugin
const md = markdownIt({
  html: true,
  breaks: true,
  linkify: true
});

md.use(mdContainer, 'no-break', {
  render(tokens, idx) {
    const token = tokens[idx];
    return token.nesting === 1 ? '<div class="no-break">\n' : '</div>\n';
  }
});

// Base paths
const baseDocsDir = path.resolve(__dirname, 'docs');
const outputDir = path.resolve(__dirname, 'output');

// Create output folder if it doesn't exist
if (!fs.existsSync(outputDir)) {
  fs.mkdirSync(outputDir);
}

// Walk through all markdown files in docs/
function walkDirectory(dir) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });

  entries.forEach(entry => {
    const fullPath = path.join(dir, entry.name);

    if (entry.isDirectory()) {
      walkDirectory(fullPath);
    } else if (entry.isFile() && fullPath.endsWith('.md')) {
      convertMarkdownToHTML(fullPath);
    }
  });
}

// Convert each .md file to .html
function convertMarkdownToHTML(mdPath) {
  const markdownText = fs.readFileSync(mdPath, 'utf-8');
  const htmlBody = md.render(markdownText);

  const artifactName = path.basename(mdPath, '.md');
  const outputPath = path.join(outputDir, `${artifactName}.html`);

  const fullHtml = `
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>${artifactName}</title>
  <link rel="stylesheet" href="../docs/assets/github-markdown.css">
  <link rel="stylesheet" href="../docs/assets/style.css">
  <link rel="stylesheet" href="../docs/assets/pdf.css">
</head>
<body class="markdown-body">
${htmlBody}
</body>
</html>`;

  fs.writeFileSync(outputPath, fullHtml.trim());
  console.log(`✅ Rendered HTML saved to: ${outputPath}`);
}

// Start processing
walkDirectory(baseDocsDir);
