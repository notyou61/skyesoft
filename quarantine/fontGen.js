// 📄 fontGen.js
// Converts a .ttf into a jsPDF-compatible JS font file (Node-safe)
const fs = require("fs");
const path = require("path");

const fontPath = path.join(__dirname, "../fonts/DejaVuSans.ttf");
const outPath  = path.join(__dirname, "../fonts/DejaVuSans.js");

if (!fs.existsSync(fontPath)) {
  console.error("❌ Could not find DejaVuSans.ttf at:", fontPath);
  process.exit(1);
}

const fontData = fs.readFileSync(fontPath).toString("base64");

// ⚡ Node-safe wrapper — no jsPDF global!
const js = `
// 📄 DejaVuSans.js (generated)
// Full font embedded for jsPDF (Node-safe)
module.exports = function(jsPDFAPI) {
  var font = '${fontData}';
  jsPDFAPI.addFileToVFS('DejaVuSans.ttf', font);
  jsPDFAPI.addFont('DejaVuSans.ttf', 'DejaVu', 'normal');
  jsPDFAPI.addFont('DejaVuSans.ttf', 'DejaVu', 'bold');
  jsPDFAPI.addFont('DejaVuSans.ttf', 'DejaVu', 'italic');
};
`;

fs.writeFileSync(outPath, js, "utf8");
console.log(`✅ Generated ${outPath}`);