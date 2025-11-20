// 📄 File: assets/js/fetchTwemoji.js
// Fetch Twemoji PNGs (72x72) and save to icons folder
// Requires: npm install node-fetch@2 twemoji-parser

const fs = require("fs");
const path = require("path");
const fetch = require("node-fetch");
const twemoji = require("twemoji-parser");

// Output directory
const iconDir = path.join(__dirname, "../../assets/images/icons");
if (!fs.existsSync(iconDir)) fs.mkdirSync(iconDir, { recursive: true });

// Emoji → base filename mapping
const ICONS = {
  "📍": "project",
  "🏢": "owner",
  "📐": "property",
  "📑": "notes",
  "✅": "integration",
  "⚙️": "dependencies",
  "🌤": "weather",
  "📅": "calendar",
  "🌅": "sunrise",
  "🌇": "sunset",
  "👷": "workman",
  "⏳": "hourglass",
  "🦺": "workman_vest",
  "🛠️": "tools",
  "🌞": "day_start",
  "🌙": "day_end",
  "☀️": "daylight",
  "🌌": "nighttime",
  "🎉": "holiday",
  "💡": "idea",
  "📂": "open_folder",
  "🚨": "flashing_light",
  "📍": "pin",          // reuse pushpin for pin
  "🔧": "wrench"
};

// Helper: convert emoji to codepoint
function getCodepoint(emoji) {
  const parsed = twemoji.parse(emoji);
  if (!parsed || parsed.length === 0) return null;
  return parsed[0].url.split("/").pop().replace(".svg", "");
}

// Main fetcher
async function fetchIcons() {
  for (const [emoji, name] of Object.entries(ICONS)) {
    const codepoint = getCodepoint(emoji);
    if (!codepoint) {
      console.error(`❌ Failed to parse emoji ${emoji}`);
      continue;
    }

    const url = `https://cdnjs.cloudflare.com/ajax/libs/twemoji/14.0.2/72x72/${codepoint}.png`;
    const outFile = path.join(iconDir, `${name}.png`);

    try {
      const res = await fetch(url);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const buffer = await res.buffer();
      fs.writeFileSync(outFile, buffer);
      console.log(`✅ Saved ${outFile}`);
    } catch (err) {
      console.error(`❌ Failed ${emoji}: ${err.message}`);
    }
  }
}

fetchIcons();
