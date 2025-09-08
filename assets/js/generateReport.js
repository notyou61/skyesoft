// 📄 File: assets/js/generateReport.js
// Generates branded, template-driven PDF reports
// Reads from codex.json if module exists, else falls back to markdown
// Requires: npm install jspdf jspdf-autotable markdown-it

const fs = require("fs");
const path = require("path");
const { jsPDF } = require("jspdf");
const autoTable = require("jspdf-autotable").default;
const MarkdownIt = require("markdown-it");

// --- PDF Setup ---
const doc = new jsPDF({ unit: "pt", format: "letter" });
doc.setFont("DejaVu", "normal");

// Load DejaVuSans for Unicode support (headers/titles)
require("../../assets/fonts/DejaVuSans.js")(doc);
require("../../assets/fonts/DejaVu.js")(doc);
require("../../assets/fonts/DejaVu-Bold.js")(doc);
require("../../assets/fonts/DejaVu-Oblique.js")(doc);
require("../../assets/fonts/DejaVu-BoldOblique.js")(doc);

// 🔎 Log available fonts to console
//console.log("Available fonts:", doc.getFontList());

const codexDocsDir = path.join(__dirname, "../../docs/codex");
const reportsDir   = path.join(__dirname, "../../docs/reports");
const codexJson    = path.join(codexDocsDir, "../codex.json");
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
  ReportTitle: { fontSize: 16, fontStyle: "bold", ySpacing: 20 },
  SectionHeader: { fontSize: 14, fontStyle: "bold", ySpacing: 20 },
  SubHeader: { fontSize: 12, fontStyle: "bold", ySpacing: 16 },
  Paragraph: { fontSize: 10, fontStyle: "normal", ySpacing: 14 },
  Bullet: { fontSize: 10, fontStyle: "normal", ySpacing: 12 },
  Table: { fontSize: 10 }
};

// --- Header Type Mapping (PNG icons only) ---
const HEADER_TYPES = {
  Project:       { keywords: ["primary role", "project information"], icon: path.join(iconDir, "pin.png"), cls: "SectionHeader" },
  Owner:         { keywords: ["day types", "owner information"], icon: path.join(iconDir, "owner.png"), cls: "SectionHeader" },
  Property:      { keywords: ["time segments", "property information"], icon: path.join(iconDir, "property.png"), cls: "SectionHeader" },
  Notes:         { keywords: ["zoning notes", "exclusion"], icon: path.join(iconDir, "notes.png"), cls: "SectionHeader" },
  Integration:   { keywords: ["integration", "recommendations"], icon: path.join(iconDir, "integration.png"), cls: "SectionHeader" },
  Dependencies:  { keywords: ["dependencies"], icon: path.join(iconDir, "dependencies.png"), cls: "SectionHeader" },
  Weather:       { keywords: ["weather"], icon: path.join(iconDir, "weather.png"), cls: "SectionHeader" },
  Calendar:      { keywords: ["calendar"], icon: path.join(iconDir, "calendar.png"), cls: "SectionHeader" },
  Sunrise:       { keywords: ["sunrise"], icon: path.join(iconDir, "sunrise.png"), cls: "SectionHeader" },
  Sunset:        { keywords: ["sunset"], icon: path.join(iconDir, "sunset.png"), cls: "SectionHeader" },
  Workman:       { keywords: ["workman"], icon: path.join(iconDir, "workman.png"), cls: "SectionHeader" },
  Hourglass:     { keywords: ["hourglass"], icon: path.join(iconDir, "hourglass.png"), cls: "SectionHeader" },
  WorkmanVest:   { keywords: ["workman vest"], icon: path.join(iconDir, "workman_vest.png"), cls: "SectionHeader" },
  Tools:         { keywords: ["tools"], icon: path.join(iconDir, "tools.png"), cls: "SectionHeader" },
  DayStart:      { keywords: ["day start"], icon: path.join(iconDir, "day_start.png"), cls: "SectionHeader" },
  DayEnd:        { keywords: ["day end"], icon: path.join(iconDir, "day_end.png"), cls: "SectionHeader" },
  Daylight:      { keywords: ["daylight"], icon: path.join(iconDir, "daylight.png"), cls: "SectionHeader" },
  Nighttime:     { keywords: ["nighttime"], icon: path.join(iconDir, "nighttime.png"), cls: "SectionHeader" },
  Holiday:       { keywords: ["holiday"], icon: path.join(iconDir, "holiday.png"), cls: "SectionHeader" },
  Idea:          { keywords: ["idea"], icon: path.join(iconDir, "idea.png"), cls: "SectionHeader" },
  OpenFolder:    { keywords: ["open folder"], icon: path.join(iconDir, "open_folder.png"), cls: "SectionHeader" },
  FlashingLight: { keywords: ["flashing light"], icon: path.join(iconDir, "flashing_light.png"), cls: "SectionHeader" },
  Pin:           { keywords: ["pin"], icon: path.join(iconDir, "pin.png"), cls: "SectionHeader" },
  Wrench:        { keywords: ["wrench"], icon: path.join(iconDir, "wrench.png"), cls: "SectionHeader" }
};

