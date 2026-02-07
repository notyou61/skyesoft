param(
    [switch]$Commit,
    [switch]$Deploy,
    [switch]$DryRun
)

# ===============================
# Skyesoft Deploy Script (v2)
# ===============================

Write-Host '=== Skyesoft Deploy ===' -ForegroundColor Cyan

# --- Machine detection ---
$computer = $env:COMPUTERNAME
if ($computer -eq 'Permit-Computer') {
    $machineRole = 'OFFICE'
} else {
    $machineRole = 'LAPTOP'
}

Write-Host "Machine: $machineRole ($computer)"

# --- Git safety checks ---
Write-Host 'Fetching remote state...'
git fetch origin | Out-Null

$status = git status -sb

if ($status -match 'behind') {
    Write-Error 'Repo is BEHIND Git SoT. Pull required. Deploy blocked.'
    exit 1
}

if ($status -match 'ahead .* behind') {
    Write-Error 'Repo has DIVERGED from Git SoT. Manual resolution required.'
    exit 1
}

if ($status -match 'ahead') {
    Write-Host 'Local commits ahead of Git. Pushing...'
    git push origin main
    if ($LASTEXITCODE -ne 0) {
        Write-Error 'Push failed. Deploy aborted.'
        exit 1
    }
    git fetch origin | Out-Null
}

# --- Clean working tree enforcement ---
$dirty = git status --porcelain
if ($dirty -and -not $Commit) {
    Write-Error 'Uncommitted changes detected. Commit required before deploy.'
    exit 1
}

Write-Host 'Git state verified against SoT.'

# --- Commit phase ---
# --- Commit phase ---
if ($Commit) {

    $changed = git status --porcelain
    if ($changed) {

        git add .
        git commit -m "$commitMessage"

        if ($LASTEXITCODE -ne 0) {
            Write-Error 'Git commit failed.'
            exit 1
        }

        # ===============================
        # Version Bump + Update Signal
        # ===============================
        $versionsPath = Join-Path $PSScriptRoot "..\data\authoritative\versions.json"

        $versions = Get-Content $versionsPath | ConvertFrom-Json

        $parts = $versions.system.siteVersion -split '\.'
        $newVersion = "$($parts[0]).$($parts[1]).$([int]$parts[2] + 1)"

        $nowUnix = [int][double]::Parse((Get-Date -UFormat %s))
        $commitHash = git rev-parse --short HEAD

        $versions.system.siteVersion    = $newVersion
        $versions.system.lastUpdateUnix = $nowUnix
        $versions.system.updateOccurred = $true
        $versions.system.commitHash     = $commitHash

        $versions | ConvertTo-Json -Depth 6 | Set-Content $versionsPath

        git add $versionsPath
        git commit --amend --no-edit

        git push origin main
    }
}

# ===============================
# Version Bump + Update Signal
# ===============================

$versionsPath = Join-Path $PSScriptRoot "..\data\authoritative\versions.json"

if (-not (Test-Path $versionsPath)) {
    Write-Error "versions.json not found at $versionsPath"
    exit 1
}

$versions = Get-Content $versionsPath | ConvertFrom-Json

# Parse semantic version x.y.z
$parts = $versions.system.siteVersion -split '\.'
$major = [int]$parts[0]
$minor = [int]$parts[1]
$patch = [int]$parts[2] + 1

$newVersion = "$major.$minor.$patch"
$nowUnix = [int][double]::Parse((Get-Date -UFormat %s))
$commitHash = git rev-parse --short HEAD

# Apply canonical updates
$versions.system.siteVersion    = $newVersion
$versions.system.lastUpdateUnix = $nowUnix
$versions.system.updateOccurred = $true
$versions.system.commitHash     = $commitHash

# Persist
$versions | ConvertTo-Json -Depth 6 | Set-Content $versionsPath

Write-Host "Version updated to v$newVersion (updateOccurred=true)" -ForegroundColor Green


# --- OFFICE confirmation gate ---
if ($machineRole -eq 'OFFICE' -and $Deploy) {
    $confirm = Read-Host 'You are deploying from OFFICE. Continue? (Y/N)'
    if ($confirm -ne 'Y') {
        Write-Host 'Deploy cancelled.'
        exit 0
    }
}

# --- DRY RUN DEFAULT ---
if (-not $Deploy) {
    Write-Host 'DRY RUN - Deploy step skipped' -ForegroundColor Yellow
    Write-Host 'Use: .\deploy.ps1 -Deploy to push to GoDaddy'
    exit 0
}

# --- GoDaddy deploy ---
Write-Host 'Deploying to GoDaddy...' -ForegroundColor Cyan

$script = Join-Path $PSScriptRoot 'deploy-winscp.txt'

# --- WinSCP auto-discovery ---
$possibleWinScpPaths = @(
    "C:\Program Files (x86)\WinSCP\WinSCP.com",
    "C:\Program Files\WinSCP\WinSCP.com",
    "$env:LOCALAPPDATA\Programs\WinSCP\WinSCP.com"
)

$winscp = $possibleWinScpPaths | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $winscp) {
    Write-Error "WinSCP CLI not found. Install WinSCP with command-line support."
    exit 1
}

Write-Host "Using WinSCP CLI: $winscp" -ForegroundColor DarkGray

if (-not (Test-Path $script)) {
    Write-Error "WinSCP script not found at: $script"
    exit 1
}

# --- Execute deploy ---
$localRoot = Get-Location | Select-Object -ExpandProperty Path

Write-Host "Deploying from local root: $localRoot" -ForegroundColor DarkCyan

& "$winscp" `
    /script="$script" `
    /parameter $localRoot

if ($LASTEXITCODE -ne 0) {
    Write-Error 'Deploy failed.'
    exit 1
}

Write-Host 'Deploy completed successfully.' -ForegroundColor Green