// #region 📌 Imports
const fetch = (...args) => global.fetch(...args);
const fs = require("fs");
const path = require("path");

// Load Version Info
const versionData = JSON.parse(
  fs.readFileSync(path.join(__dirname, "/home/notyou64/public_html/data/version.json"))
);

// Load Codex JSON
const codexPath = path.join(__dirname, "../../docs/codex/codex.json");
let codex = {};
let codexGlossary = "";
let codexReadme = "";

try {
  codex = JSON.parse(fs.readFileSync(codexPath, "utf-8"));

  codexGlossary = Object.entries(codex.modules?.glossary?.entries || {})
    .map(([term, { meaning, usage }]) => `• **${term}**: ${meaning} — _${usage}_`)
    .join("\n");

  codexReadme = (codex.readme?.modules || [])
    .map((mod) => `• ${mod.name} — ${mod.purpose}`)
    .join("\n");
} catch (err) {
  console.warn("⚠️ Could not load codex.json:", err.message);
}
// #endregion

// #region 🚀 Main Handler
exports.handler = async (event) => {
  if (event.httpMethod !== "POST") {
    return { statusCode: 405, body: "Method Not Allowed" };
  }

  const { prompt = "" } = JSON.parse(event.body || "{}");

  // #region 🧠 Codex Pre-Filter Match
  const normalize = str => str.toLowerCase().replace(/[^a-z0-9]/gi, "").trim();
  const normalizedPrompt = normalize(prompt);

  const glossaryEntries = codex.modules?.glossary?.entries || {};
  for (const term in glossaryEntries) {
    if (normalizedPrompt.includes(normalize(term))) {
      const entry = glossaryEntries[term];
      return {
        statusCode: 200,
        body: JSON.stringify({
          response: `🧠 **${term}** — ${entry.meaning}\n\n📎 *Context:* ${entry.usage}`,
          action: "none"
        })
      };
    }
  }

  const moduleEntries = codex.readme?.modules || [];
  for (const mod of moduleEntries) {
    if (normalizedPrompt.includes(normalize(mod.name))) {
      return {
        statusCode: 200,
        body: JSON.stringify({
          response: `📦 **${mod.name}** — ${mod.purpose}`,
          action: "none"
        })
      };
    }
  }
  // #endregion

  // #region 💬 OpenAI Fallback
  try {
    const openaiResponse = await fetch("https://api.openai.com/v1/chat/completions", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${process.env.OPENAI_API_KEY}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        model: "gpt-4",
        messages: [
          {
            role: "system",
            content: `You are Skyebot, a helpful assistant for Skyesoft users.
You understand internal terminology and project logic from the Skyesoft Codex.
Respond clearly and concisely.

Codex Glossary:
${codexGlossary}

Codex Modules:
${codexReadme}`
          },
          { role: "user", content: prompt }
        ],
        temperature: 0.5,
      }),
    });

    const result = await openaiResponse.json();

    return {
      statusCode: 200,
      body: JSON.stringify({
        response: result.choices?.[0]?.message?.content || "No response.",
        action: "none",
      }),
    };
  } catch (err) {
    console.error("❌ OpenAI fallback failed:", err.message);
    return {
      statusCode: 500,
      body: JSON.stringify({
        response: "Skyebot encountered an error while processing your request.",
        error: err.message,
        action: "error",
      }),
    };
  }
  // #endregion
};
// #endregion
