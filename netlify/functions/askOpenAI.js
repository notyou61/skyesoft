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
  // Role of the message
  role: "system",
  // Content of the message
  content: `You are Skyebot, a helpful assistant. Current local time is ${dateInfo.time} on ${dateInfo.dayOfWeek}, ${dateInfo.month} ${dateInfo.day}, ${dateInfo.year}.`
});
// Intent mapping for predefined commands
const intentMap = {
  // "log out": { response: "🖥️ Opening the prompt modal...", action: "openModal" },
  "log out": { response: "🖖 Logging you out, Hooman...", action: "logout" },
  // "logout": { response: "🖥️ Opening the prompt modal...", action: "openModal" },
  logout: { response: "🔒 Session terminated. May your signs be well-lit.", action: "logout" },
  // "help": { response: "🖥️ Opening the prompt modal...", action: "openModal" },
  help: { response: "🧠 You can say things like 'log out', 'check version', or 'open the prompt modal'.", action: "info" },
  // "check version": { response: "🖥️ Opening the prompt modal...", action: "openModal" },
  "check version": { response: `📦 Current version: ${dynamicVersion} (see footer)`, action: "versionCheck" }
};
// Main Netlify handler
exports.handler = async (event) => {
  try {
    // Parse request body safely
    let body;
    // Check if body is present and parse it
    try {
      // If body is a string, parse it as JSON
      body = event.body ? JSON.parse(event.body) : {};
    } catch (err) {
      // If parsing fails, log the error and return a 400 response
      console.error("Invalid request body:", err.message);
      // Return a 400 Bad Request response
      return { statusCode: 400, body: JSON.stringify({ error: "Invalid request body" }) };
    }
    // Ensure body has prompt and conversation
    const { prompt, conversation } = body;
    // Debug: log the received prompt and conversation
    const apiKey = process.env.OPENAI_API_KEY;
    // Debug: log the API key presence
    if (!apiKey) {
      // Log error if API key is missing
      console.error("Missing OpenAI API key");
      // Return a 500 Internal Server Error response
      return { statusCode: 500, body: JSON.stringify({ error: "Server configuration error" }) };
    }
    // Debug: log the prompt and conversation
    const cleanedPrompt = typeof prompt === "string" ? prompt.trim() : "";
    // Debug: log the cleaned prompt
    if (!cleanedPrompt) {
      // Log error if prompt is missing or invalid
      console.error("Missing or invalid prompt:", prompt);
      // Return a 400 Bad Request response
      return { statusCode: 400, body: JSON.stringify({ error: "Missing prompt" }) };
    }
    // Detect contact info (email + phone)
    const email = cleanedPrompt.match(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,}/)?.[0];
    // Debug: log the detected email
    const phone = cleanedPrompt.match(/\(\d{3}\)\s?\d{3}-\d{4}/)?.[0];
    // Debug: log the detected phone
    if (email && phone) {
      // Log detected contact info
      const contactCheckResult = checkProposedContact({
        // Log the detected name
        name: "Placeholder Name",
        // Log the detected title
        title: "Placeholder Title",
        // Log the detected email
        email,
        // Log the detected phone
        officePhone: phone,
        // 
        cellPhone: "",
        // Log the detected company
        company: "Placeholder Company",
        // Log the detected address
        address: "Placeholder Address"
      });
      // Debug: log the contact check result
      return { statusCode: 200, body: JSON.stringify({ response: contactCheckResult }) };
    }
    // Debug: show cleaned prompt
    console.log("🔎 Cleaned prompt:", cleanedPrompt.toLowerCase());
    // Check for predefined commands
    const intent = intentMap[cleanedPrompt.toLowerCase()];
    // If intent matches, return predefined response
    if (intent) {
      // Log the intent trigger
      console.log("🧠 Intent triggered:", cleanedPrompt.toLowerCase(), intent);
      // Return
      return {
        // Assign Status Code
        statusCode: 200,
        // Headers to ensure JSON parsing
        headers: { "Content-Type": "application/json" }, // <-- ensures correct parsing
        // Body with intent response
        body: JSON.stringify({
          // Response from intent
          response: intent.response,
          // Action to take
          action: intent.action || null,
          // Intent name for tracking
          intentName: cleanedPrompt.toLowerCase(),
          // Timstamp for logging
          timestamp: new Date().toISOString()
        })
      };
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
    // Ensure we have a valid API key
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
      // Clear timeout after response
      clearTimeout(timeout);
      // Check if response is ok
      const data = await response.json();
      if (!response.ok) {
        console.error("OpenAI API error:", data);
        throw new Error("OpenAI API Error");
      }
      // Extract content from response
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