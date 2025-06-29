# Skyebot Contact Parser Specification

## 📌 Purpose

Enable Skyebot to process pasted email signatures or contact details and automatically suggest structured JSON insertions into the `skyesoft-data.json` store. The system avoids duplicating existing entities, locations, or contacts.

---

## 📋 Required Fields

Each new contact must include:

* **Name** (e.g., "Susan Alderson")
* **Title** (e.g., "Accounting")
* **Entity Name** (e.g., "Christy Signs")
* **Address** (e.g., "3145 N 33rd Ave, Phoenix, AZ 85017")
* **Email** (e.g., "[susan@christysigns.com](mailto:susan@christysigns.com)")
* **Phone(s)**: At least one of:

  * Office Phone
  * Cell Phone
* **Google Place Data**:

  * `googlePlaceId`
  * `latitude`
  * `longitude`

---

## 🧠 Skyebot Responsibilities

1. **Parse Input**:

   * Detect name, title, email, phones, company, and address
2. **Check Existing Data**:

   * Match against `entities[]`, `locations[]`, and `contacts[]`
3. **Determine Action**:

   * If Entity, Location, and Contact exist → notify: "Already exists"
   * If Entity exists but Location is new → create new `location`
   * If both Entity and Location exist but Contact is new → add `contact`
   * If all are new → create `entity`, `location`, and `contact`
   * If entity exists but contact's address differs → treat as new location
4. **Fetch Google Place Info** (via API):

   * Based on parsed address
5. **Present Summary to User**:

   * Skyebot previews the new/updated object for review
6. **On Approval**:

   * Append to `skyesoft-data.json`

---

## 🧪 Example Scenario: Existing Entity and Location

**Input:**

```
Susan Alderson, Accounting
Christy Signs
(602) 242-4488
susan@christysigns.com
```

**Outcome:**

* `entityId = 1` (Christy Signs)
* `locationId = 1` (already exists)
* Create new `contact` with linked IDs

---

## 🔐 Notes

* Prevent duplicates by normalizing input (e.g., phone number formats)
* Use `id` integers for all objects
* Add optional `officePhoneExt` field
* Store in `assets/data/skyesoft-data.json`

---

## 📂 Related Paths

* JSON store: `assets/data/skyesoft-data.json`
* Parser code: `assets/js/parseSignature.js`
* Netlify Function for write access: `netlify/functions/addContact.js`

---

## 🛠️ Coming Enhancements

* User prompt when data is ambiguous
* Tag contacts with `role` or `tag` fields (optional)
* Fallback on Google Maps API failure with manual confirmation
