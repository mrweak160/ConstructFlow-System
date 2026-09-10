
const API = 'api';

let currentUser = null;

const ROLE_SCREEN = {field_inspector:'inspector', supervisor:'supervisor', field_worker:'fieldworker', administrator:'admin'};
const ROLE_PAGE   = {field_inspector:'inspector.html', supervisor:'supervisor.html', field_worker:'fieldworker.html', administrator:'admin.html'};
// Maps each page's data-page short key -> the DB role required to view it.
const PAGE_ROLE   = {inspector:'field_inspector', supervisor:'supervisor', fieldworker:'field_worker', admin:'administrator'};

// Maps a team role to a human label for the profile modal etc.
const ROLE_LABEL = {
  field_inspector:'Field Inspector', supervisor:'Supervisor',
  field_worker:'Field Worker', administrator:'Administrator', member:'No role yet'
};

// Reads the session from the server. Role is NOT a property of the
// account any more — it comes from the team the person currently has
// selected, so /me returns it alongside the user rather than inside it.
async function loadSession(){
  try{
    const res  = await fetch('api/auth.php?action=me', { credentials:'include' });
    const data = await res.json();
    if(!data.success) return null;
    return {
      user:        data.data.user,
      role:        data.data.role,            // null when no team is selected
      teamId:      data.data.active_team_id,
      teamName:    data.data.team_name,
      memberships: data.data.memberships || [],
      csrfToken:   data.data.csrf_token
    };
  }catch(e){
    return null;   // server unreachable
  }
}

// Runs once on inspector.html / supervisor.html / fieldworker.html / admin.html.
// Four outcomes, in order:
//   not signed in            -> login.html
//   signed in, no team/role  -> team.html (create, join, or await approval)
//   signed in, wrong page    -> the page matching their role in this team
//   signed in, right page    -> render it
async function initRolePage(pageKey){
  const requiredRole = PAGE_ROLE[pageKey];
  const session = await loadSession();

  if(!session){ window.location.href = 'login.html'; return; }

  currentUser = session.user;
  currentUser.role     = session.role;        // kept on the object so existing code keeps working
  currentUser.teamId   = session.teamId;
  currentUser.teamName = session.teamName;
  window.CSRF_TOKEN    = session.csrfToken;

  // No team selected, or approved into a team but not yet given a job.
  if(!session.role || session.role === 'member'){
    window.location.href = 'team.html';
    return;
  }

  if(session.role !== requiredRole){
    window.location.href = ROLE_PAGE[session.role] || 'team.html';
    return;
  }

  refreshScreen(ROLE_SCREEN[requiredRole]);

  initPasswordToggles();
}

// ════════════════════════════════════════
// AUTH
// ════════════════════════════════════════

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
  const viewMap   = {dashboard:'dashboard',reports:'reports',workorders:'workorders',history:'history',logs:'logs',tasks:'tasks'};  
  const prefix = prefixMap[screen] || screen;
  const vid    = prefix + '-' + (viewMap[view] || view);

  // Switch view
  s.querySelectorAll('.view').forEach(v=>v.classList.remove('active'));
  const el = document.getElementById(vid);
  if(el) el.classList.add('active');

  // Switch nav highlight
  s.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  const navSuffix = {dashboard:'dash',reports:'rep',workorders:'wo',history:'hist',logs:'logs',tasks:'tasks'};
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
    if(view==='members')   renderAdmMembers();
    if(view==='logs')      renderAdmLogs();
  }
}

// ════════════════════════════════════════
// INSPECTOR
// ════════════════════════════════════════
// ── TEAM NAME / DESCRIPTION IN THE TOPBAR ────────────────
async function paintTeamHeader(prefix){
  setText(prefix + '-teamname', currentUser && currentUser.teamName ? currentUser.teamName : '—');

  try{
    const res  = await fetch('api/teams.php?action=detail', { credentials:'include' });
    const data = await res.json();
    if(data.success && data.data.team){
      setText(prefix + '-teamname', data.data.team.name);
      setText(prefix + '-teamdesc', data.data.team.description || '');
    }
  }catch(e){ }
}

