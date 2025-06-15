const express = require("express");
const app = express();
const codexRoutes = require("./codex-files-api");

app.use("/api/files", codexRoutes);

app.listen(3000, () => console.log("Server running on port 3000"));