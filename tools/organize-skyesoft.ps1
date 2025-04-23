# 🧭 Set base path (adjust if needed)
$basePath = "$PSScriptRoot\docs"

# Ensure base category folders exist
New-Item -Path "$basePath\proposals" -ItemType Directory -Force
New-Item -Path "$basePath\use_cases" -ItemType Directory -Force
New-Item -Path "$basePath\memos" -ItemType Directory -Force

### 📁 PROPOSAL: Lead or Sell
$leadFolder = "$basePath\proposals\lead_or_sell"
New-Item -Path $leadFolder -ItemType Directory -Force

Copy-Item "$basePath\proposal\json\leadership_lead_or_sell_v1.1.json" "$leadFolder\lead_or_sell.json"
Copy-Item "$basePath\proposal\markdown\leadership_lead_or_sell_v1.1.md" "$leadFolder\lead_or_sell.md"
Copy-Item "$basePath\proposal\pdf\leadership_lead_or_sell_v1.1.pdf" "$leadFolder\lead_or_sell.pdf"
Copy-Item "$basePath\proposal\html\leadership_lead_or_sell_v1.1.html" "$leadFolder\lead_or_sell.html"

### 📁 PROPOSAL: Skyesoft Core
$skyesoftFolder = "$basePath\proposals\skyesoft_proposal"
New-Item -Path $skyesoftFolder -ItemType Directory -Force

Copy-Item "$basePath\proposal\json\skyesoft_proposal_final_v1.1.json" "$skyesoftFolder\skyesoft_proposal.json"

### 📁 USE CASE: Skyesoft Scheduling
$ucFolder = "$basePath\use_cases\skyesoft_scheduling"
New-Item -Path $ucFolder -ItemType Directory -Force

# Example file - adjust if needed
# Copy-Item "$basePath\use_cases\json\skyesoft_scheduling_v1.0.json" "$ucFolder\skyesoft_scheduling.json"

### 📁 VIEWER and OTHER TOOLS - untouched
Write-Output "✅ Folders created and files copied successfully. All originals preserved."
