
const API = 'api';

let currentUser = null;

const ROLE_SCREEN = {field_inspector:'inspector', supervisor:'supervisor', field_worker:'fieldworker', administrator:'admin'};
const ROLE_PAGE   = {field_inspector:'inspector.html', supervisor:'supervisor.html', field_worker:'fieldworker.html', administrator:'admin.html'};
// Maps each page's data-page short key -> the DB role required to view it.
const PAGE_ROLE   = {inspector:'field_inspector', supervisor:'supervisor', fieldworker:'field_worker', admin:'administrator'};

async function loadCurrentUser(){
  const res = await fetch('api/auth.php?action=me', { credentials:'include' });
  const data = await res.json();
  return data.success ? data.data.user : null;
}

// Runs once on inspector.html / supervisor.html / fieldworker.html / admin.html.
// Confirms someone is logged in and on the page matching their role
// (redirecting otherwise), then renders that page's initial view.
async function initRolePage(pageKey){
  const requiredRole = PAGE_ROLE[pageKey];
  currentUser = await loadCurrentUser();
  if(!currentUser){ window.location.href = 'login.html'; return; }
  if(currentUser.role !== requiredRole){ window.location.href = ROLE_PAGE[currentUser.role] || 'login.html'; return; }
  refreshScreen(ROLE_SCREEN[requiredRole]);
}
// ════════════════════════════════════════
// AUTH
// ════════════════════════════════════════
async function doLogin(){
  const email = $('#l-email').value.trim();
  const pass  = $('#l-pass').value.trim();
  const portalKey = $('#l-role') ? $('#l-role').value : '';
  const requestedRole = PAGE_ROLE[portalKey] || '';
  if(!email || !pass){ toast('Please enter your email and password','error'); return; }

  const res = await fetch('api/auth.php?action=login', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password: pass, requested_role: requestedRole })
  });
  const data = await res.json();

  if(data.success){
    currentUser = data.data.user;
    window.location.href = ROLE_PAGE[currentUser.role] || 'login.html';
  } else {
    document.getElementById('l-err').textContent = data.message || 'Invalid credentials. Please try again.';
    document.getElementById('l-err').style.display = 'block';
  }
}

async function doSignout(){
  await fetch('api/auth.php?action=logout', { method:'POST', credentials:'include' });
  currentUser = null;
  window.location.href = 'login.html';
}

// ════════════════════════════════════════
// NAVIGATION (between views within a page)
// ════════════════════════════════════════
function switchView(screen,view){
  const s = document.getElementById(screen);
  if(!s) return;

  // Map screen + view to the actual element ID used in HTML
  const prefixMap = {inspector:'insp',supervisor:'sup',fieldworker:'fw',admin:'adm'};
  const viewMap   = {dashboard:'dashboard',reports:'reports',workorders:'workorders',history:'history',users:'users',logs:'logs',tasks:'tasks'};
  const prefix = prefixMap[screen] || screen;
  const vid    = prefix + '-' + (viewMap[view] || view);

  // Switch view
  s.querySelectorAll('.view').forEach(v=>v.classList.remove('active'));
  const el = document.getElementById(vid);
  if(el) el.classList.add('active');

  // Switch nav highlight
  s.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  const navSuffix = {dashboard:'dash',reports:'rep',workorders:'wo',history:'hist',users:'users',logs:'logs',tasks:'tasks'};
  const navEl = document.getElementById(prefix+'-nav-'+(navSuffix[view]||view));
  if(navEl) navEl.classList.add('active');

  closeSidebar();
  refreshView(screen,view);
}

function refreshScreen(screenId){
  if(screenId==='inspector'){ refreshInspector(); }
  else if(screenId==='supervisor'){ refreshSupervisor(); }
  else if(screenId==='fieldworker'){ refreshFieldWorker(); }
  else if(screenId==='admin'){ refreshAdmin(); }
}

function refreshView(screen,view){
  if(screen==='inspector'){
    if(view==='dashboard') renderInspDash();
    if(view==='reports') renderInspReports();
  } else if(screen==='supervisor'){
    if(view==='dashboard') renderSupDash();
    if(view==='reports') renderSupReports('all');
    if(view==='workorders') renderSupWO();
    if(view==='tasks') renderSupTasks();
  } else if(screen==='fieldworker'){
    if(view==='dashboard') renderFWQueue();
    if(view==='history') renderFWHistory();
  } else if(screen==='admin'){
    if(view==='dashboard') renderAdmDash();
    if(view==='users') renderAdmUsers();
    if(view==='logs') renderAdmLogs();
  }
}

// ════════════════════════════════════════
// INSPECTOR
// ════════════════════════════════════════
function refreshInspector(){
  if(currentUser){
    document.getElementById('insp-uname').textContent = currentUser.name;
    document.getElementById('insp-topname').textContent = currentUser.name;
    const av = currentUser.name[0];
    document.getElementById('insp-av').textContent = av;
    document.getElementById('insp-topav').textContent = av;
  }
  renderInspDash();
}

async function myReports(){
  const res = await fetch('api/reports.php?action=list', { credentials:'include' });
  const data = await res.json();
  return data.success ? data.data.reports : [];
}
async function renderInspDash(){
  const reports = await myReports();
  const today = new Date().toDateString();
  const todayR = reports.filter(r=>new Date(r.submitted_at).toDateString()===today);
  const crit   = reports.filter(r=>r.severity==='critical');
  const pend   = reports.filter(r=>r.status==='pending');
  const res    = reports.filter(r=>r.status==='completed');
  setText('insp-stat-today', todayR.length);
  setText('insp-stat-crit',  crit.length);
  setText('insp-stat-pend',  pend.length);
  setText('insp-stat-res',   res.length);
  renderReportTable('insp-dash-table','insp-dash-cards', reports.slice(0,10), 'inspector');
  renderInspTaskList();
}

