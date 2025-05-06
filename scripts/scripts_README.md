# 🛠️ Skyesoft Scripts: Bulletin Board Maintenance

This folder contains helper scripts for managing version control and deployment of the **Office Electronic Bulletin Board** system.

---

## 🔁 `rollback-officeBoard.ps1`

**Purpose:** Instantly revert the project to the last known stable version of the office board layout.

- Resets your `main` branch to the `v2025.05.05-stable-officeBoard` Git tag.
- Useful when an update introduces layout bugs or breakage.
- WARNING: This will discard any uncommitted changes.

**Usage:**
```powershell
.ollback-officeBoard.ps1
```

---

## 🔖 `retag-stable-officeBoard.ps1`

**Purpose:** Update the `v2025.05.05-stable-officeBoard` tag to the latest commit.

- Deletes the previous tag (locally and remotely).
- Replaces it with a new tag pointing to your current HEAD.
- Keeps your rollback target current after a successful update.

**Usage:**
```powershell
.etag-stable-officeBoard.ps1
```

---

## 📌 Tag Convention

Use format:
```
vYYYY.MM.DD-stable-officeBoard
```

Example: `v2025.05.05-stable-officeBoard`

This helps keep your Git history clean and restores reliable.

