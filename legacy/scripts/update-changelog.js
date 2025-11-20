// update-changelog.js
const fs = require("fs");
const { execSync } = require("child_process");

const CHANGELOG_PATH = "./docs/codex/codex-changelog.json";
const now = new Date().toISOString();
const commitHash = process.argv[2]; // Get from command line

if (!commitHash) {
  console.error("❌ Please provide a commit hash. Usage:\n  node update-changelog.js <hash>");
  process.exit(1);
}

try {
  const commitData = execSync(`git show ${commitHash} --pretty=format:"%H|%an|%ad|%s" --name-status`).toString();
  const [metaLine, ...fileLines] = commitData.split("\n");
  const [hash, author, date, message] = metaLine.split("|");

  let changelog = { generated_on: now, log: [] };
  if (fs.existsSync(CHANGELOG_PATH)) {
    changelog = JSON.parse(fs.readFileSync(CHANGELOG_PATH, "utf-8"));
  }

  fileLines
    .filter(line => line.trim() !== "")
    .forEach(entry => {
      const [status, file] = entry.trim().split(/\t+/);
      const type =
        status === "A" ? "create" :
        status === "M" ? "update" :
        status === "D" ? "delete" : "other";

      changelog.log.push({
        date: now,
        file: file.trim(),
        description: message.trim(),
        user: author.trim(),
        type
      });
    });

  fs.writeFileSync(CHANGELOG_PATH, JSON.stringify(changelog, null, 2));
  console.log(`✅ codex-changelog.json updated with commit ${commitHash}`);
} catch (err) {
  console.error("❌ Error updating changelog:", err.message);
}
