// 📁 resizeRename.js
const fs = require('fs');
const path = require('path');
const sharp = require('sharp');
const readline = require('readline');

const rl = readline.createInterface({ input: process.stdin, output: process.stdout });

rl.question('📌 Enter project name: ', (projectName) => {
  const inputDir = './input';
  const outputDir = './output';
  if (!fs.existsSync(outputDir)) fs.mkdirSync(outputDir);

  const files = fs.readdirSync(inputDir).filter(f => /\.(jpe?g|png)$/i.test(f));
  if (files.length === 0) {
    console.log('⚠️ No images found in the input folder.');
    rl.close();
    return;
  }

  files.forEach((file, i) => {
    const inputPath = path.join(inputDir, file);
    const padded = String(i + 1).padStart(3, '0');
    const outputName = `${projectName} - Survey Photo ${padded}.jpg`;
    const outputPath = path.join(outputDir, outputName);

    sharp(inputPath)
      .resize({ width: 1600 }) // 👈 Resize width to 1600px, keep aspect
      .jpeg({ quality: 80 })   // 👈 Adjust quality for email size
      .toFile(outputPath)
      .then(() => console.log(`✅ Saved: ${outputName}`))
      .catch(err => console.error(`❌ Error processing ${file}:`, err));
  });

  rl.close();
});
