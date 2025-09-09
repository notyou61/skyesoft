// 📄 File: assets/js/generateReport.js
// Generates branded, template-driven PDF reports from codex.json or markdown

const fs = require("fs");
const path = require("path");
const { jsPDF } = require("jspdf");
const autoTable = require("jspdf-autotable").default;
const MarkdownIt = require("markdown-it");

// --- PDF Setup ---
const doc = new jsPDF({ unit: "pt", format: "letter" });

// --- Load DejaVu Fonts (normal, bold, italic, bolditalic) ---
require("../../assets/fonts/DejaVu.js")(doc);
require("../../assets/fonts/DejaVu-bold.js")(doc);
require("../../assets/fonts/DejaVu-italic.js")(doc);
require("../../assets/fonts/DejaVu-bolditalic.js")(doc);

// --- Paths ---
const codexDocsDir = path.join(__dirname, "../../docs/codex");
const reportsDir   = path.join(__dirname, "../../docs/reports");
const codexJson    = path.join(codexDocsDir, "codex.json");
const logoPath     = path.join(__dirname, "../../assets/images/christyLogo.png");
const iconDir      = path.join(__dirname, "../../assets/images/icons");

if (!fs.existsSync(reportsDir)) fs.mkdirSync(reportsDir, { recursive: true });

// CLI arg
const slug = process.argv[2];
if (!slug) {
  console.error("❌ Usage: node assets/js/generateReport.js <slug>");
  process.exit(1);
}

// Current date
const date = new Date().toLocaleDateString("en-US", {
  month: "2-digit", day: "2-digit", year: "numeric"
});

// --- PDF Page Info ---
const pageWidth  = doc.internal.pageSize.getWidth();
const pageHeight = doc.internal.pageSize.getHeight();

// --- Markdown Parser ---
const md = new MarkdownIt({ html: true, breaks: true });

