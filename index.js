const express = require('express');
const path = require('path');
const cors = require('cors');

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(cors());
app.use(express.json());

// Routes
const codexRoutes = require("./codex-files-api");
app.use("/api/files", codexRoutes);

// Static files (optional)
app.use(express.static(path.join(__dirname, 'public')));

app.listen(PORT, () => {
  console.log(`🚀 Codex App listening on http://localhost:${PORT}`);
});
