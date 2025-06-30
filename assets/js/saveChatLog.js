const fs = require("fs");
const path = require("path");

exports.handler = async (event) => {
  if (event.httpMethod !== "POST") {
    return {
      statusCode: 405,
      body: "Method Not Allowed"
    };
  }
  // ✅ Validate request body
  try {
    const body = JSON.parse(event.body);
    const log = body.log || [];

    // ✅ Generate UNIX timestamp
    const timestamp = Date.now(); // e.g., 1724929054029

    // ✅ Set path and filename
    const dir = path.resolve("/tmp/chatlogs");
    const fileName = `chatlog_${timestamp}.json`;
    const filePath = path.join(dir, fileName);

    // ✅ Ensure directory exists
    if (!fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true });
    }

    // ✅ Write file
    fs.writeFileSync(filePath, JSON.stringify(log, null, 2));

    return {
      statusCode: 200,
      body: JSON.stringify({ success: true, file: fileName })
    };
  } catch (err) {
    console.error("Save failed:", err);
    return {
      statusCode: 500,
      body: JSON.stringify({ error: "Internal Server Error" })
    };
  }
};
