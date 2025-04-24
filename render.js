const fs = require('fs');
const path = require('path');

const artifactKey = process.argv[2] || 'lead_or_sell';
const jsonPath = path.join(__dirname, 'docs', 'master_content.json');
const templatePath = path.join(__dirname, 'docs', 'assets', 'template.html');
const outputPath = path.join(__dirname, 'output', `${artifactKey}.html`);

const json = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
const artifact = json.artifacts.find(d => d.meta.id === artifactKey);

if (!artifact) {
  console.error(`❌ Artifact with ID '${artifactKey}' not found.`);
  process.exit(1);
}

const meta = artifact.meta || {};
const sections = artifact.content.sections || [];

let html = `<h1>${meta.title || ''}</h1>\n`;
html += `<p><strong>Date:</strong> ${meta.date || ''}<br><strong>Author:</strong> ${meta.author || ''}</p>\n`;

sections.forEach((section) => {
  const icon = section.icon ? `${section.icon} ` : '';
  const title = section.title || section.heading || '';
  const content = section.content || section.body || '';
  html += `<h2>${icon}${title}</h2>\n`;

  const lines = content.split('\n');
  let topLevelItems = [];
  let currentItem = null;
  let inEmojiList = false;

  lines.forEach((line) => {
    const trimmed = line.trim();
    if (!trimmed) return;

    const isDashEmoji = /^[-•–]\s([\p{Emoji_Presentation}\p{Extended_Pictographic}])/u.test(trimmed);
    const isTopLevel = /^((\d+\.)|[\p{Emoji_Presentation}\p{Extended_Pictographic}])\s+/u.test(trimmed);
    const isSubBullet = /^[-•–]\s+/.test(trimmed);

    if (isDashEmoji) {
      if (!inEmojiList) {
        html += `<ul class="bulleted">\n`;
        inEmojiList = true;
      }
      const clean = trimmed.replace(/^[-•–]\s+/, '');
      html += `<li><strong>${clean}</strong></li>\n`;
    } else if (isTopLevel) {
      if (inEmojiList) {
        html += `</ul>\n`;
        inEmojiList = false;
      }
      if (currentItem) topLevelItems.push(currentItem);
      currentItem = { title: trimmed, subitems: [] };
    } else if ((isSubBullet || /^[\p{Emoji_Presentation}\p{Extended_Pictographic}]/u.test(trimmed)) && currentItem) {
      const clean = trimmed.replace(/^[-•–\s]*/, '');
      currentItem.subitems.push(clean);
    } else if (currentItem) {
      currentItem.subitems.push(trimmed);
    } else {
      if (inEmojiList) {
        html += `</ul>\n`;
        inEmojiList = false;
      }
      html += `<p>${trimmed}</p>\n`;
    }
  });

  if (inEmojiList) html += `</ul>\n`;
  if (currentItem) topLevelItems.push(currentItem);

  if (topLevelItems.length) {
    html += `<ol class="numbered">\n`;
    topLevelItems.forEach(item => {
      const cleanTitle = item.title.replace(/^((\d+\.)|[\p{Emoji_Presentation}\p{Extended_Pictographic}])\s+/, '').trim();
      html += `<li><strong>${cleanTitle}</strong>\n`;
      if (item.subitems.length) {
        html += `<ul class="bulleted">\n`;
        item.subitems.forEach(sub => {
          html += `<li>${sub}</li>\n`;
        });
        html += `</ul>\n`;
      }
      html += `</li>\n`;
    });
    html += `</ol>\n`;
  }
});

const template = fs.readFileSync(templatePath, 'utf8');
const finalHtml = template.replace('$body$', html);

if (!fs.existsSync('output')) fs.mkdirSync('output');
fs.writeFileSync(outputPath, finalHtml, 'utf8');

console.log(`✅ Rendered HTML saved to: ${outputPath}`);
