/* ═══════════════════════════════════════════════════════
   ConstructFlow — Team gate
   ═══════════════════════════════════════════════════════ */

const ROLE_PAGE = {
  field_inspector: 'inspector.html',
  supervisor:      'supervisor.html',
  field_worker:    'fieldworker.html',
  administrator:   'admin.html',
};

const ROLE_LABEL = {
  administrator:   'Administrator',
  supervisor:      'Supervisor',
  field_inspector: 'Field Inspector',
  field_worker:    'Field Worker',
  member:          'No role yet',
};

const el = id => document.getElementById(id);

// ── HTTP ──────────────────────────────────────────────────
async function api(file, action, body) {
  const url = `api/${file}.php?action=${action}`;
  try {
    const res = await fetch(url, body === undefined
      ? { credentials: 'include' }
      : {
          method: 'POST',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
          body: JSON.stringify(body),
        });
    return await res.json();
  } catch (e) {
    return { success: false, message: 'Cannot reach the server. Check your connection and try again.' };
  }
}

function showErr(id, msg) { const b = el(id); b.textContent = msg; b.classList.add('show'); }
function clearErr(id)     { const b = el(id); b.textContent = ''; b.classList.remove('show'); }
function banner(msg, kind = 'ok') {
  const b = el('t-banner');
  b.textContent = msg;
  b.className = `auth-banner show auth-banner-${kind}`;
}

function busy(btnId, on, label = 'Working…') {
  const b = el(btnId);
  if (!b) return;
  if (on) {
    b.dataset.label = b.textContent;
    b.disabled = true;
    b.innerHTML = `<span class="auth-spin"></span>${label}`;
  } else {
    b.disabled = false;
    b.textContent = b.dataset.label || b.textContent;
  }
}

// ── PANES ─────────────────────────────────────────────────
function showPane(which) {
  el('t-choices').style.display        = which === 'choices'   ? 'block' : 'none';
  el('t-pane-create').style.display    = which === 'create'    ? 'block' : 'none';
  el('t-pane-created').style.display   = which === 'created'   ? 'block' : 'none';
  el('t-pane-stranded').style.display  = which === 'stranded'  ? 'block' : 'none';
  el('t-list-card').style.display      = (which === 'choices' && hasTeams) ? 'block' : 'none';

  if (which === 'create') setTimeout(() => el('t-name').focus(), 60);
}

// ── ACTIONS ───────────────────────────────────────────────
async function createTeam() {
  const name = el('t-name').value.trim();
  const desc = el('t-desc').value.trim();
  clearErr('t-err-create');

  if (!name) { showErr('t-err-create', 'Give your team a name.'); el('t-name').focus(); return; }

  busy('t-btn-create', true, 'Creating…');
  const res = await api('teams', 'create', { name, description: desc });
  busy('t-btn-create', false);

  if (!res.success) { showErr('t-err-create', res.message); return; }

  el('t-created-name').textContent = `${res.data.team_name} is ready`;
  showPane('created');
}

/** Switch the active team, then go to that team's dashboard. */
async function openTeam(teamId, role) {
  const res = await api('auth', 'switch_team', { team_id: teamId });
  if (!res.success) { banner(res.message, 'err'); return; }
  window.location.href = ROLE_PAGE[role] || 'team.html';
}

function goToDashboard() {
  window.location.href = ROLE_PAGE['administrator'];
}

async function signOut() {
  await api('auth', 'logout', {});
  window.location.href = 'login.html';
}

// ── LOAD ──────────────────────────────────────────────────
let hasTeams = false;
let isOwner  = false;
let csrfToken = '';

async function load() {
  const me = await api('auth', 'me', undefined);

  if (!me.success) { window.location.href = 'login.html'; return; }

  csrfToken = me.data.csrf_token || '';

  el('t-who').textContent = me.data.user.name;

  // 'owner'  — self-registered. May create a team.
  // 'member' — account came from an invitation link. May not.
  // teams.php enforces this regardless of what is rendered here; the
  // check below only decides whether to offer a button that would fail.
  isOwner = me.data.user.account_type === 'owner';

  const teams   = me.data.memberships || [];
  const active  = teams.filter(t => t.status === 'active');
  hasTeams = teams.length > 0;

  // Exactly one active team with a real job role
  if (active.length === 1 && active[0].role !== 'member') {
    await openTeam(active[0].team_id, active[0].role);
    return;
  }

  // Hide the create option from anyone who is not an owner.
  const createChoice = el('t-choice-create');
  if (createChoice) createChoice.style.display = isOwner ? 'flex' : 'none';

  if (hasTeams) {
    el('t-title').textContent = 'Your teams';
    el('t-sub').textContent   = isOwner
      ? 'Pick a team to work in, or set up another one.'
      : 'Pick a team to work in.';
  } else if (!isOwner) {
    el('t-title').textContent = 'Waiting on an invitation';
    el('t-sub').textContent   = 'Your administrator invites people by email.';
  }

    el('t-list').innerHTML = teams.map(t => {
    const noRole = t.role === 'member';

    const pill = noRole
      ? '<span class="team-pill pill-member">No role yet</span>'
      : '<span class="team-pill pill-active">' + (ROLE_LABEL[t.role] || t.role) + '</span>';

    const action = noRole
      ? ''
      : `<button class="team-go" onclick="openTeam(${t.team_id},'${t.role}')">Open</button>`;

    const sub = noRole
      ? 'An administrator will assign your role.'
      : ROLE_LABEL[t.role];

    return `
      <div class="team-row">
        <div class="team-row-badge">${escapeHtml((t.team_name || '?').charAt(0).toUpperCase())}</div>
        <div class="team-row-body">
          <strong>${escapeHtml(t.team_name)}</strong>
          <span>${sub}</span>
        </div>
        ${pill}
        ${action}
      </div>`;
  }).join('');

  // Three-way gate. A member with nowhere to go gets an explanation
  // rather than a dead-end screen with two buttons they cannot use.
  showPane(!hasTeams && !isOwner ? 'stranded' : 'choices');
}

/** Team names come from other users, so they are escaped on output. */
function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

load();

if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
