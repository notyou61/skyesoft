@echo off
set DOC_KEY=%1

if "%DOC_KEY%"=="" (
  echo ❌ Please provide a document key.
  echo Usage: build-proposal.bat lead_or_sell
  exit /b
)

echo 📄 Rendering HTML for %DOC_KEY%...
node render.js %DOC_KEY%

echo 🖨️ Exporting PDF for %DOC_KEY%...
node export.js %DOC_KEY%

echo ✅ All done. Files are in the output folder.
start output\%DOC_KEY%.pdf