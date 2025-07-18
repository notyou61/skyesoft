// 📁 File: scripts/generateVersion.js

const { execSync } = require("child_process");
const fs = require("fs");
const path = require("path");

try {
  // 🛰️ Get commit hash
  const commitHash = execSync("git rev-parse --short HEAD").toString().trim();

  // 📝 Get commit message
  const commitMessage = execSync("git log -1 --pretty=%s").toString().trim();

  // 🕓 Timestamp (YYYY-MM-DD HH:mm:ss)
  const timestamp = new Date().toISOString().replace("T", " ").split(".")[0];

  // 🟢 Build version object
  const versionData = {
    siteVersion: commitHash,
    lastDeployNote: commitMessage,
    lastDeployTime: timestamp,
    deployState: "live",
    deployIsLive: true
  };

  // 🛠️ Write to file
  const filePath = path.resolve(__dirname, "../version.json");
  fs.writeFileSync(filePath, JSON.stringify(versionData, null, 2));
  console.log("✅ version.json updated:", versionData);
} catch (err) {
  console.error("❌ Failed to generate version.json", err);
}
