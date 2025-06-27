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
    // ⏰ Capture Phoenix-local time info for context
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
    // 🗓️ Parse the Phoenix-local time string
    const [dayOfWeek, monthDayYear, time] = phoenixNow.split(", ");
    // 
    const [month, day, year] = monthDayYear.trim().split(" ");
    // 🗓️ Extract date components
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
       // 🧠 Define system message with current Phoenix time
       role: "system",
       // 📝 Provide context for the AI assistant
       content: `You are Skyebot, a helpful assistant. Current local time is ${dateInfo.time} on ${dateInfo.dayOfWeek}, ${dateInfo.month} ${dateInfo.day}, ${dateInfo.year}. Respond using this info when users ask about time or date.`
    };
     // 🧠 Add system message to beginning of message stack
     const sanitizedConversation = Array.isArray(conversation)
       ? conversation.filter(msg => msg && typeof msg.content === "string" && msg.content.trim() !== "")
       : [];
     // 🧼 Sanitize conversation messages
     const fullMessages = [systemMessage, ...sanitizedConversation];
     // 🔄 Send OpenAI request with full context
     const response = await fetch("https://api.openai.com/v1/chat/completions", {
        // 📝 Use POST method for OpenAI API
        method: "POST",
        // 🏷️ Set request headers
        headers: {
          // 🗂️ Specify content type as JSON
          "Content-Type": "application/json",
          // 🔑 Include OpenAI API key for authentication
          Authorization: `Bearer ${apiKey}`,
        },
        // 📝 Construct request body with messages and model
        body: JSON.stringify({
          // 
          model: "gpt-3.5-turbo",
          // 🗂️ Include full conversation context
          messages: fullMessages,
          // 🧠 Set response creativity level
          temperature: 0.7,
        }),
      });
      // 📥 Handle OpenAI response
      const data = await response.json();
      // 🧼 Extract and sanitize the assistant response content
      const content = (data.choices &&
                      data.choices[0] &&
                      data.choices[0].message &&
                      typeof data.choices[0].message.content === "string")
        ? data.choices[0].message.content.trim()
        : "🤖 Sorry, I didn’t understand that or the response was empty.";
      // 📤 Return result
      return {
        // 📬 Successful response with content
        statusCode: 200,
        // 📝 Return the response in JSON format
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
      // 🗺️ Additional command mappings
      "logout": {
        response: "🔒 Session terminated. May your signs be well-lit.",
        action: "logout"
      },
     // 🗺️ Help command with response
      "help": {
        response: "🧠 You can say things like 'log out', 'check version', or 'open the prompt modal'.",
        action: "info"
      },
      // 🗺️ Open prompt modal command
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
        // 🧠 Define system message with current Phoenix time
        role: "system",
        // 
        content: `You are Skyebot, a helpful assistant. Current local time is ${dateInfo.time} on ${dateInfo.dayOfWeek}, ${dateInfo.month} ${dateInfo.day}, ${dateInfo.year}. Respond using this info when users ask about time or date.`
      },
      {
        // 🗣️ User message with prompt
        role: "user",
        //  
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