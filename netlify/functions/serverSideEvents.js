// netlify/functions/serverSideEvents.js

export default async function handler(req, res) {
  // Set headers for SSE
  res.setHeader("Content-Type", "text/event-stream");
  res.setHeader("Cache-Control", "no-cache");
  res.setHeader("Connection", "keep-alive");

  // Keep the connection open
  res.flushHeaders();

  // Send an SSE ping every second
  const intervalId = setInterval(() => {
    const unixTime = Math.floor(Date.now() / 1000);
    res.write(`data: ${JSON.stringify({ unixTime })}\n\n`);
  }, 1000);

  // Clean up on disconnect
  req.on("close", () => {
    clearInterval(intervalId);
    res.end();
  });
}
