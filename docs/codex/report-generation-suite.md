# 📑 Report Generation Suite

## 🎯 Purpose  
Provide a standardized framework for creating, validating, and rendering reports across Skyesoft, using CRUD JSON, AI templates, and automation hooks. Ensures consistency in headers, body, and footers while supporting both standardized and custom reports.

## 📌 Key Features  
- Dynamic CRUD JSON generation via Skyebot prompts  
- Validation of required fields against `report_types.json`  
- Conditional auto-fill from APIs (parcel, jurisdiction, etc.)  
- Standardized templates for zoning, sign ordinance, photo survey, etc.  
- Custom/freeform report option for ad-hoc needs  

## ⚙️ Workflow  
1. **Prompt** → Skyebot determines `reportType` via CRUD JSON  
2. **Validation** → Required fields checked from `report_types.json`  
3. **Auto-fill** → Pull missing fields from APIs (parcel, SSE, codex)  
4. **Template Merge** → Apply header, body, footer per `reportType`  
5. **Render** → Output as HTML/PDF with Christy Signs branding  

## 📊 Report Types  
- Zoning Report  
- Sign Ordinance Report  
- Photo Survey Report  
- Custom Report (freeform/jazz cases)  

## 🧠 Smart Behaviors  
- Infers context from prompt to auto-choose reportType  
- Requests clarification if multiple types are possible  
- Normalizes project identifiers (e.g., projectName vs. jobsiteName)  
- Preserves extra user-provided fields without filtering  

## 🔌 Integration Points  
- Core Database (entities, locations, contacts)  
- Permit Management Suite  
- Mobile-First Modals  
- Skyebot AI Engine  

## 🏁 Example Report Request  
```json
{
  "actionType": "Create",
  "actionName": "Report",
  "details": {
    "reportType": "zoning",
    "title": "Zoning Report – Christy Signs HQ",
    "data": {
      "projectName": "Christy Signs HQ",
      "address": "3145 N 33rd Ave, Phoenix, AZ 85017",
      "parcel": "108-03-009E",
      "jurisdiction": "Phoenix"
    }
  }
}
```