async function renderInspTaskList(){
  const el = document.getElementById('insp-task-list');
  if(!el) return; // safety check in case HTML hasn't been updated yet

  let tasks = [];
  try{
    const res  = await fetch('api/tasks.php?action=list&status=assigned', { credentials:'include' });
    const data = await res.json();
    tasks = data.success ? data.data.tasks : [];
  }catch(err){
    el.innerHTML = '<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg><p>Couldn\'t load your tasks right now. Please try again.</p></div>';
    return;
  }

  if(!tasks.length){
    el.innerHTML = '<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg><p>No inspection tasks assigned to you right now.</p></div>';
    return;
  }
  el.innerHTML = tasks.map(t=>`
    <div class="queue-card">
      <div class="queue-card-top"><span class="badge badge-pending">Assigned</span><span style="font-size:12px;color:var(--muted)">${t.task_code}</span></div>
      <h4>${t.title.toUpperCase()}</h4>
      <p>${t.description || 'No additional instructions provided.'}</p>
      <div class="queue-card-foot">
        <span class="report-card-loc" style="font-size:12px;color:var(--muted)">📍 ${t.location_text}</span>
        ${t.due_date?`<span style="font-size:11.5px;color:var(--muted)">📅 Due ${fmtDate(t.due_date)}</span>`:''}
        <button class="btn btn-amber btn-sm" onclick="openSubmitFromTask(${t.task_id})">Perform Inspection ›</button>
      </div>
    </div>`).join('');
}

function openSubmitFromTask(taskId){
  boundTaskId = taskId;
  document.getElementById('s-title').value = '';
  document.getElementById('s-desc').value  = '';
  document.getElementById('s-loc').value   = '';
  document.getElementById('s-type').value  = '';
  document.getElementById('s-sev').value   = '';
  document.getElementById('photo-preview').innerHTML = '';
  photoDataUrls = [];
  openModal('modal-submit');
}

async function renderInspReports(sev='all'){
  const reports = await myReports();
  const filtered = sev==='all'?reports:reports.filter(r=>r.severity===sev);
  renderReportTable('insp-rep-table','insp-rep-cards', filtered, 'inspector');
}

function filterReports(btn, sev){
  document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  renderInspReports(sev);
}

// ════════════════════════════════════════
// SUPERVISOR
// ════════════════════════════════════════
function refreshSupervisor(){
  if(currentUser){
    document.getElementById('sup-uname').textContent = currentUser.name;
    document.getElementById('sup-topname').textContent = currentUser.name;
    const av = currentUser.name[0];
    document.getElementById('sup-av').textContent = av;
    document.getElementById('sup-topav').textContent = av;
  }
  renderSupDash();
}

async function renderSupDash(){
  const [repRes, woRes] = await Promise.all([
    fetch('api/reports.php?action=list', { credentials:'include' }).then(r=>r.json()),
    fetch('api/workorders.php?action=list', { credentials:'include' }).then(r=>r.json())
  ]);
  const reports = repRes.success ? repRes.data.reports : [];
  const wos     = woRes.success  ? woRes.data.work_orders : [];

  setText('sup-stat-total', reports.length);
  setText('sup-stat-pend',  reports.filter(r=>r.status==='pending').length);
  setText('sup-stat-wo',    wos.filter(w=>w.status!=='completed').length);
  setText('sup-stat-crit',  reports.filter(r=>r.severity==='high'||r.severity==='critical').length);
  setText('sup-wo-assigned',reports.filter(r=>r.status==='assigned').length);
  setText('sup-wo-inprog',  wos.filter(w=>w.status==='in_progress').length);
  setText('sup-wo-done',    wos.filter(w=>w.status==='completed').length);

  const pending = reports.filter(r=>r.status==='pending');
  const el = document.getElementById('sup-pending-list');
  if(!pending.length){
    el.innerHTML='<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg><p>No pending reports to review</p></div>';
    return;
  }
  el.innerHTML = pending.slice(0,5).reverse().map(r=>`
    <div class="report-card">
      <div class="report-card-top">
        <div class="report-card-meta"><span class="badge badge-${r.severity}">${cap(r.severity)}</span><span style="font-size:12px;color:var(--muted)">${r.report_code}</span></div>
        <span class="report-time">${timeAgo(r.submitted_at)}</span>
      </div>
      <h4>${r.title.toUpperCase()}</h4>
      <p>${r.description}</p>
      <div class="report-card-footer">
        <span class="report-card-loc"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>${r.location_text}</span>
        <button class="btn-assign" onclick="openAssign('${r.report_id}')">＋ Assign</button>
      </div>
    </div>`).join('');
}
async function renderSupReports(filter='all'){
  const res  = await fetch('api/reports.php?action=list', { credentials:'include' });
  const data = await res.json();
  const reports = data.success ? data.data.reports : [];
  const filtered = filter==='all' ? reports : reports.filter(r=>r.status===filter);

  const tbody = document.getElementById('sup-rep-table');
  const cards = document.getElementById('sup-rep-cards');
  if(!filtered.length){
    tbody.innerHTML=`<tr><td colspan="7"><div class="empty-state"><p>No reports found</p></div></td></tr>`;
    cards.innerHTML=`<div class="empty-state"><p>No reports found</p></div>`;
    return;
  }
  tbody.innerHTML = filtered.map(r=>`<tr>
    <td class="id">${r.report_code}</td>
    <td class="issue">${r.title}</td>
    <td>${r.submitted_by_name}</td>
    <td><span class="badge badge-${r.severity}">${cap(r.severity)}</span></td>
    <td>${fmtDate(r.submitted_at)}</td>
    <td>${statusBadge(r.status)}</td>
    <td><button class="action-link" onclick="openReportDetail('${r.report_id}')">View</button>
    ${r.status==='pending'?`<button class="action-link" style="margin-left:8px" onclick="openAssign('${r.report_id}')">Assign</button>`:''}</td>
  </tr>`).join('');
  cards.innerHTML = filtered.map(r=>`
    <div class="m-card">
      <div class="m-card-top"><div><div class="m-card-title">${r.title}</div><div class="m-card-id">${r.report_code} • ${r.submitted_by_name}</div></div><span class="badge badge-${r.severity}">${cap(r.severity)}</span></div>
      <div class="m-card-loc">📍 ${r.location_text}</div>
      <div class="m-card-footer">${statusBadge(r.status)}<div style="display:flex;gap:8px">
        <button class="action-link" onclick="openReportDetail('${r.report_id}')">View</button>
        ${r.status==='pending'?`<button class="btn-assign btn-sm" onclick="openAssign('${r.report_id}')">Assign</button>`:''}
      </div></div>
    </div>`).join('');
}