function classifyHeader(title) {
  const lower = title.toLowerCase();
  for (const type in HEADER_TYPES) {
    const { keywords, icon, cls } = HEADER_TYPES[type];
    if (keywords.some(k => lower.includes(k))) {
      return { icon, cls };
    }
  }
  return { icon: null, cls: "SectionHeader" };
}

// --- Render with class ---
function renderTextClass(text, cls, x, y) {
  const { fontSize, fontStyle, ySpacing } = CLASSES[cls];
  const isHeader = cls === "ReportTitle" || cls === "SectionHeader" || cls === "SubHeader";

const safeStyle = fontStyle === "bolditalic" ? "bold" : fontStyle;
doc.setFont("DejaVu", safeStyle);

  doc.setFontSize(fontSize);
  doc.text(cleanText(text), x, y);

  return y + (ySpacing || 0);
}


// --- Render header with PNG ---
function renderHeaderWithIcon(iconPath, title, cls, x, y) {
  const { fontSize, fontStyle, ySpacing } = CLASSES[cls];
  let cursorX = x;

  if (iconPath && fs.existsSync(iconPath)) {
    const img = fs.readFileSync(iconPath).toString("base64");
    const imgProps = doc.getImageProperties("data:image/png;base64," + img);
    const size = fontSize;
    const scale = size / imgProps.height;
    const w = imgProps.width * scale;
    const h = imgProps.height * scale;
    doc.addImage("data:image/png;base64," + img, "PNG", cursorX, y - h + 2, w, h);
    cursorX += w + 6;
  }

  // Always use DejaVu for headers
  const safeStyle = fontStyle === "bolditalic" ? "bold" : fontStyle;
  doc.setFont("DejaVu", safeStyle);
  doc.setFontSize(fontSize);
  doc.text(cleanText(title), cursorX, y);

  return y + (ySpacing || 0);
}

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

  renderTextClass(`Codex Information Sheet – ${title}`, "SectionHeader", textX, centerY - 2);
  renderTextClass(`Created by Skyesoft – ${date}`, "Paragraph", textX, centerY + 14);

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
  doc.setFont("DejaVu", "normal");
  doc.setFontSize(9);
  doc.text("© Christy Signs | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com", 40, pageHeight - 30);
  doc.text(`Page ${pageNum} of ${totalPages}`, pageWidth - 40, pageHeight - 30, { align: "right" });
}

