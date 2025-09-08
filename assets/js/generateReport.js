// 📄 File: assets/js/generateReport.js
// Generates branded, template-driven PDF reports (Crumbl Avondale style)
// Reads from codex.json if module exists, else falls back to markdown
// Requires: npm install jspdf jspdf-autotable markdown-it

const fs = require("fs");
const path = require("path");
const { jsPDF } = require("jspdf");
const autoTable = require("jspdf-autotable").default;
const MarkdownIt = require("markdown-it");

// --- PDF Setup ---
const doc = new jsPDF({ unit: "pt", format: "letter" });

// Load embedded DejaVuSans font (Node-safe)
require("../../assets/fonts/DejaVuSans.js")(doc);
doc.setFont("DejaVu", "normal");

const codexDocsDir = path.join(__dirname, "../../docs/codex");
const reportsDir   = path.join(__dirname, "../../docs/reports");
const codexJson    = path.join(codexDocsDir, "../codex.json");
const logoPath     = path.join(__dirname, "../../assets/images/christyLogo.png");

if (!fs.existsSync(reportsDir)) fs.mkdirSync(reportsDir, { recursive: true });

// CLI arg
const slug = process.argv[2];
if (!slug) {
  console.error("❌ Usage: node assets/js/generateReport.js <slug>");
  process.exit(1);
}

// Current date
const date = new Date().toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });

// --- PDF Page Info ---
const pageWidth  = doc.internal.pageSize.getWidth();
const pageHeight = doc.internal.pageSize.getHeight();

// --- Markdown Parser ---
const md = new MarkdownIt({ html: true, breaks: true });

