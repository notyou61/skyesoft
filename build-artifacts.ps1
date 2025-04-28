$pythonPath = "C:\Users\steve\AppData\Local\Programs\Python\Python313\python.exe"

Write-Host "🏗 Building Skyesoft Proposal..."
node render.js skyesoft_proposal
& $pythonPath generate_pdf.py skyesoft_proposal

Write-Host "`n🏗 Building Skyesoft Use Case (Geo Service)..."
node render.js skyesoft_use_case_geo_service
& $pythonPath generate_pdf.py skyesoft_use_case_geo_service

Write-Host "`n🏗 Building Lead or Sell Proposal..."
node render.js lead_or_sell
& $pythonPath generate_pdf.py lead_or_sell

Write-Host "`n✅ All artifacts built successfully!"
pause
