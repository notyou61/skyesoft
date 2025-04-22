// generate_tree.js
const fs = require('fs');
const path = require('path');

function walk(dir, depth = 0, maxDepth = 3) {
  if (depth > maxDepth) return '';
  const prefix = '  '.repeat(depth);
  let output = '';

  const items = fs.readdirSync(dir, { withFileTypes: true });
  for (const item of items) {
    if (['node_modules', '.git', 'docs/proposal/pdf', 'docs/proposal/html', 'docs/proposal/pdf_output', 'docs/viewer/svg'].some(p => path.join(dir, item.name).includes(p))) {
      continue;
    }
    output += `${prefix}- ${item.name}\n`;
    if (item.isDirectory()) {
      output += walk(path.join(dir, item.name), depth + 1, maxDepth);
    }
  }
  return output;
}

const tree = walk(process.cwd());
fs.writeFileSync('clean_tree.txt', tree, 'utf8');
console.log('✅ Tree saved to clean_tree.txt');
