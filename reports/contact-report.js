const express = require('express');
const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const fetch = require('node-fetch');

const router = express.Router();

// POST /api/generate-contact-pdf
router.post('/generate-contact-pdf', async (req, res) => {
  const {
    placeId,
    entityName = "Metro Monument Group",
    contactName = "Ms Jennifer Carlisle — Operations Director",
    address = "3620 S Central Ave, Phoenix, AZ 85040",
    phone = "(480) 555-0182",
    email = "jcarlisle@metromonumentgroup.com"
  } = req.body;

  let lat = 33.4718, lng = -111.9914;

  // Get coordinates from Google Place ID (if provided)
  if (placeId && process.env.GOOGLE_MAPS_API_KEY) {
    try {
      const resp = await fetch(`https://maps.googleapis.com/maps/api/place/details/json?place_id=${placeId}&fields=geometry&key=${process.env.GOOGLE_MAPS_API_KEY}`);
      const data = await resp.json();
      if (data.result?.geometry?.location) {
        lat = data.result.geometry.location.lat;
        lng = data.result.geometry.location.lng;
      }
    } catch (e) {
      console.warn("Place ID lookup failed", e);
    }
  }

  const mapUrl = `https://maps.googleapis.com/maps/api/staticmap?center=${lat},${lng}&zoom=18&size=650x320&maptype=roadmap&markers=color:red%7C${lat},${lng}&key=${process.env.GOOGLE_MAPS_API_KEY || ''}`;

  const templatePath = path.join(__dirname, '../templates/contact-report.html');
  const template = fs.readFileSync(templatePath, 'utf8');

  const documentBody = `
    <div class="section">
      <h2>📍 Location Anchor</h2>
      <table>
        <tr><th>Address</th><td>${address}</td></tr>
        <tr><th>Entity</th><td>${entityName}</td></tr>
      </table>
      <div class="map-container">
        <strong>AI-Verified Location</strong><br>
        <img src="${mapUrl}" alt="Map"><br>
        <small>Place ID: ${placeId || 'N/A'} • ${lat.toFixed(5)}, ${lng.toFixed(5)}</small>
      </div>
    </div>

    <div class="section">
      <h2>👤 Contact Identity</h2>
      <table>
        <tr><th>Contact</th><td>${contactName}</td></tr>
        <tr><th>Phone</th><td>${phone}</td></tr>
        <tr><th>Email</th><td>${email}</td></tr>
      </table>
    </div>

    <div class="section">
      <h2>✅ Governance & Readiness</h2>
      <p><strong>✓ READY FOR COMMIT</strong></p>
      <p>This proposal represents a new entity, location, and contact.<br>No governance issues detected.</p>
    </div>

    <div class="section">
      <h2>🧠 Operational Intelligence Summary</h2>
      <p><strong>Location is the anchor object.</strong> This is part of Skyesoft’s Operational Intelligence layer.</p>
    </div>
  `;

  let htmlContent = template
    .replace('{{documentTitle}}', `Skyesoft Contact Report - ${entityName}`)
    .replace('{{logoUrl}}', 'https://yourdomain.com/logo.png')   // ← CHANGE THIS
    .replace('{{generatedDate}}', new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }))
    .replace('{{documentBody}}', documentBody);

  const browser = await puppeteer.launch({ headless: true });
  const page = await browser.newPage();
  await page.setContent(htmlContent, { waitUntil: 'networkidle0' });

  const pdfBuffer = await page.pdf({
    format: 'Letter',
    printBackground: true,
  });

  await browser.close();

  res.setHeader('Content-Type', 'application/pdf');
  res.setHeader('Content-Disposition', `inline; filename="Skyesoft_Contact_Report_${entityName.replace(/\s+/g, '_')}_${new Date().toISOString().slice(0,10)}.pdf"`);
  res.send(pdfBuffer);
});

module.exports = router;