// 📁 netlify/functions/serverSideEvents.js

export default async (req, res) => {
  // 🛠 Set SSE headers
  res.writeHead(200, {
    "Content-Type": "text/event-stream",
    "Cache-Control": "no-cache",
    "Connection": "keep-alive",
  });

  // 📡 Start the SSE stream
  res.flushHeaders();

  // 🔁 Send a ping every second with the current Unix timestamp
  const intervalId = setInterval(() => {
    const unixTime = Math.floor(Date.now() / 1000);
    res.write(`data: ${JSON.stringify({ unixTime })}\n\n`);
  }, 1000);

  // ❌ Clean up if client disconnects
  req.on("close", () => {
    clearInterval(intervalId);
    res.end();
  });
};
