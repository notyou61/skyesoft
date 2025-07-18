// 📁 File: scripts/bump-version.js
const fs = require("fs");
const path = require("path");

const bumpType = process.argv[2] || "patch";
const versionFile = path.resolve(__dirname, "../docs/codex/codex-version.md");

let version = "0.0.1";
if (fs.existsSync(versionFile)) {
  const versionText = fs.readFileSync(versionFile, "utf8").trim();
  const match = versionText.match(/v(\d+)\.(\d+)\.(\d+)/);
  if (match) {
    let [major, minor, patch] = match.slice(1).map(Number);
    if (bumpType === "major") major++;
    else if (bumpType === "minor") minor++;
    else patch++;
    version = `${major}.${minor}.${patch}`;
  }
}

const newVersionText = `v${version}`;
fs.writeFileSync(versionFile, newVersionText);

// 🛰️ Create version.json for SSE
const commitMsg = require("child_process")
  .execSync("git log -1 --pretty=%s")
  .toString()
  .trim();

const commitHash = require("child_process")
  .execSync("git rev-parse --short HEAD")
  .toString()
  .trim();

const timestamp = new Date().toISOString().replace("T", " ").split(".")[0];

const versionJson = {
  siteVersion: newVersionText,
  lastDeployNote: commitMsg,
  lastDeployTime: timestamp,
  commitHash,
  deployState: "live",
  deployIsLive: true
};

fs.writeFileSync(
  path.resolve(__dirname, "../version.json"),
  JSON.stringify(versionJson, null, 2)
);

console.log(`✅ Version bumped to ${newVersionText} (${bumpType})`);
console.log("📄 version.json updated for getDynamicData.php");

