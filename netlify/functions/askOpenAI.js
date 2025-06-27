// netlify/functions/askOpenAI.js
// 🧠 Use native fetch (Node.js 18+ compatible)
const fetch = global.fetch;
// 📦 Import required modules
const fs = require("fs");
// 🚀 Use Node.js built-in fs module for file operations
const path = require("path");
// ✅ Load version from JSON file
let dynamicVersion = "unknown";
// Attempt to read version from assets/data/version.json
try {
  const versionPath = path.join(__dirname, "../../assets/data/version.json");
  const versionData = fs.readFileSync(versionPath, "utf8");
  const parsed = JSON.parse(versionData);
  if (parsed.version) dynamicVersion = parsed.version;
} catch (err) {
  console.error("Version load error:", err.message);
}
// 🚀 Helper to format Phoenix time
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
    const parts = date.split(", ");
    if (parts.length !== 3) {
      console.error("Unexpected date format:", date);
      return { time: "unknown", dayOfWeek: "unknown", month: "unknown", day: "unknown", year: "unknown" };
    }
    const [dayOfWeek, monthDayYear, time] = parts;
    const dateParts = monthDayYear ? monthDayYear.split(" ") : [];
    if (dateParts.length !== 3) {
      console.error("Unexpected monthDayYear format:", monthDayYear);
      return { time: time  ? time.trim() : "unknown", dayOfWeek: dayOfWeek ? dayOfWeek.trim() : "unknown", month: "unknown", day: "unknown", year: "unknown" };
    }
    const [month, day, year] = dateParts;
    return {
      time: time ? time.trim() : "unknown",
      dayOfWeek: dayOfWeek ? dayOfWeek.trim() : "unknown",
      month: month ? month.trim() : "unknown",
      day: day ? day.trim() : "unknown",
      year: year ? year.trim() : "unknown"
    };
  } catch (err) {
    console.error("Date parsing error:", err.message);
    return { time: "unknown", dayOfWeek: "unknown", month: "unknown", day: "unknown", year: "unknown" };
  }
};
// 🚀 Helper to create system message
const createSystemMessage = (dateInfo) => ({
  // 🗓️ System message with current Phoenix time
  role: "system",
  // 📜 Content of the system message
  content: `You are Skyebot, a helpful assistant. Current local time is ${dateInfo.time} on ${dateInfo.dayOfWeek}, ${dateInfo.month} ${dateInfo.day}, ${dateInfo.year}. Respond using this info when users ask about time or date.`
});
// 🚀 Intent mapping for predefined commands
const intentMap = {
  // 🧠 Predefined commands
  "log out": {
    // 🖖 Log out command
    response: "🖖 Logging you out, Hooman...",
    // 🖖 Action to perform
    action: "logout"
  },
  // 🔒 Logout command
  "logout": {
    // 🔒 Logout command
    response: "🔒 Session terminated. May your signs be well-lit.",
    // 🔒 Action to perform
    action: "logout"
  },
  // 🧠 Open prompt modal
  "help": {
    // 🧠 Help command
    response: "🧠 You can say things like 'log out', 'check version', or 'open the prompt modal'.",
    // 🧠 Action to perform
    action: "info"
  },
  // 🧠 Open prompt modal
  "check version": {
    // 📦 Version check command
    response: `📦 Current version: ${dynamicVersion} (see footer)`,
    // 📦 Action to perform
    action: "versionCheck"
  }
};
// 🚀 Main Netlify handler
exports.handler = async (event) => {
  try {
    // 📦 Parse request body safely
    let body;
    try {
      body = event.body ? JSON.parse(event.body) : {};
    } catch (err) {
      console.error("Invalid просьба body:", err.message);
      return {
        statusCode: 400,
        body: JSON.stringify({ error: "Invalid request body" })
      };
    }

    const { prompt, conversation } = body;
    const apiKey = process.env.OPENAI_API_KEY;
    if (!apiKey) {
      console.error("Missing OpenAI API key");
      return {
        statusCode: 500,
        body: JSON.stringify({ error: "Server configuration error" })
      };
    }

    const dateInfo = getPhoenixTime();
    const systemMessage = createSystemMessage(dateInfo);

    // 💡 Handle conversation if provided
    if (Array.isArray(conversation) && conversation.every(msg => msg?.role && msg?.content && ["system", "user", "assistant"].includes(msg.role))) {
      const sanitizedConversation = conversation.filter(
        msg => msg && typeof msg.content === "string" && msg.content.trim() !== ""
      );
      const messages = [systemMessage, ...sanitizedConversation];

      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 10000); // 10s timeout
      try {
        const response = await fetch("https://api.openai.com/v1/chat/completions", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Authorization: `Bearer ${apiKey}`
          },
          body: JSON.stringify({
            model: process.env.OPENAI_MODEL || "gpt-3.5-turbo",
            messages,
            temperature: 0.7
          }),
          signal: controller.signal
        });
        clearTimeout(timeout);

        const data = await response.json();
        if (!response.ok) {
          console.error("OpenAI API error:", data);
          return {
            statusCode: 500,
            body: JSON.stringify({ error: "Failed to fetch response from OpenAI" })
          };
        }
        const content = data.choices?.[0]?.message?.content && typeof data.choices[0].message.content === "string"
          ? data.choices[0].message.content.trim()
          : "🤖 Sorry, I didn’t understand that or the response was empty.";
        return {
          statusCode: 200,
          body: JSON.stringify({ response: content })
        };
      } catch (err) {
        console.error("Conversation fetch error:", err.message);
        return {
          statusCode: 500,
          body: JSON.stringify({ error: "Failed to fetch response from OpenAI" })
        };
      }
    }

    // 🧼 Validate prompt
    const cleanedPrompt = typeof prompt === "string" ? prompt.trim().toLowerCase() : "";
    if (!cleanedPrompt) {
      console.error("Missing or invalid prompt:", prompt);
      return {
        statusCode: 400,
        body: JSON.stringify({ error: "Missing prompt" })
      };
    }

    // 🔁 Check for predefined commands
    const intentKey = Object.keys(intentMap).find(key => cleanedPrompt === key);
    if (intentKey) {
      return {
        statusCode: 200,
        body: JSON.stringify(intentMap[intentKey])
      };
    }

    // 🔄 Fallback: single prompt to OpenAI
    const messages = [systemMessage, { role: "user", content: prompt }];
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000); // 10s timeout
    try {
      const response = await fetch("https://api.openai.com/v1/chat/completions", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${apiKey}`
        },
        body: JSON.stringify({
          model: process.env.OPENAI_MODEL || "gpt-3.5-turbo",
          messages,
          temperature: 0.7
        }),
        signal: controller.signal
      });
      clearTimeout(timeout);

      const data = await response.json();
      if (!response.ok) {
        console.error("OpenAI API error:", data);
        return {
          statusCode: 500,
          body: JSON.stringify({ error: "Failed to fetch response from OpenAI" })
        };
      }
      const content = data.choices?.[0]?.message?.content && typeof data.choices[0].message.content === "string"
        ? data.choices[0].message.content.trim()
        : "🤖 Sorry, I didn’t understand that or the response was empty.";
      return {
        statusCode: 200,
        body: JSON.stringify({ response: content })
      };
    } catch (err) {
      console.error("Fallback fetch error:", err.message);
      return {
        statusCode: 500,
        body: JSON.stringify({ error: "Failed to fetch response from OpenAI" })
      };
    }
  } catch (err) {
    console.error("Handler error:", err.message);
    return {
      statusCode: 500,
      body: JSON.stringify({ error: "Internal server error" })
    };
  }
};