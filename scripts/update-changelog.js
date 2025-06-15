// update-changelog.js
const fs = require("fs");
const { execSync } = require("child_process");

const CHANGELOG_PATH = "./docs/codex/codex-changelog.json";
const now = new Date().toISOString();

// Get last commit info (cross-platform safe quotes)
const commitData = execSync('git log -1 --pretty=format:"%H|%an|%ad|%s" --name-only').toString();
const [metaLine, ...files] = commitData.split("\n");
const [hash, author, date, message] = metaLine.split("|");


// Load existing changelog or create default
let changelog = { generated_on: now, log: [] };
if (fs.existsSync(CHANGELOG_PATH)) {
  changelog = JSON.parse(fs.readFileSync(CHANGELOG_PATH, "utf-8"));
}

// Add new entries
files
  .filter(f => f.trim() !== "")
  .forEach(file => {
    changelog.log.push({
      date: new Date().toISOString(),
      file: file.trim(),
      description: message.trim(),
      user: author.trim(),
      type: "update"
    });
  });

// Save updated changelog
fs.writeFileSync(CHANGELOG_PATH, JSON.stringify(changelog, null, 2));
console.log("✅ codex-changelog.json updated with latest commit.");
