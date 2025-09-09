// 📄 File: assets/js/generateReport.js
// Universal Codex Information Sheet Generator with slug-icon headers

const fs = require("fs");
const path = require("path");
const { jsPDF } = require("jspdf");

// --- PDF Setup ---
const doc = new jsPDF({ unit: "pt", format: "letter" });

// --- Load DejaVu Fonts ---
require("../../assets/fonts/DejaVu.js")(doc);
require("../../assets/fonts/DejaVu-bold.js")(doc);
require("../../assets/fonts/DejaVu-italic.js")(doc);
require("../../assets/fonts/DejaVu-bolditalic.js")(doc);

// --- Paths ---
const codexDocsDir = path.join(__dirname, "../../docs/codex");
const reportsDir = path.join(__dirname, "../../docs/reports");
const codexJson = path.join(codexDocsDir, "codex.json");
const logoPath = path.join(__dirname, "../../assets/images/christyLogo.png");

if (!fs.existsSync(reportsDir)) fs.mkdirSync(reportsDir, { recursive: true });

// --- CLI arg ---
const slug = process.argv[2];
if (!slug) {
  console.error("❌ Usage: node assets/js/generateReport.js <slug>");
  process.exit(1);
}

// --- Date ---
const today = new Date();
const dateStr = today.toISOString().split("T")[0]; // YYYY-MM-DD

// --- Page Info ---
const pageWidth = doc.internal.pageSize.getWidth();
const pageHeight = doc.internal.pageSize.getHeight();