function refreshInspector(){
  if(currentUser){
    document.getElementById('insp-uname').textContent = currentUser.name;
    document.getElementById('insp-topname').textContent = currentUser.name;
    setText('insp-topemail', currentUser.email || '');
    paintAvatar('insp-av',    currentUser.name, currentUser.avatar_path);
    paintAvatar('insp-topav', currentUser.name, currentUser.avatar_path);
  }
  paintTeamHeader('insp');
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
      <div class="report-card-meta"><span class="badge badge-${r.severity}">${cap(r.severity)}</span><span style="font-size:12px;color:var(--muted)">${esc(r.report_code)}</span></div>
      <h4>${esc(t.title.toUpperCase())}</h4>
      <p>${esc(t.description || 'No additional instructions provided.')}</p>
      <div class="queue-card-foot">
        <span class="report-card-loc" style="font-size:12px;color:var(--muted)">📍 ${esc(t.location_text)}</span>
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
  window.selectedReportPhotos = [];
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
    setText('sup-topemail', currentUser.email || '');
    paintAvatar('sup-av',    currentUser.name, currentUser.avatar_path);
    paintAvatar('sup-topav', currentUser.name, currentUser.avatar_path);
  }
  paintTeamHeader('sup');
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
      <h4>${esc(r.title.toUpperCase())}</h4>
      <p>${esc(r.description)}</p>
      <div class="report-card-footer">
        <span class="report-card-loc"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>${esc(r.location_text)}</span>
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
    <td class="id">${esc(r.report_code)}</td>
    <td class="issue">${esc(r.title)}</td>
    <td>${esc(r.submitted_by_name)}</td>
    <td><span class="badge badge-${r.severity}">${cap(r.severity)}</span></td>
    <td>${fmtDate(r.submitted_at)}</td>
    <td>${statusBadge(r.status)}</td>
    <td><button class="action-link" onclick="openReportDetail('${r.report_id}')">View</button>
    ${r.status==='pending'?`<button class="action-link" style="margin-left:8px" onclick="openAssign('${r.report_id}')">Assign</button>`:''}</td>
  </tr>`).join('');
  cards.innerHTML = filtered.map(r=>`
    <div class="m-card">
      <div class="m-card-top"><div><div class="m-card-title">${esc(r.title)}</div><div class="m-card-id">${esc(r.report_code)} • ${esc(r.submitted_by_name)}</div></div><span class="badge badge-${r.severity}">${cap(r.severity)}</span></div>
      <div class="m-card-loc">📍 ${esc(r.location_text)}</div>
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
    <td class="id">${esc(w.wo_code)}</td>
    <td class="issue">${esc(w.report_title)}</td>
    <td>${esc(w.assigned_to_name)}</td>
    <td><span class="badge badge-${w.severity}">${cap(w.severity)}</span></td>
    <td>${fmtDate(w.created_at)}</td>
    <td>${woStatusBadge(w.status)}</td>
    <td><button class="action-link" onclick="openWODetail('${w.wo_id}')">View</button></td>
  </tr>`).join('');
  cards.innerHTML = wos.map(w=>`
    <div class="m-card">
      <div class="m-card-top"><div><div class="m-card-title">${esc(w.report_title)}</div><div class="m-card-id">${esc(w.wo_code)} → ${esc(w.assigned_to_name)}</div></div><span class="badge badge-${w.severity}">${cap(w.severity)}</span></div>
      <div class="m-card-loc">📍 ${esc(w.location_text)}</div>
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
    <td class="id">${esc(t.task_code)}</td>
    <td class="issue">${esc(t.title)}</td>
    <td>${esc(t.assigned_to_name)}</td>
    <td>${esc(t.location_text)}</td>
    <td>${statusBadgeTask(t.status)}</td>
    <td>${t.due_date ? fmtDate(t.due_date) : '—'}</td>
    <td>${t.status==='submitted'?`<button class="action-link" onclick="closeTask(${t.task_id})">Close Task</button>`:''}
    ${t.report_id?`<button class="action-link" style="margin-left:8px" onclick="openReportDetail('${t.report_id}')">View Report</button>`:''}</td>
  </tr>`).join('');
  cards.innerHTML = tasks.map(t=>`
    <div class="m-card">
      <div class="m-card-top"><div><div class="m-card-title">${esc(t.title)}</div><div class="m-card-id">${esc(t.task_code)} → ${esc(t.assigned_to_name)}</div></div>${statusBadgeTask(t.status)}</div>
      <div class="m-card-loc">📍 ${esc(t.location_text)}</div>
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
    inspectors.map(u=>`<option value="${u.user_id}">${esc(u.name)}</option>`).join('');

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
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
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
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
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
    setText('fw-topemail', currentUser.email || '');
    paintAvatar('fw-av',    currentUser.name, currentUser.avatar_path);
    paintAvatar('fw-topav', currentUser.name, currentUser.avatar_path);
  }
  paintTeamHeader('fw');
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
      <div class="queue-card-top">${woStatusBadge(w.status)}<span style="font-size:12px;color:var(--muted)">${esc(w.wo_code)}</span></div>      <h4>${esc(w.report_title.toUpperCase())}</h4>
      <p>${esc(w.instructions||'No special instructions provided.')}</p>
      <div class="queue-card-foot">
        <span class="report-card-loc" style="font-size:12px;color:var(--muted)">📍 ${esc(w.location_text)}</span>
        <span style="font-size:11.5px;color:var(--muted)">⏱ ${fmtDate(w.created_at)}</span>
        <button class="btn btn-amber btn-sm" onclick="openUpdateTask('${w.wo_id}')">Process Task ›</button>
      </div>
      ${w.latest_remarks?`<div style="margin-top:10px;padding:8px 10px;background:rgba(255,255,255,.03);border-radius:6px;font-size:12px;color:var(--muted)">Last update: ${esc(w.latest_remarks)}</div>`:''}
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
    <td class="id">${esc(w.wo_code)}</td><td class="issue">${esc(w.report_title)}</td><td>${esc(w.location_text)}</td>
    <td><span class="badge badge-${w.severity}">${cap(w.severity)}</span></td>
    <td>${fmtDate(w.updated_at)}</td>
    <td>${woStatusBadge(w.status)}</td>
  </tr>`).join('');
  cards.innerHTML = wos.map(w=>`
    <div class="m-card">
      <div class="m-card-top"><div><div class="m-card-title">${esc(w.report_title)}</div><div class="m-card-id">${esc(w.wo_code)}</div></div><span class="badge badge-${w.severity}">${cap(w.severity)}</span></div>
      <div class="m-card-loc">📍 ${esc(w.location_text)} • ${fmtDate(w.updated_at)}</div>
      <div class="m-card-footer">${woStatusBadge(w.status)}</div>
    </div>`).join('');
}

// ════════════════════════════════════════
// ADMIN
// ════════════════════════════════════════
function refreshAdmin(){
  if(currentUser){
    setText('adm-uname',   currentUser.name);
    setText('adm-topname', currentUser.name);
    setText('adm-topemail',currentUser.email || '');
    paintAvatar('adm-av',    currentUser.name, currentUser.avatar_path);
    paintAvatar('adm-topav', currentUser.name, currentUser.avatar_path);
  }
    paintTeamHeader('adm');
  renderAdmDash();
}

// Shared by every admin view so the labels cannot drift apart.
const ADM_ROLE_LABEL = {
  administrator:'Administrator', supervisor:'Supervisor',
  field_inspector:'Field Inspector', field_worker:'Field Worker',
  member:'No role yet'
};
const ADM_ROLE_CHIP = {
  administrator:'chip-admin', supervisor:'chip-sup',
  field_inspector:'chip-insp', field_worker:'chip-worker', member:'chip-sup'
};
const ADM_ROLE_AV = {
  administrator:'av-red', supervisor:'av-blue',
  field_inspector:'av-green', field_worker:'av-amber', member:'av-amber'
};

async function admApi(file, action, body){
  const url = `api/${file}.php?action=${action}`;
  try{
    const res = await fetch(url, body === undefined
      ? { credentials:'include' }
      : { method:'POST', credentials:'include',
          headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
          body: JSON.stringify(body) });
    return await res.json();
  }catch(e){
    return { success:false, message:'Cannot reach the server. Check your connection and try again.' };
  }
}

// State the modals read back when the user confirms.
let _admTeam     = null;
let _admMembers  = [];
let _admInvites = [];
let _arUserId  = 0;

// ── DASHBOARD ────────────────────────────────────────────
async function renderAdmDash(){
  const [detail, members] = await Promise.all([
    admApi('teams','detail'),
    admApi('teams','members')
  ]);

  if(!detail.success){ toast(detail.message,'error'); return; }

  _admTeam = detail.data.team;
  const list = members.success ? members.data.members : [];

  setText('adm-dash-sub', `${_admTeam.member_count} member${_admTeam.member_count==1?'':'s'}`);
  setText('adm-stat-members',    _admTeam.member_count);
  setText('adm-stat-invites',    _admTeam.pending_invites ?? 0);
  setText('adm-stat-unassigned', list.filter(m=>m.role==='member').length);

  renderAdmEvents();
}

async function renderAdmEvents(){
  const data = await admApi('logs','list&limit=10');
  const logs = data.success ? data.data.logs : [];
  const el = $('adm-events-list');
  if(!logs.length){ el.innerHTML='<div class="empty-state"><p>No activity yet</p></div>'; return; }
  el.innerHTML = logs.map(l=>`
    <div class="event-row">
      <div><div class="event-name">${esc(l.action)}</div><div class="event-by">by ${esc(l.user_name || 'System')}</div></div>
      <div class="event-time">${fmtDate(l.logged_at)}</div>
    </div>`).join('');
}

// ── SETTINGS MENU ────────────────────────────────────────
function toggleSettingsMenu(e){
  e.stopPropagation();
  $('adm-settings-menu').classList.toggle('open');
}

function openSettings(which){
  $('adm-settings-menu').classList.remove('open');
  if(which === 'team'){
    teamView();
    renderAdmTeam();
    openModal('modal-team');
  }else{
    openModal('modal-profile');
  }
}
function toggleRoleMenu(e, prefix){
  e.stopPropagation();
  const m = $(prefix + '-settings-menu');
  if(m) m.classList.toggle('open');
}

function openRoleProfile(prefix){
  const m = $(prefix + '-settings-menu');
  if(m) m.classList.remove('open');
  openModal('modal-profile');
}

// One listener closes whichever menu is open, on any of the four pages.
document.addEventListener('click', () => {
  document.querySelectorAll('.menu.open').forEach(m => m.classList.remove('open'));
});

// ── MEMBERS ──────────────────────────────────────────────
async function renderAdmMembers(){
  const [res, inv] = await Promise.all([
    admApi('teams','members'),
    admApi('teams','invites')
  ]);
  if(!res.success){ toast(res.message,'error'); return; }

  _admMembers     = res.data.members;

  const me = currentUser ? currentUser.user_id : 0;

  $('adm-member-table').innerHTML = _admMembers.map(m=>{
    const isSelf     = Number(m.user_id) === Number(me);
    const isAdminRow = m.role === 'administrator';

    const actions = isAdminRow
      ? (isSelf
          ? '<span style="font-size:12px;color:var(--muted);display:inline-block;width:100px">Administrator</span>'
          : `<span style="font-size:12px;color:var(--muted);display:inline-block;width:100px">Administrator</span><button class="action-link" style="color:var(--red)" onclick="removeMember(${m.user_id})">Remove</button>`)
      : `<span style="display:inline-block;width:100px"><button class="action-link" onclick="openAssignRole(${m.user_id})">Change role</button></span>` +
        `<button class="action-link" style="color:var(--red)" onclick="removeMember(${m.user_id})">Remove</button>`;
        
        return `<tr>
      <td><div style="display:flex;align-items:center;gap:10px">
                ${avatarHtml(m.name, m.avatar_path, ADM_ROLE_AV[m.role]||'av-amber', 28, 11)}
        <div>
          <div style="font-weight:600;color:var(--text);font-size:13px">${esc(m.name)}${isSelf?' <span style="color:var(--muted);font-weight:400">(you)</span>':''}</div>
          <div style="font-size:11px;color:var(--muted)">${esc(m.email)}</div>
        </div>
      </div></td>
      <td><span class="chip ${ADM_ROLE_CHIP[m.role]||'chip-sup'}">${ADM_ROLE_LABEL[m.role]||esc(m.role)}</span></td>
      <td style="font-size:12.5px;color:var(--muted)">${m.joined_at ? fmtDate(m.joined_at) : '—'}</td>
      <td>${actions}</td>
    </tr>`;
  }).join('');
  $('adm-member-cards').innerHTML = _admMembers.map(m=>{
    const isSelf     = Number(m.user_id) === Number(me);
    const isAdminRow = m.role === 'administrator';

    const actions = isAdminRow
      ? (isSelf
          ? '<span style="font-size:12px;color:var(--muted)">Administrator</span>'
          : `<span style="font-size:12px;color:var(--muted)">Administrator</span> <button class="action-link" style="color:var(--red)" onclick="removeMember(${m.user_id})">Remove</button>`)
      : `<button class="action-link" onclick="openAssignRole(${m.user_id})">Change role</button>` +
        ` <button class="action-link" style="color:var(--red);margin-left:14px" onclick="removeMember(${m.user_id})">Remove</button>`;

    return `<div class="m-card">
      <div class="m-card-top">
        <div style="display:flex;align-items:center;gap:10px;min-width:0;flex:1">
          ${avatarHtml(m.name, m.avatar_path, ADM_ROLE_AV[m.role]||'av-amber', 30, 12)}
          <div>
            <div class="m-card-title">${esc(m.name)}${isSelf?' <span style="color:var(--muted);font-weight:400">(you)</span>':''}</div>
            <div class="m-card-id">${esc(m.email)}</div>
          </div>
        </div>
        <span class="chip ${ADM_ROLE_CHIP[m.role]||'chip-sup'}">${ADM_ROLE_LABEL[m.role]||esc(m.role)}</span>
      </div>
      <div class="m-card-loc">Joined ${m.joined_at ? fmtDate(m.joined_at) : '—'}</div>
      <div class="m-card-footer">${actions}</div>
    </div>`;
  }).join('');

  renderAdmInvites(inv.success ? inv.data.invitations : []);
}

function renderAdmInvites(invites){
  _admInvites = invites;
  const card = $('adm-invites-card');
  const list = $('adm-invite-list');
  if(!card || !list) return;

  if(!invites.length){ card.style.display = 'none'; return; }
  card.style.display = 'block';

  list.innerHTML = invites.map(i=>{
    const expired = Number(i.is_expired) === 1;
    return `
    <div class="event-row">
      <div>
        <div class="event-name">${esc(i.email)}</div>
        <div class="event-by">
          ${ADM_ROLE_LABEL[i.role]||esc(i.role)} ·
          ${expired ? '<span style="color:var(--red)">Expired</span>' : 'Sent ' + timeAgo(i.created_at)}
        </div>
      </div>
      <div style="display:flex;gap:12px;align-items:center;flex-shrink:0">
        <button class="action-link" onclick="resendInvite(${i.invitation_id})">Resend</button>
        <button class="action-link" style="color:var(--red)" onclick="revokeInvite(${i.invitation_id})">Cancel</button>
      </div>
    </div>`;
  }).join('');
}

// ── INVITING ─────────────────────────────────────────────
function openInviteModal(){
  ['inv-first','inv-last','inv-email','inv-birth'].forEach(id => { if($(id)) $(id).value = ''; });
  $('inv-role').value = 'field_worker';
  const b = $('inv-birth');
  if(b) b.max = new Date().toISOString().slice(0,10);
  openModal('modal-invite');
  setTimeout(()=>$('inv-first').focus(), 80);
}

async function sendInvite(){
  const first = $('inv-first').value.trim();
  const last  = $('inv-last').value.trim();
  const email = $('inv-email').value.trim();
  const birth = $('inv-birth').value;
  const role  = $('inv-role').value;

  if(!first || !last){ toast("Enter the member's first and last name",'error'); return; }
  if(!email){ toast('Enter an email address','error'); return; }
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ toast('Enter a valid email address','error'); return; }
  if(!birth){ toast('Enter their birthdate','error'); $('inv-birth').focus(); return; }

  const btn = $('inv-send-btn');
  btn.disabled = true;
  const res = await admApi('teams','invite',{
    first_name: first, last_name: last, email, birthdate: birth, role,
  });
  btn.disabled = false;

  if(!res.success){ toast(res.message,'error'); return; }

  closeModal('modal-invite');
  toast(res.message,'success');
  renderAdmMembers();
  renderAdmDash();
}

async function resendInvite(id){
  const res = await admApi('teams','resend_invite',{ invitation_id:id });
  toast(res.message, res.success ? 'success' : 'error');
  if(res.success) renderAdmMembers();
}

async function revokeInvite(id){
  const inv = (_admInvites || []).find(x => Number(x.invitation_id) === Number(id));
  const email = inv ? inv.email : 'this address';
  if(!confirm(`Cancel the invitation to ${email}? The link stops working immediately.`)) return;
  const res = await admApi('teams','revoke_invite',{ invitation_id:id });
  toast(res.message, res.success ? 'success' : 'error');
  if(res.success) renderAdmMembers();
}

// ── ROLE CHANGES ─────────────────────────────────────────
function openAssignRole(userId){
  const m = _admMembers.find(x=>Number(x.user_id)===Number(userId));
  if(!m) return;

  _arUserId = userId;
  setText('ar-subtitle', `${m.name} · currently ${ADM_ROLE_LABEL[m.role]||m.role}`);
  $('ar-role').value = m.role;

    openModal('modal-assign-role');
}

async function confirmAssignRole(){
  const role = $('ar-role').value;
  const res  = await admApi('teams','assign_role',{ user_id:_arUserId, role });

  if(!res.success){ toast(res.message,'error'); return; }

  closeModal('modal-assign-role');
  toast(res.message,'success');
  renderAdmMembers();
  renderAdmDash();
}

async function removeMember(userId){
  const m = _admMembers.find(x=>Number(x.user_id)===Number(userId));
  if(!m || !confirm(`Remove ${m.name} from this team?`)) return;

  const res = await admApi('teams','remove_member',{ user_id:userId });
  toast(res.message, res.success ? 'success' : 'error');
  if(res.success){ renderAdmMembers(); renderAdmDash(); }
}

// ── TEAM SETTINGS ────────────────────────────────────────
async function renderAdmTeam(){
  const res = await admApi('teams','detail');
  if(!res.success){ toast(res.message,'error'); return; }

  _admTeam = res.data.team;
  setText('adm-team-name', _admTeam.name);
  setText('adm-team-meta', `${_admTeam.member_count} member${_admTeam.member_count==1?'':'s'} · created ${fmtDate(_admTeam.created_at)}`);

  setText('team-view-desc',    _admTeam.description || 'No description');
  setText('team-view-members', _admTeam.member_count);
  setText('team-view-admins',  _admTeam.admin_count);
  setText('team-view-invites', _admTeam.pending_invites ?? 0);
  setText('team-view-created', fmtDate(_admTeam.created_at));

  $('adm-team-name-inp').value = _admTeam.name;
  $('adm-team-desc-inp').value = _admTeam.description || '';
}

function teamView(){
  $('team-view').style.display       = 'block';
  $('team-edit').style.display       = 'none';
  $('team-close-btn').style.display  = '';
  $('team-cancel-btn').style.display = 'none';
  $('team-save-btn').style.display   = 'none';
  $('team-edit-btn').style.display   = (_admTeam && _admTeam.can_rename === false) ? 'none' : '';
}

function teamEdit(){
  $('team-view').style.display       = 'none';
  $('team-edit').style.display       = 'block';
  $('team-close-btn').style.display  = 'none';
  $('team-edit-btn').style.display   = 'none';
  $('team-cancel-btn').style.display = '';
  $('team-save-btn').style.display   = '';
}

async function saveTeamDetails(){
  const name = $('adm-team-name-inp').value.trim();
  const description = $('adm-team-desc-inp').value.trim();
  if(!name){ toast('Team name cannot be empty','error'); return; }

  const res = await admApi('teams','update_team',{ name, description });
  toast(res.message, res.success ? 'success' : 'error');
  if(res.success){
    await renderAdmTeam();
    teamView();
    renderAdmDash();
  }
}


// ── LOGS ─────────────────────────────────────────────────
async function renderAdmLogs(){
  const data = await admApi('logs','list&limit=200');
  const logs = data.success ? data.data.logs : [];

  const el = $('adm-logs-list');
  if(!logs.length){ el.innerHTML='<div class="empty-state"><p>No logs yet</p></div>'; return; }
  el.innerHTML = logs.map(l=>`
    <div class="event-row">
      <div><div class="event-name">${esc(l.action)}</div><div class="event-by">by ${esc(l.user_name || 'System')}</div></div>
      <div class="event-time">${fmtDate(l.logged_at)}</div>
    </div>`).join('');
}

async function clearLogs(){
  if(!confirm('Clear all audit logs?')) return;
  const res = await admApi('logs','clear',{});
  if(res.success){
    renderAdmLogs();
    renderAdmEvents();
    toast('Logs cleared','info');
  }else{
    toast(res.message || 'Failed to clear logs','error');
  }
}

function exportRecords(){
  window.open('api/logs.php?action=export', '_blank');
  toast('Preparing export…','info');
}

// ════════════════════════════════════════
// REPORT SUBMISSION
// ════════════════════════════════════════

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
  fd.append('csrf_token', window.CSRF_TOKEN || '');

  const res = await fetch('api/reports.php?action=submit', {
    method: 'POST',
    credentials: 'include',
    body: fd
  });
  const data = await res.json();

  if(data.success){
    closeModal('modal-submit'); // also clears staged photos, see closeModal() below
    toast(`Report ${data.data.report_code} submitted successfully`, 'success');

    const warnings = data.data.photo_warnings || [];
    if(warnings.length){
      toast(`${warnings.length} photo${warnings.length>1?'s':''} skipped: ${warnings.join(' ')}`, 'error');
    }

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
    workers.map(u=>`<option value="${u.user_id}">${esc(u.name)}</option>`).join('');

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
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
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
  window.selectedTaskPhotos = [];
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
  fd.append('csrf_token', window.CSRF_TOKEN || '');

  const res = await fetch('api/workorders.php?action=update_status', {
    method: 'POST',
    credentials: 'include',
    body: fd
  });
  const data = await res.json();

  if(data.success){
    closeModal('modal-update-task'); // also clears staged photos, see closeModal()
    toast(`Task updated to ${cap(status)}`, 'success');

    const warnings = data.data.photo_warnings || [];
    if(warnings.length){
      toast(`${warnings.length} photo${warnings.length>1?'s':''} skipped: ${warnings.join(' ')}`, 'error');
    }

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
    <div class="detail-row"><span class="detail-label">Type</span><span class="detail-value">${esc(r.issue_type)}</span></div>
    <div class="detail-row"><span class="detail-label">Location</span><span class="detail-value">${esc(r.location_text)}</span></div>
    <div class="detail-row"><span class="detail-label">Severity</span><span class="detail-value"><span class="badge badge-${r.severity}">${cap(r.severity)}</span></span></div>
    <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value">${statusBadge(r.status)}</span></div>
    <div class="detail-row"><span class="detail-label">Inspector</span><span class="detail-value">${esc(r.submitted_by_name)}</span></div>
    <div class="detail-row"><span class="detail-label">Submitted</span><span class="detail-value">${fmtDate(r.submitted_at)}</span></div>
    <div style="margin-top:14px"><div style="font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:600">Description</div><div style="font-size:13px;color:var(--label);line-height:1.6">${esc(r.description)}</div></div>`;
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
    <div class="detail-row"><span class="detail-label">Location</span><span class="detail-value">${esc(w.location_text)}</span></div>
    <div class="detail-row"><span class="detail-label">Priority</span><span class="detail-value"><span class="badge badge-${w.severity}">${cap(w.severity)}</span></span></div>
    <div class="detail-row"><span class="detail-label">Assigned To</span><span class="detail-value">${esc(w.assigned_to_name || 'Unassigned')}</span></div>
    <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value">${woStatusBadge(w.status)}</span></div>
    <div class="detail-row"><span class="detail-label">Created</span><span class="detail-value">${fmtDate(w.created_at)}</span></div>
    ${w.updated_at?`<div class="detail-row"><span class="detail-label">Last Updated</span><span class="detail-value">${fmtDate(w.updated_at)}</span></div>`:''}
    ${w.instructions?`<div style="margin-top:14px"><div style="font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:600">Work Instructions</div><div style="font-size:13px;color:var(--label);line-height:1.6">${esc(w.instructions)}</div></div>`:''}
    ${lastUpdate?`<div style="margin-top:14px"><div style="font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:600">Latest Activity Report</div><div style="font-size:13px;color:var(--label);line-height:1.6">${esc(lastUpdate.remarks)}</div></div>`:''}
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