// --- Utility ---
function cleanText(txt) {
  return txt
    .replace(/[-\u001F\u007F-\u009F]/g, "")
    .replace(/\*\*/g, "")
    .replace(/\*/g, "")
    .replace(/`/g, "")
    .replace(/---/g, "");
}

// --- Classes ---
const CLASSES = {
  ReportTitle:   { fontSize: 16, fontStyle: "bold",       ySpacing: 20 },
  SectionHeader: { fontSize: 14, fontStyle: "bold",       ySpacing: 20 },
  SubHeader:     { fontSize: 12, fontStyle: "bold",       ySpacing: 16 },
  Paragraph:     { fontSize: 10, fontStyle: "normal",     ySpacing: 14 },
  Bullet:        { fontSize: 10, fontStyle: "normal",     ySpacing: 12 },
  Table:         { fontSize: 10 }
};

// --- Safe Font Setter ---
function safeSetFont(family, style) {
  const fonts = doc.getFontList();
  const fam = family in fonts ? family : "helvetica";
  const styles = fonts[fam] || [];

  let safeStyle = style;
  if (!styles.includes(style)) {
    if (styles.includes("normal")) safeStyle = "normal";
    else if (styles.includes("bold")) safeStyle = "bold";
    else if (styles.includes("italic")) safeStyle = "italic";
    else safeStyle = styles[0] || "normal";
  }

  doc.setFont(fam, safeStyle);
}

// --- Default Font ---
safeSetFont("DejaVu", "normal");

// --- Header ---
function addHeader(title) {
  const logoTopY = 25;
  let logoBottomY = logoTopY + 60;
  let w = 0, h = 0;

  try {
    const img = fs.readFileSync(logoPath).toString("base64");
    const imgProps = doc.getImageProperties("data:image/png;base64," + img);
    const scale = 60 / imgProps.height;
    w = imgProps.width * scale;
    h = imgProps.height * scale;
    doc.addImage("data:image/png;base64," + img, "PNG", 40, logoTopY, w, h);
    logoBottomY = logoTopY + h;
  } catch {
    console.warn("⚠️ Logo not found");
  }

  const textX = 40 + w + 8;
  const centerY = logoTopY + (h / 2);

  doc.setFontSize(14);
  safeSetFont("DejaVu", "bold");
  doc.text(`Codex Information Sheet – ${title}`, textX, centerY - 2);
  safeSetFont("DejaVu", "normal");
  doc.setFontSize(10);
  doc.text(`Created by Skyesoft – ${date}`, textX, centerY + 14);

  const lineY = Math.max(logoBottomY, centerY + 20) + 10;
  doc.setLineWidth(0.5);
  doc.line(40, lineY, pageWidth - 40, lineY);
  return lineY;
}

function newPageWithHeader(title) {
  doc.addPage();
  return addHeader(title) + 10;
}

// --- Footer ---
function addFooter(pageNum, totalPages) {
  doc.setLineWidth(0.5);
  doc.line(40, pageHeight - 50, pageWidth - 40, pageHeight - 50);
  safeSetFont("DejaVu", "normal");
  doc.setFontSize(9);
  doc.text("© Christy Signs | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com", 40, pageHeight - 30);
  doc.text(`Page ${pageNum} of ${totalPages}`, pageWidth - 40, pageHeight - 30, { align: "right" });
}

// --- Render Section ---
function renderSection(title, content, yStart) {
  let y = yStart;
  safeSetFont("DejaVu", "bold");
  doc.setFontSize(12);
  doc.text(cleanText(title), 40, y + 20);
  y += 30;

  if (typeof content === "string") {
    const wrapped = doc.splitTextToSize(cleanText(content), pageWidth - 80);
    wrapped.forEach(line => {
      safeSetFont("DejaVu", "normal");
      doc.setFontSize(10);
      doc.text(line, 40, y);
      y += 14;
    });
  } else if (Array.isArray(content)) {
    content.forEach(item => {
      safeSetFont("DejaVu", "normal");
      doc.setFontSize(10);
      doc.text("• " + cleanText(item), 60, y);
      y += 12;
    });
  } else if (typeof content === "object" && content) {
    for (const [k, v] of Object.entries(content)) {
      safeSetFont("DejaVu", "bold");
      doc.setFontSize(10);
      doc.text(cleanText(k) + ":", 40, y);
      y += 14;
      if (typeof v === "string") {
        safeSetFont("DejaVu", "normal");
        const wrapped = doc.splitTextToSize(cleanText(v), pageWidth - 80);
        wrapped.forEach(line => {
          doc.text(line, 60, y);
          y += 12;
        });
      } else if (Array.isArray(v)) {
        v.forEach(item => {
          safeSetFont("DejaVu", "normal");
          doc.text("• " + cleanText(item), 60, y);
          y += 12;
        });
      }
    }
  }
  return y;
}

// --- Render JSON Module ---
function renderJsonModule(moduleData, headerBottomY) {
  let y = headerBottomY + 20;

  const keys = moduleData.order && Array.isArray(moduleData.order)
    ? moduleData.order
    : Object.keys(moduleData);

  for (const key of keys) {
    if (key === "title" || key === "order") continue;
    const value = moduleData[key];
    y = renderSection(key, value, y) + 20;
    if (y > pageHeight - 150) {
      y = newPageWithHeader(moduleData.title) + 20;
    }
  }
}

// --- Markdown fallback ---
function renderMarkdown(mdText, startY, title = "") {
  let y = startY;
  const lines = mdText.split("\n");
  lines.forEach(line => {
    if (!line.trim()) return;
    safeSetFont("DejaVu", "normal");
    doc.setFontSize(10);
    const wrapped = doc.splitTextToSize(cleanText(line), pageWidth - 80);
    wrapped.forEach(wl => {
      doc.text(wl, 40, y);
      y += 14;
    });
  });
  return y;
}

// --- Build Document ---
let reportTitle = slug;
const headerBottomY = (() => {
  if (fs.existsSync(codexJson)) {
    const codex = JSON.parse(fs.readFileSync(codexJson, "utf8"));
    if (codex.modules && codex.modules[slug] && codex.modules[slug].title) {
      reportTitle = codex.modules[slug].title;
    } else if (codex[slug] && codex[slug].title) {
      reportTitle = codex[slug].title;
    }
  }
  return addHeader(reportTitle);
})();

let usedJson = false;
if (fs.existsSync(codexJson)) {
  const codex = JSON.parse(fs.readFileSync(codexJson, "utf8"));
  let moduleData = codex.modules?.[slug] || codex[slug];
  if (moduleData) {
    renderJsonModule(moduleData, headerBottomY);
    usedJson = true;
  }
}

if (!usedJson) {
  const mdPath = path.join(codexDocsDir, `${slug}.md`);
  if (!fs.existsSync(mdPath)) {
    console.error(`❌ No JSON or markdown found for '${slug}'`);
    process.exit(1);
  }
  const mdContent = fs.readFileSync(mdPath, "utf8");
  renderMarkdown(mdContent, headerBottomY + 10, reportTitle);
}

const totalPages = doc.internal.getNumberOfPages();
for (let i = 1; i <= totalPages; i++) {
  doc.setPage(i);
  addFooter(i, totalPages);
}

const outPath = path.join(reportsDir, `codex_info_sheet.pdf`);
doc.save(outPath);
console.log(`✅ Report generated: ${outPath} (slug: ${slug})`);
