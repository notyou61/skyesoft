// 📁 File: scripts/bump-version.js
const fs = require("fs");
const path = require("path");
const execSync = require("child_process").execSync;

// 🧠 Define bump type (default: patch)
const bumpType = process.argv[2] || "patch";

// 📌 Define path for version.json
const versionJsonPath = path.resolve(__dirname, "/home/notyou64/data/version.json");

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

// === NEW: Also update skyesoft-data.json siteMeta block ===
const skyeSoftPath = path.resolve(__dirname, "/home/notyou64/data/skyesoft-data.json");

if (fs.existsSync(skyeSoftPath)) {
  const skyeSoftData = JSON.parse(fs.readFileSync(skyeSoftPath, "utf8"));
  if (!skyeSoftData.siteMeta) skyeSoftData.siteMeta = {};
  skyeSoftData.siteMeta.siteVersion = newVersionText;
  skyeSoftData.siteMeta.lastDeployNote = commitMsg;
  skyeSoftData.siteMeta.lastDeployTime = timestamp;
  skyeSoftData.siteMeta.commitHash = commitHash;
  fs.writeFileSync(skyeSoftPath, JSON.stringify(skyeSoftData, null, 2));
  console.log(`📄 Updated skyesoft-data.json`);
} else {
  console.warn("⚠️ skyesoft-data.json not found; skipped updating siteMeta.");
}