// ════════════════════════════════════════
// PROFILE
// ════════════════════════════════════════
function openModal(id){
  if(id==='modal-profile' && currentUser){
    paintProfile();
    profileView(); 
  }
  document.getElementById(id).classList.add('open');
}

function paintProfile(){
  const avClasses = {field_inspector:'av-amber',supervisor:'av-blue',field_worker:'av-green',administrator:'av-red'};
  const av = document.getElementById('prof-av');
  av.className = 'avatar '+avClasses[currentUser.role];
  av.style.cssText='width:64px;height:64px;font-size:24px';
  paintAvatar('prof-av', currentUser.name, currentUser.avatar_path);

  paintPhotoControls();

  const roleLabel = ROLE_LABEL[currentUser.role] || cap(currentUser.role);
  document.getElementById('prof-name').textContent = currentUser.name;
  document.getElementById('prof-role').textContent = roleLabel;

  setText('prof-view-email', currentUser.email);
  setText('prof-view-role',  roleLabel);
  setText('prof-view-team',  currentUser.teamName || '—');

  document.getElementById('prof-name-inp').value  = currentUser.name;
  document.getElementById('prof-email-inp').value = currentUser.email;
  document.getElementById('prof-curpass').value   = '';
  document.getElementById('prof-pass').value      = '';
}