// --- Utility ---
function cleanText(txt) {
  txt = txt.replace(/[-\u001F\u007F-\u009F]/g, ""); // strip control chars
  txt = txt.replace(/\*\*/g, '');
  txt = txt.replace(/\*/g, '');
  txt = txt.replace(/`/g, '');
  txt = txt.replace(/---/g, '');
  return txt;
}

// --- Header ---
function addHeader(title) {
  let logoTopY = 25;
  let logoBottomY = 25 + 60;
  let w = 0;
  let h = 0;

  try {
    const img = fs.readFileSync(logoPath).toString("base64");
    const imgProps = doc.getImageProperties("data:image/png;base64," + img);
    const scale = 60 / imgProps.height;
    w = imgProps.width * scale;
    h = imgProps.height * scale;
    doc.addImage("data:image/png;base64," + img, "PNG", 40, logoTopY, w, h);
    logoBottomY = logoTopY + h;
  } catch (e) {
    console.warn("⚠️ Logo not found");
  }

  const textX = 40 + w + 20;
  const firstLineY = logoTopY + (h / 2) - 10;

  doc.setFont("DejaVu", "bold");
  doc.setFontSize(16);
  doc.text(`Information Report – ${title}`, textX, firstLineY, { align: "left" });

  doc.setFont("DejaVu", "normal");
  doc.setFontSize(12);
  doc.text(`Created by Skyesoft – ${date}`, textX, firstLineY + 18, { align: "left" });

  const lineY = logoBottomY + 5;
  doc.setLineWidth(0.5);
  doc.line(40, lineY, pageWidth - 40, lineY);

  return lineY;
}

// --- Helper: new page with header ---
function newPageWithHeader(title) {
  doc.addPage();
  const headerBottomY = addHeader(title);
  return headerBottomY + 20;
}

// --- Footer ---
function addFooter(pageNum, totalPages) {
  doc.setLineWidth(0.5);
  doc.line(40, pageHeight - 50, pageWidth - 40, pageHeight - 50);

  doc.setFont("DejaVu", "normal");
  doc.setFontSize(9);
  doc.text(
    "© Christy Signs | 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com",
    40,
    pageHeight - 30
  );
  doc.text(`Page ${pageNum} of ${totalPages}`, pageWidth - 40, pageHeight - 30, { align: "right" });
}

// --- JSON Module Rendering ---
function renderSection(title, content, yStart) {
  let y = yStart;

  // Add icon based on title
  let icon = '';
  const lowerTitle = title.toLowerCase();
  if (lowerTitle.includes('primary role') || lowerTitle.includes('project information')) icon = '📍 ';
  else if (lowerTitle.includes('day types') || lowerTitle.includes('owner information')) icon = '🏢 ';
  else if (lowerTitle.includes('time segments') || lowerTitle.includes('property information')) icon = '📐 ';
  else if (lowerTitle.includes('exclusion') || lowerTitle.includes('zoning notes')) icon = '📑 ';
  else if (lowerTitle.includes('integration') || lowerTitle.includes('recommendations')) icon = '✅ ';
  else if (lowerTitle.includes('dependencies')) icon = '⚙️ ';
  title = icon + title;

  doc.setFont("DejaVu","bold");
  doc.setFontSize(14);
  y += 20;
  doc.text(title, 40, y);

  if (typeof content === "string") {
    doc.setFont("DejaVu","normal");
    doc.setFontSize(12);
    const wrapped = doc.splitTextToSize(cleanText(content), pageWidth-80);
    wrapped.forEach(line => {
      y += 16;
      if (y > pageHeight - 80) y = newPageWithHeader(slug);
      doc.text(line, 40, y);
    });
  } else if (Array.isArray(content)) {
    doc.setFont("DejaVu","normal");
    doc.setFontSize(12);
    content.forEach(item => {
      y += 16;
      if (y > pageHeight - 80) y = newPageWithHeader(slug);
      doc.text("• " + cleanText(item), 60, y);
    });
  } else if (typeof content === "object" && content) {
    if (Object.values(content).every(v => typeof v === 'string')) {
      // Render as key: value lines
      doc.setFont("DejaVu", "normal");
      doc.setFontSize(12);
      for (const [k, v] of Object.entries(content)) {
        y += 16;
        if (y > pageHeight - 80) y = newPageWithHeader(slug);
        doc.setFont("DejaVu", "bold");
        const label = k + ': ';
        doc.text(label, 40, y);
        const labelWidth = doc.getTextWidth(label);
        doc.setFont("DejaVu", "normal");
        doc.text(cleanText(v), 40 + labelWidth, y);
      }
    } else {
      // Render as table
      const rows = Object.entries(content).map(([k,v]) => [k, ...(typeof v === "object" ? Object.values(v) : [v])]);
      autoTable(doc, {
        startY: y+10,
        head: [ ["Key", ...(typeof Object.values(content)[0]==="object" ? Object.keys(Object.values(content)[0]) : ["Value"])] ],
        body: rows,
        theme: 'grid',
        styles: { font: "DejaVu", fontSize: 11, cellPadding: 5, halign:"left" },
        headStyles: { fillColor: [211,211,211], textColor: [0,0,0], fontStyle: 'bold' }
      });
      y = doc.lastAutoTable.finalY;
    }
  }

  return y;
}

function renderJsonModule(moduleData, headerBottomY) {
  let y = headerBottomY + 20;
  for (const [key, value] of Object.entries(moduleData)) {
    y = renderSection(
      key.charAt(0).toUpperCase() + key.slice(1),
      value,
      y
    ) + 20;
  }
}

// --- Markdown Fallback ---
function renderMarkdown(mdText, startY, title = "") {
  let y = startY;
  const lines = mdText.split("\n");
  let i = 0;
  while (i < lines.length) {
    let line = lines[i].trim();
    if (line === '' || line === '---' || line.startsWith('<!--')) {
      i++;
      continue;
    }
    if (/^#{1,6}\s/.test(line)) {
      const level = line.match(/^#+/)[0].length;
      let text = cleanText(line.replace(/^#+\s/, ""));
      // Add icon based on text
      let icon = '';
      const lowerText = text.toLowerCase();
      if (lowerText.includes('primary role') || lowerText.includes('project information')) icon = '📍 ';
      else if (lowerText.includes('day types') || lowerText.includes('owner information')) icon = '🏢 ';
      else if (lowerText.includes('time segments') || lowerText.includes('property information')) icon = '📐 ';
      else if (lowerText.includes('exclusion') || lowerText.includes('zoning notes')) icon = '📑 ';
      else if (lowerText.includes('integration') || lowerText.includes('recommendations')) icon = '✅ ';
      else if (lowerText.includes('dependencies')) icon = '⚙️ ';
      text = icon + text;
      y += 20;
      if (y > pageHeight - 80) y = newPageWithHeader(title);
      doc.setFont("DejaVu", "bold");
      doc.setFontSize(level === 1 ? 16 : 14);
      doc.text(text, 40, y);
      y += 10;
    } else if (line.startsWith('|')) {
      // Parse table
      let tableLines = [];
      while (i < lines.length && lines[i].trim().startsWith('|')) {
        tableLines.push(lines[i].trim());
        i++;
      }
      i--; // adjust for loop increment
      // Parse head and body
      let head = tableLines[0].split('|').map(s => cleanText(s.trim()).replace(/^\*+|\*+$/g, '')).filter(s => s);
      let body = [];
      for (let j = 2; j < tableLines.length; j++) { // skip separator line
        let row = tableLines[j].split('|').map(s => cleanText(s.trim()).replace(/^\*+|\*+$/g, '')).filter(s => s);
        body.push(row);
      }
      if (y > pageHeight - 180) y = newPageWithHeader(title); // rough estimate to avoid split
      autoTable(doc, {
        startY: y + 10,
        head: [head],
        body: body,
        theme: 'grid',
        styles: { font: "DejaVu", fontSize: 11, cellPadding: 5, halign: "left" },
        headStyles: { fillColor: [211,211,211], textColor: [0,0,0], fontStyle: 'bold' }
      });
      y = doc.lastAutoTable.finalY + 20;
    } else if (/^\s*[-*]\s+/.test(line)) {
      const text = cleanText(line.replace(/^\s*[-*]\s+/, ""));
      doc.setFont("DejaVu", "normal");
      doc.setFontSize(12);
      if (y > pageHeight - 80) y = newPageWithHeader(title);
      doc.text("• " + text, 60, y += 14);
    } else if (line.trim()) {
      doc.setFont("DejaVu","normal");
      doc.setFontSize(12);
      const wrapped = doc.splitTextToSize(cleanText(line), pageWidth - 80);
      wrapped.forEach(wl => {
        if (y > pageHeight - 80) y = newPageWithHeader(title);
        doc.text(wl, 40, y +=16);
      });
    }
    i++;
  }
  return y; // Optional, if needed
}

// --- Build Document ---
const reportTitle = slug.replace(/-/g, " ").replace(/\b\w/g, c => c.toUpperCase());
const headerBottomY = addHeader(reportTitle);

let usedJson = false;
if (fs.existsSync(codexJson)) {
  const codex = JSON.parse(fs.readFileSync(codexJson,"utf8"));
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
  renderMarkdown(mdContent, headerBottomY + 20, reportTitle);
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