// 📁 netlify/functions/checkProposedContact.js
// 🧠 Use native fetch (Node.js 18+ compatible)
const path = require("path");
// 📦 Import required modules
const skyesoftData = require("/home/notyou64/public_html/data/skyesoft-data.json");
// 🚀 Function to check proposed contact against existing data
function checkProposedContact({ name, title, email, officePhone, cellPhone, company, address }) {
  const { entities, locations, contacts } = skyesoftData;
  const result = {
    status: "unknown",
    matchedEntityId: null,
    matchedLocationId: null,
    matchedContactId: null,
    notes: []
  };
  // Normalize input
  const entity = entities.find(e => e.name.toLowerCase() === company.toLowerCase());
  // Check if the company matches an existing entity
  if (entity) {
    result.matchedEntityId = entity.id;
    result.notes.push(`Matched entity: ${entity.name}`);
  } else {
    result.notes.push("No matching entity found – new entity will be created.");
  }
  // Normalize contact details
  const location = locations.find(loc =>
    loc.entityId === result.matchedEntityId &&
    loc.address.toLowerCase() === address.toLowerCase()
  );
  // Check if the address matches an existing location
  if (location) {
    result.matchedLocationId = location.id;
    result.notes.push(`Matched location: ${location.address}`);
  } else {
    result.notes.push("No matching location found – new location will be created.");
  }
  // Check if the contact already exists
  const contact = contacts.find(c =>
    c.entityId === result.matchedEntityId &&
    c.locationId === result.matchedLocationId &&
    c.email.toLowerCase() === email.toLowerCase()
  );
  // If contact exists, return its ID and status
  if (contact) {
    result.matchedContactId = contact.id;
    result.status = "duplicate";
    result.notes.push(`Contact already exists: ${contact.name}`);
  } else {
    result.status = "new";
    result.notes.push("New contact will be created.");
  }
  // If no contact ID, check for phone matches
  return result;
}
// 📁 Export the function for use in other modules
module.exports = { checkProposedContact };