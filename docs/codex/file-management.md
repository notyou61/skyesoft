# 📁 File Management Suite

## 🧭 Primary Role
To provide a centralized and modular system for storing, linking, retrieving, and tagging files such as documents, images, forms, and scanned records — fully integrated with Skyesoft’s core database and accessible across all modules.

---

## 🔧 Key Capabilities

- **Upload & Tagging**
  - Files can be uploaded from desktop, mobile, or scanned input.
  - Each file can be tagged by:
    - Module (`Permit`, `Order`, `Contact`, etc.)
    - Record ID
    - File Type (`PDF`, `Image`, `Form`, etc.)
    - Category (`Job Photo`, `Final Approval`, etc.)

- **Record Linking**
  - Each file is stored in a secure directory with an associated metadata row.
  - Files are linked to specific record types via `tableName` and `recordId` values.
  - Example: `file → permits (table), ID #11542`

- **Access Control**
  - Read/write permissions based on user role (`Admin`, `Permit Agent`, `Field Rep`)
  - Internal use vs. public or client-facing document access

- **File Previews & Quick Access**
  - Inline preview support (PDF, JPG, PNG) within admin panel
  - Quick download link and audit trail for each file access event

- **Audit & Version Control**
  - Every upload is logged with `uploadedBy`, `timestamp`, and `ipAddress`
  - Optional version stacking (retain old versions)

- **Storage Paths & Redundancy**
  - Directory structure follows: `/files/[year]/[module]/[recordId]/[filename]`
  - Files stored locally and optionally mirrored to cloud (OneDrive/Drive)

---

## 📂 Sample Table: `files`

| Field           | Type         | Notes                                  |
|----------------|--------------|----------------------------------------|
| `id`           | INT          | Primary Key                            |
| `fileName`     | VARCHAR      | Actual file name                       |
| `filePath`     | VARCHAR      | Server path                            |
| `fileType`     | VARCHAR      | `pdf`, `jpg`, etc.                     |
| `recordId`     | INT          | Linked record ID                       |
| `tableName`    | VARCHAR      | e.g., `permits`, `orders`, `contacts` |
| `category`     | VARCHAR      | e.g., `Scan`, `Invoice`, `Photo`       |
| `uploadedBy`   | VARCHAR      | Username or ID                         |
| `timestamp`    | DATETIME     | Upload time                            |
| `ipAddress`    | VARCHAR      | For audit                              |

---

## 📌 Integration Points

- **Permits** → store applications, approvals, city responses
- **Orders** → attach customer POs, sign-offs, install photos
- **Contacts** → retain signed agreements, proof of insurance
- **Mobile** → field teams can upload via modal interface
- **One-Line Task** → users can drop file links that auto-link to a task or contact

---

## 🧠 Future Enhancements

- OCR on scanned documents
- Automated tag suggestions via AI
- Client upload portals (dropbox-style link)

---