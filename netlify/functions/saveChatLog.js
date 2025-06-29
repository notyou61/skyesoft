const fs = require("fs");

exports.handler = async (event) => {
  try {
    const { log } = JSON.parse(event.body);
    const timestamp = new Date().toISOString().replace(/[:.]/g, "-");
    const filePath = `/tmp/chatlog-${timestamp}.txt`; // For temporary file

    fs.writeFileSync(filePath, JSON.stringify(log, null, 2));

    return {
      statusCode: 200,
      body: JSON.stringify({ message: "Log saved", path: filePath }),
    };
  } catch (err) {
    return {
      statusCode: 500,
      body: JSON.stringify({ error: err.message }),
    };
  }
};