
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
  const viewMap   = {dashboard:'dashboard',reports:'reports',workorders:'workorders',history:'history',logs:'logs',tasks:'tasks',verify:'verify'};
  const prefix = prefixMap[screen] || screen;
  const vid    = prefix + '-' + (viewMap[view] || view);

  // Switch view
  s.querySelectorAll('.view').forEach(v=>v.classList.remove('active'));
  const el = document.getElementById(vid);
  if(el) el.classList.add('active');

  // Switch nav highlight
  s.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  const navSuffix = {dashboard:'dash',reports:'rep',workorders:'wo',history:'hist',logs:'logs',tasks:'tasks',verify:'verify'};
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

// The two filtered lists remember their filter, so refreshing after a form
// submit redraws what the user is actually looking at rather than resetting
// them to "All" with the filter button still highlighted.
let _supRepFilter = 'all';
let _inspRepSev   = 'all';

// A form just succeeded and the list behind it is stale. switchView already
// re-renders on tab change, so only the view on screen needs redrawing.
function refreshActiveView(){
  const screen = document.querySelector('.screen.active');
  const view   = screen && screen.querySelector('.view.active');
  if(!screen || !view) return;
  refreshView(screen.id, view.id.split('-').slice(1).join('-'));
}

// ── WORKLOAD ──────────────────────────────────────────────
// Who did how much, ranked. Two tables, because inspectors and workers do
// different jobs and sharing a leaderboard between them would be
// meaningless. Weighted ranking is the default; the plain count is one
// click away, because the weights are a judgement and the raw number is a
// fact and both are worth seeing.
//
// Rendered from a prefix so the admin hub and the supervisor's own view
// share one implementation: 'ar' and 'sw'.
const WORKLOAD_MODE = {};        // prefix -> 'weighted' | 'count'
const WORKLOAD_DATA = {};        // prefix -> last payload

/**
 * autoLoad is false only while a screen is setting its own default, which
 * loads once on its own afterwards; every other call is someone choosing a
 * period, and a choice should show its result without a second click.
 */
function wlRangePreset(prefix, v, autoLoad = true){
  const from  = document.getElementById(prefix + '-wl-from');
  const to    = document.getElementById(prefix + '-wl-to');
  const dates = document.getElementById(prefix + '-wl-dates');
  const now   = new Date();
  // The From/To pair belongs to "Custom" alone. Beside a preset it read as a
  // second control for the same decision.
  if(dates) dates.classList.toggle('fb-hide', v !== 'custom');
  if(v === 'custom') return;
  if(v === 'all'){ from.value = ''; to.value = ''; }
  else if(v === 'month'){
    from.value = _ymd(new Date(now.getFullYear(), now.getMonth(), 1));
    to.value   = _ymd(now);
  } else {
    const back = new Date(now); back.setDate(back.getDate() - (parseInt(v,10) || 30));
    from.value = _ymd(back);
    to.value   = _ymd(now);
  }
  if(autoLoad) wlLoad(prefix);
}

function wlSetMode(prefix, mode){
  WORKLOAD_MODE[prefix] = mode;
  ['weighted','count'].forEach(k => {
    const b = document.getElementById(`${prefix}-wl-mode-${k}`);
    if(b) b.classList.toggle('active', k === mode);
  });
  if(WORKLOAD_DATA[prefix]) wlPaint(prefix, WORKLOAD_DATA[prefix]);
}

async function wlLoad(prefix){
  const q = new URLSearchParams({ action: 'workload' });
  const from = document.getElementById(prefix + '-wl-from').value;
  const to   = document.getElementById(prefix + '-wl-to').value;
  if(from) q.set('from', from);
  if(to)   q.set('to', to);

  const res  = await fetch('api/summary.php?' + q.toString(), { credentials:'include' });
  const data = await res.json();
  if(!data.success){ toast(data.message || 'Could not load the workload','error'); return; }

  WORKLOAD_DATA[prefix] = data.data;
  wlPaint(prefix, data.data);
}

function wlTable(rows, mode, opts){
  if(!rows.length){
    return `<div class="empty-state"><p>${esc(opts.empty)}</p></div>`;
  }
  // Ranked by whichever measure is on screen, so the numbering always
  // matches the column people are reading.
  const sorted = mode === 'count'
    ? [...rows].sort((a,b) => b.done - a.done || b.score - a.score || a.name.localeCompare(b.name))
    : rows;

  return `<div class="table-wrap"><table>
    <thead><tr>
      <th style="width:34px">#</th><th>Name</th>
      <th style="width:92px">${mode === 'count' ? 'Completed' : 'Score'}</th>
      <th style="width:78px">${mode === 'count' ? 'Score' : 'Completed'}</th>
      <th style="width:42px">L</th><th style="width:42px">M</th>
      <th style="width:42px">H</th><th style="width:42px">C</th>
      ${opts.lead ? '<th style="width:60px">As lead</th>' : '<th style="width:70px">Verified</th>'}
    </tr></thead>
    <tbody>${sorted.map((r,i) => `<tr>
      <td style="color:var(--muted)">${i+1}</td>
      <td>${esc(r.name)}</td>
      <td><strong>${esc(mode === 'count' ? r.done : r.score)}</strong></td>
      <td style="color:var(--muted)">${esc(mode === 'count' ? r.score : r.done)}</td>
      <td>${r.low      || '<span style="color:var(--muted)">·</span>'}</td>
      <td>${r.moderate || '<span style="color:var(--muted)">·</span>'}</td>
      <td>${r.high     || '<span style="color:var(--muted)">·</span>'}</td>
      <td>${r.critical ? `<strong style="color:var(--red)">${r.critical}</strong>` : '<span style="color:var(--muted)">·</span>'}</td>
      <td style="color:var(--muted)">${esc(opts.lead ? r.lead : (r.verified ?? 0))}</td>
    </tr>`).join('')}</tbody></table></div>
    <div class="mobile-card-list"><div class="widget wl-cards">${sorted.map((r,i) => `
      <div class="wl-card">
        <div class="wl-top">
          <span class="wl-rank">${i+1}</span>
          <span class="wl-name">${esc(r.name)}</span>
          <span class="wl-score"><strong>${esc(mode === 'count' ? r.done : r.score)}</strong> ${mode === 'count' ? 'completed' : 'pts'}</span>
        </div>
        <div class="wl-meta">
          <span>${mode === 'count' ? esc(r.score) + ' pts' : esc(r.done) + ' completed'}</span>
          <span>L ${esc(r.low || 0)} · M ${esc(r.moderate || 0)} · H ${esc(r.high || 0)} · C ${esc(r.critical || 0)}</span>
          <span>${opts.lead ? esc(r.lead) + ' as lead' : esc(r.verified ?? 0) + ' verified'}</span>
        </div>
      </div>`).join('')}</div></div>`;
}

function wlPaint(prefix, d){
  const mode = WORKLOAD_MODE[prefix] || 'weighted';
  const w = d.weights;

  document.getElementById(prefix + '-wl-body').innerHTML = `
    <div class="section-header wl-head">
      <div class="section-title">Field Inspectors${infoIcon(`<strong>How the score works</strong><span class="info-formula">Score = (Low × ${esc(w.low)}) + (Moderate × ${esc(w.moderate)}) + (High × ${esc(w.high)}) + (Critical × ${esc(w.critical)})</span>Example: 2 Low + 1 Critical = ${esc(2 * w.low)} + ${esc(w.critical)} = <strong>${esc(2 * w.low + Number(w.critical))} points</strong><br>Field workers score from closed work orders; inspectors from submitted reports.`, 'How the score works')}</div>
      <div style="font-size:12px;color:var(--muted)">
        ${esc(d.totals.inspector_reports)} reports submitted · ${esc(d.totals.inspector_points)} points
      </div>
    </div>
    ${wlTable(d.inspectors, mode, { lead:false, empty:'No reports submitted in this range.' })}

    <div class="section-header wl-head" style="margin-top:22px">
      <div class="section-title">Field Workers</div>
      <div style="font-size:12px;color:var(--muted)">
        ${esc(d.totals.worker_orders)} work orders closed · ${esc(d.totals.worker_assignments)} assignments · ${esc(d.totals.worker_points)} points
      </div>
    </div>
    ${wlTable(d.workers, mode, { lead:true, empty:'No work orders closed in this range.' })}

    `;
}

function wlPrint(prefix){
  const q = new URLSearchParams({ type:'workload' });
  const from = document.getElementById(prefix + '-wl-from').value;
  const to   = document.getElementById(prefix + '-wl-to').value;
  if(from) q.set('from', from);
  if(to)   q.set('to', to);
  q.set('mode', WORKLOAD_MODE[prefix] || 'weighted');
  window.open('print.html?' + q.toString(), '_blank', 'noopener');
}

function wlInit(prefix){
  const from = document.getElementById(prefix + '-wl-from');
  if(!from) return;
  if(!from.dataset.touched){
    from.dataset.touched = '1';
    wlRangePreset(prefix, '30', false);
    WORKLOAD_MODE[prefix] = 'weighted';
  }
  wlSetMode(prefix, WORKLOAD_MODE[prefix] || 'weighted');
  wlLoad(prefix);
}

function renderSupWorkload(){ wlInit('sw'); }

// ── ADMIN REPORTS & EXPORTS ───────────────────────────────
// Two summaries behind one set of filters (Workload has its own page). Inspection reports and work
// orders are summaries of what the team found and fixed; the activity log
// is the audit trail and stays a CSV, because it answers a different
// question and spreadsheets are what people do with audit data.
let _admSummaryKind = 'reports';
let _admSummaryData = null;

/** Local YYYY-MM-DD. toISOString() would shift to UTC and lose a day. */
function _ymd(d){
  return new Date(d.getTime() - d.getTimezoneOffset()*60000).toISOString().slice(0,10);
}

function admRangePreset(v, autoLoad = true){
  const from  = document.getElementById('ar-from');
  const to    = document.getElementById('ar-to');
  const dates = document.getElementById('ar-dates');
  const now   = new Date();

  if(dates) dates.classList.toggle('fb-hide', v !== 'custom');
  if(v === 'custom') return;

  if(v === 'all'){ from.value = ''; to.value = ''; }
  else if(v === 'month'){
    from.value = _ymd(new Date(now.getFullYear(), now.getMonth(), 1));
    to.value   = _ymd(now);
  } else {
    const days = parseInt(v, 10) || 30;
    const back = new Date(now); back.setDate(back.getDate() - days);
    from.value = _ymd(back);
    to.value   = _ymd(now);
  }
  if(autoLoad) admLoadSummary();
}

function admSwitchSummary(kind){
  _admSummaryKind = kind;
  ['reports','workorders'].forEach(k => {
    const b = document.getElementById('ar-tab-' + k);
    if(b) b.classList.toggle('active', k === kind);
  });
  admLoadSummary();
}

function admFilterQuery(){
  const q = new URLSearchParams();
  const from = document.getElementById('ar-from').value;
  const to   = document.getElementById('ar-to').value;
  const sev  = document.getElementById('ar-sev').value;
  if(from) q.set('from', from);
  if(to)   q.set('to', to);
  if(sev)  q.set('severity', sev);
  return q;
}

async function admLoadSummary(){
  const q = admFilterQuery();
  q.set('action', _admSummaryKind);
  const res  = await fetch('api/summary.php?' + q.toString(), { credentials:'include' });
  const data = await res.json();
  if(!data.success){ toast(data.message || 'Could not load the summary','error'); return; }

  _admSummaryData = data.data;
  admRenderSummary(data.data);
}

function admRenderSummary(d){
  const t = d.totals;
  const isReports = d.kind === 'reports';

  document.getElementById('ar-table-title').textContent =
    isReports ? 'Inspection Reports' : 'Work Orders';

  const stat = (label, value, tone) =>
    `<div class="stat-cell"><div class="stat-label">${esc(label)}</div>
       <div class="stat-value ${tone || ''}">${esc(value)}</div></div>`;

  document.getElementById('ar-stats').innerHTML = isReports
    ? stat('Reports', t.count) +
      stat('High / Critical', (t.severity.high + t.severity.critical), 'red') +
      stat('Resolved', t.status.completed, 'blue') +
      stat('Severity overridden', t.overridden, 'amber')
    : stat('Work orders', t.count) +
      stat('Closed', t.closed, 'blue') +
      stat('Open', t.open, 'amber') +
      stat('Past deadline', t.overdue, 'red');

  const head = isReports
    ? ['ID','Issue','Inspector','Engine','Final','Status','Submitted']
    : ['WO #','From report','Crew','Priority','Status','Raised','Days open'];
  document.getElementById('ar-head').innerHTML =
    '<tr>' + head.map(h => `<th>${esc(h)}</th>`).join('') + '</tr>';

  const rows = d.rows || [];
  if(!rows.length){
    document.getElementById('ar-body').innerHTML =
      `<tr><td colspan="${head.length}" style="text-align:center;color:var(--muted);padding:26px">
         Nothing recorded in this range.</td></tr>`;
    document.getElementById('ar-cards').innerHTML = '';
    return;
  }

  document.getElementById('ar-body').innerHTML = rows.map(r => isReports
    ? `<tr>
        <td>${esc(r.report_code)}</td>
        <td>${esc(r.title)}</td>
        <td>${esc(r.submitted_by_name)}</td>
        <td>${r.recommended_severity
              ? `<span class="badge badge-${esc(r.recommended_severity)}">${cap(r.recommended_severity)}</span>
                 ${r.classification_confidence != null ? `<span style="color:var(--muted);font-size:11px"> ${esc(r.classification_confidence)}%</span>` : ''}`
              : '<span style="color:var(--muted)">—</span>'}</td>
        <td><span class="badge badge-${esc(r.severity)}">${cap(r.severity)}</span></td>
        <td>${statusBadge(r.status)}</td>
        <td style="white-space:nowrap">${fmtDate(r.submitted_at)}</td>
      </tr>`
    : `<tr>
        <td>${esc(r.wo_code)}</td>
        <td>${esc(r.report_code)} — ${esc(r.report_title)}</td>
        <td>${esc(r.crew_names || r.assigned_to_name || '—')}</td>
        <td><span class="badge badge-${esc(r.severity)}">${cap(r.severity)}</span></td>
        <td>${esc(cap(String(r.status).replace(/_/g,' ')))}</td>
        <td style="white-space:nowrap">${fmtDate(r.created_at)}</td>
        <td>${r.days_open === null ? '<span style="color:var(--muted)">open</span>' : esc(r.days_open)}</td>
      </tr>`).join('');

  document.getElementById('ar-cards').innerHTML = rows.map(r => isReports
    ? `<div class="m-card">
         <div class="m-card-top"><div><div class="m-card-title">${esc(r.title)}</div>
           <div class="m-card-id">${esc(r.report_code)} • ${esc(r.submitted_by_name)}</div></div>
           <span class="badge badge-${esc(r.severity)}">${cap(r.severity)}</span></div>
         <div class="m-card-id">${fmtDate(r.submitted_at)}</div>
       </div>`
    : `<div class="m-card">
         <div class="m-card-top"><div><div class="m-card-title">${esc(r.report_title)}</div>
           <div class="m-card-id">${esc(r.wo_code)} → ${esc(r.crew_names || r.assigned_to_name || '—')}</div></div>
           <span class="badge badge-${esc(r.severity)}">${cap(r.severity)}</span></div>
         <div class="m-card-id">${fmtDate(r.created_at)} • ${r.days_open === null ? 'open' : r.days_open + ' days'}</div>
       </div>`).join('');
}