// --- Helpers ---
function cleanText(txt) {
  if (!txt) return "";
  return txt
    .toString()
    .replace(/[-\u001F\u007F-\u009F]/g, "")
    .replace(/\*\*/g, "")
    .replace(/\*/g, "")
    .replace(/`/g, "")
    .replace(/---/g, "");
}

function safeSetFont(family, style) {
  const fonts = doc.getFontList();
  const fam = family in fonts ? family : "helvetica";
  const styles = fonts[fam] || [];
  let safeStyle =
    styles.includes(style) ? style : styles.includes("normal")
      ? "normal"
      : styles[0] || "normal";
  doc.setFont(fam, safeStyle);
}

function toTitleCase(str) {
  return str
    .replace(/([a-z])([A-Z])/g, "$1 $2")
    .replace(/_/g, " ")
    .split(/\s+/)
    .map(
      (word) =>
        word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()
    )
    .join(" ");
}

// --- Slug Icons ---
const SLUG_ICONS = {
  reportgenerationsuite: "📑",
  coredatabasestructure: "🧩",
  glossary: "📘",
  constitution: "📜",
  attendancesuite: "🕒",
  permitmanagementsuite: "🗂️",
  financialcontrolsuite: "💼",
  servicemanagementsuite: "🛠️",
  managementescalationtrees: "📈",
  mobilefirstmodals: "📱",
  officebulletins: "📢",
  onelinetask: "🧠",
  realtimeSSE: "🔄",
  skyebotcontactparser: "🤖",
  loginsessionsuite: "🔐",
  default: "📄"
};

// --- Section Icons ---
const SECTION_ICONS = {
  purpose: "🎯 Purpose",
  features: "⚙ Features",
  workflow: "📋 Workflow",
  integrations: "🔌 Integrations",
  status: "📌 Status",
  rules: "📜 Rules",
  sourcesoftruth: "📂 Sources of Truth",
  aibehavior: "🤖 AI Behavior",
  examples: "💡 Examples",
  futureenhancements: "🔮 Future Enhancements",
  strategicimportance: "🏆 Strategic Importance",
  components: "🧩 Components",
  dashboards: "📊 Dashboards",
  controls: "🛡 Controls",
  architecture: "🏗 Architecture",
  guidelines: "📖 Guidelines",
  entries: "📘 Glossary Entries",
  logging: "📝 Logging",
  files: "📂 Files",
  conventions: "📜 Conventions",
  disclaimers: "⚠️ Disclaimers",
  reporttypesspec: "📑 Report Types Spec",
  lastupdated: "📅 Last Updated",
  usecases: "📋 Use Cases",
  types: "📄 Types",
  default: "📄 Section"
};

function formatSectionTitle(key) {
  return SECTION_ICONS[key.toLowerCase()] || `📄 ${toTitleCase(key)}`;
}

// --- Header ---
function addHeader(title, slug) {
  const logoTopY = 25;
  let logoBottomY = logoTopY + 60;
  let w = 0,
    h = 0;

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
  const centerY = logoTopY + h / 2;

  // Main header
  safeSetFont("DejaVu", "bold");
  doc.setFontSize(14);
  doc.text("Codex Information Sheet", textX, centerY - 6);

  // Module title with icon
  const icon = SLUG_ICONS[slug.toLowerCase()] || SLUG_ICONS.default;
  doc.setFontSize(12);
  doc.text(`${icon} ${title}`, textX, centerY + 10);

  // Date/creator line
  safeSetFont("DejaVu", "normal");
  doc.setFontSize(10);
  doc.text(`Created by Skyesoft – ${dateStr}`, textX, centerY + 26);

  // Divider
  const lineY = Math.max(logoBottomY, centerY + 40) + 10;
  doc.setLineWidth(0.5);
  doc.line(40, lineY, pageWidth - 40, lineY);
  return lineY;
}

function newPageWithHeader(title, slug) {
  doc.addPage();
  return addHeader(title, slug) + 10;
}

// --- Footer ---
function addFooter(pageNum, totalPages) {
  doc.setLineWidth(0.5);
  doc.line(40, pageHeight - 50, pageWidth - 40, pageHeight - 50);
  safeSetFont("DejaVu", "normal");
  doc.setFontSize(9);
  doc.text("Skyesoft Systems – Internal Project Overview", 40, pageHeight - 35);
  doc.text(
    `Skyesoft™ Info Sheet | Updated: ${dateStr}`,
    40,
    pageHeight - 22
  );
  doc.text(
    `Page ${pageNum} of ${totalPages}`,
    pageWidth - 40,
    pageHeight - 22,
    { align: "right" }
  );
}

// --- Universal Section Renderer ---
function renderSection(key, content, yStart) {
  let y = yStart + 20;
  const sectionTitle = formatSectionTitle(key);
  safeSetFont("DejaVu", "bold");
  doc.setFontSize(12);
  doc.text(sectionTitle, 40, y);
  y += 6;
  doc.setLineWidth(0.5);
  doc.line(40, y, pageWidth - 40, y); // horizontal line
  y += 14;

  if (typeof content === "string") {
    safeSetFont("DejaVu", "normal");
    doc.setFontSize(10);
    const wrapped = doc.splitTextToSize(cleanText(content), pageWidth - 80);
    wrapped.forEach((line) => {
      doc.text(line, 40, y);
      y += 14;
    });
  } else if (Array.isArray(content)) {
    content.forEach((item) => {
      safeSetFont("DejaVu", "normal");
      doc.setFontSize(10);
      doc.circle(50, y - 3, 2, "F"); // bullet
      const wrapped = doc.splitTextToSize(cleanText(item), pageWidth - 100);
      wrapped.forEach((wl, idx) => {
        doc.text(wl, idx === 0 ? 60 : 70, y);
        y += 12;
      });
      y += 6;
    });
  } else if (typeof content === "object" && content) {
    for (const [k, v] of Object.entries(content)) {
      safeSetFont("DejaVu", "bold");
      doc.setFontSize(10);
      doc.text(`${toTitleCase(k)}:`, 50, y);
      y += 14;
      if (typeof v === "string") {
        safeSetFont("DejaVu", "normal");
        const wrapped = doc.splitTextToSize(cleanText(v), pageWidth - 100);
        wrapped.forEach((line) => {
          doc.text(line, 70, y);
          y += 12;
        });
      } else if (Array.isArray(v)) {
        v.forEach((item) => {
          safeSetFont("DejaVu", "normal");
          doc.circle(65, y - 3, 1.5, "F");
          const wrapped = doc.splitTextToSize(cleanText(item), pageWidth - 120);
          wrapped.forEach((line) => {
            doc.text(line, 80, y);
            y += 12;
          });
          y += 4;
        });
      }
    }
  }
  return y + 10;
}

// --- Render JSON Section ---
function renderJsonSection(sectionData, headerBottomY, slug) {
  let y = headerBottomY + 30;

  safeSetFont("DejaVu", "bold");
  doc.setFontSize(16);
  doc.text(`🗂 ${sectionData.title || "Untitled Section"}`, 40, y);
  y += 20;

  const keys = sectionData.order && Array.isArray(sectionData.order)
    ? sectionData.order
    : Object.keys(sectionData).filter(k => k !== "title" && k !== "order");

  for (const key of keys) {
    const value = sectionData[key];
    y = renderSection(key, value, y);
    if (y > pageHeight - 150) {
      y = newPageWithHeader(sectionData.title, slug) + 30;
    }
  }
  return y;
}

// --- Build Document ---
if (!fs.existsSync(codexJson)) {
  console.error("❌ codex.json not found");
  process.exit(1);
}

const codex = JSON.parse(fs.readFileSync(codexJson, "utf8"));
let sectionData = codex.modules?.[slug] || codex[slug];

if (!sectionData) {
  console.error(`❌ No section found in codex.json for slug '${slug}'`);
  process.exit(1);
}

const headerBottomY = addHeader(sectionData.title || slug, slug);
renderJsonSection(sectionData, headerBottomY, slug);

// --- Footer Pages ---
const totalPages = doc.internal.getNumberOfPages();
for (let i = 1; i <= totalPages; i++) {
  doc.setPage(i);
  addFooter(i, totalPages);
}

// --- Save ---
const outPath = path.join(reportsDir, `codex_info_sheet.pdf`);
doc.save(outPath);
console.log(`✅ Report generated: ${outPath} (slug: ${slug})`);
