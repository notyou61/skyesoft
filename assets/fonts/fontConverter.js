// 📄 assets/fonts/fontConverter.js
// Convert TTF -> jsPDF .js font file
// Usage: node assets/fonts/fontConverter.js DejaVuSans.ttf DejaVu normal

const fs = require("fs");
const path = require("path");
const { Font } = require("jspdf");

if (process.argv.length < 5) {
  console.error("❌ Usage: node fontConverter.js <ttfFile> <familyName> <style>");
  process.exit(1);
}

const [ , , ttfFile, family, style ] = process.argv;

const ttfPath = path.resolve(ttfFile);
if (!fs.existsSync(ttfPath)) {
  console.error(`❌ File not found: ${ttfPath}`);
  process.exit(1);
}

// Read TTF
const fontData = fs.readFileSync(ttfPath, "base64");

// Build JS wrapper
const jsContent = `
module.exports = function(doc) {
  doc.addFileToVFS("${path.basename(ttfFile)}", "${fontData}");
  doc.addFont("${path.basename(ttfFile)}", "${family}", "${style}");
};
`;

const outFile = path.join(
  path.dirname(ttfPath),
  `${family}${style === "normal" ? "" : "-" + style}.js`
);

fs.writeFileSync(outFile, jsContent);
console.log(`✅ Created ${outFile}`);
