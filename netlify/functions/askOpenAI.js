// netlify/functions/askOpenAI.js
// vscode-fold=2  <-- Auto Fold trigger
//
// 📁 askOpenAI.js – Netlify Function to handle OpenAI chat interactions
// 🧠 Responds to prompt, routes intents, handles contact logic
// #region ⛓️ Module Imports
// Netlify Function to handle OpenAI requests
const fetch = global.fetch;
// Ensure fetch is available globally
const fs = require("fs");
// Ensure fetch is available globally
const path = require("path");
// Import the contact check utility
const checkProposedContact = require("./checkProposedContact");
// #endregion
// #region 📦 Load Dynamic Version
let dynamicVersion = "vUnknown";

const possiblePaths = [
  path.join(__dirname, "../../assets/data/version.json"),
  path.join(__dirname, "../../version.json")
];

for (const versionPath of possiblePaths) {
  try {
    const versionData = fs.readFileSync(versionPath, "utf8");
    const parsed = JSON.parse(versionData);
    if (parsed.version) {
      dynamicVersion = parsed.version;
      console.log("📦 Loaded version from:", versionPath);
      break;
    }
  } catch (err) {
    console.warn("⚠️ Failed to load version from:", versionPath, "| Reason:", err.message);
  }
}
// #endregion
// #region 🕒 Get Phoenix Time
const getPhoenixTime = () => {
  try {
    const date = new Date().toLocaleString("en-US", {
      timeZone: "America/Phoenix",
      hour: "2-digit",
      minute: "2-digit",
      hour12: true,
      weekday: "long",
      year: "numeric",
      month: "long",
      day: "numeric"
    });
    const [dayOfWeek, monthDayYear, time] = date.split(", ");
    const [month, day, year] = monthDayYear ? monthDayYear.split(" ") : ["unknown", "unknown", "unknown"];
    return {
      time: time ? time.trim() : "unknown",
      dayOfWeek: dayOfWeek ? dayOfWeek.trim() : "unknown",
      month: month.trim(),
      day: day.trim(),
      year: year.trim()
    };
  } catch (err) {
    console.error("Phoenix time error:", err.message);
    return { time: "unknown", dayOfWeek: "unknown", month: "unknown", day: "unknown", year: "unknown" };
  }
};
// #endregion
// #region 🤖 Create System Message
const createSystemMessage = (dateInfo) => ({
  // Role of the message
  role: "system",
  // Content of the message
  content: `You are Skyebot, a helpful assistant. Current local time is ${dateInfo.time} on ${dateInfo.dayOfWeek}, ${dateInfo.month} ${dateInfo.day}, ${dateInfo.year}.`
});
// #endregion
// #region 🤖 Skyebot Intent Map
const intentMap = {
  // #region 🔐 Logout Commands
  "log out": () => ({
    // Response for logging out
    response: "🖖 Logging you out, Hooman...",
    action: "logout"
  }),
  logout: () => ({
    response: "🔒 Session terminated. May your signs be well-lit.",
    action: "logout"
  }),
  // #endregion
  // #region 🧠 Help Command
  help: () => ({
    response: "🧠 You can say things like 'log out', 'check version', 'what time is it?', or 'open the prompt modal'.",
    action: "info"
  }),
  // #endregion
  // #region 📦 Version Check Commands
  "check version": () => ({
    response: `📦 Current version: ${dynamicVersion} (see footer)`,
    action: "versionCheck"
  }),
  version: () => ({
    response: `📦 Current version: ${dynamicVersion} (see footer)`,
    action: "versionCheck"
  }),
  // #endregion
  // #region 🕒 Time Response
  getTime: () => {
    const now = new Date();
    const time = now.toLocaleTimeString("en-US", {
      hour: "numeric",
      minute: "numeric",
      hour12: true,
      timeZone: "America/Phoenix"
    });
    return {
      response: `🕒 The current time is ${time}.`,
      action: "none"
    };
  },
  // #endregion
  // #region 📅 Date Response
  getDate: () => {
    const today = new Date().toLocaleDateString("en-US", {
      weekday: "long",
      month: "long",
      day: "numeric",
      year: "numeric",
      timeZone: "America/Phoenix"
    });
    return {
      response: `📅 Today is ${today}.`,
      action: "none"
    };
  },
  // #endregion
  // #region 📆 DateTime Response
  getDateTime: () => {
    const now = new Date().toLocaleString("en-US", {
      weekday: "long",
      month: "long",
      day: "numeric",
      year: "numeric",
      hour: "numeric",
      minute: "numeric",
      hour12: true,
      timeZone: "America/Phoenix"
    });
    return {
      response: `📆 It's currently ${now}.`,
      action: "none"
    };
  }
  // #endregion
};
// #endregion
// #region 🛠️ Exported Handler Function
/*
exports.handler = async (event) => {
  try {
    const { prompt, conversationHistory } = JSON.parse(event.body || "{}");

    // 🧠 Built-in Intent Check
    const lowerPrompt = prompt?.toLowerCase().trim();
    if (lowerPrompt && intentMap[lowerPrompt]) {
      const intentResponse = intentMap[lowerPrompt]();
      return {
        statusCode: 200,
        body: JSON.stringify({
          response: intentResponse.response,
          action: intentResponse.action
        })
      };
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
*/
// #endregion

// #region 🧪 Minimal Test Handler
// netlify/functions/askOpenAI.js
exports.handler = async (event) => {
  return {
    statusCode: 200,
    body: JSON.stringify({
      response: "🟢 Minimal handler is working!",
      action: "test"
    })
  };
};