/** Opens the same filters as a printable sheet. */
function admPrintSummary(){
  const q = admFilterQuery();
  q.set('type', 'admin');
  q.set('kind', _admSummaryKind);
  window.open('print.html?' + q.toString(), '_blank', 'noopener');
}

function renderAdmReports(){
  // First visit lands on the default range rather than an empty screen.
  const from = document.getElementById('ar-from');
  if(from && !from.value && !from.dataset.touched){
    from.dataset.touched = '1';
    admRangePreset('30', false);
  }
  admSwitchSummary(_admSummaryKind);
}

function refreshView(screen,view){
  if(screen==='inspector'){
    if(view==='dashboard') renderInspDash();
    if(view==='reports') renderInspReports(_inspRepSev);
    if(view==='verify')  renderVerifyQueue('inspector');
  } else if(screen==='supervisor'){
    if(view==='dashboard') renderSupDash();
    if(view==='reports') renderSupReports(_supRepFilter);
    if(view==='workorders') renderSupWO();
    if(view==='tasks') renderSupTasks();
    if(view==='verify') renderVerifyQueue('supervisor');
    if(view==='workload') renderSupWorkload();
  } else if(screen==='fieldworker'){
    if(view==='dashboard') renderFWQueue();
    if(view==='history') renderFWHistory();
  } else if(screen==='admin'){
    if(view==='dashboard') renderAdmDash();
    if(view==='members')   renderAdmMembers();
    if(view==='reports')   renderAdmReports();
    if(view==='workload')  wlInit('ar');
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
  // "Recent" means newest first. The API orders by severity, so slicing it
  // straight would show the ten most severe, not the ten most recent — a
  // September critical would outrank anything filed today.
  const recent = [...reports].sort((a,b)=> new Date(b.submitted_at) - new Date(a.submitted_at));
  renderReportTable('insp-dash-table','insp-dash-cards', recent.slice(0,10), 'inspector');
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
  el.innerHTML = lateFirst(tasks, taskOverdue).map(t=>`
    <div class="queue-card">
      <div class="report-card-meta"><span style="font-size:12px;color:var(--muted)">${esc(t.task_code)}</span>${taskOverdue(t) ? overdueTag(t.due_date) : ''}</div>
      <h4>${esc(t.title.toUpperCase())}</h4>
      <p>${esc(t.description || 'No additional instructions provided.')}</p>
      <div class="queue-card-foot">
        <span class="report-card-loc" style="font-size:12px;color:var(--muted)">📍 ${esc(t.location_text)}</span>
        ${t.due_date?`<span style="font-size:11.5px;color:var(--muted)">📅 Due ${fmtDay(t.due_date)}</span>`:''}
        <button class="btn btn-amber btn-sm" onclick="openSubmitFromTask(${t.task_id})">Perform Inspection ›</button>
      </div>
    </div>`).join('');
}

function openSubmitFromTask(taskId){
  boundTaskId = taskId;
  document.getElementById('s-title').value = '';
  document.getElementById('s-desc').value  = '';
  document.getElementById('s-loc').value   = '';
  document.getElementById('s-phase').value = 'construction';
  document.getElementById('s-gps-status').textContent = '';
  document.getElementById('s-address').value = '';
  resetAddressCombo('s-address');
  initAddressCombo('s-address', codes => { gpsCodes = codes; });
  gpsLat = null; gpsLng = null; gpsSource = null;
  gpsCodes = { province_code:null, city_code:null, brgy_code:null };
  watchManualCoords();
  document.getElementById('photo-preview').innerHTML = '';
  window.selectedReportPhotos = [];
  openModal('modal-submit');
  
}

async function renderInspReports(sev='all'){
  _inspRepSev = sev;
  const reports = await myReports();
  const filtered = sev==='all'?reports:reports.filter(r=>r.severity===sev);
  // The callback replays the active severity filter, so changing the sort
  // never silently snaps the filter back to All.
  syncSortBar('insp-rep', sortAnchor('insp-rep-table'), true, filtered.length,
              ()=>renderInspReports(sev));
  renderReportTable('insp-rep-table','insp-rep-cards',
    applySort('insp-rep', filtered, 'submitted_at', true), 'inspector');
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
  // No reverse. api/reports.php already returns these ordered
  // critical -> low; reversing it put the worst finding at the bottom
  // of the one panel whose job is deciding what to deal with first.
  el.innerHTML = pending.slice(0,5).map(r=>`
    <div class="report-card">
      <div class="report-card-top">
        <div class="report-card-meta"><span class="badge badge-${r.severity}">${cap(r.severity)}</span><span style="font-size:12px;color:var(--muted)">${r.report_code}</span></div>
        <span class="report-time">${timeAgo(r.submitted_at)}</span>
      </div>
      <h4>${esc(r.title.toUpperCase())}</h4>
      <p>${esc(r.description)}</p>
      <div class="report-card-footer">
        <span class="report-card-loc"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>${esc(r.address_text || r.task_location || r.location_text)}</span>
        <button class="btn-assign" onclick="openAssign('${r.report_id}')">＋ Assign</button>
      </div>
    </div>`).join('');
}
async function renderSupReports(filter='all'){
  _supRepFilter = filter;
  const res  = await fetch('api/reports.php?action=list', { credentials:'include' });
  const data = await res.json();
  const reports = data.success ? data.data.reports : [];
  const unsorted = filter==='all' ? reports : reports.filter(r=>r.status===filter);
  syncSortBar('sup-rep', sortAnchor('sup-rep-table'), true, unsorted.length,
              ()=>renderSupReports(filter));
  const filtered = applySort('sup-rep', unsorted, 'submitted_at', true);

  const tbody = document.getElementById('sup-rep-table');
  const cards = document.getElementById('sup-rep-cards');
  if(!filtered.length){
    tbody.innerHTML=`<tr><td colspan="6"><div class="empty-state"><p>No reports found</p></div></td></tr>`;
    cards.innerHTML=`<div class="empty-state"><p>No reports found</p></div>`;
    return;
  }
  // A pending report is the one a supervisor can still raise a work order
  // from, so that is the action its detail offers.
  const open = r => `openReportDetail('${r.report_id}'${r.status==='pending' ? ",'assign'" : ''})`;
  tbody.innerHTML = filtered.map(r=>`<tr ${rowOpen(open(r))}>
    <td class="id">${esc(r.report_code)}</td>
    <td class="issue">${esc(r.title)}</td>
    <td>${esc(r.submitted_by_name)}</td>
    <td><span class="badge badge-${r.severity}">${cap(r.severity)}</span></td>
    <td>${fmtDate(r.submitted_at)}</td>
    <td>${statusBadge(r.status)}</td>
  </tr>`).join('');
  cards.innerHTML = filtered.map(r=>`
    <div ${rowOpen(open(r), 'm-card')}>
      <div class="m-card-top"><div><div class="m-card-title">${esc(r.title)}</div><div class="m-card-id">${esc(r.report_code)} • ${esc(r.submitted_by_name)}</div></div><span class="badge badge-${r.severity}">${cap(r.severity)}</span></div>
      <div class="m-card-loc">📍 ${esc(r.address_text || r.task_location || r.location_text)}</div>
      <div class="m-card-footer">${statusBadge(r.status)}</div>
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
  const all = data.success ? data.data.work_orders : [];
  syncSortBar('sup-wo', sortAnchor('sup-wo-table'), true, all.length, renderSupWO);
  const wos = lateFirst(applySort('sup-wo', all, 'created_at', true), woOverdue);

  const tbody = document.getElementById('sup-wo-table');
  const cards = document.getElementById('sup-wo-cards');
  if(!wos.length){
    tbody.innerHTML=`<tr><td colspan="6"><div class="empty-state"><p>No work orders yet</p></div></td></tr>`;
    cards.innerHTML='';return;
  }
  // Crew can still be changed until the job is finished.
  const open = w => `openWODetail('${w.wo_id}'${w.status!=='completed' ? ",'crew'" : ''})`;
  tbody.innerHTML = wos.map(w=>`<tr ${rowOpen(open(w))}>
    <td class="id">${esc(w.wo_code)}</td>
    <td class="issue">${esc(w.report_title)}</td>
    <td>${esc(w.assigned_to_name)}</td>
    <td><span class="badge badge-${w.severity}">${cap(w.severity)}</span></td>
    <td>${fmtDate(w.created_at)}</td>
    <td>${woStatusBadge(w.status)}${woOverdue(w) ? overdueTag(w.deadline) : ''}</td>
  </tr>`).join('');
  cards.innerHTML = wos.map(w=>`
    <div ${rowOpen(open(w), 'm-card')}>
      <div class="m-card-top"><div><div class="m-card-title">${esc(w.report_title)}</div><div class="m-card-id">${esc(w.wo_code)} → ${esc(w.assigned_to_name)}</div></div><span class="badge badge-${w.severity}">${cap(w.severity)}</span></div>
      <div class="m-card-loc">📍 ${esc(w.address_text || w.task_location || w.location_text)}</div>
      <div class="m-card-footer">${woStatusBadge(w.status)}${woOverdue(w) ? overdueTag(w.deadline) : ''}</div>
    </div>`).join('');
}

async function renderSupTasks(){
  const res  = await fetch('api/tasks.php?action=list', { credentials:'include' });
  const data = await res.json();
  // InspectionTasks has no severity column, so this list is date only and the
  // dropdown shows a single option.
  const all = data.success ? data.data.tasks : [];
  syncSortBar('sup-tasks', sortAnchor('sup-tasks-table'), false, all.length, renderSupTasks);
  const tasks = lateFirst(applySort('sup-tasks', all, 'created_at', false), taskOverdue);
  _supTasks = Object.fromEntries(all.map(t => [String(t.task_id), t]));

  const statusBadgeTask = s => ({
    assigned:  '<span class="badge badge-pending">Assigned</span>',
    submitted: '<span class="badge badge-amber">Awaiting Review</span>',
    closed:    '<span class="badge badge-green">Closed</span>',
    cancelled: '<span class="badge badge-cancelled">Cancelled</span>'
  }[s] || s);

  const tbody = document.getElementById('sup-tasks-table');
  const cards = document.getElementById('sup-tasks-cards');
  if(!tasks.length){
    tbody.innerHTML=`<tr><td colspan="6"><div class="empty-state"><p>No inspection tasks created yet</p></div></td></tr>`;
    cards.innerHTML=`<div class="empty-state"><p>No inspection tasks created yet</p></div>`;
    return;
  }
  // A task opens the report it produced. One that has not been inspected yet
  // has no report to show; its row opens Manage Task — edit, hand it to
  // another inspector, or cancel it. A task still sitting at "submitted"
  // never became a work order, which is the only case where closing it by
  // hand is still needed.
  const open = (t, cls = '') => {
    if(!t.report_id){
      return t.status === 'assigned'
        ? rowOpen(`openManageTask('${t.task_id}')`, cls)
        : (cls ? `class="${cls}"` : '');
    }
    const extra = t.status==='submitted' ? `,'close-task','${t.task_id}'` : '';
    return rowOpen(`openReportDetail('${t.report_id}'${extra})`, cls);
  };
  tbody.innerHTML = tasks.map(t=>`<tr ${open(t)}>
    <td class="id">${esc(t.task_code)}</td>
    <td class="issue">${esc(t.title)}</td>
    <td>${esc(t.assigned_to_name)}</td>
    <td>${esc(t.location_text)}</td>
    <td>${statusBadgeTask(t.status)}${taskOverdue(t) ? overdueTag(t.due_date) : ''}</td>
    <td>${t.due_date ? fmtDay(t.due_date) : '—'}</td>
  </tr>`).join('');
  cards.innerHTML = tasks.map(t=>`
    <div ${open(t, 'm-card')}>
      <div class="m-card-top"><div><div class="m-card-title">${esc(t.title)}</div><div class="m-card-id">${esc(t.task_code)} → ${esc(t.assigned_to_name)}</div></div>${statusBadgeTask(t.status)}</div>
      <div class="m-card-loc">📍 ${esc(t.location_text)}</div>
      <div class="m-card-footer">${t.due_date?`Due ${fmtDate(t.due_date)}`:''}</div>
    </div>`).join('');
}

// ── MANAGE A TASK (supervisor) ───────────────────────────
// A task nobody has inspected yet can still be corrected, handed to another
// inspector, or cancelled. Once a report exists it stays as filed.
let _supTasks = {};

function openManageTask(taskId){
  const t = _supTasks[String(taskId)];
  if(!t) return;
  document.getElementById('mt-id').value = t.task_id;
  setText('mt-sub', `${t.task_code} · assigned to ${t.assigned_to_name}`);
  setText('mt-v-title', t.title || '—');
  setText('mt-v-loc',   t.location_text || '—');
  setText('mt-v-due',   t.due_date ? fmtDay(t.due_date) : 'None set');
  setText('mt-v-insp',  t.assigned_to_name || '—');
  setText('mt-v-desc',  t.description || 'No instructions given.');
  manageTaskMode('view');
  openModal('modal-manage-task');
}

// Read-only until Edit. Going back to the summary discards anything typed,
// so the form always starts from the task as saved.
function manageTaskMode(mode){
  const edit = mode === 'edit';
  const t    = _supTasks[document.getElementById('mt-id').value];
  if(t){
    document.getElementById('mt-title').value  = t.title || '';
    document.getElementById('mt-desc').value   = t.description || '';
    document.getElementById('mt-loc').value    = t.location_text || '';
    document.getElementById('mt-due').value    = t.due_date ? String(t.due_date).slice(0, 10) : '';
    document.getElementById('mt-reason').value = '';
  }
  document.getElementById('mt-view').style.display = edit ? 'none' : 'block';
  document.getElementById('mt-edit').style.display = edit ? 'block' : 'none';
  ['mt-close-btn', 'mt-edit-btn'].forEach(id => document.getElementById(id).style.display = edit ? 'none' : '');
  ['mt-reassign-btn', 'mt-back-btn', 'mt-save-btn'].forEach(id => document.getElementById(id).style.display = edit ? '' : 'none');
  if(edit) document.getElementById('mt-title').focus();
}

async function saveManagedTask(){
  const id    = document.getElementById('mt-id').value;
  const title = document.getElementById('mt-title').value.trim();
  const loc   = document.getElementById('mt-loc').value.trim();
  if(!title || !loc){ toast('Title and location are required', 'error'); return; }
  const res = await admApi('tasks', 'update', {
    task_id: Number(id), title, location_text: loc,
    description: document.getElementById('mt-desc').value.trim(),
    due_date:    document.getElementById('mt-due').value
  });
  if(!res.success){ toast(res.message || 'Could not save the task', 'error'); return; }
  closeModal('modal-manage-task');
  toast(res.message, 'success');
  refreshActiveView();
}

async function cancelManagedTask(){
  const id     = document.getElementById('mt-id').value;
  const reason = document.getElementById('mt-reason').value.trim();
  if(!reason){
    toast('Say why the task is being cancelled', 'error');
    document.getElementById('mt-reason').focus();
    return;
  }
  if(!confirm('Cancel this task? The inspector will no longer see it.')) return;
  const res = await admApi('tasks', 'cancel', { task_id: Number(id), reason });
  if(!res.success){ toast(res.message || 'Could not cancel the task', 'error'); return; }
  closeModal('modal-manage-task');
  toast(res.message, 'success');
  refreshActiveView();
}

function reassignManagedTask(){
  const t = _supTasks[document.getElementById('mt-id').value];
  if(!t) return;
  closeModal('modal-manage-task');
  openPickInspector('task', t.task_id, t.assigned_to, t.task_code);
}

// ── EDIT A WORK ORDER (supervisor) ───────────────────────
// Instructions and deadline only: severity sets the crew limit and the crew
// has its own dialog. Reads the work order the detail view just loaded.
let _woDetail = null;

function openEditWO(){
  const w = _woDetail;
  if(!w) return;
  document.getElementById('ew-id').value           = w.wo_id;
  setText('ew-sub', `${w.wo_code} · ${w.report_title}`);
  document.getElementById('ew-instructions').value = w.instructions || '';
  document.getElementById('ew-deadline').value     = w.deadline ? String(w.deadline).slice(0, 10) : '';
  openModal('modal-edit-wo');
}

async function saveEditWO(){
  const res = await admApi('workorders', 'update_details', {
    wo_id:        Number(document.getElementById('ew-id').value),
    instructions: document.getElementById('ew-instructions').value.trim(),
    deadline:     document.getElementById('ew-deadline').value
  });
  if(!res.success){ toast(res.message || 'Could not save the work order', 'error'); return; }
  closeModal('modal-edit-wo');
  toast(res.message, 'success');
  refreshActiveView();
}

// ── REASSIGN AN INSPECTOR ────────────────────────────────
// One small dialog for both hand-overs a supervisor may need: a task nobody
// has inspected yet, and a site verification waiting on its inspector.
let _pick = null;   // { kind: 'task' | 'verify', id }

async function openPickInspector(kind, id, currentId, code){
  _pick = { kind, id };
  setText('pi-title', kind === 'task' ? 'Reassign Inspection Task' : 'Change Verifying Inspector');
  setText('pi-sub', kind === 'task'
    ? `${code} has not been inspected yet. Choose who should do it.`
    : `${code} is waiting for a site check. Choose who should verify it.`);
  const sel = document.getElementById('pi-select');
  sel.innerHTML = '<option value="">Loading inspectors…</option>';
  openModal('modal-pick-inspector');

  const res  = await fetch('api/users.php?action=list&role=field_inspector', { credentials:'include' });
  const data = await res.json();
  const list = (data.success ? data.data.users : []).filter(u => String(u.user_id) !== String(currentId));
  sel.innerHTML = list.length
    ? '<option value="">Select field inspector...</option>'
      + list.map(u => `<option value="${u.user_id}">${esc(u.name)}</option>`).join('')
    : '<option value="">No other field inspector on this team</option>';
  // Filled after the dialog opened, so the custom dropdown needs a repaint.
  ddRepaintAll(document.getElementById('modal-pick-inspector'));
}

async function submitPickInspector(){
  if(!_pick) return;
  const inspector = Number(document.getElementById('pi-select').value);
  if(!inspector){ toast('Choose an inspector', 'error'); return; }
  const isTask = _pick.kind === 'task';
  const res = await fetch(isTask ? 'api/tasks.php?action=reassign' : 'api/workorders.php?action=reassign_verifier', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
    body: JSON.stringify(isTask ? { task_id: _pick.id, assigned_to: inspector }
                                : { wo_id: _pick.id, verify_inspector_id: inspector })
  });
  const data = await res.json();
  if(!data.success){ toast(data.message || 'Could not reassign', 'error'); return; }
  closeModal('modal-pick-inspector');
  toast(data.message, 'success');
  refreshActiveView();
}

async function openCreateTask(){
  document.getElementById('ct-title').value = '';
  document.getElementById('ct-desc').value = '';
  document.getElementById('ct-loc').value = '';
  document.getElementById('ct-due').value = '';
  lockToFuture('ct-due');

  const res  = await fetch('api/users.php?action=list&role=field_inspector', { credentials:'include' });
  const data = await res.json();
  const inspectors = data.success ? data.data.users : [];
  const sel = document.getElementById('ct-inspector');
  sel.innerHTML = '<option value="">Select field inspector...</option>' +
  inspectors.map(u=>`<option value="${u.user_id}">${esc(u.name)}</option>`).join('');
  resetAddressCombo('ct-loc');
  initAddressCombo('ct-loc');
  openModal('modal-create-task');
}

async function createTask(){
  const assignedTo = document.getElementById('ct-inspector').value;
  const title       = document.getElementById('ct-title').value.trim();
  const desc        = document.getElementById('ct-desc').value.trim();
  const loc         = document.getElementById('ct-loc').value.trim();
  const due         = document.getElementById('ct-due').value;
  if(!assignedTo || !title || !loc){ toast('Please select an inspector and fill all required fields','error'); return; }
  if(due && due < todayISO()){ toast('The due date must be today or a later date','error'); return; }

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
    refreshActiveView();
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
  syncSortBar('fw-queue', el, true, wos.length, renderFWQueue);
  const sorted = lateFirst(applySort('fw-queue', wos, 'created_at', true), woOverdue);
  if(!wos.length){
    el.innerHTML='<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg><p>No active tasks assigned to you</p></div>';
    return;
  }
  el.innerHTML = sorted.map(w=>`
    <div ${rowOpen(`openWODetail('${w.wo_id}')`, `queue-card${w.status==='completed'?' done':''}`)}>
      <div class="queue-card-top"><span class="badge badge-${w.severity}">${cap(w.severity)}</span>${woStatusBadge(w.status)}${woOverdue(w) ? overdueTag(w.deadline) : ''}<span style="font-size:12px;color:var(--muted)">${esc(w.wo_code)}</span></div>      <h4>${esc(w.report_title.toUpperCase())}</h4>
      <p>${esc(w.instructions||'No special instructions provided.')}</p>
      ${w.status==='rework' ? `<div style="margin-top:8px;padding:9px 11px;border-left:3px solid var(--red);background:rgba(239,68,68,.08);font-size:12.5px;color:var(--label);line-height:1.6"><strong style="color:var(--red)">Sent back:</strong> ${esc(w.latest_remarks || 'No reason given.')}</div>` : ''}
      ${w.crew_names && w.crew_names.includes(',') ? `<div style="font-size:11.5px;color:var(--muted);margin-top:6px">Crew: ${esc(w.crew_names)}</div>` : ''}
      <div class="queue-card-foot">
        <span class="report-card-loc" style="font-size:12px;color:var(--muted)">📍 ${esc(w.address_text || w.task_location || w.location_text)}</span>
        <span style="font-size:11.5px;color:var(--muted)">⏱ ${fmtDate(w.created_at)}</span>
        ${Number(w.assigned_to) === Number(currentUser.user_id)
          ? `<button class="btn btn-amber btn-sm" onclick="openUpdateTask('${w.wo_id}')">Process Task &rsaquo;</button>`
          : `<span style="font-size:11.5px;color:var(--muted)">Supporting &mdash; ${esc(w.assigned_to_name || 'lead')} leads</span>`}
      </div>
      ${w.latest_remarks?`<div style="margin-top:10px;padding:8px 10px;background:rgba(255,255,255,.03);border-radius:6px;font-size:12px;color:var(--muted)">Last update: ${esc(w.latest_remarks)}</div>`:''}
    </div>`).join('');
}

async function renderFWHistory(){
  const allMine = await myWorkOrders();
  const wos = allMine.filter(w=>w.status==='completed');
  const tbody = document.getElementById('fw-hist-table');
  const cards = document.getElementById('fw-hist-cards');
  syncSortBar('fw-hist', sortAnchor('fw-hist-table'), true, wos.length, renderFWHistory);
  const sorted = applySort('fw-hist', wos, 'updated_at', true);
  if(!wos.length){
    tbody.innerHTML=`<tr><td colspan="6"><div class="empty-state"><p>No completed tasks yet</p></div></td></tr>`;
    cards.innerHTML='';return;
  }
  // Finished work is still the worker's own record: the row opens it so they
  // can re-read the instructions, the remarks they filed and the photos, and
  // save the job sheet. Read-only — closing and crew are not theirs to change.
  const open = w => `openWODetail('${w.wo_id}')`;
  tbody.innerHTML = sorted.map(w=>`<tr ${rowOpen(open(w))}>
    <td class="id">${esc(w.wo_code)}</td><td class="issue">${esc(w.report_title)}</td><td>${esc(w.address_text || w.task_location || w.location_text)}</td>
    <td><span class="badge badge-${w.severity}">${cap(w.severity)}</span></td>
    <td>${fmtDate(w.updated_at)}</td>
    <td>${woStatusBadge(w.status)}</td>
  </tr>`).join('');
  cards.innerHTML = sorted.map(w=>`
    <div ${rowOpen(open(w), 'm-card')}>
      <div class="m-card-top"><div><div class="m-card-title">${esc(w.report_title)}</div><div class="m-card-id">${esc(w.wo_code)}</div></div><span class="badge badge-${w.severity}">${cap(w.severity)}</span></div>
      <div class="m-card-loc">📍 ${esc(w.address_text || w.task_location || w.location_text)} • ${fmtDate(w.updated_at)}</div>
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

// ── MEMBER PROFILE (admin) ───────────────────────────────
/** 09171234567 -> 0917 123 4567. Anything else is shown as stored. */
function formatContact(n){
  const s = String(n || '');
  return /^09\d{9}$/.test(s) ? `${s.slice(0,4)} ${s.slice(4,7)} ${s.slice(7)}` : s;
}
const GENDER_LABEL = { male:'Male', female:'Female', other:'Other', prefer_not_to_say:'Prefer not to say' };
/** Built from its parts: new Date('YYYY-MM-DD') is read as UTC and can land
 *  on the previous day in local time. */
function fmtBirthdate(ymd){
  if(!ymd) return '—';
  const [y, m, d] = String(ymd).split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('en-PH', { month:'long', day:'numeric', year:'numeric' });
}
async function openMemberProfile(userId){
  const res = await admApi('teams', 'member_profile&user_id=' + encodeURIComponent(userId));
  if(!res.success){ toast(res.message || 'Could not load this profile', 'error'); return; }
  const p = res.data.member;
  document.getElementById('mp-av').className = 'avatar ' + (ADM_ROLE_AV[p.role] || 'av-amber');
  paintAvatar('mp-av', p.name, p.avatar_path);
  setText('mp-name',    p.name);
  setText('mp-role',    ADM_ROLE_LABEL[p.role] || cap(p.role));
  setText('mp-email',   p.email);
  setText('mp-contact', formatContact(p.contact_number) || 'Not provided');
  setText('mp-gender',  GENDER_LABEL[p.gender] || '—');
  setText('mp-birth',   fmtBirthdate(p.birthdate));
  setText('mp-address', p.address || '—');
  setText('mp-joined',  p.joined_at ? fmtDate(p.joined_at) : '—');
  openModal('modal-member');
}

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

    // A flex row with a gap, rather than a fixed-width span. The old
    // width:100px was narrower than the "Change role" button actually renders,
    // so it overflowed and collided with "Remove". The first slot keeps a
    // min-width so the Remove buttons still line up down the column.
    const actions = isAdminRow
      ? (isSelf
          ? '<span class="adm-actions"><span class="adm-actions-slot adm-actions-note">Administrator</span></span>'
          : `<span class="adm-actions"><span class="adm-actions-slot adm-actions-note">Administrator</span><button class="action-link" style="color:var(--red)" onclick="removeMember(${m.user_id})">Remove</button></span>`)
      : `<span class="adm-actions"><span class="adm-actions-slot"><button class="action-link" onclick="openAssignRole(${m.user_id})">Change role</button></span>`
        + `<button class="action-link" style="color:var(--red)" onclick="removeMember(${m.user_id})">Remove</button></span>`;
        
        return `<tr ${rowOpen(`openMemberProfile(${m.user_id})`)}>
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

    return `<div ${rowOpen(`openMemberProfile(${m.user_id})`, 'm-card')}>
      <div class="m-card-top">
        <div style="display:flex;align-items:center;gap:10px;min-width:0;flex:1">
          ${avatarHtml(m.name, m.avatar_path, ADM_ROLE_AV[m.role]||'av-amber', 30, 12)}
          <div style="min-width:0">
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
          ${expired ? '<span style="color:var(--red)">Expired</span>'
                    : 'Sent ' + timeAgo(i.last_sent_at || i.created_at)}
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
  refreshActiveView();
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
  refreshActiveView();
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
// The period on the Activity Logs page. Starts at the last 30 days, like
// Reports & Exports; the list and the export both follow it.
let _alReady = false;
function logRangePreset(v, autoLoad = true){
  const from = $('al-from'), to = $('al-to'), now = new Date();
  $('al-dates').classList.toggle('fb-hide', v !== 'custom');
  if(v === 'custom') return;
  if(v === 'all'){ from.value = ''; to.value = ''; }
  else if(v === 'month'){
    from.value = _ymd(new Date(now.getFullYear(), now.getMonth(), 1));
    to.value   = _ymd(now);
  } else {
    const back = new Date(now); back.setDate(back.getDate() - (parseInt(v, 10) || 30));
    from.value = _ymd(back);
    to.value   = _ymd(now);
  }
  if(autoLoad) renderAdmLogs();
}

function logFilterQuery(){
  const q = new URLSearchParams();
  if($('al-from').value) q.set('from', $('al-from').value);
  if($('al-to').value)   q.set('to',   $('al-to').value);
  return q;
}

async function renderAdmLogs(){
  if(!_alReady){ _alReady = true; logRangePreset($('al-range').value, false); }
  if($('al-from').value && $('al-to').value && $('al-from').value > $('al-to').value){
    toast('The From date is after the To date', 'error');
    return;
  }
  paintLogSeal();
  const q = logFilterQuery();
  q.set('limit', '200');
  const data  = await admApi('logs', 'list&' + q.toString());
  const logs  = data.success ? data.data.logs : [];
  const total = data.success ? data.data.total : 0;

  const el = $('adm-logs-list');
  // Logs carry no severity, so admin gets the date toggle only. This is the
  // one sortable list on that dashboard; Members is ordered by role on purpose.
  syncSortBar('adm-logs', el, false, logs.length, renderAdmLogs);
  if(!logs.length){ el.innerHTML='<div class="empty-state"><p>No activity in this period</p></div>'; return; }
  el.innerHTML = applySort('adm-logs', logs, 'logged_at', false).map(l=>`
    <div class="event-row">
      <div><div class="event-name">${esc(l.action)}</div><div class="event-by">by ${esc(l.user_name || 'System')}</div></div>
      <div class="event-time">${fmtDate(l.logged_at)}</div>
    </div>`).join('')
    + (total > logs.length
      ? `<div class="log-more">Showing the latest ${logs.length} of ${total} entries in this period. Narrow the dates, or Export to get them all.</div>`
      : '');
}

// Whether the log is exactly as ConstructFlow signed it (config/logseal.php).
async function paintLogSeal(){
  const el = $('adm-log-seal');
  if(!el) return;
  const res = await admApi('logs','verify');
  if(!res.success){ el.className = 'log-seal'; el.textContent = ''; return; }
  const d = res.data;
  el.className = 'log-seal ' + (d.intact ? 'ok' : 'bad');
  el.title = d.intact ? '' : d.problems.join('\n');
  el.textContent = d.intact
    ? `✓ Sealed and unchanged · ${d.checked} entr${d.checked === 1 ? 'y' : 'ies'} checked`
    : `⚠ This log was changed outside ConstructFlow. ${d.problems[0]}`
      + (d.problems.length > 1 ? ` (+${d.problems.length - 1} more)` : '');
}

function exportRecords(){
  const q = logFilterQuery();
  q.set('action', 'export');
  window.open('api/logs.php?' + q.toString(), '_blank');
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

// Photos the inspector takes at the site check, staged until they verify.
window.selectedSitePhotos = [];

function handleSitePhotos(input){
  [...input.files].forEach(file => {
    const reader = new FileReader();
    reader.onload = e => {
      window.selectedSitePhotos.push({ file, dataUrl: e.target.result });
      renderSitePhotoPreview();
    };
    reader.readAsDataURL(file);
  });
  input.value = '';
}

function renderSitePhotoPreview(){
  const prev = document.getElementById('vf-photo-preview');
  if(!prev) return;
  prev.innerHTML = window.selectedSitePhotos.map((p, i) => `
    <div class="photo-thumb-wrap">
      <img src="${p.dataUrl}">
      <button type="button" onclick="removeSitePhoto(${i})" title="Remove photo">×</button>
    </div>`).join('');
}

function removeSitePhoto(index){
  window.selectedSitePhotos.splice(index, 1);
  renderSitePhotoPreview();
}

let gpsLat = null, gpsLng = null;
let gpsCodes = { province_code:null, city_code:null, brgy_code:null };
// Where the coordinates currently in gpsLat/gpsLng came from: the device, or
// text the inspector typed. The difference matters when the field is edited.
let gpsSource = null;          // 'device' | 'manual' | null
let _coordTimer = null;

// Accepts the shapes people actually paste out of Google Maps or a phone GPS
// app: "15.48611, 120.59694" · "15.48611 120.59694" · "15.48611° N, 120.59694° E".
// The whole field must be the pair, and both halves must carry decimals, so a
// plain site description like "Block 4, Lot 5" is never mistaken for a location.
function parseLatLng(raw){
  const s = String(raw || '').trim().replace(/[°º]/g,' ').replace(/\s+/g,' ');
  const m = s.match(/^([+-]?\d{1,3}\.\d+)\s*([NS])?\s*[, ]\s*([+-]?\d{1,3}\.\d+)\s*([EW])?$/i);
  if(!m) return null;
  let lat = parseFloat(m[1]), lng = parseFloat(m[3]);
  if(m[2] && m[2].toUpperCase() === 'S') lat = -Math.abs(lat);
  if(m[4] && m[4].toUpperCase() === 'W') lng = -Math.abs(lng);
  const okLat = Math.abs(lat) <= 90, okLng = Math.abs(lng) <= 180;
  if(okLat && okLng) return { lat, lng, valid:true, swapped:false };
  // A latitude past 90 beside a longitude that would be a legal latitude is the
  // classic wrong-order paste. Reported to the inspector, never auto-corrected.
  return { lat, lng, valid:false, swapped: !okLat && Math.abs(lng) <= 90 };
}

// Shared by the GPS button and by typed coordinates — geocode.php only ever
// reads lat and lng, so it cannot tell the two apart and neither should we.
async function lookupAddress(lat, lng, prefix){
  const st = document.getElementById('s-gps-status');
  st.textContent = prefix + ' — looking up address...';
  try{
    const res  = await fetch(`api/geocode.php?lat=${lat}&lng=${lng}`, { credentials:'include' });
    const data = await res.json();
    if(data.success){
      const d = data.data;
      const addrEl = document.getElementById('s-address');
      addrEl.value = d.address_text;
      // Hand the picker the whole result rather than just the text. It needs
      // the codes and names to open on the detected barangay; given only a
      // string it starts again at "Select province" and the dropdown looks
      // as though the lookup never happened.
      addrEl.dispatchEvent(new CustomEvent('addr:set', { detail: {
        text:          d.address_text,
        province_code: d.province_code, province_name: d.province,
        city_code:     d.city_code,     city_name:     d.city,
        brgy_code:     d.brgy_code,     brgy_name:     d.barangay
      }}));
      gpsCodes = { province_code:d.province_code, city_code:d.city_code, brgy_code:d.brgy_code };
      st.textContent = !d.validated
        ? prefix + ' — address not found in PSGC, please check it'
        : (d.fuzzy ? prefix + ' — address matched approximately, please confirm the barangay'
                   : prefix + ' — address detected');
    }else{
      st.textContent = prefix + ' — ' + data.message;
    }
  }catch(e){
    st.textContent = prefix + ' — address lookup failed, please type it';
  }
}

function detectGPS(){
  const st = document.getElementById('s-gps-status');
  st.textContent='Detecting location...';
  if(!navigator.geolocation){ st.textContent='GPS not supported on this device.'; return; }
  navigator.geolocation.getCurrentPosition(
    pos=>{
      gpsLat = pos.coords.latitude;
      gpsLng = pos.coords.longitude;
      gpsSource = 'device';
      document.getElementById('s-loc').value = `${gpsLat.toFixed(5)}, ${gpsLng.toFixed(5)}`;
      lookupAddress(gpsLat, gpsLng, '✓ Location detected');
    },
    ()=>{ st.textContent='GPS unavailable. Please enter location manually.'; }
  );
}

// The GPS button is one way coordinates get in; pasting them straight into the
// field is the other. Without this, a pasted pair was only ever text — latitude
// and longitude stayed null and the report saved with no usable coordinates and
// no address.
function watchManualCoords(){
  const loc = document.getElementById('s-loc');
  if(!loc || loc.dataset.coordWatch) return;
  loc.dataset.coordWatch = '1';
  loc.addEventListener('input', ()=>{
    clearTimeout(_coordTimer);
    const st = document.getElementById('s-gps-status');
    const p  = parseLatLng(loc.value);

    if(!p || !p.valid){
      // Back to a plain site description, or a pair we cannot use. Drop
      // coordinates inferred from an earlier paste, but never a real device
      // reading — the inspector may simply be annotating it.
      if(gpsSource === 'manual'){ gpsLat = null; gpsLng = null; gpsSource = null; st.textContent = ''; }
      if(p && p.swapped) st.textContent = 'Those look reversed — latitude first, then longitude.';
      else if(p)         st.textContent = 'Those coordinates are out of range.';
      return;
    }

    gpsLat = p.lat; gpsLng = p.lng; gpsSource = 'manual';
    st.textContent = '✓ Coordinates entered — looking up address...';
    // Debounced: this fires on every keystroke, and Nominatim allows one
    // request per second.
    _coordTimer = setTimeout(()=> lookupAddress(p.lat, p.lng, '✓ Coordinates entered'), 700);
  });
}

let boundTaskId = null;

async function submitReport(){
  if(!boundTaskId){ toast('No inspection task selected. Please open this form from an assigned task.','error'); return; }

  const title = $('#s-title').value.trim();
  const desc  = $('#s-desc').value.trim();
  const loc   = $('#s-loc').value.trim();
  const phase = $('#s-phase').value;
  if(!title||!desc||!loc){ toast('Please fill all required fields','error'); return; }
  if(desc.length < 15){ toast('Please describe the issue in a full sentence so it can be classified','error'); return; }

  const fd = new FormData();
  fd.append('task_id',       boundTaskId);
  fd.append('title',         title);
  fd.append('description',   desc);
  fd.append('location_text', loc);
  fd.append('phase',         phase);
  if(gpsLat !== null){ fd.append('latitude',  gpsLat); }
  if(gpsLng !== null){ fd.append('longitude', gpsLng); }
  if(gpsCodes.province_code){ fd.append('province_code', gpsCodes.province_code); }
  if(gpsCodes.city_code){     fd.append('city_code',     gpsCodes.city_code); }
  if(gpsCodes.brgy_code){     fd.append('brgy_code',     gpsCodes.brgy_code); }
  fd.append('address_text', document.getElementById('s-address').value.trim());

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

    // Show what the engine decided, so the inspector sees it happen.
    const c = data.data.classification;
    if(c){
      const sev   = String(c.severity || '').toUpperCase();
      const filed = String(c.filed_as || c.severity || '').toUpperCase();
      toast(filed !== sev
        ? `No defect type recognised — filed as ${filed} for the supervisor to reassess`
        : c.needs_review
        ? `Classified ${sev} (confidence ${c.confidence}%) — flagged for supervisor review`
        : `Classified ${sev} (confidence ${c.confidence}%)`,
        c.needs_review ? 'info' : 'success');
    }

    const warnings = data.data.photo_warnings || [];
    if(warnings.length){
      toast(`${warnings.length} photo${warnings.length>1?'s':''} skipped: ${warnings.join(' ')}`, 'error');
    }

    boundTaskId = null;
    refreshActiveView();
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
  document.getElementById('a-loc').value = r.location_text;
  setText('a-report-sub', `${r.report_code} · ${r.title}`);
  setText('a-reporter', `${r.submitted_by_name || '—'} · ${fmtDate(r.submitted_at)}`);
  // Address and coordinates are separate columns; show both.
  setText('a-address', r.address_text || r.task_location || '—');
  setText('a-gps', (r.latitude && r.longitude)
    ? `${Number(r.latitude).toFixed(5)}, ${Number(r.longitude).toFixed(5)}`
    : (r.location_text || '—'));
  setText('a-type', r.issue_type || '—');
  // setText and the guard below keep a stale cached page from killing the
  // whole function when markup and script versions disagree.
  setText('a-desc', r.description || 'No description given.');

  const photos = data.data.photos || [];
  window.currentDetailPhotos = photos.map(p => p.file_path);
  const photoBox = document.getElementById('a-photos');
  if(photoBox){
    photoBox.innerHTML = photos.length
      ? photos.map((p,i)=>`<img src="${esc(p.file_path)}" onclick="openLightbox(window.currentDetailPhotos, ${i})"/>`).join('')
      : '<span style="font-size:12px;color:var(--muted)">No photos attached.</span>';
  }

  document.getElementById('a-sev').value = cap(r.severity);
  // The verdict reads as a badge; the engine's reasoning sits behind the (i)
  // rather than running four lines under the field.
  const sevView = document.getElementById('a-sev-view');
  if(sevView){
    sevView.innerHTML = `<span class="badge badge-${esc(r.severity)}">${cap(r.severity)}</span>`
      + (r.recommended_severity ? `<span class="sev-conf">${esc(r.classification_confidence)}% confidence</span>` : '')
      + (r.recommended_severity && r.classification_notes
          ? infoIcon('<strong>Engine notes.</strong><br>' + esc(r.classification_notes).replace(/\n/g, '<br>'), 'Why the engine chose this') : '');
  }
  document.getElementById('a-sev-override').value = '';
  document.getElementById('a-instructions').value = '';
  document.getElementById('a-deadline').value = '';
  lockToFuture('a-deadline');

  const wRes = await fetch('api/users.php?action=list&role=field_worker', { credentials:'include' });
  const wData = await wRes.json();
  const workers = wData.success ? wData.data.users : [];
  renderCrewPicker(workers, _assignSeverity(), []);

  openModal('modal-assign');
}

async function assignWorkOrder(){
  const reportId     = document.getElementById('a-report-id').value;
  const crew         = crewSelection();
  const sevOverride  = document.getElementById('a-sev-override').value;
  const instructions = document.getElementById('a-instructions').value.trim();
  const deadline     = document.getElementById('a-deadline').value;
  if(!crew.lead_id){ toast('Choose the lead field worker','error'); return; }
  if(deadline && deadline < todayISO()){ toast('The deadline must be today or a later date','error'); return; }

  const res = await fetch('api/workorders.php?action=create', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
    body: JSON.stringify({
      report_id:    reportId,
      worker_ids:   crew.worker_ids,
      lead_id:      crew.lead_id,
      severity:     sevOverride || document.getElementById('a-sev').value.toLowerCase(),
      instructions: instructions,
      deadline:     deadline
    })
  });
  const data = await res.json();

  if(data.success){
    closeModal('modal-assign');
    toast(`Work order ${data.data.wo_code} assigned successfully`, 'success');
    refreshActiveView();
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
  document.getElementById('ut-cur-status').value = cap(w.status.replace(/_/g,' '));
  setText('ut-report-desc', w.report_description || 'No description recorded on the report.');
  setText('ut-instructions', w.instructions || 'No specific instructions given.');
  document.getElementById('ut-status').value = 'in_progress';
  document.getElementById('ut-remarks').value = '';
  window.selectedTaskPhotos = [];
  document.getElementById('task-photo-preview').innerHTML='';
  setPrintTarget('workorder', woId, w.report_id);
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

    refreshActiveView();
  } else {
    toast(data.message || 'Failed to update task', 'error');
  }
}

// ════════════════════════════════════════
// REPORT / WO DETAIL MODALS
// ════════════════════════════════════════
// ── PRINTABLE SUMMARIES ───────────────────────────────────
// Opens print.html in its own tab, which fetches the document and calls
// the browser's print dialog. "Save as PDF" in that dialog is how a
// summary becomes a file — no PDF library, nothing added to the cache.
//
// What a person is allowed to print is settled by api/summary.php, not
// here: these buttons only decide what is offered.
// reportId is tracked separately because a case file is keyed by the
// report — it is the spine the task and the work order hang off. Reusing
// the work order's id here would open somebody else's case file.
let _printTarget = null;   // { type, id, reportId }

function setPrintTarget(type, id, reportId){
  _printTarget = (type && id) ? { type, id, reportId: reportId || null } : null;
  document.querySelectorAll('[data-print-btn]').forEach(b => {
    const wants = b.dataset.printBtn;          // 'self' or 'case'
    const show  = !!_printTarget && (wants !== 'case' || !!_printTarget.reportId);
    b.style.display = show ? '' : 'none';
  });
}

function openPrint(kind){
  if(!_printTarget){ toast('Nothing to print yet','error'); return; }
  const wantCase = kind === 'case';
  if(wantCase && !_printTarget.reportId){ toast('No case file for this record','error'); return; }
  const type = wantCase ? 'case' : _printTarget.type;
  const id   = wantCase ? _printTarget.reportId : _printTarget.id;
  window.open(`print.html?type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`,
              '_blank', 'noopener');
}

async function openReportDetail(reportId, extra = null, extraId = null){
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
    <div class="detail-row"><span class="detail-label">Location</span><span class="detail-value">${esc(r.address_text || r.task_location || r.location_text)}</span></div>
    <div class="detail-row"><span class="detail-label">Severity</span><span class="detail-value"><span class="badge badge-${r.severity}">${cap(r.severity)}</span></span></div>
    ${r.recommended_severity ? `
    <div class="detail-row"><span class="detail-label">Engine Recommendation</span><span class="detail-value"><span class="badge badge-${r.recommended_severity}">${cap(r.recommended_severity)}</span> D${esc(String(r.d_level))}/C${esc(String(r.c_level))} · ${esc(String(r.classification_confidence))}% confidence</span></div>
    ${r.severity_overridden_at ? `<div class="detail-row"><span class="detail-label">Overridden</span><span class="detail-value">Changed to ${cap(r.severity)} on ${fmtDate(r.severity_overridden_at)}</span></div>` : ''}` : ''}
    <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value">${statusBadge(r.status)}</span></div>
    <div class="detail-row"><span class="detail-label">Inspector</span><span class="detail-value">${esc(r.submitted_by_name)}</span></div>
    <div class="detail-row"><span class="detail-label">Submitted</span><span class="detail-value">${fmtDate(r.submitted_at)}</span></div>
    <div style="margin-top:14px"><div style="font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:600">Description</div><div style="font-size:13px;color:var(--label);line-height:1.6">${esc(r.description)}</div></div>
    ${r.classification_notes ? `<div style="margin-top:14px"><div style="font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:600">Engine Notes</div><div style="font-size:13px;color:var(--label);line-height:1.6">${esc(r.classification_notes).replace(/\n/g,'<br>')}</div></div>` : ''}`;
  setDetailActions([
    extra === 'assign'     ? { label: 'Assign Work Order', js: `openAssign('${reportId}')` }     : null,
    extra === 'edit'       ? { label: 'Edit Report',       js: `openEditReport('${reportId}')` } : null,
    extra === 'close-task' ? { label: 'Close Task',        js: `closeTask('${extraId}')` }       : null
  ]);
  setPrintTarget('report', reportId, reportId);
  openModal('modal-report-detail');
}

async function openWODetail(woId, extra = null){
  const res  = await fetch(`api/workorders.php?action=detail&id=${woId}`, { credentials:'include' });
  const data = await res.json();
  if(!data.success){ toast('Could not load work order details','error'); return; }
  const w = data.data.work_order;
  _woDetail = w;
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
    <div class="detail-row"><span class="detail-label">Location</span><span class="detail-value">${esc(w.address_text || w.task_location || w.location_text)}</span></div>
    <div class="detail-row"><span class="detail-label">Priority</span><span class="detail-value"><span class="badge badge-${w.severity}">${cap(w.severity)}</span></span></div>
    <div class="detail-row"><span class="detail-label">Assigned To</span><span class="detail-value">${esc(w.assigned_to_name || 'Unassigned')}</span></div>
    <div class="detail-row"><span class="detail-label">Status</span><span class="detail-value">${woStatusBadge(w.status)}</span></div>
    ${w.status==='pending_inspector' && w.verify_inspector_name ? `<div class="detail-row"><span class="detail-label">Verifying Inspector</span><span class="detail-value">${esc(w.verify_inspector_name)}</span></div>` : ''}
    <div class="detail-row"><span class="detail-label">Deadline</span><span class="detail-value">${w.deadline ? fmtDay(w.deadline) : 'None set'}${woOverdue(w) ? overdueTag(w.deadline) : ''}</span></div>
    <div class="detail-row"><span class="detail-label">Created</span><span class="detail-value">${fmtDate(w.created_at)}</span></div>
    ${w.updated_at?`<div class="detail-row"><span class="detail-label">Last Updated</span><span class="detail-value">${fmtDate(w.updated_at)}</span></div>`:''}
    ${w.instructions?`<div style="margin-top:14px"><div style="font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:600">Work Instructions</div><div style="font-size:13px;color:var(--label);line-height:1.6">${esc(w.instructions)}</div></div>`:''}
    ${lastUpdate?`<div style="margin-top:14px"><div style="font-size:12px;color:var(--muted);margin-bottom:6px;font-weight:600">Latest Activity Report</div><div style="font-size:13px;color:var(--label);line-height:1.6">${esc(lastUpdate.remarks)}</div></div>`:''}
    ${photoPaths.length?`<div class="photo-preview" style="margin-top:14px">${photoPaths.map((src,i)=>`<img src="${src}" class="photo-view-thumb" onclick="openLightbox(window.currentDetailPhotos, ${i})">`).join('')}</div>`:''}`;
  window.currentDetailPhotos = photoPaths; 
  setDetailActions([
    extra === 'crew' ? { label: 'Manage Crew', js: `openEditCrew('${woId}')` } : null,
    extra === 'crew' ? { label: 'Edit Details', js: 'openEditWO()' } : null,
    extra === 'crew' && w.status === 'pending_inspector'
      ? { label: 'Change Inspector', js: `openPickInspector('verify','${woId}','${w.verify_inspector_id || ''}','${esc(w.wo_code)}')` }
      : null
  ]);
  setPrintTarget('workorder', woId, w.report_id);
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
  const el = document.getElementById(id);
  el.classList.add('open');
  // Forms set select values just before opening, and a programmatic .value
  // fires no event, so refresh the custom buttons here.
  ddEnhanceAll(el); ddRepaintAll(el);
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
  setText('prof-view-contact', formatContact(currentUser.contact_number) || 'Not set');
  setText('prof-view-role',  roleLabel);
  setText('prof-view-team',  currentUser.teamName || '—');

  document.getElementById('prof-name-inp').value  = currentUser.name;
  document.getElementById('prof-email-inp').value = currentUser.email;
  document.getElementById('prof-contact-inp').value = formatContact(currentUser.contact_number);
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

  const newContact = document.getElementById('prof-contact-inp').value.trim();
  if(newName !== currentUser.name || newContact !== formatContact(currentUser.contact_number)){
    const nameRes  = await fetch('api/users.php?action=self_edit', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
      body: JSON.stringify({ name: newName, contact_number: newContact })
    });
    const nameData = await nameRes.json();
    if(!nameData.success){
      toast(nameData.message || 'Failed to update profile', 'error');
      return;
    }
    currentUser.name = newName;
    currentUser.contact_number = nameData.data.contact_number;
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

  if(id === 'modal-verify'){
    window.selectedSitePhotos = [];
    renderSitePhotoPreview();
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
/**
 * Turns a table row or a mobile card into the single click target for its
 * record. The tables carry no buttons: the whole row opens the record, and
 * whatever used to sit in the Action column is offered inside that detail.
 * The row is focusable and answers Enter and Space, because taking the
 * buttons away would otherwise take the rows away from the keyboard too.
 */
function rowOpen(js, cls = ''){
  const go = js.replace(/"/g, '&quot;');
  // A click that began on a control inside the row belongs to that control,
  // not to the row. Without this guard the lead's "Process Task" button would
  // open the update modal and the read-only detail at the same time. The
  // keyboard rule is the same one: Enter on a focused child must not bubble
  // up and fire the row as well.
  // No role override. An explicit role replaces <tr>'s implicit "row", so
  // the table stops being a table for a screen reader and the column
  // headers stop being announced. tabindex plus the handlers below already
  // make the row operable.
  return `class="${cls ? cls + ' ' : ''}row-link" tabindex="0" `
       + `onclick="if(event.target.closest('button,a,input,select,textarea'))return;${go}" `
       + `onkeydown="if(event.target===this&&(event.key==='Enter'||event.key===' ')){event.preventDefault();${go}}"`;
}

/**
 * Fills the detail modal's action slot. One modal serves both reports and
 * work orders, so what it offers is decided by the row that opened it: that
 * row already knows the record's state and which portal it is in, which is
 * cheaper and safer than re-deriving both here. Each action closes the detail
 * first — the overlays stack otherwise.
 */
function setDetailActions(buttons){
  const slot = document.getElementById('rd-actions');
  if(!slot) return;
  slot.innerHTML = (buttons || []).filter(Boolean).map(b =>
    `<button class="btn btn-dark" onclick="closeModal('modal-report-detail');${b.js}">${esc(b.label)}</button>`
  ).join('');
}

function renderReportTable(tbodyId, cardsId, reports, role){
  const tbody = document.getElementById(tbodyId);
  const cards = document.getElementById(cardsId);
  if(!reports||!reports.length){
    if(tbody) tbody.innerHTML=`<tr><td colspan="6"><div class="empty-state"><p>No reports found</p></div></td></tr>`;
    if(cards) cards.innerHTML=`<div class="empty-state"><p>No reports found</p></div>`;
    return;
  }
  // An inspector may still correct their own report while it is pending.
  const open = r =>
    `openReportDetail('${r.report_id}'${role==='inspector' && r.status==='pending' ? ",'edit'" : ''})`;
  if(tbody) tbody.innerHTML = reports.map(r=>`<tr ${rowOpen(open(r))}>
    <td class="id">${esc(r.report_code)}</td>
    <td class="issue">${esc(r.title)}</td>
    <td>${esc(r.address_text || r.task_location || r.location_text)}</td>
    <td><span class="badge badge-${r.severity}">${cap(r.severity)}</span></td>
    <td>${fmtDate(r.submitted_at)}</td>
    <td>${statusBadge(r.status)}</td>
  </tr>`).join('');
  if(cards) cards.innerHTML = reports.map(r=>`
    <div ${rowOpen(open(r), 'm-card')}>
      <div class="m-card-top"><div><div class="m-card-title">${esc(r.title)}</div><div class="m-card-id">${esc(r.report_code)} • ${fmtDate(r.submitted_at)}</div></div><span class="badge badge-${r.severity}">${cap(r.severity)}</span></div>
      <div class="m-card-loc">📍 ${esc(r.address_text || r.task_location || r.location_text)}</div>
      <div class="m-card-footer">${statusBadge(r.status)}</div>
    </div>`).join('');
}

// ════════════════════════════════════════
// UTILITIES
// ════════════════════════════════════════
function $(id){ return document.getElementById(id.replace(/^#/,'')); }

function setText(id,v){ const e=document.getElementById(id); if(e) e.textContent=v; }

// Today in the browser's own timezone as YYYY-MM-DD. toISOString() alone would
// shift to UTC and hand back "yesterday" for anyone east of Greenwich.
// ── OVERDUE ──────────────────────────────────────────────
// A work order is late while the work itself is unfinished and its deadline
// has passed; once handed in for verification the work is done. A task is
// late while it is still waiting to be inspected.
const WO_WORKING = ['pending', 'in_progress', 'on_hold', 'rework'];
function woOverdue(w){
  return !!w.deadline && WO_WORKING.includes(w.status) && String(w.deadline).slice(0, 10) < todayISO();
}
function taskOverdue(t){
  return !!t.due_date && t.status === 'assigned' && String(t.due_date).slice(0, 10) < todayISO();
}
/** Late items first, each group keeping the order the list was sorted in. */
function lateFirst(items, isLate){
  return [...items.filter(isLate), ...items.filter(i => !isLate(i))];
}
/** The red tag, with the missed date on hover. */
function overdueTag(due){
  return ` <span class="tag-overdue" title="Due ${esc(fmtDay(due))}">Overdue</span>`;
}
/** A DATE column as a date. fmtDate() reads 'YYYY-MM-DD' as UTC midnight
 *  and would print a time nobody entered. */
function fmtDay(ymd){
  if(!ymd) return '—';
  const [y, m, d] = String(ymd).slice(0, 10).split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' });
}

function todayISO(){
  const d = new Date();
  return new Date(d.getTime() - d.getTimezoneOffset()*60000).toISOString().slice(0,10);
}
// Set on modal open rather than hardcoded in the HTML, so the floor moves
// forward with the calendar instead of going stale.
function lockToFuture(id){
  const e = document.getElementById(id);
  if(e) e.min = todayISO();
}

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
  const m={'pending':'badge-pending','in_progress':'badge-inprogress','on_hold':'badge-pending','pending_supervisor':'badge-inprogress','pending_inspector':'badge-inprogress','pending_closure':'badge-inprogress','rework':'badge-pending','completed':'badge-completed'};
  const l={'pending':'Pending','in_progress':'In Progress','on_hold':'On Hold','pending_supervisor':'Awaiting Supervisor','pending_inspector':'Awaiting Inspector','pending_closure':'For Final Review','rework':'Needs Rework','completed':'Completed'};
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

// Clicking the backdrop closes a read-only modal, but never one holding a
// form: losing half-typed input to a stray click costs far more than the
// extra click on Cancel. Forms close only via Cancel or the × button.
document.querySelectorAll('.overlay').forEach(o => o.addEventListener('click', e => {
  if (e.target !== o) return;
  if (o.querySelector('input:not([type="hidden"]), textarea, select')) return;
  closeModal(o.id);
}));

// PWA
if('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(()=>{});

// ── Address picker (PSGC) — single-field drill-down ──────────
// One input. Clicking it opens a list of provinces; choosing one replaces
// the list with that province's municipalities, then that municipality's
// barangays. Each level is fetched only when it is needed, so the browser
// never holds more than a few kilobytes of the 42,029-row reference list.

const ADDR_CACHE = { cities:{}, barangays:{} };

async function addrFetch(url){
  const res  = await fetch(url, { credentials:'include' });
  const data = await res.json();
  if(!data.success) throw new Error(data.message || 'Lookup failed');
  return data.data;
}

// inputId  — the single field that ends up holding "Province, Municipality, Barangay"
// onPick   — optional callback given {province_code, city_code, brgy_code}
function initAddressCombo(inputId, onPick){
  const input = document.getElementById(inputId);
  if(!input || input.dataset.comboReady) return;
  input.dataset.comboReady = '1';

  // Chromium ignores autocomplete="off" on anything its heuristics read as a
  // username box. Two things actually stop it:
  //   1. type="search" — excluded from credential autofill outright.
  //   2. readonly at parse time — autofill skips readonly fields, and the
  //      attribute is dropped the moment the user interacts, so typing to
  //      filter still works.
  input.type = 'search';
  input.setAttribute('readonly','readonly');
  const dropReadonly = ()=> input.removeAttribute('readonly');
  input.addEventListener('mousedown', dropReadonly);
  input.addEventListener('touchstart', dropReadonly, { passive:true });
  input.addEventListener('focus', dropReadonly);

  input.setAttribute('autocomplete','address-level2');
  input.setAttribute('name','address-picker');     // an unnamed input invites guessing
  input.setAttribute('role','combobox');
  input.setAttribute('aria-autocomplete','list');
  input.setAttribute('aria-expanded','false');
  // Opt-outs honoured by the common password managers.
  input.setAttribute('data-lpignore','true');      // LastPass
  input.setAttribute('data-1p-ignore','true');     // 1Password
  input.setAttribute('data-bwignore','true');      // Bitwarden
  input.setAttribute('data-form-type','other');    // Dashlane

  const wrap = document.createElement('div');
  wrap.className = 'addr-combo';
  input.parentNode.insertBefore(wrap, input);
  wrap.appendChild(input);

  const panel = document.createElement('div');
  panel.className = 'addr-panel';
  panel.hidden = true;
  wrap.appendChild(panel);

  // level: 0 province, 1 municipality, 2 barangay
  let level = 0, rows = [], picked = { prov:null, city:null, brgy:null }, committed = '';

  const codes = ()=>({
    province_code: picked.prov?.province_code ?? null,
    city_code:     picked.city?.city_code     ?? null,
    brgy_code:     picked.brgy?.brgy_code     ?? null
  });

  const labelOf = r => r.province_name ?? r.city_name ?? r.brgy_name ?? '';
  const heading = ()=> level === 0 ? 'Select province'
                     : level === 1 ? picked.prov.province_name
                     :               picked.city.city_name;

  function render(filter=''){
    const f = filter.trim().toLowerCase();
    const shown = f ? rows.filter(r=>labelOf(r).toLowerCase().includes(f)) : rows;
    panel.innerHTML =
      `<div class="addr-head">${level>0?'<button type="button" class="addr-back">&lsaquo;</button>':''}`
      + `<span>${esc(heading())}</span></div>`
      + (shown.length
          ? shown.map((r,i)=>`<button type="button" class="addr-opt" data-i="${rows.indexOf(r)}">${esc(labelOf(r))}</button>`).join('')
          : '<div class="addr-empty">No match</div>');
    panel.hidden = false; input.setAttribute("aria-expanded","true");
  }

  async function load(){
    panel.innerHTML = '<div class="addr-empty">Loading…</div>';
    panel.hidden = false; input.setAttribute("aria-expanded","true");
    try{
      if(level === 0){
        if(!ADDR_CACHE.provinces){
          ADDR_CACHE.provinces = (await addrFetch('api/address.php?action=provinces')).provinces;
        }
        rows = ADDR_CACHE.provinces;
      }else if(level === 1){
        const c = picked.prov.province_code;
        if(!ADDR_CACHE.cities[c]){
          ADDR_CACHE.cities[c] = (await addrFetch(`api/address.php?action=cities&province=${encodeURIComponent(c)}`)).cities;
        }
        rows = ADDR_CACHE.cities[c];
      }else{
        const c = picked.city.city_code;
        if(!ADDR_CACHE.barangays[c]){
          ADDR_CACHE.barangays[c] = (await addrFetch(`api/address.php?action=barangays&city=${encodeURIComponent(c)}`)).barangays;
        }
        rows = ADDR_CACHE.barangays[c];
      }
      input.value = '';
      input.placeholder = level===0 ? 'Type to filter provinces…'
                        : level===1 ? 'Type to filter municipalities…'
                        :             'Type to filter barangays…';
      render();
      input.focus();
    }catch(err){
      panel.hidden = true; input.setAttribute("aria-expanded","false");
      toast('Could not load addresses','error');
    }
  }

  function close(restore=true){
    panel.hidden = true; input.setAttribute("aria-expanded","false");
    if(restore) input.value = committed;
    input.placeholder = 'Province, Municipality, Barangay';
  }

  function commit(){
    // PSGC keeps suffixes like "(Capital)" in the official name; drop them
    // for display while the stored code stays canonical.
    const clean = s => s.replace(/\s*\([^)]*\)/g,'').trim();
    committed = [picked.prov.province_name, picked.city.city_name, picked.brgy.brgy_name]
                  .map(clean).join(', ');
    input.value = committed;
    close(false);
    if(onPick) onPick(codes());
  }

  panel.addEventListener('mousedown', e=>{
    const back = e.target.closest('.addr-back');
    const opt  = e.target.closest('.addr-opt');
    e.preventDefault();                       // keep focus on the input
    if(back){ level--; if(level===0) picked.city=null; load(); return; }
    if(!opt) return;
    const r = rows[Number(opt.dataset.i)];
    if(level===0){ picked.prov=r; picked.city=null; picked.brgy=null; level=1; load(); }
    else if(level===1){ picked.city=r; picked.brgy=null; level=2; load(); }
    else { picked.brgy=r; commit(); }
  });

  // Open where the user already is: once GPS or an earlier pick has settled a
  // municipality, start on its barangay list so the detected value can be
  // confirmed or corrected in one tap. Back climbs out.
  input.addEventListener('focus', ()=>{
    if(panel.hidden){ level = picked.city ? 2 : picked.prov ? 1 : 0; load(); }
  });
  input.addEventListener('input', ()=>{ if(!panel.hidden) render(input.value); });
  input.addEventListener('keydown', e=>{ if(e.key==='Escape') close(); });
  // Capture phase on purpose. Choosing an option re-renders the panel
  // synchronously, which detaches the clicked button — so by the bubble
  // phase wrap.contains(e.target) is false and this would wrongly treat a
  // valid pick as a click outside, closing the panel and blanking the field.
  document.addEventListener('mousedown', e=>{ if(!wrap.contains(e.target)) close(); }, true);

  // Lets GPS autofill set the field without the picker wiping it. When the
  // detail carries PSGC codes the picker adopts them, so opening the dropdown
  // afterwards shows the detected place instead of an empty province list,
  // and the back button still climbs out to municipality and province.
  // A plain string is still accepted, which is what resetAddressCombo sends.
  input.addEventListener('addr:set', e=>{
    const d = (e.detail && typeof e.detail === 'object') ? e.detail : { text: e.detail || '' };
    const nm = s => String(s || '').replace(/\s*\([^)]*\)/g, '').trim();
    committed  = d.text || '';
    input.value = committed;
    picked = {
      prov: d.province_code ? { province_code: d.province_code, province_name: nm(d.province_name) } : null,
      city: d.city_code     ? { city_code:     d.city_code,     city_name:     nm(d.city_name)     } : null,
      brgy: d.brgy_code     ? { brgy_code:     d.brgy_code,     brgy_name:     nm(d.brgy_name)     } : null
    };
    level = 0;
  });
}

// Clears a combo so a reopened modal does not show the previous address.
function resetAddressCombo(inputId){
  const input = document.getElementById(inputId);
  if(!input) return;
  input.value = '';
  input.dispatchEvent(new CustomEvent('addr:set', { detail:'' }));
}

// ── TWO-STAGE VERIFICATION ───────────────────────────────
// Supervisor sees work a field worker has marked done; inspector sees only
// what a supervisor nominated them to check on site.
let _vfRole  = 'supervisor';
let _vfStage = 'pending_supervisor';   // which of the three stages this modal is acting at

async function renderVerifyQueue(role){
  _vfRole = role;
  const el = document.getElementById(role === 'supervisor' ? 'sup-verify-list' : 'insp-verify-list');
  if(!el) return;

  // The supervisor owns two stages: the first review, and the final closure
  // after the inspector has confirmed on site.
  const want = role === 'supervisor' ? ['pending_supervisor','pending_closure'] : ['pending_inspector'];
  const wos  = (await myWorkOrders()).filter(w => want.includes(w.status));

  // Separate key per role so the supervisor's choice and the inspector's
  // choice do not overwrite each other.
  const vkey = 'verify-' + role;
  syncSortBar(vkey, el, true, wos.length, ()=>renderVerifyQueue(role));

  if(!wos.length){
    el.innerHTML = '<div class="empty-state"><p>Nothing awaiting your verification.</p></div>';
    return;
  }

  el.innerHTML = applySort(vkey, wos, 'created_at', true).map(w=>`
    <div class="queue-card">
      <div class="report-card-meta">
        <span class="badge badge-${w.severity}">${cap(w.severity)}</span>
        ${woStatusBadge(w.status)}
        <span style="font-size:12px;color:var(--muted)">${esc(w.wo_code)}</span>
      </div>
      <h4>${esc(w.report_title)}</h4>
      <p>${esc(w.latest_remarks || 'No remarks from the field worker.')}</p>
      ${w.status==='pending_closure'?`<div style="margin-top:6px;font-size:11.5px;color:var(--green)">Confirmed on site by the inspector &mdash; ready to close.</div>`:''}
      <div class="queue-card-foot">
        <span class="report-card-loc" style="font-size:12px;color:var(--muted)">📍 ${esc(w.address_text || w.task_location || w.location_text)}</span>
        <span style="font-size:11.5px;color:var(--muted)">Done by ${esc(w.assigned_to_name || 'unassigned')}</span>
        <button class="btn btn-amber btn-sm" onclick="openVerify(${w.wo_id})">${w.status==='pending_closure'?'Final review &rsaquo;':'Review &rsaquo;'}</button>
      </div>
    </div>`).join('');
}

async function openVerify(woId){
  const res  = await fetch(`api/workorders.php?action=detail&id=${woId}`, { credentials:'include' });
  const data = await res.json();
  if(!data.success){ toast('Could not load work order','error'); return; }
  const w = data.data.work_order;
  const updates = data.data.updates || [];
  // The field worker's completion — not updates[0], which at the inspector
  // stage is the supervisor's approval and carries no remarks or photos.
  // Falls back to the worker's latest entry, never a verification step's.
  const last = updates.find(u => u.status === 'pending_supervisor')
            || updates.find(u => !['pending_inspector', 'pending_closure', 'completed', 'rework'].includes(u.status));

  document.getElementById('vf-wo-id').value = woId;
  _vfStage = w.status;
  setText('vf-title', {
    pending_supervisor: 'Verify Completed Work',
    pending_inspector:  'Verify On Site',
    pending_closure:    'Final Review & Close'
  }[_vfStage] || 'Verify Work Order');
  setText('vf-sub', `${w.wo_code} · ${w.report_title}`);
  document.getElementById('vf-remarks').value = '';

  // Everything needed to judge the work, so the supervisor never has to open
  // another tab to decide.
  setText('vf-sev', cap(w.severity));
  setText('vf-address', w.address_text || w.task_location || '—');
  setText('vf-gps', w.location_text || '—');
  setText('vf-crew', w.crew_names || w.assigned_to_name || '—');
  setText('vf-deadline', w.deadline ? fmtDate(w.deadline) : 'None set');
  setText('vf-report-desc', w.report_description || 'No description recorded.');
  setText('vf-instructions', w.instructions || 'No specific instructions given.');
  setText('vf-worker-note', last ? (last.remarks || '(no remarks given)') : 'No completion report recorded.');

  // The field worker's photos are the actual evidence the decision rests on.
  const shots = (last && last.photos) ? last.photos.map(p => p.file_path) : [];
  const pbox = document.getElementById('vf-photos');
  if(pbox){
    pbox.innerHTML = shots.length
      ? shots.map((src,i)=>`<img src="${esc(src)}" class="photo-view-thumb" onclick="openLightbox(window.currentDetailPhotos, ${i})"/>`).join('')
      : '<span style="font-size:12px;color:var(--muted)">No photos submitted.</span>';
  }

  // At final review, what the inspector found on site. Its photos follow the
  // field worker's in the lightbox.
  const site = _vfStage === 'pending_closure' ? updates.find(u => u.status === 'pending_closure') : null;
  const siteShots = (site && site.photos) ? site.photos.map(p => p.file_path) : [];
  const sitePanel = document.getElementById('vf-site-panel');
  if(sitePanel){
    sitePanel.style.display = site ? '' : 'none';
    if(site){
      setText('vf-site-head', `Inspector's Site Check · ${site.updated_by_name || ''}`);
      setText('vf-site-note', site.remarks || '(no remarks given)');
      document.getElementById('vf-site-photos').innerHTML = siteShots.length
        ? siteShots.map((src,i)=>`<img src="${esc(src)}" class="photo-view-thumb" onclick="openLightbox(window.currentDetailPhotos, ${shots.length + i})"/>`).join('')
        : '<span style="font-size:12px;color:var(--muted)">No photos from the site check.</span>';
    }
  }
  window.currentDetailPhotos = shots.concat(siteShots);

  // The inspector adds their own photos at the site check.
  const up = document.getElementById('vf-site-upload');
  if(up) up.style.display = _vfStage === 'pending_inspector' ? '' : 'none';
  window.selectedSitePhotos = [];
  renderSitePhotoPreview();

  // Only the supervisor nominates an inspector; the inspector stage has none,
  // so the remarks box takes the full width there.
  // Only the first supervisor stage nominates an inspector. The closure stage
  // has one already, so the remarks box takes the full width there too.
  const grp = document.getElementById('vf-inspector-group');
  const needsInspector = _vfStage === 'pending_supervisor';
  if(grp){
    grp.style.display = needsInspector ? 'block' : 'none';
    const row = grp.parentElement;
    if(row) row.style.gridTemplateColumns = needsInspector ? '' : '1fr';
  }

  // Relabel the confirm button for whichever stage this is.
  const okBtn = document.getElementById('vf-approve-btn');
  if(okBtn) okBtn.textContent = {
    pending_supervisor: 'Assign',
    pending_inspector:  'Mark as Complete',
    pending_closure:    'Mark as Done'
  }[_vfStage] || 'Approve';

  if(needsInspector){
    const iRes  = await fetch('api/users.php?action=list&role=field_inspector', { credentials:'include' });
    const iData = await iRes.json();
    const inspectors = iData.success ? iData.data.users : [];
    const sel = document.getElementById('vf-inspector');
    sel.innerHTML = '<option value="">Select inspector...</option>' +
      inspectors.map(u=>`<option value="${u.user_id}">${esc(u.name)}</option>`).join('');
    // The inspector who filed the report has already been to the site.
    if(w.submitted_by) sel.value = String(w.submitted_by);
  }

  openModal('modal-verify');
}

async function submitVerify(decision){
  const woId      = document.getElementById('vf-wo-id').value;
  const remarks   = document.getElementById('vf-remarks').value.trim();
  const inspector = document.getElementById('vf-inspector').value;

  if(decision === 'reject' && !remarks){
    toast('Say what needs reworking','error');
    document.getElementById('vf-remarks').focus();
    return;
  }
  if(decision === 'approve' && _vfStage === 'pending_supervisor' && !inspector){
    toast('Choose an inspector to verify on site','error');
    return;
  }

  const onSite = _vfStage === 'pending_inspector';
  if(decision === 'approve' && onSite && !window.selectedSitePhotos.length){
    toast('Add at least one photo of the finished work','error');
    return;
  }

  const fd = new FormData();
  fd.append('wo_id',    woId);
  fd.append('decision', decision);
  fd.append('remarks',  remarks);
  if(inspector) fd.append('verify_inspector_id', inspector);
  if(onSite) window.selectedSitePhotos.forEach(p => fd.append('photos[]', p.file));
  fd.append('csrf_token', window.CSRF_TOKEN || '');

  const res = await fetch('api/workorders.php?action=verify', {
    method:'POST', credentials:'include', body: fd
  }).then(r=>r.json()).catch(()=>({ success:false, message:'Cannot reach the server.' }));

  toast(res.message, res.success ? 'success' : 'error');
  const warnings = (res.data && res.data.photo_warnings) || [];
  if(res.success && warnings.length){
    toast(`${warnings.length} photo${warnings.length>1?'s':''} skipped: ${warnings.join(' ')}`, 'error');
  }
  if(res.success){
    closeModal('modal-verify');
    refreshActiveView();
  }
}

// ── WORK ORDER CREW ──────────────────────────────────────
// One dropdown per slot. Low/moderate work gets two, high/critical three.
// Slot 1 is the lead — the only person who may update the status — so the
// lead is simply "whoever is in slot 1", with no separate control.
// The server enforces the cap and the lead rule independently.
//
// `pfx` names the container pair: 'a' for the assign modal, 'ec' for the
// edit-crew modal.
function crewCap(severity){
  return (severity === 'high' || severity === 'critical') ? 3 : 2;
}

function _assignSeverity(){
  const ov = document.getElementById('a-sev-override');
  if(ov && ov.value) return ov.value;
  const cur = document.getElementById('a-sev');
  return (cur ? cur.value : '').toLowerCase();
}

const _crewState = {};   // per-prefix: { workers, severity }

function renderCrewPicker(workers, severity, preselected, pfx = 'a'){
  const box = document.getElementById(`${pfx}-crew`);
  if(!box) return;
  _crewState[pfx] = { workers: workers || [], severity };

  const cap    = crewCap(severity);
  const lead   = (preselected || []).find(p => Number(p.is_lead) === 1);
  const rest   = (preselected || []).filter(p => Number(p.is_lead) !== 1);
  const chosen = [lead, ...rest].map(p => p ? String(p.user_id) : '');

  let html = '';
  for(let i = 0; i < cap; i++){
    html += `
      <div class="crew-row">
        <span class="crew-role${i === 0 ? ' is-lead' : ''}">${i === 0 ? 'Lead' : 'Support'}</span>
        <select class="form-control crew-slot" data-slot="${i}" onchange="crewChanged('${pfx}')">
          <option value="">${i === 0 ? 'Select lead field worker...' : 'Select field worker (optional)...'}</option>
        </select>
      </div>`;
  }
  box.innerHTML = html;
  box.querySelectorAll('.crew-slot').forEach((sel, i) => { sel.dataset.want = chosen[i] || ''; });
  // These slots are rebuilt while the modal is already open, so wrap them now
  // rather than waiting on the observer, which needs a frame to run.
  ddEnhanceAll(box);
  crewChanged(pfx);
}

function crewChanged(pfx = 'a'){
  const box  = document.getElementById(`${pfx}-crew`);
  const hint = document.getElementById(`${pfx}-crew-hint`);
  if(!box) return;

  const st = _crewState[pfx] || { workers: [], severity: 'low' };
  // The assign modal's severity can change mid-form; the edit modal's cannot.
  const severity = pfx === 'a' ? _assignSeverity() : st.severity;
  const cap      = crewCap(severity);
  let slots      = [...box.querySelectorAll('.crew-slot')];

  // The cap follows the severity, so add or drop slots when it changes.
  if(slots.length !== cap){
    const keep = slots.map(s => s.value).filter(Boolean).slice(0, cap);
    renderCrewPicker(st.workers, severity,
      keep.map((id, i) => ({ user_id:id, is_lead: i === 0 ? 1 : 0 })), pfx);
    return;
  }

  // Nobody may appear in two slots, so each dropdown hides the others' picks.
  const taken = slots.map(s => s.value || s.dataset.want || '').filter(Boolean);
  slots.forEach((sel, i) => {
    const mine   = sel.value || sel.dataset.want || '';
    const others = taken.filter(v => v !== mine);
    sel.innerHTML =
      `<option value="">${i === 0 ? 'Select lead field worker...' : 'Select field worker (optional)...'}</option>` +
      st.workers
        .filter(u => !others.includes(String(u.user_id)))
        .map(u => `<option value="${u.user_id}">${esc(u.name)}</option>`).join('');
    sel.value = mine;
    delete sel.dataset.want;
    // A support slot is pointless before a lead is chosen. Clear it too,
    // rather than leaving a stale name in a greyed-out dropdown.
    sel.disabled = i > 0 && !slots[0].value;
    if(sel.disabled) sel.value = '';
  });

  // .value and .disabled fire no event, so refresh the custom buttons
  // explicitly instead of relying on observer timing.
  ddRepaintAll(box);

  const picked = slots.map(s => s.value).filter(Boolean);
  if(hint){
    hint.textContent = !slots[0].value
      ? `Up to ${cap} for this severity`
      : `${picked.length} of ${cap} assigned`;
  }
}

function crewSelection(pfx = 'a'){
  const box = document.getElementById(`${pfx}-crew`);
  if(!box) return { worker_ids: [], lead_id: 0 };
  const slots = [...box.querySelectorAll('.crew-slot')];
  const ids   = slots.map(s => Number(s.value)).filter(Boolean);
  // Slot 1 is the lead by construction.
  return { worker_ids: ids, lead_id: Number(slots[0]?.value || 0) };
}

// ── EDIT CREW ON AN EXISTING WORK ORDER ──────────────────
async function openEditCrew(woId){
  const res  = await fetch(`api/workorders.php?action=detail&id=${woId}`, { credentials:'include' });
  const data = await res.json();
  if(!data.success){ toast('Could not load work order','error'); return; }
  const w = data.data.work_order;

  if(w.status === 'completed'){ toast('This work order is already closed','error'); return; }

  const wRes  = await fetch('api/users.php?action=list&role=field_worker', { credentials:'include' });
  const wData = await wRes.json();

  document.getElementById('ec-wo-id').value = woId;
  setText('ec-sub', `${w.wo_code} · ${w.report_title}`);
  renderCrewPicker(wData.success ? wData.data.users : [], w.severity, data.data.crew || [], 'ec');
  openModal('modal-edit-crew');
}

async function submitEditCrew(){
  const woId = document.getElementById('ec-wo-id').value;
  const crew = crewSelection('ec');
  if(!crew.lead_id){ toast('Choose the lead field worker','error'); return; }

  const res = await fetch('api/workorders.php?action=update_assignees', {
    method:'POST', credentials:'include',
    headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
    body: JSON.stringify({ wo_id:woId, worker_ids:crew.worker_ids, lead_id:crew.lead_id })
  }).then(r=>r.json()).catch(()=>({ success:false, message:'Cannot reach the server.' }));

  toast(res.message, res.success ? 'success' : 'error');
  if(res.success){ closeModal('modal-edit-crew'); refreshActiveView(); }
}

// ── EDIT A SUBMITTED REPORT ──────────────────────────────
// Open until a supervisor turns it into a work order. Saving re-runs the
// classifier, so severity never contradicts the description it came from.
let _erCodes = { province_code:null, city_code:null, brgy_code:null };

async function openEditReport(reportId){
  const res  = await fetch(`api/reports.php?action=detail&id=${reportId}`, { credentials:'include' });
  const data = await res.json();
  if(!data.success){ toast('Could not load report','error'); return; }
  const r = data.data.report;

  if(data.data.work_order){
    toast('A work order already exists for this report — it can no longer be edited','error');
    return;
  }

  document.getElementById('er-report-id').value = reportId;
  setText('er-sub', `${r.report_code} · currently ${cap(r.severity)}`);
  document.getElementById('er-title').value   = r.title || '';
  document.getElementById('er-desc').value    = r.description || '';
  document.getElementById('er-loc').value     = r.location_text || '';
  document.getElementById('er-phase').value   = r.phase || 'construction';
  document.getElementById('er-address').value = r.address_text || '';
  _erCodes = {
    province_code: r.province_code || null,
    city_code:     r.city_code     || null,
    brgy_code:     r.brgy_code     || null
  };

  initAddressCombo('er-address', codes => { _erCodes = codes; });
  document.getElementById('er-address')
    .dispatchEvent(new CustomEvent('addr:set', { detail: r.address_text || '' }));

  openModal('modal-edit-report');
}

async function submitEditReport(){
  const id    = document.getElementById('er-report-id').value;
  const title = document.getElementById('er-title').value.trim();
  const desc  = document.getElementById('er-desc').value.trim();
  const loc   = document.getElementById('er-loc').value.trim();

  if(!title || !desc || !loc){ toast('Title, description and location are required','error'); return; }
  if(desc.length < 15){ toast('Describe the issue in a full sentence so it can be classified','error'); return; }

  const res = await fetch('api/reports.php?action=edit', {
    method:'POST', credentials:'include',
    headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
    body: JSON.stringify({
      report_id: id, title, description: desc, location_text: loc,
      address_text: document.getElementById('er-address').value.trim(),
      phase: document.getElementById('er-phase').value,
      province_code: _erCodes.province_code,
      city_code:     _erCodes.city_code,
      brgy_code:     _erCodes.brgy_code
    })
  }).then(r=>r.json()).catch(()=>({ success:false, message:'Cannot reach the server.' }));

  if(!res.success){ toast(res.message,'error'); return; }

  closeModal('modal-edit-report');
  toast(`${res.message} Now ${String(res.data.severity).toUpperCase()} (${res.data.confidence}%)`, 'success');
  refreshActiveView();
}

// ── SORTING CONTROLS ─────────────────────────────────────
// One reusable bar per list: a "sort by" dropdown and a direction toggle.
// It mounts itself above the list container, so no dashboard markup changes.
// Sorting is done client side on data the page already holds.
const SORT = {};                 // listKey -> { by:'severity'|'date', dir:'desc'|'asc' }
const SORT_RERENDER = {};        // listKey -> function that redraws that list
/* ═══ INFO POPOVER ═══
   One open at a time. Closes on outside click and on Escape, and flips to
   the right edge when the panel would overflow the window. */
function infoIcon(html, label){
  return `<span class="info-wrap">`
    + `<button type="button" class="info-btn" aria-expanded="false" `
    + `aria-label="${esc(label || 'More information')}" onclick="toggleInfo(this)">`
    + `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" `
    + `stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-5"/>`
    + `<path d="M12 8h.01"/></svg></button>`
    + `<span class="info-pop" role="note" hidden>${html}</span></span>`;
}
function closeAllInfo(){
  document.querySelectorAll('.info-btn[aria-expanded="true"]').forEach(b => {
    b.setAttribute('aria-expanded', 'false');
    const p = b.nextElementSibling;
    if (p) { p.hidden = true; p.style.transform = ''; }
    b.parentElement.classList.remove('info-right');
  });
}
function toggleInfo(btn){
  const wasOpen = btn.getAttribute('aria-expanded') === 'true';
  closeAllInfo();
  if (wasOpen) return;
  const pop = btn.nextElementSibling;
  if (!pop) return;
  btn.setAttribute('aria-expanded', 'true');
  pop.hidden = false;
  // Measured after it is visible: width:max-content means the box has no
  // size to measure until then.
  if (pop.getBoundingClientRect().right > window.innerWidth - 8) {
    btn.parentElement.classList.add('info-right');
  }
  // Flipped and still off screen (an (i) near the left of a phone): slide
  // it back in by however far it overshoots.
  const left = pop.getBoundingClientRect().left;
  if (left < 8) pop.style.transform = `translateX(${8 - left}px)`;
}
document.addEventListener('click', e => { if (!e.target.closest('.info-wrap')) closeAllInfo(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAllInfo(); });

const SEV_RANK = { critical:4, high:3, moderate:2, low:1 };

// A <div> cannot live inside <table>, so for a tbody we climb to the wrapper
// (or the table itself) and mount above that. The mobile card list is a
// sibling just below, so one bar serves the desktop and mobile layouts.
function sortAnchor(id){
  const el = document.getElementById(id);
  if(!el) return null;
  return el.closest('.table-wrap') || el.closest('table') || el;
}

function clearSortBar(key){
  const bar = document.getElementById('sortbar-' + key);
  if(bar) bar.remove();
}

// Show the bar only when the list actually has rows. The remembered choice in
// SORT[key] survives, so it comes back the way you left it.
function syncSortBar(key, anchorEl, hasSeverity, count, rerender){
  if(count) mountSortBar(key, anchorEl, hasSeverity, rerender);
  else clearSortBar(key);
}

function sortState(key, hasSeverity){
  if(!SORT[key]) SORT[key] = { by: hasSeverity ? 'severity' : 'date', dir: 'desc' };
  return SORT[key];
}

function mountSortBar(key, anchorEl, hasSeverity, rerender){
  if(!anchorEl) return;
  SORT_RERENDER[key] = rerender;
  const s = sortState(key, hasSeverity);
  const id = `sortbar-${key}`;
  let bar = document.getElementById(id);
  if(!bar){
    bar = document.createElement('div');
    bar.id = id;
    bar.className = 'sort-bar';
    anchorEl.parentNode.insertBefore(bar, anchorEl);
  }
  bar.innerHTML =
    `<span class="sort-label">Sort by</span>
     <select class="sort-select" onchange="setSortBy('${key}', this.value)">
       ${hasSeverity ? `<option value="severity"${s.by==='severity'?' selected':''}>Severity</option>` : ''}
       <option value="date"${s.by==='date'?' selected':''}>Date</option>
     </select>
     <button class="sort-dir ${s.dir==='desc' ? 'is-desc' : 'is-asc'}" onclick="flipSortDir('${key}')"
             title="${s.dir==='desc' ? 'Highest / newest first' : 'Lowest / oldest first'}">
       <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
            stroke-linecap="round" stroke-linejoin="round">
         <g class="sd-down"><polyline points="7 4 7 20"/><polyline points="4 17 7 20 10 17"/></g>
         <g class="sd-up"><polyline points="17 20 17 4"/><polyline points="14 7 17 4 20 7"/></g>
       </svg>
     </button>`;
}

function setSortBy(key, by){
  sortState(key, true).by = by;
  if(SORT_RERENDER[key]) SORT_RERENDER[key]();
}

function flipSortDir(key){
  const s = sortState(key, true);
  s.dir = s.dir === 'desc' ? 'asc' : 'desc';
  if(SORT_RERENDER[key]) SORT_RERENDER[key]();
}

// dateField differs per list (submitted_at, created_at, updated_at).
function applySort(key, items, dateField, hasSeverity){
  const s = sortState(key, hasSeverity);
  const sign = s.dir === 'desc' ? -1 : 1;
  return [...items].sort((a,b)=>{
    if(s.by === 'severity' && hasSeverity){
      const d = (SEV_RANK[a.severity]||0) - (SEV_RANK[b.severity]||0);
      // Same severity falls back to date, newest first, as it was before.
      if(d !== 0) return sign * d;
      return -1 * (new Date(a[dateField]) - new Date(b[dateField]));
    }
    return sign * (new Date(a[dateField]) - new Date(b[dateField]));
  });
}

// ── CUSTOM DROPDOWNS ─────────────────────────────────────────
// A native <select> list is drawn by the browser or the OS, not by the page:
// on Android it is a system dialog, on desktop a native popup. It follows the
// device theme, so on a light device it paints a white panel over our dark UI
// and no CSS on the control can reach it. We keep the real <select> as the
// source of truth — every existing .value read, option write and change
// handler keeps working — and draw the list ourselves.
let _ddOpen = null;
const DD_STYLE_CLASSES = ['form-control', 'sort-select'];

function ddClose(){
  if(!_ddOpen) return;
  _ddOpen.classList.remove('open');
  _ddOpen = null;
}

// The panel is position:fixed so a scrolling .modal-body cannot clip it.
function ddPlace(wrap){
  const btn   = wrap.querySelector('.cf-dd-btn');
  const panel = wrap.querySelector('.cf-dd-panel');
  if(!btn || !panel) return;
  const r = btn.getBoundingClientRect();
  panel.style.minWidth = r.width + 'px';
  panel.style.left     = r.left + 'px';
  panel.style.top      = 'auto';
  panel.style.bottom   = 'auto';
  const below = window.innerHeight - r.bottom;
  if(below < 180 && r.top > below){
    panel.style.bottom = (window.innerHeight - r.top + 4) + 'px';   // flip up
  }else{
    panel.style.top = (r.bottom + 4) + 'px';
  }
}

function ddPaint(wrap){
  const sel   = wrap.querySelector('select');
  const btn   = wrap.querySelector('.cf-dd-btn');
  const panel = wrap.querySelector('.cf-dd-panel');
  if(!sel || !btn || !panel) return;
  const opt = sel.options[sel.selectedIndex];
  btn.innerHTML =
    `<span class="cf-dd-label">${esc(opt ? opt.text : '')}</span>`
  + `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"`
  + ` stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>`;
  btn.disabled = sel.disabled;
  panel.innerHTML = Array.from(sel.options).map((o,i)=>
    `<button type="button" class="cf-dd-opt${i===sel.selectedIndex?' is-sel':''}"`
  + ` data-i="${i}"${o.disabled?' disabled':''}>${esc(o.text)}</button>`
  ).join('');
}

function ddEnhance(sel){
  if(sel.dataset.cfDd) return;
  sel.dataset.cfDd = '1';

  const wrap = document.createElement('div');
  wrap.className = 'cf-dd';
  sel.parentNode.insertBefore(wrap, sel);
  wrap.appendChild(sel);

  // Only presentational classes move to the button. A class the app also
  // queries as a JS hook (.crew-slot) has to stay unique to the <select>, or
  // box.querySelectorAll('.crew-slot') counts the button too and the crew
  // picker recurses rebuilding itself.
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = ['cf-dd-btn',
    ...DD_STYLE_CLASSES.filter(c => sel.classList.contains(c))].join(' ');

  const panel = document.createElement('div');
  panel.className = 'cf-dd-panel';

  wrap.appendChild(btn);
  wrap.appendChild(panel);
  ddPaint(wrap);

  btn.addEventListener('click', e=>{
    e.preventDefault(); e.stopPropagation();
    const wasOpen = wrap.classList.contains('open');
    ddClose();
    if(wasOpen || sel.disabled) return;
    ddPaint(wrap);
    wrap.classList.add('open');
    ddPlace(wrap);
    _ddOpen = wrap;
  });

  panel.addEventListener('click', e=>{
    const o = e.target.closest('.cf-dd-opt');
    if(!o || o.disabled) return;
    e.preventDefault(); e.stopPropagation();
    sel.selectedIndex = Number(o.dataset.i);
    ddClose();
    ddPaint(wrap);
    sel.dispatchEvent(new Event('change', { bubbles:true }));
  });

  // Several of these are filled in after a fetch, so redraw when the
  // <select> gains or loses options.
  new MutationObserver(()=>ddPaint(wrap)).observe(sel, { childList:true });
  sel.addEventListener('change', ()=>ddPaint(wrap));
}

function ddEnhanceAll(root){
  (root || document).querySelectorAll('select:not([data-cf-dd])').forEach(ddEnhance);
}
function ddRepaintAll(root){
  (root || document).querySelectorAll('.cf-dd').forEach(ddPaint);
}

// Capture phase on purpose: choosing an option re-renders the panel, which
// detaches the element the event started on.
document.addEventListener('mousedown', e=>{
  if(_ddOpen && !_ddOpen.contains(e.target)) ddClose();
}, true);
document.addEventListener('keydown', e=>{ if(e.key === 'Escape') ddClose(); });
// A fixed panel would drift away from its button, so close rather than chase.
window.addEventListener('resize', ddClose);
window.addEventListener('scroll', ddClose, true);

// Lists re-render constantly, and the crew slots and sort bars build their
// selects at run time. One rAF-debounced pass per frame covers both.
let _ddQueued = false;
new MutationObserver(()=>{
  if(_ddQueued) return;
  _ddQueued = true;
  requestAnimationFrame(()=>{ _ddQueued = false; ddEnhanceAll(); });
}).observe(document.documentElement, { childList:true, subtree:true });

document.addEventListener('DOMContentLoaded', ()=>ddEnhanceAll());

// ── FILTER BAR ON A PHONE ────────────────────────────────
/* Under 720px each filter bar hides behind a Filter button and opens in a
 * small overlay. Print
 * stays on the page beside the button. The bar is moved into the overlay
 * while open and put back on close, so its ids, listeners and values are
 * the same elements throughout and every filter applies the moment it
 * changes; Done only closes. Above 720px CSS hides the toolbar and the
 * bar shows inline. */
let _fbSeq = 0;
function fbEnhance(bar){
  if (bar.dataset.fb) return;
  bar.dataset.fb = '1';
  const id = 'fb-modal-' + (_fbSeq++);

  const ov = document.createElement('div');
  ov.className = 'overlay fb-overlay';
  ov.id = id;
  ov.innerHTML =
      '<div class="modal">'
    +   '<div class="modal-header"><div><h2>Filter</h2></div>'
    +     '<button type="button" class="btn-close" aria-label="Close">×</button></div>'
    +   '<div class="modal-body"></div>'
    +   '<div class="modal-footer"><button type="button" class="btn btn-amber">Done</button></div>'
    + '</div>';
  document.body.appendChild(ov);

  // Marks where the bar lives on the page while it is in the overlay.
  const home = document.createComment('filter bar');
  const open = () => {
    if (home.parentNode) return;
    bar.parentNode.insertBefore(home, bar);
    ov.querySelector('.modal-body').appendChild(bar);
    bar.classList.add('fb-in-modal');
    openModal(id);
  };
  const close = () => {
    closeModal(id);
    if (!home.parentNode) return;
    bar.classList.remove('fb-in-modal');
    home.parentNode.insertBefore(bar, home);
    home.remove();
  };
  ov.querySelector('.btn-close').addEventListener('click', close);
  ov.querySelector('.modal-footer .btn').addEventListener('click', close);

  const tb  = document.createElement('div');
  tb.className = 'fb-toolbar';
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'btn btn-dark';
  btn.textContent = 'Filter';
  btn.addEventListener('click', open);
  tb.appendChild(btn);

  // Print is an action, not a filter, so a copy stays on the page.
  bar.querySelectorAll('.fb-actions button').forEach(b => {
    const c = b.cloneNode(true);
    c.setAttribute('aria-label', b.textContent.trim());
    c.textContent = 'Print';
    tb.appendChild(c);
  });
  bar.parentNode.insertBefore(tb, bar);
}
document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.filter-bar').forEach(fbEnhance));
ddEnhanceAll();

// ── NETWORK FAILURE FEEDBACK ─────────────────────────────────
// Nearly every fetch in this file is awaited without a catch, so a dropped
// connection used to fail in total silence: a list stayed blank or kept
// showing stale rows, and a submitted form did nothing at all — no toast,
// no error, the modal just sat there and the work was never saved.
// One listener covers all of them without touching each call site.
let _netToastAt = 0;

function isNetworkError(err){
  // A refused/aborted fetch rejects with a TypeError; an HTTP 500 does not.
  return err instanceof TypeError &&
         /fetch|network|load failed|connection/i.test(String(err.message || ''));
}

function reportNetworkFailure(){
  const now = Date.now();
  if(now - _netToastAt < 4000) return;   // one message per burst, not per request
  _netToastAt = now;
  if(typeof toast !== 'function' || !document.getElementById('toasts')) return;
  toast(navigator.onLine
    ? 'Could not reach the server. Nothing was saved — please try again.'
    : 'You are offline. Nothing was saved — reconnect and try again.', 'error');
}

window.addEventListener('unhandledrejection', e => {
  if(!isNetworkError(e.reason)) return;
  console.warn('Network request failed:', e.reason);  // keep it visible for debugging
  reportNetworkFailure();
  e.preventDefault();                                 // handled; no console noise
});

window.addEventListener('offline', () => {
  if(typeof toast === 'function' && document.getElementById('toasts')){
    toast('You are offline. Changes will not save until you reconnect.', 'error');
  }
});

window.addEventListener('online', () => {
  if(typeof toast === 'function' && document.getElementById('toasts')){
    toast('Back online.', 'success');
  }
  // Whatever is on screen was last drawn before the connection dropped.
  if(typeof refreshActiveView === 'function') refreshActiveView();
});