// A photo change is staged here until Save Changes commits it:
//   undefined = no change    File = upload this    null = remove the current one
let _pendingAvatar = undefined;

// What the modal is showing right now, staged change included.
function hasPhotoNow(){
  if(_pendingAvatar === null) return false;
  if(_pendingAvatar) return true;
  return !!(currentUser && currentUser.avatar_path);
}

// Picking a file only previews it. Nothing reaches the server until save,
// which is what makes Cancel able to genuinely undo it.
function stageAvatar(input){
  const file = input.files && input.files[0];
  input.value = '';          // so re-picking the same file still fires onchange
  if(!file) return;

  // Mirrors the server checks so an obvious reject costs no round trip.
  if(!['image/jpeg','image/png','image/webp'].includes(file.type)){
    toast('Only JPG, PNG or WEBP images are accepted','error'); return;
  }
  if(file.size > 5 * 1024 * 1024){
    toast('Photo must be smaller than 5MB','error'); return;
  }

  _pendingAvatar = file;

  const reader = new FileReader();
  reader.onload = e => {
    const av = document.getElementById('prof-av');
    if(!av) return;
    av.textContent = '';
    av.style.backgroundImage    = 'url(' + JSON.stringify(e.target.result) + ')';
    av.style.backgroundSize     = 'cover';
    av.style.backgroundPosition = 'center';
    paintPhotoControls();
  };
  reader.readAsDataURL(file);
}

