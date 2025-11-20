const { jsPDF } = require("jspdf");
const autoTable = require("jspdf-autotable").default;  // 👈 note `.default`

const doc = new jsPDF();
doc.text("AutoTable Test", 10, 10);

autoTable(doc, {
  head: [["Col1", "Col2"]],
  body: [
    ["Row 1, Col 1", "Row 1, Col 2"],
    ["Row 2, Col 1", "Row 2, Col 2"]
  ],
  startY: 20
});

doc.save("table-test.pdf");
console.log("✅ Generated table-test.pdf");
