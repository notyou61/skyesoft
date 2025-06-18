// api/sse.js
const express = require('express');
const router = express.Router();
const { query } = require('./dbConnect');

router.get('/stream', async (req, res) => {
  res.setHeader('Content-Type', 'text/event-stream');
  res.setHeader('Cache-Control', 'no-cache');
  res.setHeader('Connection', 'keep-alive');

  const interval = setInterval(async () => {
    try {
      const [entities, locations, contacts] = await Promise.all([
        query('SELECT * FROM entities WHERE status = 1'),
        query('SELECT * FROM locations WHERE status = 1'),
        query('SELECT * FROM contacts WHERE status = 1')
      ]);

      const payload = {
        timestamp: new Date().toISOString(),
        data: { entities, locations, contacts }
      };

      res.write(`data: ${JSON.stringify(payload)}\n\n`);
    } catch (err) {
      res.write(`event: error\ndata: ${JSON.stringify({ error: err.message })}\n\n`);
    }
  }, 1000); // Heartbeat every 1s

  req.on('close', () => {
    clearInterval(interval);
    res.end();
  });
});

module.exports = router;
