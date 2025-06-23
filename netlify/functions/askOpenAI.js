// netlify/functions/askOpenAI.js
const fetch = require('node-fetch');

exports.handler = async (event) => {
  try {
    const { prompt } = JSON.parse(event.body || '{}');
    if (!prompt) {
      return {
        statusCode: 400,
        body: JSON.stringify({ error: "Missing prompt" }),
      };
    }

    const apiKey = process.env.OPENAI_API_KEY;
    if (!apiKey) {
      return {
        statusCode: 500,
        body: JSON.stringify({ error: "API key missing" }),
      };
    }

    const cleanedPrompt = prompt.trim().toLowerCase();

    // 🔁 LGBAS-style intent routing
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
        response: "📦 Current version: v2025.06.16 (see footer)",
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

    // 🤖 Fallback to OpenAI chat completion
    const response = await fetch("https://api.openai.com/v1/chat/completions", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${apiKey}`,
      },
      body: JSON.stringify({
        model: "gpt-3.5-turbo",
        messages: [{ role: "user", content: prompt }],
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
