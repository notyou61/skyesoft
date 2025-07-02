// netlify/functions/serverSideEvents.js

export default async function handler(req, res) {
  // 🧠 Set headers for Server-Sent Events (SSE)
  res.setHeader("Content-Type", "text/event-stream");
  res.setHeader("Cache-Control", "no-cache");
  res.setHeader("Connection", "keep-alive");
  res.flushHeaders();

  // ⏱️ Emit data every second
  const intervalId = setInterval(() => {
    const unixTime = Math.floor(Date.now() / 1000);

    const streamPayload = {
      timeDateArray: {
        currentUnixTime: unixTime
      }
    };

    res.write(`data: ${JSON.stringify(streamPayload)}\n\n`);
  }, 1000);

  // ❌ Clean up on client disconnect
  req.on("close", () => {
    clearInterval(intervalId);
    res.end();
  });
}