// index.js
const express = require('express');
const sseRoute = require('./api/sse');
const app = express();

app.use('/api', sseRoute);

app.listen(3000, () => {
  console.log('🚀 Server running on http://localhost:3000');
});
