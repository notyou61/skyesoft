// 📁 File: scripts/bump-version.js
const fs = require("fs");
const path = require("path");
const execSync = require("child_process").execSync;

// 🧠 Define bump type (default: patch)
const bumpType = process.argv[2] || "patch";

// 📌 Define paths
const codexPath = path.resolve(__dirname, "../docs/codex/codex-version.md");
const versionJsonPath = path.resolve(__dirname, "../assets/data/version.json");

// 🧮 Read current version
let version = "0.0.1";
if (fs.existsSync(codexPath)) {
  const versionText = fs.readFileSync(codexPath, "utf8").trim();
  const match = versionText.match(/v(\d+)\.(\d+)\.(\d+)/);
  if (match) {
    let [major, minor, patch] = match.slice(1).map(Number);
    if (bumpType === "major") major++;
    else if (bumpType === "minor") minor++;
    else patch++;
    version = `${major}.${minor}.${patch}`;
  }
}

// 🖊️ Write updated codex version
const newVersionText = `v${version}`;
fs.writeFileSync(codexPath, newVersionText);

// 📅 Get Git metadata
const commitMsg = execSync("git log -1 --pretty=%s").toString().trim();
const commitHash = execSync("git rev-parse --short HEAD").toString().trim();
const timestamp = new Date().toISOString().replace("T", " ").split(".")[0];

// 🛰️ Create new version.json
const versionJson = {
  siteVersion: newVersionText,
  lastDeployNote: commitMsg,
  lastDeployTime: timestamp,
  commitHash,
  deployState: "live",
  deployIsLive: true
};

fs.writeFileSync(versionJsonPath, JSON.stringify(versionJson, null, 2));

console.log(`✅ Version bumped to ${newVersionText} (${bumpType})`);
console.log(`📄 Updated ${path.basename(versionJsonPath)} and ${path.basename(codexPath)}`);