// --- JSON Module Rendering ---
function renderSection(title, content, yStart) {
  let y = yStart;
  const { icon, cls } = classifyHeader(title);

  y = renderHeaderWithIcon(icon, title, cls, 40, y + 20);
  y += 6;

  if (typeof content === "string") {
    const wrapped = doc.splitTextToSize(cleanText(content), pageWidth - 80);
    wrapped.forEach(line => y = renderTextClass(line, "Paragraph", 40, y));
  } else if (Array.isArray(content)) {
    content.forEach(item => y = renderTextClass("• " + cleanText(item), "Bullet", 60, y));
  } else if (typeof content === "object" && content) {
    if (Object.values(content).every(v => typeof v === "string")) {
      for (const [k, v] of Object.entries(content)) {
        const label = k + ": ";
        y = renderTextClass(label, "SubHeader", 40, y);
        y = renderTextClass(cleanText(v), "Paragraph", 40 + doc.getTextWidth(label), y - CLASSES.Paragraph.ySpacing);
      }
    } else {
      const rows = Object.entries(content).map(([k, v]) => [
        k, ...(typeof v === "object" ? Object.values(v) : [v])
      ]);
      autoTable(doc, {
        startY: y + 10,
        head: [["Key", ...(typeof Object.values(content)[0] === "object" ? Object.keys(Object.values(content)[0]) : ["Value"])]],
        body: rows,
        theme: "grid",
        styles: { font: "DejaVu", fontSize: 10, cellPadding: 5, halign: "left" },
        headStyles: { fillColor: [211, 211, 211], textColor: [0, 0, 0], fontStyle: "bold" }
      });
      y = doc.lastAutoTable.finalY;
    }
  }
  return y;
}

function renderJsonModule(moduleData, headerBottomY) {
  let y = headerBottomY + 10;
  for (const [key, value] of Object.entries(moduleData)) {
    y = renderSection(key, value, y) + 20;
  }
}

// --- Markdown Fallback ---
function renderMarkdown(mdText, startY, title = "") {
  let y = startY;
  const lines = mdText.split("\n");
  let i = 0;
  while (i < lines.length) {
    let line = lines[i].trim();
    if (!line || line === "---" || line.startsWith("<!--")) { i++; continue; }

    if (/^#{1,6}\s/.test(line)) {
      let text = cleanText(line.replace(/^#+\s/, ""));
      const { icon, cls } = classifyHeader(text);
      if (line.startsWith("# ")) {
        y = renderHeaderWithIcon(icon, text, "ReportTitle", 40, y + 20);
      } else {
        y = renderHeaderWithIcon(icon, text, cls, 40, y + 20);
      }
      y += 6;
    } else if (line.startsWith("|")) {
      let tableLines = [];
      while (i < lines.length && lines[i].trim().startsWith("|")) {
        tableLines.push(lines[i].trim());
        i++;
      }
      i--;
      let head = tableLines[0].split("|").map(s => cleanText(s.trim())).filter(Boolean);
      let body = [];
      for (let j = 2; j < tableLines.length; j++) {
        let row = tableLines[j].split("|").map(s => cleanText(s.trim())).filter(Boolean);
        body.push(row);
      }
      if (y > pageHeight - 180) y = newPageWithHeader(title);
      autoTable(doc, {
        startY: y + 10,
        head: [head],
        body: body,
        theme: "grid",
        styles: { font: "DejaVu", fontSize: 10, cellPadding: 5, halign: "left" },
        headStyles: { fillColor: [211, 211, 211], textColor: [0, 0, 0], fontStyle: "bold" }
      });
      y = doc.lastAutoTable.finalY + 20;
    } else if (/^\s*[-*]\s+/.test(line)) {
      const text = cleanText(line.replace(/^\s*[-*]\s+/, ""));
      y = renderTextClass("• " + text, "Bullet", 60, y);
    } else {
      const wrapped = doc.splitTextToSize(cleanText(line), pageWidth - 80);
      wrapped.forEach(wl => y = renderTextClass(wl, "Paragraph", 40, y));
    }
    i++;
  }
  return y;
}

// --- Build Document ---
const reportTitle = slug.replace(/-/g, " ").replace(/\b\w/g, c => c.toUpperCase());
const headerBottomY = addHeader(reportTitle);

let usedJson = false;
if (fs.existsSync(codexJson)) {
  const codex = JSON.parse(fs.readFileSync(codexJson, "utf8"));
  if (codex.modules && codex.modules[slug]) {
    renderJsonModule(codex.modules[slug], headerBottomY);
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

// --- Save ---
const outPath = path.join(reportsDir, `${slug}.pdf`);
doc.save(outPath);
console.log(`✅ Report generated: ${outPath}`);