function stageAvatarRemove(){
  _pendingAvatar = null;
  paintAvatar('prof-av', currentUser.name, null);
  paintPhotoControls();
}

async function commitPendingAvatar(){
  if(_pendingAvatar === undefined) return true;

  if(_pendingAvatar === null){
    const res = await fetch('api/users.php?action=avatar_remove', {
      method:'POST', credentials:'include',
      headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
      body:'{}'
    }).then(r=>r.json()).catch(()=>({ success:false, message:'Cannot reach the server.' }));

    if(!res.success){ toast(res.message,'error'); return false; }
    currentUser.avatar_path = null;
  }else{
    const fd = new FormData();
    fd.append('avatar', _pendingAvatar);
    fd.append('csrf_token', window.CSRF_TOKEN || '');

    let data;
    try{
      const r = await fetch('api/users.php?action=avatar_upload',
        { method:'POST', credentials:'include', body: fd });
      data = await r.json();
    }catch(e){
      toast('Cannot reach the server. Check your connection and try again.','error');
      return false;
    }
    if(!data.success){ toast(data.message,'error'); return false; }
    currentUser.avatar_path = data.data.avatar_path;
  }

  _pendingAvatar = undefined;
  return true;
}

let _profEditing = false;

function paintPhotoControls(){
  const has   = hasPhotoNow();
  const badge = document.getElementById('prof-photo-btn');
  const rm    = document.getElementById('prof-photo-remove');
  const av    = document.getElementById('prof-av');

  if(badge) badge.style.display = _profEditing ? 'flex' : 'none';
  if(rm)    rm.style.display    = (_profEditing && has) ? '' : 'none';
  if(av)    av.style.cursor     = (!_profEditing && has) ? 'zoom-in' : 'default';
}

