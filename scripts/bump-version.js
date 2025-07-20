// 📁 File: scripts/bump-version.js
const fs = require("fs");
const path = require("path");
const execSync = require("child_process").execSync;

// 🧠 Define bump type (default: patch)
const bumpType = process.argv[2] || "patch";

// 📌 Define path for version.json only
const versionJsonPath = path.resolve(__dirname, "../assets/data/version.json");

// 🧮 Read current version from version.json (fallback to 0.0.1)
let version = "0.0.1";
if (fs.existsSync(versionJsonPath)) {
  const json = JSON.parse(fs.readFileSync(versionJsonPath, "utf8"));
  if (json.siteVersion && /^v?\d+\.\d+\.\d+$/.test(json.siteVersion)) {
    let [major, minor, patch] = json.siteVersion.replace(/^v/, "").split(".").map(Number);
    if (bumpType === "major") major++;
    else if (bumpType === "minor") minor++;
    else patch++;
    version = `${major}.${minor}.${patch}`;
  }
}

// 🖊️ Set new version string
const newVersionText = `v${version}`;

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
console.log(`📄 Updated ${path.basename(versionJsonPath)}`);