function filterSupervisorReports(btn,filter){
  document.querySelectorAll('.filter-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  renderSupReports(filter);
}

async function renderSupWO(){
  const res  = await fetch('api/workorders.php?action=list', { credentials:'include' });
  const data = await res.json();
  const wos = data.success ? data.data.work_orders : [];

  const tbody = document.getElementById('sup-wo-table');
  const cards = document.getElementById('sup-wo-cards');
  if(!wos.length){
    tbody.innerHTML=`<tr><td colspan="7"><div class="empty-state"><p>No work orders yet</p></div></td></tr>`;
    cards.innerHTML='';return;
  }
  tbody.innerHTML = wos.map(w=>`<tr>
    <td class="id">${w.wo_code}</td>
    <td class="issue">${w.report_title}</td>
    <td>${w.assigned_to_name}</td>
    <td><span class="badge badge-${w.severity}">${cap(w.severity)}</span></td>
    <td>${fmtDate(w.created_at)}</td>
    <td>${woStatusBadge(w.status)}</td>
    <td><button class="action-link" onclick="openWODetail('${w.wo_id}')">View</button></td>
  </tr>`).join('');
  cards.innerHTML = wos.map(w=>`
    <div class="m-card">
      <div class="m-card-top"><div><div class="m-card-title">${w.report_title}</div><div class="m-card-id">${w.wo_code} → ${w.assigned_to_name}</div></div><span class="badge badge-${w.severity}">${cap(w.severity)}</span></div>
      <div class="m-card-loc">📍 ${w.location_text}</div>
      <div class="m-card-footer">${woStatusBadge(w.status)}<button class="action-link" onclick="openWODetail('${w.wo_id}')">View</button></div>
    </div>`).join('');
}

async function renderSupTasks(){
  const res  = await fetch('api/tasks.php?action=list', { credentials:'include' });
  const data = await res.json();
  const tasks = data.success ? data.data.tasks : [];

  const statusBadgeTask = s => ({
    assigned:  '<span class="badge badge-pending">Assigned</span>',
    submitted: '<span class="badge badge-amber">Awaiting Review</span>',
    closed:    '<span class="badge badge-green">Closed</span>'
  }[s] || s);

  const tbody = document.getElementById('sup-tasks-table');
  const cards = document.getElementById('sup-tasks-cards');
  if(!tasks.length){
    tbody.innerHTML=`<tr><td colspan="7"><div class="empty-state"><p>No inspection tasks created yet</p></div></td></tr>`;
    cards.innerHTML=`<div class="empty-state"><p>No inspection tasks created yet</p></div>`;
    return;
  }
  tbody.innerHTML = tasks.map(t=>`<tr>
    <td class="id">${t.task_code}</td>
    <td class="issue">${t.title}</td>
    <td>${t.assigned_to_name}</td>
    <td>${t.location_text}</td>
    <td>${statusBadgeTask(t.status)}</td>
    <td>${t.due_date ? fmtDate(t.due_date) : '—'}</td>
    <td>${t.status==='submitted'?`<button class="action-link" onclick="closeTask(${t.task_id})">Close Task</button>`:''}
    ${t.report_id?`<button class="action-link" style="margin-left:8px" onclick="openReportDetail('${t.report_id}')">View Report</button>`:''}</td>
  </tr>`).join('');
  cards.innerHTML = tasks.map(t=>`
    <div class="m-card">
      <div class="m-card-top"><div><div class="m-card-title">${t.title}</div><div class="m-card-id">${t.task_code} → ${t.assigned_to_name}</div></div>${statusBadgeTask(t.status)}</div>
      <div class="m-card-loc">📍 ${t.location_text}</div>
      <div class="m-card-footer">${t.due_date?`Due ${fmtDate(t.due_date)}`:''}<div style="display:flex;gap:8px">
        ${t.status==='submitted'?`<button class="action-link" onclick="closeTask(${t.task_id})">Close</button>`:''}
        ${t.report_id?`<button class="action-link" onclick="openReportDetail('${t.report_id}')">View Report</button>`:''}
      </div></div>
    </div>`).join('');
}

async function openCreateTask(){
  document.getElementById('ct-title').value = '';
  document.getElementById('ct-desc').value = '';
  document.getElementById('ct-loc').value = '';
  document.getElementById('ct-due').value = '';

  const res  = await fetch('api/users.php?action=list&role=field_inspector', { credentials:'include' });
  const data = await res.json();
  const inspectors = data.success ? data.data.users : [];
  const sel = document.getElementById('ct-inspector');
  sel.innerHTML = '<option value="">Select field inspector...</option>' +
    inspectors.map(u=>`<option value="${u.user_id}">${u.name}</option>`).join('');

  openModal('modal-create-task');
}

async function createTask(){
  const assignedTo = document.getElementById('ct-inspector').value;
  const title       = document.getElementById('ct-title').value.trim();
  const desc        = document.getElementById('ct-desc').value.trim();
  const loc         = document.getElementById('ct-loc').value.trim();
  const due         = document.getElementById('ct-due').value;
  if(!assignedTo || !title || !loc){ toast('Please select an inspector and fill all required fields','error'); return; }

  const res = await fetch('api/tasks.php?action=create', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ assigned_to: assignedTo, title, description: desc, location_text: loc, due_date: due })
  });
  const data = await res.json();

  if(data.success){
    closeModal('modal-create-task');
    toast(`Task ${data.data.task_code} created and assigned`, 'success');
    renderSupTasks();
  } else {
    toast(data.message || 'Failed to create task', 'error');
  }
}

async function closeTask(taskId){
  if(!confirm('Close this task? This confirms you have reviewed the submitted report.')) return;
  const res = await fetch('api/tasks.php?action=close', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ task_id: taskId })
  });
  const data = await res.json();
  if(data.success){
    toast('Task closed', 'success');
    renderSupTasks();
  } else {
    toast(data.message || 'Failed to close task', 'error');
  }
}

// ════════════════════════════════════════
// FIELD WORKER
// ════════════════════════════════════════
function refreshFieldWorker(){
  if(currentUser){
    document.getElementById('fw-uname').textContent = currentUser.name;
    document.getElementById('fw-topname').textContent = currentUser.name;
    const av = currentUser.name[0];
    document.getElementById('fw-av').textContent = av;
    document.getElementById('fw-topav').textContent = av;
  }
  renderFWQueue();
}

