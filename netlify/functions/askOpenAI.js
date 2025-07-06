// netlify/functions/askOpenAI.js

// #region 📌 Imports
const fetch = (...args) => global.fetch(...args);
const fs = require("fs");
const path = require("path");

const versionData = JSON.parse(
  fs.readFileSync(path.join(__dirname, "../../assets/data/version.json"))
);
// #endregion

// #region 🌐 Timezone Helper
function getPhoenixTime() {
  const options = {
    timeZone: "America/Phoenix",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    weekday: "long",
    year: "numeric",
    month: "long",
    day: "numeric"
  };
  return new Intl.DateTimeFormat("en-US", options).format(new Date());
}
// #endregion

// #region 🧠 System Message Constructor
function createSystemMessage(datetime) {
  return {
    role: "system",
    content: `You are Skyebot, a helpful assistant for Christy Signs.
When the user prompt includes a clear intent (such as logout, version check, etc), you must return a JSON response with:

{"response": "<your reply text>", "action": "<logout|versionCheck|none>"}

If the prompt has no clear intent, just reply normally with a helpful response, and set \"action\": \"none\".

Current Phoenix time: ${datetime}`
  };
}
// #endregion

// #region 🛠️ Exported Handler Function
exports.handler = async (event) => {
  try {
    const { prompt, conversationHistory } = JSON.parse(event.body || "{}");
    const openaiKey = process.env.OPENAI_API_KEY;
    if (!openaiKey) throw new Error("Missing OpenAI API key");

    const dateInfo = getPhoenixTime();
    const systemMessage = createSystemMessage(dateInfo);

    const messages = [
      systemMessage,
      ...(conversationHistory || []),
      { role: "user", content: prompt }
    ];

    const openAIResponse = await fetch("https://api.openai.com/v1/chat/completions", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${openaiKey}`,
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        model: "gpt-4o",
        messages,
        temperature: 0.7
      })
    });

    const result = await openAIResponse.json();
    const rawReply = result.choices?.[0]?.message?.content || "🤖 Sorry, I couldn’t generate a reply.";

    let response = rawReply;
    let action = "none";

    try {
      const parsed = JSON.parse(rawReply);
      if (typeof parsed === "object" && parsed.response && parsed.action) {
        response = parsed.response;
        action = parsed.action;
      }
    } catch (_) {
      // Ignore JSON.parse errors – fallback to raw text
    }

    return {
      statusCode: 200,
      body: JSON.stringify({ response, action })
    };
  } catch (err) {
    console.error("❌ Skyebot Error:", err.message);
    return {
      statusCode: 500,
      body: JSON.stringify({
        errorType: err.name || "UnknownError",
        errorMessage: err.message || "An unknown error occurred",
        trace: err.stack ? err.stack.split("\n") : []
      })
    };
  }
};
// #endregion