// netlify/functions/askOpenAI.js
const fetch = global.fetch;
const fs = require("fs");
const path = require("path");
const { checkProposedContact } = require("./checkProposedContact");

// Load version from JSON file
let dynamicVersion = "unknown";
try {
  const versionPath = path.join(__dirname, "../../assets/data/version.json");
  const versionData = fs.readFileSync(versionPath, "utf8");
  const parsed = JSON.parse(versionData);
  if (parsed.version) dynamicVersion = parsed.version;
} catch (err) {
  console.error("Version load error:", err.message);
}

// Helper to format Phoenix time
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

// Helper to create system message
const createSystemMessage = (dateInfo) => ({
  role: "system",
  content: `You are Skyebot, a helpful assistant. Current local time is ${dateInfo.time} on ${dateInfo.dayOfWeek}, ${dateInfo.month} ${dateInfo.day}, ${dateInfo.year}.`
});

// Intent mapping for predefined commands
const intentMap = {
  "log out": { response: "🖖 Logging you out, Hooman...", action: "logout" },
  logout: { response: "🔒 Session terminated. May your signs be well-lit.", action: "logout" },
  help: { response: "🧠 You can say things like 'log out', 'check version', or 'open the prompt modal'.", action: "info" },
  "check version": { response: `📦 Current version: ${dynamicVersion} (see footer)`, action: "versionCheck" }
};

// Main Netlify handler
exports.handler = async (event) => {
  try {
    // Parse request body safely
    let body;
    try {
      body = event.body ? JSON.parse(event.body) : {};
    } catch (err) {
      console.error("Invalid request body:", err.message);
      return { statusCode: 400, body: JSON.stringify({ error: "Invalid request body" }) };
    }

    const { prompt, conversation } = body;
    const apiKey = process.env.OPENAI_API_KEY;

    if (!apiKey) {
      console.error("Missing OpenAI API key");
      return { statusCode: 500, body: JSON.stringify({ error: "Server configuration error" }) };
    }

    const cleanedPrompt = typeof prompt === "string" ? prompt.trim() : "";
    if (!cleanedPrompt) {
      console.error("Missing or invalid prompt:", prompt);
      return { statusCode: 400, body: JSON.stringify({ error: "Missing prompt" }) };
    }

    // Detect contact info (email + phone)
    const email = cleanedPrompt.match(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,}/)?.[0];
    const phone = cleanedPrompt.match(/\(\d{3}\)\s?\d{3}-\d{4}/)?.[0];
    if (email && phone) {
      const contactCheckResult = checkProposedContact({
        name: "Placeholder Name",
        title: "Placeholder Title",
        email,
        officePhone: phone,
        cellPhone: "",
        company: "Placeholder Company",
        address: "Placeholder Address"
      });
      return { statusCode: 200, body: JSON.stringify({ response: contactCheckResult }) };
    }

    // Check for predefined commands
    const intent = intentMap[cleanedPrompt.toLowerCase()];
    if (intent) {
      return { statusCode: 200, body: JSON.stringify(intent) };
    }

    // Get Phoenix time and create system message
    const dateInfo = getPhoenixTime();
    const systemMessage = createSystemMessage(dateInfo);
    const baseMessages = [systemMessage];

    // Handle conversation or single prompt
    const chatMessages = Array.isArray(conversation)
      ? [...baseMessages, ...conversation.filter(m => m?.role && m?.content?.trim() && ["system", "user", "assistant"].includes(m.role))]
      : [...baseMessages, { role: "user", content: cleanedPrompt }];

    // Call OpenAI API
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 10000);

    try {
      const response = await fetch("https://api.openai.com/v1/chat/completions", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${apiKey}`
        },
        body: JSON.stringify({
          model: process.env.OPENAI_MODEL || "gpt-3.5-turbo",
          messages: chatMessages,
          temperature: 0.7
        }),
        signal: controller.signal
      });
      clearTimeout(timeout);

      const data = await response.json();
      if (!response.ok) {
        console.error("OpenAI API error:", data);
        throw new Error("OpenAI API Error");
      }

      const content = data.choices?.[0]?.message?.content?.trim() || "🤖 No response from model.";
      return { statusCode: 200, body: JSON.stringify({ response: content }) };
    } catch (err) {
      console.error("OpenAI fetch error:", err.message);
      return { statusCode: 500, body: JSON.stringify({ error: "Failed to fetch response from OpenAI" }) };
    }
  } catch (err) {
    console.error("Handler error:", err.message);
    return { statusCode: 500, body: JSON.stringify({ error: "Internal server error" }) };
  }
};