function viewAvatar(){
  if(_profEditing || !currentUser || !currentUser.avatar_path) return;
  const img = document.getElementById('avatar-full');
  if(!img) return;
  img.src = currentUser.avatar_path;
  openModal('modal-avatar');
}


function profileView(){
  document.getElementById('prof-view').style.display       = 'block';
  document.getElementById('prof-edit').style.display       = 'none';
  document.getElementById('prof-close-btn').style.display  = '';
  document.getElementById('prof-edit-btn').style.display   = '';
  document.getElementById('prof-cancel-btn').style.display = 'none';
  document.getElementById('prof-save-btn').style.display   = 'none';
  _profEditing   = false;
  _pendingAvatar = undefined;
  if(currentUser) paintAvatar('prof-av', currentUser.name, currentUser.avatar_path);
  paintPhotoControls();
}

function profileEdit(){
  document.getElementById('prof-view').style.display       = 'none';
  document.getElementById('prof-edit').style.display       = 'block';
  document.getElementById('prof-close-btn').style.display  = 'none';
  document.getElementById('prof-edit-btn').style.display   = 'none';
  document.getElementById('prof-cancel-btn').style.display = '';
  document.getElementById('prof-save-btn').style.display   = '';
  _profEditing = true;
  paintPhotoControls();
}
async function saveProfile(){
  if(!currentUser) return;

  const newName  = document.getElementById('prof-name-inp').value.trim();
  const currPass = document.getElementById('prof-curpass').value.trim();
  const newPass  = document.getElementById('prof-pass').value.trim();

  if(!newName){ toast('Name cannot be empty','error'); return; }

  if(newName !== currentUser.name){
    const nameRes  = await fetch('api/users.php?action=self_edit', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
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
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
      body: JSON.stringify({ current_password: currPass, new_password: newPass })
    });
    const passData = await passRes.json();
    if(!passData.success){
      toast(passData.message || 'Failed to update password', 'error');
      return;
    }
  }

  const photoOk = await commitPendingAvatar();
  if(!photoOk) return;

  toast('Profile updated successfully','success');
  paintProfile();
  repaintMyAvatars();
  profileView();
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
    <td class="id">${esc(r.report_code)}</td>
    <td class="issue">${esc(r.title)}</td>
    <td>${esc(r.location_text)}</td>
    <td><span class="badge badge-${r.severity}">${cap(r.severity)}</span></td>
    <td>${fmtDate(r.submitted_at)}</td>
    <td>${statusBadge(r.status)}</td>
    <td><button class="action-link" onclick="openReportDetail('${r.report_id}')">Details</button></td>
  </tr>`).join('');
  if(cards) cards.innerHTML = reports.map(r=>`
    <div class="m-card">
      <div class="m-card-top"><div><div class="m-card-title">${esc(r.title)}</div><div class="m-card-id">${esc(r.report_code)} • ${fmtDate(r.submitted_at)}</div></div><span class="badge badge-${r.severity}">${cap(r.severity)}</span></div>
      <div class="m-card-loc">📍 ${esc(r.location_text)}</div>
      <div class="m-card-footer">${statusBadge(r.status)}<button class="action-link" onclick="openReportDetail('${r.report_id}')">Details</button></div>
    </div>`).join('');
}

// ════════════════════════════════════════
// UTILITIES
// ════════════════════════════════════════
function $(id){ return document.getElementById(id.replace(/^#/,'')); }

function setText(id,v){ const e=document.getElementById(id); if(e) e.textContent=v; }

// ── AVATARS ──────────────────────────────────────────────
function paintAvatar(id, name, avatarPath){
  const el = document.getElementById(id);
  if(!el) return;
  if(avatarPath){
    el.textContent = '';
    el.style.backgroundImage    = 'url(' + JSON.stringify(avatarPath) + ')';
    el.style.backgroundSize     = 'cover';
    el.style.backgroundPosition = 'center';
  }else{
    el.style.backgroundImage = '';
    el.textContent = (name || '?').charAt(0).toUpperCase();
  }
}

function avatarHtml(name, avatarPath, cls, size, fontSize){
  const base = `class="avatar ${cls}" style="width:${size}px;height:${size}px;font-size:${fontSize}px`;
  return avatarPath
    ? `<div ${base};background-image:url('${esc(avatarPath)}');background-size:cover;background-position:center"></div>`
    : `<div ${base}">${esc((name||'?').charAt(0).toUpperCase())}</div>`;
}

