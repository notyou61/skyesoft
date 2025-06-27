// netlify/functions/askOpenAI.js
const fetch = (...args) => import('node-fetch').then(({ default: fetch }) => fetch(...args));

exports.handler = async (event) => {
  try {
    const body = JSON.parse(event.body || '{}');
    const prompt = body.prompt;
    const conversation = body.conversation;
    const apiKey = process.env.OPENAI_API_KEY;

    if (!apiKey) {
      return {
        statusCode: 500,
        body: JSON.stringify({ error: "API key missing" }),
      };
    }

    // 🧠 Handle modern chat UI conversation structure
    if (Array.isArray(conversation)) {
      const now = new Date();
      const dateInfo = {
        dayOfWeek: now.toLocaleDateString("en-US", { weekday: "long" }),
        month: now.toLocaleDateString("en-US", { month: "long" }),
        day: now.getDate(),
        year: now.getFullYear(),
        time: now.toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit" }),
        iso: now.toISOString()
      };

      const systemMessage = {
        role: "system",
        content: `You are Skyebot, a helpful assistant. Current local time is ${dateInfo.time} on ${dateInfo.dayOfWeek}, ${dateInfo.month} ${dateInfo.day}, ${dateInfo.year}. Respond using this info when users ask about time or date.`
      };

      const fullMessages = [systemMessage, ...conversation];

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

      const data = await response.json();
      const content = data.choices?.[0]?.message?.content || "🤖 Sorry, I didn’t understand that.";

      return {
        statusCode: 200,
        body: JSON.stringify({ response: content }),
      };
    }

    // 🔁 Fallback for plain prompts (legacy or manual testing)
    if (!prompt) {
      return {
        statusCode: 400,
        body: JSON.stringify({ error: "Missing prompt" }),
      };
    }

    const cleanedPrompt = prompt.trim().toLowerCase();
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

    for (let key in intentMap) {
      if (cleanedPrompt.includes(key)) {
        return {
          statusCode: 200,
          body: JSON.stringify(intentMap[key]),
        };
      }
    }

    // Standard OpenAI call with standalone prompt
    const fallbackMessages = [
      {
        role: "system",
        content: `You are Skyebot, a helpful assistant. The current time is ${new Date().toLocaleTimeString("en-US", { hour: "2-digit", minute: "2-digit" })}.`
      },
      {
        role: "user",
        content: prompt
      }
    ];

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

    const data = await response.json();
    const content = data.choices?.[0]?.message?.content || "🤖 Sorry, I didn’t understand that.";

    return {
      statusCode: 200,
      body: JSON.stringify({ response: content }),
    };

  } catch (err) {
    return {
      statusCode: 500,
      body: JSON.stringify({ error: err.message }),
    };
  }
};