async function myWorkOrders(){
  const res = await fetch('api/workorders.php?action=list', { credentials:'include' });
  const data = await res.json();
  return data.success ? data.data.work_orders : [];
}

async function renderFWQueue(){
  const allMine = await myWorkOrders();
  const wos = allMine.filter(w=>w.status!=='completed');
  const today = new Date().toDateString();
  setText('fw-stat-active', wos.filter(w=>w.status==='pending').length);
  setText('fw-stat-prog',   wos.filter(w=>w.status==='in_progress').length);
  setText('fw-stat-done',   allMine.filter(w=>w.status==='completed'&&new Date(w.updated_at).toDateString()===today).length);
  setText('fw-stat-total',  allMine.length);

  const el = document.getElementById('fw-queue-list');
  if(!wos.length){
    el.innerHTML='<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg><p>No active tasks assigned to you</p></div>';
    return;
  }
  el.innerHTML = wos.map(w=>`
    <div class="queue-card ${w.status==='completed'?'done':''}">
      <div class="queue-card-top">${woStatusBadge(w.status)}<span style="font-size:12px;color:var(--muted)">${w.wo_code}</span></div>
      <h4>${w.report_title.toUpperCase()}</h4>
      <p>${w.instructions||'No special instructions provided.'}</p>
      <div class="queue-card-foot">
        <span class="report-card-loc" style="font-size:12px;color:var(--muted)">📍 ${w.location_text}</span>
        <span style="font-size:11.5px;color:var(--muted)">⏱ ${fmtDate(w.created_at)}</span>
        <button class="btn btn-amber btn-sm" onclick="openUpdateTask('${w.wo_id}')">Process Task ›</button>
      </div>
      ${w.latest_remarks?`<div style="margin-top:10px;padding:8px 10px;background:rgba(255,255,255,.03);border-radius:6px;font-size:12px;color:var(--muted)">Last update: ${w.latest_remarks}</div>`:''}
    </div>`).join('');
}

async function renderFWHistory(){
  const allMine = await myWorkOrders();
  const wos = allMine.filter(w=>w.status==='completed');
  const tbody = document.getElementById('fw-hist-table');
  const cards = document.getElementById('fw-hist-cards');
  if(!wos.length){
    tbody.innerHTML=`<tr><td colspan="6"><div class="empty-state"><p>No completed tasks yet</p></div></td></tr>`;
    cards.innerHTML='';return;
  }
  tbody.innerHTML = wos.map(w=>`<tr>
    <td class="id">${w.wo_code}</td><td class="issue">${w.report_title}</td><td>${w.location_text}</td>
    <td><span class="badge badge-${w.severity}">${cap(w.severity)}</span></td>
    <td>${fmtDate(w.updated_at)}</td>
    <td>${woStatusBadge(w.status)}</td>
  </tr>`).join('');
  cards.innerHTML = wos.map(w=>`
    <div class="m-card">
      <div class="m-card-top"><div><div class="m-card-title">${w.report_title}</div><div class="m-card-id">${w.wo_code}</div></div><span class="badge badge-${w.severity}">${cap(w.severity)}</span></div>
      <div class="m-card-loc">📍 ${w.location_text} • ${fmtDate(w.updated_at)}</div>
      <div class="m-card-footer">${woStatusBadge(w.status)}</div>
    </div>`).join('');
}

// ════════════════════════════════════════
// ADMIN
// ════════════════════════════════════════
function refreshAdmin(){
  if(currentUser){
    document.getElementById('adm-uname').textContent = currentUser.name;
    document.getElementById('adm-topname').textContent = currentUser.name;
    const av = currentUser.name[0];
    document.getElementById('adm-av').textContent = av;
    document.getElementById('adm-topav').textContent = av;
  }
  renderAdmDash();
}

async function renderAdmDash(){
  const [repRes, woRes, userRes] = await Promise.all([
    fetch('api/reports.php?action=list', { credentials:'include' }).then(r=>r.json()),
    fetch('api/workorders.php?action=list', { credentials:'include' }).then(r=>r.json()),
    fetch('api/users.php?action=list', { credentials:'include' }).then(r=>r.json())
  ]);
  const reports = repRes.success  ? repRes.data.reports      : [];
  const wos     = woRes.success   ? woRes.data.work_orders   : [];
  const users   = userRes.success ? userRes.data.users       : [];

  setText('adm-stat-users',   users.filter(u=>u.is_active==1).length);
  setText('adm-stat-reports', reports.length);
  setText('adm-stat-wo',      wos.length);
  renderAdmEvents();
}

async function renderAdmEvents(){
  const res  = await fetch('api/logs.php?action=list&limit=10', { credentials:'include' });
  const data = await res.json();
  const logs = data.success ? data.data.logs : [];

  const el = document.getElementById('adm-events-list');
  if(!logs.length){ el.innerHTML='<div class="empty-state"><p>No activity yet</p></div>'; return; }
  el.innerHTML = logs.map(l=>`
    <div class="event-row">
      <div><div class="event-name">${l.action}</div><div class="event-by">by ${l.user_name || 'System'}</div></div>
      <div class="event-time">${fmtDate(l.logged_at)}</div>
    </div>`).join('');
}

let _admUsersCache = [];

async function renderAdmUsers(q=''){
  const res  = await fetch('api/users.php?action=list', { credentials:'include' });
  const data = await res.json();
  const users = data.success ? data.data.users : [];
  _admUsersCache = users; // cache for deactivateUser lookups

  const filtered = q ? users.filter(u=>u.name.toLowerCase().includes(q)||u.email.toLowerCase().includes(q)) : users;
  const roleChip  = {field_inspector:'chip-insp',supervisor:'chip-sup',field_worker:'chip-worker',administrator:'chip-admin'};
  const roleLabel = {field_inspector:'Inspector',supervisor:'Supervisor',field_worker:'Field Worker',administrator:'Admin'};

  document.getElementById('adm-user-table').innerHTML = filtered.map(u=>`<tr>
    <td><div style="display:flex;align-items:center;gap:10px">
      <div class="avatar ${u.role==='administrator'?'av-red':u.role==='supervisor'?'av-blue':u.role==='field_worker'?'av-green':'av-amber'}" style="width:28px;height:28px;font-size:11px">${u.name[0]}</div>
      <div><div style="font-weight:600;color:var(--text);font-size:13px">${u.name}</div><div style="font-size:11px;color:var(--muted)">${u.email}</div></div>
    </div></td>
    <td><span class="chip ${roleChip[u.role]}">${roleLabel[u.role]}</span></td>
    <td><span style="display:flex;align-items:center;gap:6px"><span class="dot-active"></span><span style="font-size:12.5px;color:var(--green)">${u.is_active==1?'Active':'Inactive'}</span></span></td>
    <td><button class="action-link" onclick="deactivateUser(${u.user_id})">Deactivate</button></td>
  </tr>`).join('');
}