function repaintMyAvatars(){
  ['adm','sup','insp','fw'].forEach(p => {
    paintAvatar(p+'-av',    currentUser.name, currentUser.avatar_path);
    paintAvatar(p+'-topav', currentUser.name, currentUser.avatar_path);
  });
}

function esc(v){
  return String(v ?? '').replace(/[&<>"']/g, c => (
    {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]
  ));
}
function cap(s){ return s?s.charAt(0).toUpperCase()+s.slice(1):'' }
function fmtDate(iso){ if(!iso) return '-'; const d=new Date(iso); return d.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
function timeAgo(iso){
  const d=new Date(iso);const now=new Date();const diff=Math.floor((now-d)/1000);
  if(diff<60) return 'just now';if(diff<3600) return Math.floor(diff/60)+'m ago';
  if(diff<86400) return Math.floor(diff/3600)+'h ago';return Math.floor(diff/86400)+'d ago';
}
function statusBadge(s){
  const m={pending:'badge-pending pending',assigned:'badge-assigned assigned',in_progress:'badge-inprogress',completed:'badge-completed completed',rejected:'badge-pending'};
  const l={pending:'Pending',assigned:'Assigned',in_progress:'In Progress',completed:'Completed',rejected:'Rejected'};
  return `<span class="badge ${m[s]||'badge-pending'}">${l[s]||cap(s)}</span>`;
}
function woStatusBadge(s){
  const m={'pending':'badge-pending','in_progress':'badge-inprogress','on_hold':'badge-pending','completed':'badge-completed'};
  const l={'pending':'Pending','in_progress':'In Progress','on_hold':'On Hold','completed':'Completed'};
  return `<span class="badge ${m[s]||'badge-pending'}">${l[s]||cap(s)}</span>`;
}
function toast(msg, type='success'){
  const c = document.getElementById('toasts');
  const icons={success:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>',error:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',info:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'};
  const t = document.createElement('div');
  t.className=`toast toast-${type}`;t.innerHTML=icons[type]+esc(msg);c.appendChild(t);
  setTimeout(()=>t.remove(),3500);
}

// show password
function initPasswordToggles(){
  document.querySelectorAll('input[type="password"]').forEach(inp => {
    if(inp.parentElement.classList.contains('pw-wrap')) return;   // already done

    const wrap = document.createElement('div');
    wrap.className = 'pw-wrap';
    inp.parentNode.insertBefore(wrap, inp);
    wrap.appendChild(inp);

    const eye    = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    const eyeOff = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-toggle';
    btn.setAttribute('aria-label', 'Show password');
    btn.innerHTML = eye;

    btn.addEventListener('click', () => {
      const showing = inp.type === 'text';
      inp.type = showing ? 'password' : 'text';
      btn.innerHTML = showing ? eye : eyeOff;
      btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
      inp.focus();
    });

    wrap.appendChild(btn);
  });
}
// ════════════════════════════════════════
// PAGE INIT
// Each HTML file sets <body data-page="..."> so this one shared
// script knows which role guard/render to run on load.
// ════════════════════════════════════════
const currentPageRole = document.body ? document.body.dataset.page : null;
if(currentPageRole && PAGE_ROLE[currentPageRole]) initRolePage(currentPageRole);

// Close modals on overlay click
document.querySelectorAll('.overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o) closeModal(o.id)}));

// PWA
if('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(()=>{});
