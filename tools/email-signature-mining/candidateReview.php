<?php
/**
 * candidateReview.php
 * Skyesoft – Email Signature Mining – Proposal Candidate (PC) Review Tool
 * 
 * Location: /skyesoft/tools/email-signature-mining/candidateReview.php
 */

declare(strict_types=1);

$baseDir     = __DIR__ . '/emailSignatureExtraction/';
$dataFile    = $baseDir . 'elcCandidates.json';
$stateFile   = $baseDir . 'candidateReviewState.json';

if (!file_exists($dataFile)) {
    die("Error: Candidate data file not found at {$dataFile}");
}

// Read raw extraction data
$candidates = json_decode(file_get_contents($dataFile), true) ?? [];

// Load or initialize state store
$states = [];
if (file_exists($stateFile)) {
    $states = json_decode(file_get_contents($stateFile), true) ?? [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Skyesoft – Proposal Candidate Review</title>
<style>
    :root {
        --bg: #f4f6f8; --panel: #ffffff; --border: #ced4da;
        --text: #212529; --muted: #6c757d; --accent: #0d6efd;
        --success: #198754; --warning: #ffc107; --danger: #dc3545;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--bg); color: var(--text); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
    
    /* Header */
    header { background: #1e293b; color: #fff; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; }
    header h1 { font-size: 1.25rem; font-weight: 600; }
    
    /* Layout */
    .app-container { display: flex; flex: 1; overflow: hidden; }
    
    /* Sidebar Queue */
    .sidebar { width: 320px; background: var(--panel); border-right: 1px solid var(--border); display: flex; flex-direction: column; }
    .filter-bar { padding: 12px; border-bottom: 1px solid var(--border); background: #f8f9fa; }
    .filter-bar select { width: 100%; padding: 6px; border-radius: 4px; border: 1px solid var(--border); }
    .candidate-list { flex: 1; overflow-y: auto; list-style: none; }
    .candidate-item { padding: 12px; border-bottom: 1px solid var(--border); cursor: pointer; transition: background 0.15s; }
    .candidate-item:hover { background: #f1f5f9; }
    .candidate-item.active { background: #e2e8f0; border-left: 4px solid var(--accent); }
    .item-title { font-weight: 600; font-size: 0.9rem; }
    .item-sub { font-size: 0.8rem; color: var(--muted); }
    .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; margin-top: 4px; }
    .badge-pending { background: #e2e8f0; color: #475569; }
    .badge-ready { background: #dbeafe; color: #1e40af; }
    .badge-imported { background: #dcfce7; color: #166534; }
    .badge-rejected { background: #fee2e2; color: #991b1b; }

    /* Main Workspace */
    .workspace { flex: 1; display: flex; flex-direction: column; overflow-y: auto; padding: 24px; gap: 24px; }
    .top-nav { display: flex; justify-content: space-between; align-items: center; background: var(--panel); padding: 12px 20px; border: 1px solid var(--border); border-radius: 8px; }
    
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
    .card { background: var(--panel); border: 1px solid var(--border); border-radius: 8px; padding: 20px; display: flex; flex-direction: column; gap: 16px; }
    .card h2 { font-size: 1rem; border-bottom: 1px solid var(--border); padding-bottom: 8px; color: #334155; }
    
    /* Form Layout */
    .form-group { display: flex; flex-direction: column; gap: 4px; }
    .form-group label { font-size: 0.8rem; font-weight: 600; color: var(--muted); }
    .form-group input, .form-group textarea { padding: 8px; border: 1px solid var(--border); border-radius: 4px; font-size: 0.9rem; font-family: inherit; }
    
    /* Pre / Inspection Blocks */
    pre.raw-sig { background: #f8fafc; border: 1px solid var(--border); padding: 12px; border-radius: 4px; font-family: ui-monospace, Consolas, monospace; font-size: 0.85rem; white-space: pre-wrap; word-break: break-word; }
    
    /* Buttons */
    .btn { padding: 8px 16px; border: none; border-radius: 4px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 0.9rem; text-decoration: none; }
    .btn-primary { background: var(--accent); color: #fff; }
    .btn-primary:hover { background: #0b5ed7; }
    .btn-secondary { background: #6c757d; color: #fff; }
    .btn-success { background: var(--success); color: #fff; }
    .btn-danger { background: var(--danger); color: #fff; }
    
    .prompt-box { background: #0f172a; color: #f8fafc; padding: 16px; border-radius: 6px; font-family: ui-monospace, Consolas, monospace; font-size: 0.85rem; white-space: pre-wrap; margin-bottom: 12px; }
    
    .toast { position: fixed; bottom: 20px; right: 20px; background: #10b981; color: white; padding: 12px 24px; border-radius: 6px; font-weight: bold; display: none; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
</style>
</head>
<body>

<header>
    <h1>Skyesoft – Email Signature Mining Review</h1>
    <div id="counter">Candidate 0 of 0</div>
</header>

<div class="app-container">
    <!-- Queue Sidebar -->
    <div class="sidebar">
        <div class="filter-bar">
            <select id="statusFilter" onchange="renderQueue()">
                <option value="ALL">All Candidates</option>
                <option value="pending" selected>Pending Review</option>
                <option value="ready">Ready to Import</option>
                <option value="imported">Imported / Committed</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>
        <ul class="candidate-list" id="candidateList"></ul>
    </div>

    <!-- Main Review Workspace -->
    <div class="workspace">
        <div class="top-nav">
            <div>
                <button class="btn btn-secondary" onclick="navigate(-1)">← Previous</button>
                <button class="btn btn-secondary" onclick="navigate(1)">Next →</button>
            </div>
            <div style="display: flex; gap: 8px;">
                <button class="btn btn-danger" onclick="setStatus('rejected')">Reject</button>
                <button class="btn btn-secondary" onclick="setStatus('pending')">Mark Pending</button>
                <button class="btn btn-success" onclick="setStatus('ready')">Mark Ready</button>
            </div>
        </div>

        <div class="grid">
            <!-- Left Panel: Editable Data -->
            <div class="card">
                <h2>Editable Candidate Record</h2>
                <div class="form-group">
                    <label>Entity (Company):</label>
                    <input type="text" id="field_entity" onchange="updateActiveRecord()">
                </div>
                <div class="form-group">
                    <label>Contact Name:</label>
                    <input type="text" id="field_contact" onchange="updateActiveRecord()">
                </div>
                <div class="form-group">
                    <label>Title:</label>
                    <input type="text" id="field_title" onchange="updateActiveRecord()">
                </div>
                <div class="form-group">
                    <label>Location / Address:</label>
                    <textarea id="field_address" rows="3" onchange="updateActiveRecord()"></textarea>
                </div>
                <div class="form-group">
                    <label>Phone:</label>
                    <input type="text" id="field_phone" onchange="updateActiveRecord()">
                </div>
                <div class="form-group">
                    <label>Email:</label>
                    <input type="text" id="field_email" onchange="updateActiveRecord()">
                </div>
            </div>

            <!-- Right Panel: Inspection & Skyebot Prompt Output -->
            <div class="card">
                <h2>Skyebot Copy Payload</h2>
                <div class="prompt-box" id="promptPreview"></div>
                <button class="btn btn-primary" onclick="copySkyebotPrompt()">
                    📋 Copy Proposal Prompt
                </button>

                <h2 style="margin-top: 12px;">Extraction Provenance</h2>
                <div class="form-group">
                    <label>Sender:</label>
                    <div id="meta_sender" style="font-size:0.85rem; font-weight:bold;"></div>
                </div>
                <div class="form-group">
                    <label>Folder / Subject:</label>
                    <div id="meta_folder_subject" style="font-size:0.8rem; color:var(--muted);"></div>
                </div>
                <div class="form-group">
                    <label>Raw Extracted Signature:</label>
                    <pre class="raw-sig" id="meta_raw_sig"></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="toast" id="toast">Copied to Clipboard!</div>

<script>
// Data Store Initialization
const rawCandidates = <?php echo json_encode($candidates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let states = <?php echo json_encode($states, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

let activeIndex = 0;
let filteredQueue = [];

function getRecord(idx) {
    const raw = rawCandidates[idx];
    if (!raw) return null;
    
    const id = raw.candidateId || `PC-${idx}`;
    const state = states[id] || {};

    return {
        id: id,
        idx: idx,
        status: state.status || 'pending',
        entity: state.entity !== undefined ? state.entity : (raw.entity || ''),
        contact: state.contact !== undefined ? state.contact : (raw.contact || ''),
        title: state.title !== undefined ? state.title : (raw.title || ''),
        address: state.address !== undefined ? state.address : (raw.address || ''),
        phone: state.phone !== undefined ? state.phone : (raw.phone || ''),
        email: state.email !== undefined ? state.email : (raw.email || ''),
        sender: raw.sender_email ? `${raw.sender_name || ''} <${raw.sender_email}>` : '',
        folder: raw.folder_path || '',
        subject: raw.subject || '',
        rawSignature: raw.signature || raw.rawSignature || ''
    };
}

function renderQueue() {
    const filter = document.getElementById('statusFilter').value;
    const listEl = document.getElementById('candidateList');
    listEl.innerHTML = '';
    filteredQueue = [];

    rawCandidates.forEach((_, idx) => {
        const rec = getRecord(idx);
        if (filter === 'ALL' || rec.status === filter) {
            filteredQueue.push(idx);
            
            const li = document.createElement('li');
            li.className = `candidate-item ${idx === activeIndex ? 'active' : ''}`;
            li.onclick = () => selectCandidate(idx);
            li.innerHTML = `
                <div class="item-title">${rec.contact || rec.entity || 'Unknown Candidate'}</div>
                <div class="item-sub">${rec.entity ? rec.entity : rec.email}</div>
                <span class="badge badge-${rec.status}">${rec.status}</span>
            `;
            listEl.appendChild(li);
        }
    });

    if (filteredQueue.length > 0 && !filteredQueue.includes(activeIndex)) {
        selectCandidate(filteredQueue[0]);
    } else {
        updateWorkspace();
    }
}

function selectCandidate(idx) {
    activeIndex = idx;
    renderQueue();
}

function updateWorkspace() {
    const rec = getRecord(activeIndex);
    if (!rec) return;

    document.getElementById('counter').innerText = `Candidate ${rec.idx + 1} of ${rawCandidates.length} (${rec.id})`;

    // Form fields
    document.getElementById('field_entity').value = rec.entity;
    document.getElementById('field_contact').value = rec.contact;
    document.getElementById('field_title').value = rec.title;
    document.getElementById('field_address').value = rec.address;
    document.getElementById('field_phone').value = rec.phone;
    document.getElementById('field_email').value = rec.email;

    // Metadata
    document.getElementById('meta_sender').innerText = rec.sender;
    document.getElementById('meta_folder_subject').innerText = `${rec.folder} | ${rec.subject}`;
    document.getElementById('meta_raw_sig').innerText = rec.rawSignature;

    // Generated Skyebot Prompt
    document.getElementById('promptPreview').innerText = generatePromptText(rec);
}

function generatePromptText(rec) {
    let out = '';
    if (rec.entity)  out += `Entity:\n${rec.entity}\n\n`;
    if (rec.contact) out += `Contact:\n${rec.contact}\n\n`;
    if (rec.title)   out += `Title:\n${rec.title}\n\n`;
    if (rec.address) out += `Address:\n${rec.address}\n\n`;
    if (rec.phone)   out += `Phone:\n${rec.phone}\n\n`;
    if (rec.email)   out += `Email:\n${rec.email}`;
    return out.trim();
}

function updateActiveRecord() {
    const rec = getRecord(activeIndex);
    
    states[rec.id] = states[rec.id] || {};
    states[rec.id].entity  = document.getElementById('field_entity').value;
    states[rec.id].contact = document.getElementById('field_contact').value;
    states[rec.id].title   = document.getElementById('field_title').value;
    states[rec.id].address = document.getElementById('field_address').value;
    states[rec.id].phone   = document.getElementById('field_phone').value;
    states[rec.id].email   = document.getElementById('field_email').value;

    document.getElementById('promptPreview').innerText = generatePromptText(getRecord(activeIndex));
    saveStateRemote(rec.id);
}

function setStatus(status) {
    const rec = getRecord(activeIndex);
    states[rec.id] = states[rec.id] || {};
    states[rec.id].status = status;
    saveStateRemote(rec.id);
    renderQueue();
}

function navigate(direction) {
    const currentQueueIdx = filteredQueue.indexOf(activeIndex);
    if (currentQueueIdx !== -1) {
        const nextIdx = currentQueueIdx + direction;
        if (nextIdx >= 0 && nextIdx < filteredQueue.length) {
            selectCandidate(filteredQueue[nextIdx]);
        }
    }
}

function copySkyebotPrompt() {
    const text = document.getElementById('promptPreview').innerText;
    navigator.clipboard.writeText(text).then(() => {
        const rec = getRecord(activeIndex);
        if (rec.status === 'pending') {
            setStatus('ready');
        }
        showToast();
    });
}

function showToast() {
    const toast = document.getElementById('toast');
    toast.style.display = 'block';
    setTimeout(() => { toast.style.display = 'none'; }, 2000);
}

function saveStateRemote(candidateId) {
    fetch('saveReviewState.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ candidateId: candidateId, state: states[candidateId] })
    });
}

// Initial Run
renderQueue();
</script>
</body>
</html>