async function renderAdmLogs(){
  const res  = await fetch('api/logs.php?action=list&limit=200', { credentials:'include' });
  const data = await res.json();
  const logs = data.success ? data.data.logs : [];

  const el = document.getElementById('adm-logs-list');
  if(!logs.length){ el.innerHTML='<div class="empty-state"><p>No logs yet</p></div>'; return; }
  el.innerHTML = logs.map(l=>`
    <div class="event-row">
      <div><div class="event-name">${l.action}</div><div class="event-by">by ${l.user_name || 'System'}</div></div>
      <div class="event-time">${fmtDate(l.logged_at)}</div>
    </div>`).join('');
}

function searchUsers(q){ renderAdmUsers(q.toLowerCase()); }

async function clearLogs(){
  if(!confirm('Clear all audit logs?')) return;
  const res  = await fetch('api/logs.php?action=clear', { method:'POST', credentials:'include' });
  const data = await res.json();
  if(data.success){
    renderAdmLogs();
    renderAdmEvents();
    toast('Logs cleared','info');
  } else {
    toast(data.message || 'Failed to clear logs', 'error');
  }
}

async function deactivateUser(userId){
  const u = _admUsersCache.find(u=>u.user_id===userId);
  if(!u || !confirm(`Deactivate ${u.name}?`)) return;

  const res = await fetch('api/users.php?action=edit', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ user_id: u.user_id, name: u.name, email: u.email, role: u.role, is_active: 0 })
  });
  const data = await res.json();

  if(data.success){
    toast(`${u.name} deactivated`, 'info');
    renderAdmUsers();
  } else {
    toast(data.message || 'Failed to deactivate user', 'error');
  }
}

async function createUser(){
  const name  = $('#cu-name').value.trim();
  const email = $('#cu-email').value.trim();
  const role  = $('#cu-role').value;
  const pass  = $('#cu-pass').value.trim();
  if(!name||!email||!pass){ toast('Please fill all required fields','error'); return; }
  if(pass.length < 8){ toast('Password must be at least 8 characters','error'); return; }

  const res = await fetch('api/users.php?action=create', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, email, password: pass, role })
  });
  const data = await res.json();

  if(data.success){
    closeModal('modal-create-user');
    toast(`User ${name} created successfully`,'success');
    renderAdmUsers();
    renderAdmDash();
    ['cu-name','cu-email','cu-pass'].forEach(id=>{document.getElementById(id).value=''});
  } else {
    toast(data.message || 'Failed to create user', 'error');
  }
}

function exportRecords(){
  window.open('api/logs.php?action=export', '_blank');
  toast('Preparing export…','info');
}

// ════════════════════════════════════════
// REPORT SUBMISSION
// ════════════════════════════════════════
let photoDataUrls = [];

// selectedReportPhotos holds { file, dataUrl } for every photo currently
// staged for this submission. New picks are added on top, not replaced.
window.selectedReportPhotos = window.selectedReportPhotos || [];

function handlePhotos(input){
  const files = [...input.files];
  if(!files.length) return;

  files.forEach(file => {
    const reader = new FileReader();
    reader.onload = e => {
      window.selectedReportPhotos.push({ file, dataUrl: e.target.result });
      renderReportPhotoPreview();
    };
    reader.readAsDataURL(file);
  });

  input.value = ''; // reset so picking the same file again still fires onchange
}

function renderReportPhotoPreview(){
  const prev = document.getElementById('photo-preview');
  prev.innerHTML = window.selectedReportPhotos.map((p, i) => `
    <div class="photo-thumb-wrap">
      <img src="${p.dataUrl}">
      <button type="button" onclick="removeReportPhoto(${i})" title="Remove photo">×</button>
    </div>`).join('');
}

function removeReportPhoto(index){
  window.selectedReportPhotos.splice(index, 1);
  renderReportPhotoPreview();
}

window.selectedTaskPhotos = window.selectedTaskPhotos || [];

function handlePhotosTask(input){
  const files = [...input.files];
  if(!files.length) return;

  files.forEach(file => {
    const reader = new FileReader();
    reader.onload = e => {
      window.selectedTaskPhotos.push({ file, dataUrl: e.target.result });
      renderTaskPhotoPreview();
    };
    reader.readAsDataURL(file);
  });

  input.value = '';
}

function renderTaskPhotoPreview(){
  const prev = document.getElementById('task-photo-preview');
  prev.innerHTML = window.selectedTaskPhotos.map((p, i) => `
    <div class="photo-thumb-wrap">
      <img src="${p.dataUrl}">
      <button type="button" onclick="removeTaskPhoto(${i})" title="Remove photo">×</button>
    </div>`).join('');
}

function removeTaskPhoto(index){
  window.selectedTaskPhotos.splice(index, 1);
  renderTaskPhotoPreview();
}

function detectGPS(){
  const st = document.getElementById('s-gps-status');
  st.textContent='Detecting location...';
  if(!navigator.geolocation){ st.textContent='GPS not supported on this device.'; return; }
  navigator.geolocation.getCurrentPosition(
    pos=>{ document.getElementById('s-loc').value=`${pos.coords.latitude.toFixed(5)}, ${pos.coords.longitude.toFixed(5)}`; st.textContent='✓ Location detected'; },
    ()=>{ st.textContent='GPS unavailable. Please enter location manually.'; }
  );
}

let boundTaskId = null;

