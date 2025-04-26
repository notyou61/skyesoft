# 🖼️ File Management (Survey, WIP, Completion Photo Uploads)

## 🏷️ Purpose
Organize project photos (survey, work-in-progress, and completion) efficiently under each order for easy retrieval, reporting, and project tracking.

## 🛠️ Technical Details
- Server Storage: Folder structure per OrderID
- Subfolders: `/survey/`, `/wip/`, `/completion/`
- Upload Modal: Drag/drop or mobile capture
- Filename Convention: OrderID_ReportType_Timestamp.jpg
- File Summaries: Maintain metadata for easy search

## 🎯 Key Features
- Centralized access to all job photos
- File versioning for re-uploads
- Image resizing for storage optimization
- Attach files to work orders and reports

## 🏗️ Implementation Notes
- Security: Restrict file types (jpg, png, pdf)
- Optional S3 or cloud backup integration
- Photo upload notifications