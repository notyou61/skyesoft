// emoji_embedder.js
const fs = require('fs');
const path = require('path');
const twemoji = require('twemoji');

const inputPath = path.join(__dirname, 'docs/proposal/markdown/leadership_lead_or_sell_v1.1.md');
const outputPath = path.join(__dirname, 'docs/proposal/markdown/tmp_with_emoji.md');

const raw = fs.readFileSync(inputPath, 'utf8');

// Convert emojis to <img> tags using Twemoji CDN
const processed = twemoji.parse(raw, {
  folder: 'svg',
  ext: '.svg',
  base: 'https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/'
});

fs.writeFileSync(outputPath, processed, 'utf8');
console.log('✅ Emoji preprocessing complete:', outputPath);