async function submitReport(){
  if(!boundTaskId){ toast('No inspection task selected. Please open this form from an assigned task.','error'); return; }

  const title = $('#s-title').value.trim();
  const type  = $('#s-type').value;
  const desc  = $('#s-desc').value.trim();
  const loc   = $('#s-loc').value.trim();
  const sev   = $('#s-sev').value;
  if(!title||!type||!desc||!loc||!sev){ toast('Please fill all required fields','error'); return; }

  const fd = new FormData();
  fd.append('task_id',       boundTaskId);
  fd.append('title',         title);
  fd.append('issue_type',    type);
  fd.append('description',   desc);
  fd.append('location_text', loc);
  fd.append('severity',      sev);

  // attach the first selected photo, if any (backend only accepts one)
  window.selectedReportPhotos.forEach(p => fd.append('photos[]', p.file));

  const res = await fetch('api/reports.php?action=submit', {
    method: 'POST',
    credentials: 'include',
    body: fd
  });
  const data = await res.json();

  if(data.success){
    closeModal('modal-submit'); // also clears staged photos, see closeModal() below
    toast(`Report ${data.data.report_code} submitted successfully`, 'success');
    boundTaskId = null;
    renderInspDash();
  } else {
    toast(data.message || 'Failed to submit report', 'error');
  }
}

// ════════════════════════════════════════
// WORK ORDER ASSIGNMENT
// ════════════════════════════════════════
async function openAssign(reportId){
  const res  = await fetch(`api/reports.php?action=detail&id=${reportId}`, { credentials:'include' });
  const data = await res.json();
  if(!data.success){ toast('Could not load report details','error'); return; }
  const r = data.data.report;

  document.getElementById('a-report-id').value = reportId;
  document.getElementById('a-report-title').value = r.title;
  document.getElementById('a-loc').value = r.location_text;
  document.getElementById('a-sev').value = cap(r.severity);
  document.getElementById('a-sev-override').value = '';
  document.getElementById('a-instructions').value = '';
  document.getElementById('a-deadline').value = '';

  const wRes = await fetch('api/users.php?action=list&role=field_worker', { credentials:'include' });
  const wData = await wRes.json();
  const workers = wData.success ? wData.data.users : [];
  const sel = document.getElementById('a-worker');
  sel.innerHTML = '<option value="">Select field worker...</option>' +
    workers.map(u=>`<option value="${u.user_id}">${u.name}</option>`).join('');

  openModal('modal-assign');
}

async function assignWorkOrder(){
  const reportId     = document.getElementById('a-report-id').value;
  const worker       = document.getElementById('a-worker').value;
  const sevOverride  = document.getElementById('a-sev-override').value;
  const instructions = document.getElementById('a-instructions').value.trim();
  const deadline     = document.getElementById('a-deadline').value;
  if(!worker){ toast('Please select a field worker','error'); return; }

  const res = await fetch('api/workorders.php?action=create', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      report_id:    reportId,
      assigned_to:  worker,
      severity:     sevOverride || document.getElementById('a-sev').value.toLowerCase(),
      instructions: instructions,
      deadline:     deadline
    })
  });
  const data = await res.json();

  if(data.success){
    closeModal('modal-assign');
    toast(`Work order ${data.data.wo_code} assigned successfully`, 'success');
    renderSupDash();
  } else {
    toast(data.message || 'Failed to assign work order', 'error');
  }
}

// ════════════════════════════════════════
// TASK STATUS UPDATE
// ════════════════════════════════════════
async function openUpdateTask(woId){
  const res  = await fetch(`api/workorders.php?action=detail&id=${woId}`, { credentials:'include' });
  const data = await res.json();
  if(!data.success){ toast('Could not load task details','error'); return; }
  const w = data.data.work_order;

  document.getElementById('ut-wo-id').value = woId;
  document.getElementById('ut-title').value = w.report_title;
  document.getElementById('ut-cur-status').value = cap(w.status.replace('_',' '));
  document.getElementById('ut-status').value = 'in_progress';
  document.getElementById('ut-remarks').value = '';
  taskPhotoUrls=[];
  document.getElementById('task-photo-preview').innerHTML='';
  openModal('modal-update-task');
}

async function updateTaskStatus(){
  const woId   = document.getElementById('ut-wo-id').value;
  const status = document.getElementById('ut-status').value;
  const remarks= document.getElementById('ut-remarks').value.trim();
  if(!remarks){ toast('Please add an activity report / remarks','error'); return; }

  const fd = new FormData();
  fd.append('wo_id',   woId);
  fd.append('status',  status);
  fd.append('remarks', remarks);
  window.selectedTaskPhotos.forEach(p => fd.append('photos[]', p.file));

  const res = await fetch('api/workorders.php?action=update_status', {
    method: 'POST',
    credentials: 'include',
    body: fd
  });
  const data = await res.json();

  if(data.success){
    closeModal('modal-update-task'); // also clears staged photos, see closeModal()
    toast(`Task updated to ${cap(status)}`, 'success');
    renderFWQueue();
  } else {
    toast(data.message || 'Failed to update task', 'error');
  }
}

// ════════════════════════════════════════
// REPORT / WO DETAIL MODALS
// ════════════════════════════════════════
async function openReportDetail(reportId){
  const res  = await fetch(`api/reports.php?action=detail&id=${reportId}`, { credentials:'include' });
  const data = await res.json();
  if(!data.success){ toast('Could not load report details','error'); return; }
  const r = data.data.report;
  const photos = data.data.photos || [];
  window.currentDetailPhotos = photos.map(p => p.file_path);

  document.getElementById('rd-title').textContent = r.title;
  document.getElementById('rd-id').textContent    = r.report_code;
  document.getElementById('rd-body').innerHTML = `
    ${photos.length?`<div class="photo-preview" style="margin-bottom:16px">${photos.map((p,i)=>`<img src="${p.file_path}" class="photo-view-thumb" onclick="openLightbox(window.currentDetailPhotos, ${i})">`).join('')}</div>`:''}
    <div class="detail-row"><span class="detail-label">Type</span><span class="detail-value">${r.issue_type}</span></div>
    <div class="detail-row"><span class="detail-label">Location</span><span class="detail-value">${r.location_text}</span></div>
    <div class="detail-row"><span class="detail-label">Severity</span><span class="detail-value"><span class="badge badge-${r.severity}">${cap(r.severity)}</span></span></div>
    <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value">${statusBadge(r.status)}</span></div>
    <div class="detail-row"><span class="detail-label">Inspector</span><span class="detail-value">${r.submitted_by_name}</span></div>
    <div class="detail-row"><span class="detail-label">Submitted</span><span class="detail-value">${fmtDate(r.submitted_at)}</span></div>
    <div style="margin-top:14px"><div style="font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:600">Description</div><div style="font-size:13px;color:var(--label);line-height:1.6">${r.description}</div></div>`;
  openModal('modal-report-detail');
}

