// netlify/functions/askOpenAI.js

// #region 📌 Imports
// ✅ Use native global fetch in Netlify
const fetch = (...args) => global.fetch(...args);

const fs = require("fs");
const path = require("path");

// If needed later, for version tracking
const versionData = JSON.parse(
  fs.readFileSync(path.join(__dirname, "../../assets/data/version.json"))
);
// #endregion

// #region 🧠 Intent Map (Optional Commands)
const intentMap = {
  "log out": () => ({
    response: "👋 Logging you out now...",
    action: "logout"
  }),
  "version": () => ({
    response: `📦 Skyebot version: ${versionData.version}`,
    action: "none"
  })
};
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
    content: `You are Skyebot, a helpful assistant for Christy Signs. The current Phoenix time is ${datetime}.`
  };
}
// #endregion

// #region 🛠️ Exported Handler Function
exports.handler = async (event) => {
  try {
    const { prompt, conversationHistory } = JSON.parse(event.body || "{}");
    //  
    const lowerPrompt = prompt?.toLowerCase().trim();

    // 🧠 Intent Check (fuzzy match)
    if (lowerPrompt) {
      const matchedIntent = Object.keys(intentMap).find(
        key => lowerPrompt.includes(key)
      );

      if (matchedIntent) {
        const intentResponse = intentMap[matchedIntent]();
        return {
          statusCode: 200,
          body: JSON.stringify({
            response: intentResponse.response,
            action: intentResponse.action
          })
        };
      }
    }

    // 🌐 OpenAI Chat Fallback
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
        model: "gpt-4",
        messages,
        temperature: 0.7
      })
    });

    const result = await openAIResponse.json();
    const reply = result.choices?.[0]?.message?.content || "🤖 Sorry, I couldn’t generate a reply.";

    return {
      statusCode: 200,
      body: JSON.stringify({ response: reply, action: "none" })
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