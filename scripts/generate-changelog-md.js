const fs = require('fs');
const path = './docs/codex/codex-changelog.json';

const raw = JSON.parse(fs.readFileSync(path, 'utf-8'));
const log = raw.log;

const grouped = log.reduce((acc, entry) => {
  const date = entry.date.split('T')[0];
  if (!acc[date]) acc[date] = [];
  acc[date].push(entry);
  return acc;
}, {});

let output = `# 📝 Skyesoft Codex Changelog\n\n`;

for (const date of Object.keys(grouped).sort().reverse()) {
  output += `## ${date}\n\n`;
  for (const entry of grouped[date]) {
    output += `- **${entry.file}** — _${entry.description}_  \n`;
    output += `  **Author:** ${entry.user}  \n`;
    output += `  **Type:** ${entry.type}\n\n`;
  }
  output += `---\n\n`;
}

fs.writeFileSync('./docs/codex/codex-changelog.md', output.trim());
console.log('✅ Markdown changelog generated: docs/codex/codex-changelog.md');