async function openWODetail(woId){
  const res  = await fetch(`api/workorders.php?action=detail&id=${woId}`, { credentials:'include' });
  const data = await res.json();
  if(!data.success){ toast('Could not load work order details','error'); return; }
  const w = data.data.work_order;
  const updates = data.data.updates || [];
  const lastUpdate = updates[0]; // API returns newest first
  const photoPaths = lastUpdate
    ? (lastUpdate.photos && lastUpdate.photos.length
        ? lastUpdate.photos.map(p => p.file_path)
        : (lastUpdate.photo_path ? [lastUpdate.photo_path] : []))
    : [];

  document.getElementById('rd-title').textContent = 'Work Order: '+w.wo_code;
  document.getElementById('rd-id').textContent    = w.report_title;
  document.getElementById('rd-body').innerHTML = `
    <div class="detail-row"><span class="detail-label">Location</span><span class="detail-value">${w.location_text}</span></div>
    <div class="detail-row"><span class="detail-label">Priority</span><span class="detail-value"><span class="badge badge-${w.severity}">${cap(w.severity)}</span></span></div>
    <div class="detail-row"><span class="detail-label">Assigned To</span><span class="detail-value">${w.assigned_to_name || 'Unassigned'}</span></div>
    <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value">${woStatusBadge(w.status)}</span></div>
    <div class="detail-row"><span class="detail-label">Created</span><span class="detail-value">${fmtDate(w.created_at)}</span></div>
    ${w.updated_at?`<div class="detail-row"><span class="detail-label">Last Updated</span><span class="detail-value">${fmtDate(w.updated_at)}</span></div>`:''}
    ${w.instructions?`<div style="margin-top:14px"><div style="font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:600">Work Instructions</div><div style="font-size:13px;color:var(--label);line-height:1.6">${w.instructions}</div></div>`:''}
    ${lastUpdate?`<div style="margin-top:14px"><div style="font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:600">Latest Activity Report</div><div style="font-size:13px;color:var(--label);line-height:1.6">${lastUpdate.remarks}</div></div>`:''}
    ${photoPaths.length?`<div class="photo-preview" style="margin-top:14px">${photoPaths.map((src,i)=>`<img src="${src}" class="photo-view-thumb" onclick="openLightbox(window.currentDetailPhotos, ${i})">`).join('')}</div>`:''}`;
  window.currentDetailPhotos = photoPaths; 
  openModal('modal-report-detail');
}

// ════════════════════════════════════════
// PHOTO LIGHTBOX (swipeable, Messenger-style viewer)
// ════════════════════════════════════════
let lightboxPhotos = [];
let lightboxIndex  = 0;

function openLightbox(photos, startIndex = 0){
  if(!photos || !photos.length) return;
  lightboxPhotos = photos;
  lightboxIndex  = startIndex;
  renderLightbox();
  document.getElementById('lightbox-overlay').classList.add('open');
}

function closeLightbox(){
  document.getElementById('lightbox-overlay').classList.remove('open');
}

function renderLightbox(){
  const track = document.getElementById('lightbox-track');
  track.innerHTML = lightboxPhotos.map(src => `<div class="lightbox-slide"><img src="${src}"></div>`).join('');
  track.style.transform = `translateX(${-lightboxIndex * 100}%)`;

  const multi = lightboxPhotos.length > 1;
  document.getElementById('lightbox-counter').textContent = multi ? `${lightboxIndex + 1} / ${lightboxPhotos.length}` : '';
  document.getElementById('lightbox-prev').style.display   = multi ? 'flex' : 'none';
  document.getElementById('lightbox-next').style.display   = multi ? 'flex' : 'none';
}

function lightboxNav(dir){
  lightboxIndex = Math.max(0, Math.min(lightboxPhotos.length - 1, lightboxIndex + dir));
  document.getElementById('lightbox-track').style.transform = `translateX(${-lightboxIndex * 100}%)`;
  document.getElementById('lightbox-counter').textContent = lightboxPhotos.length > 1 ? `${lightboxIndex + 1} / ${lightboxPhotos.length}` : '';
}

document.addEventListener('DOMContentLoaded', () => {
  const track = document.getElementById('lightbox-track');
  if(!track) return;
  let touchStartX = null;
  track.addEventListener('touchstart', e => { touchStartX = e.touches[0].clientX; }, { passive: true });
  track.addEventListener('touchend', e => {
    if(touchStartX === null) return;
    const dx = e.changedTouches[0].clientX - touchStartX;
    if(Math.abs(dx) > 40) lightboxNav(dx < 0 ? 1 : -1);
    touchStartX = null;
  }, { passive: true });
});

document.addEventListener('keydown', e => {
  const ov = document.getElementById('lightbox-overlay');
  if(!ov || !ov.classList.contains('open')) return;
  if(e.key === 'Escape')     closeLightbox();
  if(e.key === 'ArrowLeft')  lightboxNav(-1);
  if(e.key === 'ArrowRight') lightboxNav(1);
});

// ════════════════════════════════════════
// PROFILE
// ════════════════════════════════════════
function openModal(id){
  if(id==='modal-profile' && currentUser){
    const avClasses = {field_inspector:'av-amber',supervisor:'av-blue',field_worker:'av-green',administrator:'av-red'};
    const av = document.getElementById('prof-av');
    av.textContent = currentUser.name[0];
    av.className = 'avatar '+avClasses[currentUser.role];
    av.style.cssText='width:64px;height:64px;font-size:24px;margin:0 auto 12px';
    document.getElementById('prof-name').textContent  = currentUser.name;
    document.getElementById('prof-role').textContent  = cap(currentUser.role);
    document.getElementById('prof-name-inp').value    = currentUser.name;
    document.getElementById('prof-email-inp').value   = currentUser.email;
    document.getElementById('prof-pass').value        = '';
  }
  document.getElementById(id).classList.add('open');
}

