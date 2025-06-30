// netlify/functions/askOpenAI.js
// Netlify Function to handle OpenAI requests
const fetch = global.fetch;
// Ensure fetch is available globally
const fs = require("fs");
// Ensure fetch is available globally
const path = require("path");
// Import the contact check utility
//const { checkProposedContact } = require("./checkProposedContact");
const checkProposedContact = require("./checkProposedContact");
// Load version from JSON file
let dynamicVersion = "unknown";
// Attempt to read version from file
try {
  const versionPath = path.join(__dirname, "../../assets/data/version.json");
  const versionData = fs.readFileSync(versionPath, "utf8");
  const parsed = JSON.parse(versionData);
  if (parsed.version) dynamicVersion = parsed.version;
} catch (err) {
  // If reading version fails, log the error and use default
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
// Intent mapping for predefined commands (static + dynamic responses)
const intentMap = {
  // Static responses for common commands
  "log out": () => ({
    // Response for logging out
    response: "🖖 Logging you out, Hooman...",
    // Action to take (logout
    action: "logout"
  }),
  // Dynamic responses based on current time
  logout: () => ({
    // Response for logging out
    response: "🔒 Session terminated. May your signs be well-lit.",
    // Action to take (logout)
    action: "logout"
  }),
  // Dynamic response for opening the prompt modal
  help: () => ({
    // Response for help comman
    response: "🧠 You can say things like 'log out', 'check version', 'what time is it?', or 'open the prompt modal'.",
    // Action to take (open help modal)
    action: "info"
  }),
  // Dynamic response for opening the prompt modal
  "check version": () => ({
    // Response for checking version
    response: `📦 Current version: ${dynamicVersion} (see footer)`,
    // Action to take (open version modal)
    action: "versionCheck"
  }),
  // Dynamic response for opening the prompt modal
  getTime: () => {
    // Get the current time in Phoenix timezone
    const now = new Date();
    // Format the time to a readable string
    const time = now.toLocaleTimeString("en-US", {
      // Options for formatting hour
      hour: "numeric",
      // Options for formatting minute
      minute: "numeric",
      // Options for formatting hour in 12-hour format
      hour12: true,
      // Timezone for Phoenix
      timeZone: "America/Phoenix"
    });
    // 
    return {
      // Response with current time
      response: `🕒 The current time is ${time}.`,
      // Action to take (none in this case)
      action: "none"
    };
  },
  // Dynamic response for getting the current date
  getDate: () => {
    // Get the current date in Phoenix timezone
    const today = new Date().toLocaleDateString("en-US", {
      // Options for formatting weekday
      weekday: "long",
      // Options for formatting month
      month: "long",
      // Options for formatting day
      day: "numeric",
      // Options for formatting year
      year: "numeric",
      // Options for timezone
      timeZone: "America/Phoenix"
    });
    // Return the formatted date
    return {
      // Response with current date
      response: `📅 Today is ${today}.`,
      // Action to take (none in this case)
      action: "none"
    };
  },
  // Dynamic response for getting the current date and time 
  getDateTime: () => {
    // Get the current date and time in Phoenix timezone
    const now = new Date().toLocaleString("en-US", {
      // Options for formatting weekday
      weekday: "long",
      // Options for formatting month
      month: "long",
      // Options for formatting day
      day: "numeric",
      // Options for formatting year
      year: "numeric",
      // Options for formatting hour
      hour: "numeric",
      // Options for formatting minut
      minute: "numeric",
      // Options for formatting hour in 12-hour format
      hour12: true,
      // Timezone for Phoenix
      timeZone: "America/Phoenix"
    });
    // Return the formatted date and time 
    return {
      // Response with current date and time
      response: `📆 It's currently ${now}.`,
      // Action to take (none in this case)
      action: "none"
    };
  }
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
    // Start with direct match
    let intentName = cleanedPrompt.toLowerCase();
    // Check if the intent exists in the static map
    let intent = intentMap[intentName];
    // If not found, try AI-based fallback
    if (!intent) {
      console.log("🤖 No direct match found. Falling back to AI intent detection...");

      const aiIntentResponse = await fetch("https://api.openai.com/v1/chat/completions", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${apiKey}`
        },
        body: JSON.stringify({
          model: process.env.OPENAI_MODEL || "gpt-3.5-turbo",
          messages: [
            {
              role: "system",
              content: `
    You are an intent classifier for Skyebot. Based on the user input, reply with only one word representing the intent. Valid options are:

    "log out", "logout", "help", "check version", "getTime", "getDate", "getDateTime"

    Reply ONLY with the matching intent string. If none match, reply "unknown".`
            },
            { role: "user", content: cleanedPrompt }
          ],
          temperature: 0
        })
      });

      const aiIntentData = await aiIntentResponse.json();
      const aiIntent = aiIntentData?.choices?.[0]?.message?.content?.trim();
      console.log("🧠 AI-Detected Intent:", aiIntent);

      if (aiIntent && intentMap[aiIntent]) {
        intentName = aiIntent;
        intent = intentMap[aiIntent];
      }
    }
    // Respond if a valid intent was found
    if (intent) {
      console.log("🧠 Intent triggered:", intentName);
      return {
        statusCode: 200,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          response: intent.response,
          action: intent.action || null,
          intentName,
          timestamp: new Date().toISOString()
        })
      };
    }
    // Get Phoenix time and create system message
    const dateInfo = getPhoenixTime();
    // Debug: log the Phoenix time
    const systemMessage = createSystemMessage(dateInfo);
    // Debug: log the system message
    const baseMessages = [systemMessage];
    // Handle conversation or single prompt
    const chatMessages = Array.isArray(conversation)
      ? [...baseMessages, ...conversation.filter(m => m?.role && m?.content?.trim() && ["system", "user", "assistant"].includes(m.role))]
      : [...baseMessages, { role: "user", content: cleanedPrompt }];
    // Call OpenAI API
    const controller = new AbortController();
    // Set a timeout to abort the request after 10 seconds
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
      // Clear timeout if fetch fails
      console.error("OpenAI fetch error:", err.message);
      // Clear the timeout to prevent memory leaks
      return { statusCode: 500, body: JSON.stringify({ error: "Failed to fetch response from OpenAI" }) };
    }
  } catch (err) {
    // Catch any unexpected errors
    console.error("Handler error:", err.message);
    // Return a 500 Internal Server Error response
    return { statusCode: 500, body: JSON.stringify({ error: "Internal server error" }) };
  }
};