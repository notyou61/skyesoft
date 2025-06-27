const fs = require("fs");
const path = require("path");

// Updated logo path
const logoPath = path.relative(__dirname, "../images/christyLogo.png");

function generateSurveyHTML(imageDir) {
  const imageFiles = fs
    .readdirSync(imageDir)
    .filter(file => file.toLowerCase().endsWith(".jpg"))
    .sort();

  const projectName = path.basename(path.dirname(imageDir));

  const imageHTML = imageFiles
    .map(file => {
      const relativePath = path.relative(__dirname, path.join(imageDir, file));
      return `<div class="photo"><img src="${relativePath}" alt="${file}"></div>`;
    })
    .join("\n");

  const html = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Photo Survey – ${projectName}</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      margin: 0;
      padding: 0;
    }
    .header, .footer {
      text-align: center;
      padding: 8px;
    }
    .header img {
      height: 40px;
      float: left;
    }
    .title {
      font-size: 20px;
      font-weight: bold;
      padding: 8px 0;
      text-align: center;
    }
    hr {
      border: 1px solid #000;
      margin: 8px 0;
    }
    .photos {
      display: flex;
      flex-wrap: wrap;
      justify-content: space-between;
      padding: 8px 32px;
      box-sizing: border-box;
    }
    .photo {
      width: 48%;
      margin-bottom: 8px;
    }
    .photo img {
      width: 100%;
      height: auto;
      display: block;
    }
    .footer hr {
      border: 1px solid #000;
      margin: 8px 0;
    }
    .footer p {
      font-size: 11px;
      margin: 4px 0;
    }
  </style>
</head>
<body>
  <div class="header">
    <img src="${logoPath}" alt="Christy Signs Logo" />
    <div class="title">Photo Survey – ${projectName}</div>
  </div>
  <hr />
  <div class="photos">
    ${imageHTML}
  </div>
  <div class="footer">
    <hr />
    <p>Christy Signs – 3145 N 33rd Ave, Phoenix, AZ 85017 | (602) 242-4488 | christysigns.com</p>
    <p>Copyright 2022 Christy Signs | All Rights Reserved</p>
  </div>
</body>
</html>`;

  const outputPath = path.join(path.dirname(imageDir), "Survey Report.html");
  fs.writeFileSync(outputPath, html);
  console.log(`✅ Report created: ${outputPath}`);
}

const inputDir = process.argv[2];
if (!inputDir) {
  console.error("❌ Please provide the image directory as an argument.");
  process.exit(1);
}

generateSurveyHTML(inputDir);
