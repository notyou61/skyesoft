// netlify/functions/askOpenAI.js

// 🧠 Dynamically import fetch (Node.js compatible)
const fetch = (...args) => import('node-fetch').then(({ default: fetch }) => fetch(...args));

// 🚀 Main Netlify handler for serverless function
exports.handler = async (event) => {
  try {
    // 📦 Parse the incoming request body
    const body = JSON.parse(event.body || '{}');

    // 💬 Extract user prompt and conversation history
    const prompt = body.prompt;
    const conversation = body.conversation;

    // 🔐 Validate presence of OpenAI API key
    const apiKey = process.env.OPENAI_API_KEY;
    if (!apiKey) {
      return {
        statusCode: 500,
        body: JSON.stringify({ error: "API key missing" }),
      };
    }

    // 🕒 Generate Phoenix-local time context for AI system message
    const phoenixNow = new Date().toLocaleString("en-US", {
      timeZone: "America/Phoenix",
      hour: "2-digit",
      minute: "2-digit",
      hour12: true,
      weekday: "long",
      year: "numeric",
      month: "long",
      day: "numeric"
    });

    // 🗂️ Break Phoenix date/time into components
    const [dayOfWeek, monthDayYear, time] = phoenixNow.split(", ");
    const [month, day, year] = monthDayYear.split(" ");

    // 🧱 Construct structured date object
    const dateInfo = {
      time: time.trim(),
      dayOfWeek: dayOfWeek.trim(),
      month: month.trim(),
      day: day.trim(),
      year: year.trim()
    };

    // 💡 Add system context message if conversation history exists
    const isValidConversation = Array.isArray(conversation) &&
      conversation.every(msg => msg?.role && msg?.content);

    if (isValidConversation) {
      // 📣 Inject system message with Phoenix-local time context
      const systemMessage = {
        role: "system",
        content: `You are Skyebot, a helpful assistant. Current local time is ${dateInfo.time} on ${dateInfo.dayOfWeek}, ${dateInfo.month} ${dateInfo.day}, ${dateInfo.year}. Respond using this info when users ask about time or date.`
      };

      // 🧠 Add system message to beginning of message stack
      const fullMessages = [systemMessage, ...conversation];

      // 🔄 Send OpenAI request with full context
      const response = await fetch("https://api.openai.com/v1/chat/completions", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Authorization: `Bearer ${apiKey}`,
        },
        body: JSON.stringify({
          model: "gpt-3.5-turbo",
          messages: fullMessages,
          temperature: 0.7,
        }),
      });

      // 📥 Handle OpenAI response
      const data = await response.json();
      const content = data.choices?.[0]?.message?.content?.trim() ||
        "🤖 Sorry, I didn’t understand that or the response was empty.";

      // 📤 Return result
      return {
        statusCode: 200,
        body: JSON.stringify({ response: content }),
      };
    }

    // 🧼 Sanitize legacy text-only prompt
    const cleanedPrompt = typeof prompt === "string" ? prompt.trim().toLowerCase() : "";

    // ❌ Reject empty or undefined prompts
    if (!cleanedPrompt) {
      return {
        statusCode: 400,
        body: JSON.stringify({ error: "Missing prompt" }),
      };
    }

    // 🗺️ Predefined text-command handler (logout, help, etc.)
    const intentMap = {
      "log out": {
        response: "🖖 Logging you out, Hooman...",
        action: "logout"
      },
      "logout": {
        response: "🔒 Session terminated. May your signs be well-lit.",
        action: "logout"
      },
      "help": {
        response: "🧠 You can say things like 'log out', 'check version', or 'open the prompt modal'.",
        action: "info"
      },
      "check version": {
        response: "📦 Current version: v2025.06.27 (see footer)",
        action: "versionCheck"
      }
    };

    // 🔁 Match cleaned prompt against command keywords
    for (let key in intentMap) {
      if (cleanedPrompt.includes(key)) {
        return {
          statusCode: 200,
          body: JSON.stringify(intentMap[key]),
        };
      }
    }

    // 🔄 Send basic OpenAI request with fallback logic
    const fallbackMessages = [
      {
        role: "system",
        content: `You are Skyebot, a helpful assistant. Current local time is ${dateInfo.time} on ${dateInfo.dayOfWeek}, ${dateInfo.month} ${dateInfo.day}, ${dateInfo.year}. Respond using this info when users ask about time or date.`
      },
      {
        role: "user",
        content: prompt
      }
    ];

    // 📡 Request to OpenAI without conversation context
    const response = await fetch("https://api.openai.com/v1/chat/completions", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${apiKey}`,
      },
      body: JSON.stringify({
        model: "gpt-3.5-turbo",
        messages: fallbackMessages,
        temperature: 0.7,
      }),
    });

    // 📬 Handle fallback response
    const data = await response.json();
    const content = data.choices?.[0]?.message?.content?.trim() ||
      "🤖 Sorry, I didn’t understand that or the response was empty.";

    // 📤 Return fallback result
    return {
      statusCode: 200,
      body: JSON.stringify({ response: content }),
    };

  } catch (err) {
    // 💥 Return error message if function crashes
    return {
      statusCode: 500,
      body: JSON.stringify({ error: err.message }),
    };
  }
};