const fs = require('fs');
const path = './docs/codex/codex-version.json';

const type = process.argv[2]; // 'patch', 'minor', or 'major'

const bump = (version, type) => {
  const [major, minor, patch] = version.split('.').map(Number);
  if (type === 'major') return `${major + 1}.0.0`;
  if (type === 'minor') return `${major}.${minor + 1}.0`;
  return `${major}.${minor}.${patch + 1}`;
};

const data = JSON.parse(fs.readFileSync(path, 'utf-8'));
const newVersion = bump(data.version, type || 'patch');
data.version = newVersion;
data.last_updated = new Date().toISOString();

fs.writeFileSync(path, JSON.stringify(data, null, 2));
console.log(`✅ Version updated to ${newVersion}`);