async function saveProfile(){
  if(!currentUser) return;

  const newName  = document.getElementById('prof-name-inp').value.trim();
  const currPass = document.getElementById('prof-curpass').value.trim();
  const newPass  = document.getElementById('prof-pass').value.trim();

  if(!newName){ toast('Name cannot be empty','error'); return; }

  // Update name if it changed
  if(newName !== currentUser.name){
    const nameRes  = await fetch('api/users.php?action=self_edit', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: newName })
    });
    const nameData = await nameRes.json();
    if(!nameData.success){
      toast(nameData.message || 'Failed to update name', 'error');
      return;
    }
    currentUser.name = newName;
  }

  // Update password only if the user filled in both fields
  if(newPass){
    if(!currPass){ toast('Please enter your current password to change it','error'); return; }
    if(newPass.length < 8){ toast('New password must be at least 8 characters','error'); return; }

    const passRes  = await fetch('api/auth.php?action=change_password', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ current_password: currPass, new_password: newPass })
    });
    const passData = await passRes.json();
    if(!passData.success){
      toast(passData.message || 'Failed to update password', 'error');
      return;
    }
  }

  closeModal('modal-profile');
  toast('Profile updated successfully','success');
  refreshScreen(ROLE_SCREEN[currentUser.role]);
}

function closeModal(id){
  document.getElementById(id).classList.remove('open');
  if(id === 'modal-submit'){
    window.selectedReportPhotos = [];
    const prev = document.getElementById('photo-preview');
    if(prev) prev.innerHTML = '';
    const input = document.getElementById('s-photo');
    if(input) input.value = '';
  }

  if(id === 'modal-update-task'){
    window.selectedTaskPhotos = [];
    const prev = document.getElementById('task-photo-preview');
    if(prev) prev.innerHTML = '';
    const input = document.getElementById('ut-photo');
    if(input) input.value = '';
  }
  
}

// ════════════════════════════════════════
// MOBILE SIDEBAR
// ════════════════════════════════════════
function toggleSidebar(){
  const screen = document.querySelector('.screen.active');
  const sb = screen.querySelector('.sidebar');
  const ov = screen.querySelector('.sidebar-overlay');
  sb.classList.toggle('open');
  ov.classList.toggle('open');
}
function closeSidebar(){
  document.querySelectorAll('.sidebar.open').forEach(s=>s.classList.remove('open'));
  document.querySelectorAll('.sidebar-overlay.open').forEach(o=>o.classList.remove('open'));
}

// ════════════════════════════════════════
// SHARED RENDER HELPERS
// ════════════════════════════════════════
function renderReportTable(tbodyId, cardsId, reports, role){
  const tbody = document.getElementById(tbodyId);
  const cards = document.getElementById(cardsId);
  if(!reports||!reports.length){
    if(tbody) tbody.innerHTML=`<tr><td colspan="7"><div class="empty-state"><p>No reports found</p></div></td></tr>`;
    if(cards) cards.innerHTML=`<div class="empty-state"><p>No reports found</p></div>`;
    return;
  }
  if(tbody) tbody.innerHTML = reports.map(r=>`<tr>
    <td class="id">${r.report_code}</td>
    <td class="issue">${r.title}</td>
    <td>${r.location_text}</td>
    <td><span class="badge badge-${r.severity}">${cap(r.severity)}</span></td>
    <td>${fmtDate(r.submitted_at)}</td>
    <td>${statusBadge(r.status)}</td>
    <td><button class="action-link" onclick="openReportDetail('${r.report_id}')">Details</button></td>
  </tr>`).join('');
  if(cards) cards.innerHTML = reports.map(r=>`
    <div class="m-card">
      <div class="m-card-top"><div><div class="m-card-title">${r.title}</div><div class="m-card-id">${r.report_code} • ${fmtDate(r.submitted_at)}</div></div><span class="badge badge-${r.severity}">${cap(r.severity)}</span></div>
      <div class="m-card-loc">📍 ${r.location_text}</div>
      <div class="m-card-footer">${statusBadge(r.status)}<button class="action-link" onclick="openReportDetail('${r.report_id}')">Details</button></div>
    </div>`).join('');
}

// ════════════════════════════════════════
// UTILITIES
// ════════════════════════════════════════
function $(id){ return document.getElementById(id.replace(/^#/,'')); }
function setText(id,v){ const e=document.getElementById(id); if(e) e.textContent=v; }
function cap(s){ return s?s.charAt(0).toUpperCase()+s.slice(1):'' }
function fmtDate(iso){ if(!iso) return '-'; const d=new Date(iso); return d.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
function timeAgo(iso){
  const d=new Date(iso);const now=new Date();const diff=Math.floor((now-d)/1000);
  if(diff<60) return 'just now';if(diff<3600) return Math.floor(diff/60)+'m ago';
  if(diff<86400) return Math.floor(diff/3600)+'h ago';return Math.floor(diff/86400)+'d ago';
}
function statusBadge(s){
  const m={pending:'badge-pending pending',assigned:'badge-assigned assigned',completed:'badge-completed completed',resolved:'badge-resolved resolved'};
  const l={pending:'Pending',assigned:'Assigned',completed:'Completed',resolved:'Resolved'};
  return `<span class="badge ${m[s]||'badge-pending'}">${l[s]||cap(s)}</span>`;
}
function woStatusBadge(s){
  const m={'assigned':'badge-assigned','in-progress':'badge-inprogress','completed':'badge-completed','on-hold':'badge-pending'};
  const l={'assigned':'Assigned','in-progress':'In Progress','completed':'Completed','on-hold':'On Hold'};
  return `<span class="badge ${m[s]||'badge-pending'}">${l[s]||cap(s)}</span>`;
}
function toast(msg, type='success'){
  const c = document.getElementById('toasts');
  const icons={success:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>',error:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',info:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'};
  const t = document.createElement('div');
  t.className=`toast toast-${type}`;t.innerHTML=icons[type]+msg;c.appendChild(t);
  setTimeout(()=>t.remove(),3500);
}

// ════════════════════════════════════════
// PAGE INIT
// Each HTML file sets <body data-page="..."> so this one shared
// script knows which role guard/render to run on load.
// ════════════════════════════════════════
const currentPageRole = document.body ? document.body.dataset.page : null;
if(currentPageRole && PAGE_ROLE[currentPageRole]) initRolePage(currentPageRole);

// Close modals on overlay click
document.querySelectorAll('.overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o) o.classList.remove('open')}));

// PWA
if('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(()=>{});
