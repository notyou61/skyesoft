# 📂 Core Database (Entities, Locations, Contacts, Orders, Applications, Notes)

## 🏷️ Purpose
The Core Database is the foundation of Skyesoft. It holds all operational records — entities, locations, contacts, orders, applications, and internal notes — ensuring everything the business tracks is organized and accessible.

## 🛠️ Technical Details
- Database: MySQL
- Relationships: Normalized (1-to-many) with foreign key integrity
- Tables: tblEntities, tblLocations, tblContacts, tblOrders, tblApplications, tblNotes
- Metadata: CRUD Action Logs (user, GPS, timestamp)
- SSE Streaming: Database changes reflected in real-time frontend via server-sent events
- Global Variables: gblVar mirrored to keep frontend lightning fast

## 🎯 Key Features
- Fast lookups and search
- Redundancy minimized through normalized structure
- Flexible enough to grow with company needs

## 🏗️ Implementation Notes
- Index critical columns for speed
- Auto-log all actions with CRUD ID and GPS
- Treat all deletes as "soft deletes" (set IsNotValid = 1)