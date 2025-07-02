// 📁 netlify/functions/serverSideEvents.js

export const handler = async (event, context) => {
  return {
    statusCode: 200,
    headers: {
      "Content-Type": "text/event-stream",
      "Cache-Control": "no-cache",
      Connection: "keep-alive",
    },
    body: createStreamBody(),
  };
};

// Helper: Simple SSE generator using ReadableStream
function createStreamBody() {
  const encoder = new TextEncoder();
  let interval;

  const stream = new ReadableStream({
    start(controller) {
      interval = setInterval(() => {
        const unixTime = Math.floor(Date.now() / 1000);
        const payload = `data: ${JSON.stringify({ unixTime })}\n\n`;
        controller.enqueue(encoder.encode(payload));
      }, 1000);
    },
    cancel() {
      clearInterval(interval);
    },
  });

  return stream;
}
