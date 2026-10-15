/* ═══════════════════════════════════════════════════════════
   ConstructFlow — printable summaries

   Opened in its own tab as print.html?type=…&id=…, fetches one normalised
   payload from api/summary.php and renders whichever sections came back.
   Four document types share this renderer:

     task       the assignment
     report     the finding, with the engine's reasoning
     workorder  the repair job and its closure trail
     case       all three, the whole life of one defect

   Access is settled server-side before anything is rendered, so a URL
   typed by hand returns 403 rather than a page.
   ═══════════════════════════════════════════════════════════ */

const PARAMS = new URLSearchParams(location.search);
const TYPE   = PARAMS.get('type') || '';
const ID     = PARAMS.get('id')   || '';

const KIND_LABEL = {
  task:      'Inspection Task',
  report:    'Inspection Report',
  workorder: 'Work Order',
  case:      'Case File',
};

const el = id => document.getElementById(id);

/** Everything here is other people's text, so nothing reaches the page unescaped. */
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

const dash = v => (v === null || v === undefined || v === '' ? '—' : v);

function fmtDate(v, withTime = true) {
  if (!v) return '—';
  // MySQL DATETIME with a space is not a format Safari will parse.
  const d = new Date(String(v).replace(' ', 'T'));
  if (isNaN(d)) return esc(v);
  const date = d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  if (!withTime) return date;
  return `${date}, ${d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}`;
}

const cap     = s => (s ? String(s).charAt(0).toUpperCase() + String(s).slice(1) : '');
const statusText = s => cap(String(s || '').replace(/_/g, ' '));

function sev(s) {
  const v = String(s || '').toLowerCase();
  return `<span class="sev sev-${esc(v)}">${esc(v.toUpperCase() || '—')}</span>`;
}

/**
 * A <dl> row, skipped entirely when there is nothing to say.
 *
 * The pair is wrapped rather than emitted loose, because the fields flow
 * down columns: without a wrapper a column can break between a label and
 * its value and strand "COORDINATES:" at the foot of one column with the
 * numbers at the head of the next.
 */
function kv(label, value, { plain = false, skipEmpty = true } = {}) {
  if (skipEmpty && (value === null || value === undefined || value === '')) return '';
  return `<div class="kv-row"><dt>${esc(label)}</dt>` +
         `<dd class="${plain ? 'plain' : ''}">${value}</dd></div>`;
}

const section = (title, body) => (body ? `<section class="sec"><h2>${esc(title)}</h2>${body}</section>` : '');

/**
 * The masthead every printed document shares: the mark on the left, the
 * document type centred, then the identifying detail split left/right.
 *
 * The logo is an <img> rather than CSS so it survives "print background
 * graphics: off", which is the browser default — a sheet that loses its
 * identity on a default print setting is not much of a letterhead.
 */
function docHead({ kind, code, sub, who, team, when }) {
  return `<header class="doc-head">
    <div class="doc-brandbar">
      <img class="doc-logo" src="logo/logo.png" alt=""/>
      <div class="doc-org">
        <div class="org-1">ConstructFlow</div>
        <div class="org-2">${esc(team)}</div>
        <div class="org-3">Severity-Based Inspection and Work Order System</div>
      </div>
      <!-- Balances the mark so the two outer tracks are identical and the
           issuing lines centre on the page, not on what is left beside the
           mark. Occupies space, paints nothing. -->
      <img class="doc-logo doc-logo-ghost" src="logo/logo.png" alt="" aria-hidden="true"/>
    </div>
    <h1 class="doc-kind">${esc(kind)}</h1>
    <div class="doc-meta">
      <div class="doc-meta-l">
        ${code ? `<div class="doc-code">${esc(code)}${
          sub ? `<span class="doc-title"> — ${esc(sub)}</span>` : ''}</div>` : ''}
        ${who  ? `<div class="doc-who">${esc(who)}</div>` : ''}
        ${!code && sub ? `<div class="doc-sub">${esc(sub)}</div>` : ''}
      </div>
      <div class="doc-meta-r">
        <div>${fmtDate(when)}</div>
      </div>
    </div>
  </header>`;
}

/** Whose document this is — the person the reader will look for first. */
function personFor(d) {
  if (d.type === 'workorder') {
    return d.work_order ? (d.work_order.assigned_to_name || d.work_order.created_by_name || '') : '';
  }
  if (d.type === 'task') return d.task ? (d.task.assigned_to_name || '') : '';
  return d.report ? (d.report.submitted_by_name || '') : '';
}

/**
 * Who signs. The pairing follows the workflow rather than being decorative:
 * whoever did the work signs on the left, whoever accepted it on the right.
 */
function signatories(type) {
  switch (type) {
    case 'workorder': return ['Field Worker', 'Supervisor'];
    case 'task':      return ['Field Inspector', 'Supervisor'];
    default:          return ['Field Inspector', 'Supervisor'];
  }
}

// ── Sections ────────────────────────────────────────────────

function taskSection(t) {
  if (!t) return '';
  return section('Inspection task', `<dl class="kv">
    ${kv('Task code', esc(t.task_code))}
    ${kv('Title', esc(t.title))}
    ${kv('Assigned to', esc(t.assigned_to_name))}
    ${kv('Raised by', esc(t.created_by_name))}
    ${kv('Location', esc(t.location_text))}
    ${kv('Due date', t.due_date ? fmtDate(t.due_date, false) : '')}
    ${kv('Status', esc(statusText(t.status)))}
    ${kv('Created', fmtDate(t.created_at))}
    ${kv('Instructions', esc(t.description), { plain: true })}
  </dl>`);
}

function reportSection(r) {
  if (!r) return '';
  const coords = (r.latitude && r.longitude)
    ? `${Number(r.latitude).toFixed(5)}, ${Number(r.longitude).toFixed(5)}` : '';
  return section('Inspection report', `<dl class="kv">
    ${kv('Report code', esc(r.report_code))}
    ${kv('Title', esc(r.title))}
    ${kv('Submitted by', esc(r.submitted_by_name))}
    ${kv('Submitted', fmtDate(r.submitted_at))}
    ${kv('Issue type', esc(cap(r.issue_type)))}
    ${kv('Project phase', esc(r.phase === 'post_completion' ? 'After completion / warranty' : 'During construction'))}
    ${kv('Site', esc(r.location_text))}
    ${kv('Address', esc(r.address_text))}
    ${kv('Coordinates', esc(coords))}
    ${kv('Final severity', sev(r.severity))}
    ${kv('Status', esc(statusText(r.status)))}
  </dl>`) +
  // The finding in the inspector's own words gets its own labelled section
  // rather than trailing the field block unannounced: on a form, a block of
  // running text with no heading reads as a stray note.
  (r.description ? section('Description', `<div class="prose">${esc(r.description)}</div>`) : '');
}

/**
 * The engine's reasoning. This is the section that makes the document
 * worth printing — without it this is a form dump any system could emit.
 */
function engineSection(r) {
  if (!r || !r.recommended_severity) return '';

  const overridden = r.severity && r.recommended_severity &&
                     String(r.severity) !== String(r.recommended_severity);
  const conf  = r.classification_confidence;
  const terms = String(r.matched_terms || '')
    .split(/[,;]/).map(t => t.trim()).filter(Boolean);

  let figures = `
    <div class="eng-fig">Engine said<b>${sev(r.recommended_severity)}</b></div>
    <div class="eng-fig">Confidence<b>${conf === null || conf === undefined ? '—' : esc(conf) + '%'}</b></div>`;
  if (r.d_level) figures += `<div class="eng-fig">Defect (D)<b>${esc(r.d_level)}</b></div>`;
  if (r.c_level) figures += `<div class="eng-fig">Consequence (C)<b>${esc(r.c_level)}</b></div>`;
  figures += `<div class="eng-fig">Recorded as<b>${sev(r.severity)}</b></div>`;

  let body = `<div class="engine"><div class="eng-top">${figures}</div>`;

  if (r.defect_type_name || r.element_type_name) {
    body += `<div class="eng-pair">
      ${r.defect_type_name  ? `<span><i>Defect type</i> ${esc(r.defect_type_name)}</span>` : ''}
      ${r.element_type_name ? `<span><i>Element</i> ${esc(r.element_type_name)}</span>` : ''}
    </div>`;
  }
  if (terms.length) {
    body += `<div class="eng-note">Matched lexicon terms</div>
             <div class="terms">${terms.map(t => `<span>${esc(t)}</span>`).join('')}</div>`;
  }
  if (r.classification_notes) {
    body += `<div class="eng-note" style="margin-top:7px">${esc(r.classification_notes)}</div>`;
  }

  // needs_review is computed at classification time and never stored, so it
  // is restated here from what the row does keep — and labelled as derived
  // rather than presented as the original flag.
  if (conf !== null && conf !== undefined && Number(conf) < 80) {
    body += `<div class="flag"><b>Flagged for review (derived):</b> confidence below 80%.
             The classifier was not certain and a supervisor was asked to confirm.</div>`;
  }
  if (overridden) {
    body += `<div class="flag"><b>Severity overridden.</b>
             The engine recommended ${esc(String(r.recommended_severity).toUpperCase())};
             recorded as ${esc(String(r.severity).toUpperCase())}
             ${r.overridden_by_name ? 'by ' + esc(r.overridden_by_name) : ''}
             ${r.severity_overridden_at ? 'on ' + fmtDate(r.severity_overridden_at, false) : ''}.</div>`;
  }
  body += `</div>`;
  return section('Severity classification', body);
}

function workOrderSection(w, crew) {
  if (!w) return '';
  // One line rather than a table: a crew is two or three names, and a
  // table header plus a row each cost more paper than the facts are worth.
  const crewRows = (crew || []).length
    ? `<p class="prose prose-tight"><span class="crew-lab">Crew</span> ${
        crew.map(c => `${esc(c.name)}${Number(c.is_lead) === 1
          ? ' <span class="crew-tag">lead</span>' : ''}`).join(' · ')}</p>`
    : '';

  return section('Work order', `<dl class="kv">
      ${kv('Work order', esc(w.wo_code))}
      ${kv('Raised by', esc(w.created_by_name))}
      ${kv('Raised on', fmtDate(w.created_at))}
      ${kv('Priority', sev(w.severity))}
      ${kv('Deadline', w.deadline ? fmtDate(w.deadline, false) : '')}
      ${kv('Status', esc(statusText(w.status)))}
      ${kv('Instructions', esc(w.instructions), { plain: true })}
    </dl>${crewRows ? '<div style="margin-top:10px">' + crewRows + '</div>' : ''}`);
}

function updatesSection(updates) {
  if (!updates || !updates.length) return '';
  return section('Work log', `<table>
    <thead><tr><th style="width:34mm">When</th><th style="width:30mm">Status</th>
      <th style="width:34mm">By</th><th>Remarks</th></tr></thead>
    <tbody>${updates.map(u => `<tr>
      <td class="num">${fmtDate(u.updated_at)}</td>
      <td>${esc(statusText(u.status))}</td>
      <td>${esc(u.updated_by_name)}</td>
      <td>${esc(dash(u.remarks))}</td>
    </tr>`).join('')}</tbody></table>`);
}

/** The three-stage closure, shown as a trail so gaps are obvious. */
function closureSection(w, updates) {
  if (!w) return '';

  // WorkOrders has no completed_at column — the moment the field worker
  // marked the job done is the first work-log entry that handed it on.
  const handoff = (updates || []).find(u =>
    u.status === 'completed' || u.status === 'pending_supervisor');

  // One line per stage rather than three stacked: four stages were costing
  // 55mm of a 277mm page, which is a lot of paper for four facts.
  const step = (title, who, when) => {
    const ok = !!when;
    return `<li class="${ok ? 'ok' : ''}">
      <span class="t-stage">${title}</span>
      ${ok ? `<span class="t-who">${esc(who || '—')}</span>
              <span class="t-when">${fmtDate(when)}</span>`
           : `<span class="t-pending">Not yet recorded</span>`}
    </li>`;
  };
  return section('Verification and closure', `<ul class="trail">
    ${step('Work completed by field worker', handoff ? handoff.updated_by_name : w.assigned_to_name,
           handoff ? handoff.updated_at : null)}
    ${step('Supervisor review', w.supervisor_verified_name, w.supervisor_verified_at)}
    ${step('Field inspector sign-off', w.inspector_verified_name, w.inspector_verified_at)}
    ${step('Closed', w.closed_by_name, w.closed_at)}
  </ul>`);
}

function photosSection(photos, includeImages) {
  if (!photos || !photos.length) return '';
  if (!includeImages) {
    return section('Photo evidence', `<p class="prose prose-tight">${photos.length}
      photograph${photos.length === 1 ? '' : 's'} on file —
      ${photos.map(p => esc(p.file_name)).join(', ')}.
      <em>Tick “Include photos” above to print them.</em></p>`);
  }
  return section('Photo evidence', `<div class="shots">
    ${photos.map(p => `<figure>
      <img src="${esc(p.file_path)}" alt=""/>
      <figcaption>${esc(p.file_name)}</figcaption>
    </figure>`).join('')}</div>`);
}

function signatureBlock(d) {
  // On every record document, finished or not. These sheets are printed to
  // be signed on site, and a work order still in progress is exactly the one
  // a worker carries out and gets countersigned when the job is done — so
  // the ruled lines have to be on the paper before the work is.
  const [left, right] = signatories(d.type);
  return `<div class="signs">
    <div class="sign-line"><b>&nbsp;</b>${esc(left)}</div>
    <div class="sign-line"><b>&nbsp;</b>${esc(right)}</div>
  </div>`;
}

/**
 * Scale a document so it fills one A4 sheet.
 *
 * Compacting everything to the smallest readable size left a short report
 * using half a page and the rest white. What a reader wants is one full
 * page: a short document set larger, a long one set smaller, and only a
 * genuinely long one allowed to run on.
 *
 * zoom is used rather than transform because transform does not affect
 * layout, so the page box would keep its unscaled height and paginate
 * wrongly. But zoom does affect layout, and that is the trap: at zoom k
 * the content lays out in width/k of space and is then scaled up, so it
 * reflows to narrower lines and ends up TALLER than height × k. Guessing
 * the scale from one unscaled measurement therefore overshoots and spills
 * onto a second page. So each candidate scale is applied and the result
 * measured for real, a few times, until it settles.
 */
const PAGE_MM  = 279;      // A4 297mm less the 9mm @page margins
const FIT_FILL = 0.98;     // leave a hair, so rounding cannot spill a page
/* A narrow band on purpose. These sheets are forms, and a form is set at
 * one size: a reader who files a work order next to an inspection report
 * should not find the type twice as big on one of them. Scaling to fill
 * the page regardless printed the body at 6.3pt on a case file and 14.1pt
 * on a workload sheet off the same stylesheet. Within this band the fitter
 * still earns its keep — it squeezes a dense record onto one page and lets
 * a sparse one breathe — but a short document now simply ends early, with
 * white paper under it, the way a printed form does. */
const FIT_MIN  = 0.78;     // below this, let it run to a second page instead
const FIT_MAX  = 1.15;     // beyond this it stops reading as a form
const FIT_STEPS = 8;       // halvings of a 0.37 range: settles within 0.002

function fitToPage() {
  const sheet = el('sheet');
  const page  = el('page');
  if (!sheet || !page || sheet.hidden) return;

  let style = document.getElementById('fit-style');
  if (!style) {
    style = document.createElement('style');
    style.id = 'fit-style';
    document.head.appendChild(style);
  }

  // Pin the geometry to the printable area while fitting: on a narrow
  // screen the responsive rules give the page a different width, so text
  // wraps differently and the height measured would not be the height the
  // printer produces.
  sheet.classList.add('fit-measuring');

  const target = PAGE_MM * FIT_FILL;
  const heightAt = k => {
    style.textContent = `.page{zoom:${k}}`;
    return page.getBoundingClientRect().height / (96 / 25.4);
  };
  // Fitting means fitting the paper, not just its height. Zoom lays the
  // page out in width/k and scales it up, so anything that cannot wrap —
  // the masthead, a wide table — takes a larger share of the line as the
  // scale rises and eventually runs off the right edge. Height alone would
  // happily pick such a scale.
  const fitsAt = k => {
    const h = heightAt(k);
    return h > 0 && h <= target && page.scrollWidth <= page.clientWidth;
  };
  const settle = scale => {
    sheet.classList.remove('fit-measuring');
    style.textContent  = scale === null ? '' : `.page{zoom:${scale.toFixed(3)}}`;
    sheet.dataset.fit  = scale === null ? 'paginated' : scale.toFixed(3);
    scaleSheet();
  };

  if (!heightAt(1)) { settle(null); return; }

  // Too long for one sheet even at the smallest readable size: leave it
  // unscaled and let it paginate, with table headers repeating, rather
  // than shrink it into something nobody can read on site.
  if (!fitsAt(FIT_MIN)) { settle(null); return; }
  if (fitsAt(FIT_MAX))  { settle(FIT_MAX); return; }

  // Both tests tighten monotonically as the zoom rises, so bisect for the
  // largest scale that still fits. Guessing it instead — scaling by
  // target/height — does not converge: zoom reflows the text to narrower
  // lines, so height grows faster than the scale, the guess overshoots,
  // comes back under, and settles wherever it ran out of passes. That left
  // the work orders summary at 179mm of a 273mm page.
  let lo = FIT_MIN, hi = FIT_MAX;
  for (let i = 0; i < FIT_STEPS; i++) {
    const mid = (lo + hi) / 2;
    if (fitsAt(mid)) lo = mid; else hi = mid;
  }
  settle(lo);
}

// ── Screen scale ────────────────────────────────────────────
/* Every device gets the same A4 sheet; a window narrower than the paper
 * zooms the whole sheet down to fit. Its own 10px margins are zoomed with
 * it, so they are counted inside the paper's width. */
const SHEET_PX  = 210 * 96 / 25.4;
const SHEET_GAP = 10;
function scaleSheet() {
  const sheet = el('sheet');
  if (!sheet) return;
  const k = document.documentElement.clientWidth / (SHEET_PX + 2 * SHEET_GAP);
  sheet.style.zoom = k < 1 ? k.toFixed(4) : '';
}
window.addEventListener('resize', scaleSheet);
// The page's own width also changes without a resize event — a scrollbar
// appearing once the sheet rescales. Deferred a frame so the zoom it sets
// is not a size change made inside the observer's own callback.
if (window.ResizeObserver) {
  new ResizeObserver(() => requestAnimationFrame(scaleSheet)).observe(document.documentElement);
}

// ── Assemble ────────────────────────────────────────────────

function render(d, includePhotos) {
  const r = d.report, w = d.work_order, t = d.task;

  const code = (d.type === 'workorder' && w) ? w.wo_code
             : (d.type === 'task' && t)      ? t.task_code
             : (r ? r.report_code : '');
  const title = (d.type === 'workorder' && w) ? (w.instructions || '').split('\n')[0]
              : (d.type === 'task' && t)      ? t.title
              : (r ? r.title : '');

  document.title = `${KIND_LABEL[d.type] || 'Summary'} ${code || ''} — ConstructFlow`;
  el('pr-bar-what').textContent = `${KIND_LABEL[d.type]} ${code || ''}`;

  // A case file reads chronologically: assignment, finding, reasoning, repair.
  const order = d.type === 'case'
    ? [taskSection(t), reportSection(r), engineSection(r),
       workOrderSection(w, d.crew), updatesSection(d.updates), closureSection(w, d.updates),
       photosSection(d.photos, includePhotos)]
    : d.type === 'task'
      ? [taskSection(t), reportSection(r), photosSection(d.photos, includePhotos)]
    : d.type === 'report'
      ? [reportSection(r), engineSection(r), photosSection(d.photos, includePhotos),
         workOrderSection(w, d.crew)]
    : [workOrderSection(w, d.crew), reportSection(r), engineSection(r),
       updatesSection(d.updates), closureSection(w, d.updates), photosSection(d.photos, includePhotos)];

  el('page').innerHTML = `
    ${docHead({
      kind: KIND_LABEL[d.type] || 'Summary',
      code, sub: title,
      who: personFor(d),
      team: d.team_name,
      when: d.generated_at,
    })}

    <div class="doc-status ${esc(d.status.tone)}">
      <span class="st-label">${esc(d.status.label)}</span>
      <span class="st-note">${esc(d.status.note)}</span>
    </div>

    ${order.join('')}
    ${signatureBlock(d)}

    <footer class="doc-foot">
      <span>Generated by ${esc(d.generated_by)} (${esc(d.viewer_role)}) · ${fmtDate(d.generated_at)}</span>
      <span>ConstructFlow · ${esc(d.team_name)}</span>
    </footer>`;

  el('pr-state').hidden = true;
  el('sheet').hidden    = false;
  fitToPage();
}

// ── Admin summary ───────────────────────────────────────────
// The same filters the dashboard is showing, rendered as a sheet: a cover
// block of totals, then every matching row. Long tables flow across pages
// with the header repeating, which is what thead{display:table-header-group}
// in the stylesheet is for.

function filterLine(f) {
  const bits = [];
  if (f.from || f.to) {
    bits.push(`${f.from ? fmtDate(f.from, false) : 'the beginning'} to ${f.to ? fmtDate(f.to, false) : 'today'}`);
  } else {
    bits.push('All time');
  }
  if (f.severity) bits.push(`severity ${f.severity}`);
  if (f.status)   bits.push(`status ${statusText(f.status)}`);
  return bits.join(' · ');
}

function figures(pairs) {
  return `<div class="engine"><div class="eng-top">${
    pairs.filter(Boolean).map(([k, v]) => `<div class="eng-fig">${esc(k)}<b>${v}</b></div>`).join('')
  }</div></div>`;
}

function sevSpread(t) {
  return ['low', 'moderate', 'high', 'critical']
    .map(s => `<div class="eng-fig">${s}<b>${esc(t[s] ?? 0)}</b></div>`).join('');
}

function renderAdminReports(d) {
  const t = d.totals;
  return `
    ${section('Overview', figures([
      ['Reports', esc(t.count)],
      ['High + critical', esc(t.severity.high + t.severity.critical)],
      ['Resolved', esc(t.status.completed)],
      ['Severity overridden', esc(t.overridden)],
    ]))}
    ${section('By severity', `<div class="engine"><div class="eng-top">${sevSpread(t.severity)}</div></div>`)}
    ${section('Inspection reports', d.rows.length ? `<table>
      <thead><tr><th>ID</th><th>Issue</th><th>Inspector</th><th>Engine</th>
        <th>Final</th><th>Status</th><th>Work order</th><th>Submitted</th></tr></thead>
      <tbody>${d.rows.map(r => `<tr>
        <td>${esc(r.report_code)}</td>
        <td>${esc(r.title)}</td>
        <td>${esc(r.submitted_by_name)}</td>
        <td>${r.recommended_severity
              ? `${esc(cap(r.recommended_severity))}${r.classification_confidence != null
                  ? ` <span style="color:var(--muted)">${esc(r.classification_confidence)}%</span>` : ''}`
              : '—'}</td>
        <td>${sev(r.severity)}</td>
        <td>${esc(statusText(r.status))}</td>
        <td>${esc(dash(r.wo_code))}</td>
        <td class="num">${fmtDate(r.submitted_at, false)}</td>
      </tr>`).join('')}</tbody></table>`
      : '<p class="prose prose-tight">Nothing recorded in this range.</p>')}`;
}

function renderAdminWorkOrders(d) {
  const t = d.totals;
  return `
    ${section('Overview', figures([
      ['Work orders', esc(t.count)],
      ['Closed', esc(t.closed)],
      ['Still open', esc(t.open)],
      ['Past deadline', esc(t.overdue)],
      ['Avg days to close', t.avg_days_open === null ? '—' : esc(t.avg_days_open)],
    ]))}
    ${section('By severity', `<div class="engine"><div class="eng-top">${sevSpread(t.severity)}</div></div>`)}
    ${section('Work orders', d.rows.length ? `<table>
      <thead><tr><th>WO #</th><th>From report</th><th>Crew</th><th>Priority</th>
        <th>Status</th><th>Raised</th><th>Closed</th><th>Days</th></tr></thead>
      <tbody>${d.rows.map(w => `<tr>
        <td>${esc(w.wo_code)}</td>
        <td>${esc(w.report_code)} — ${esc(w.report_title)}</td>
        <td>${esc(dash(w.crew_names || w.assigned_to_name))}</td>
        <td>${sev(w.severity)}</td>
        <td>${esc(statusText(w.status))}</td>
        <td class="num">${fmtDate(w.created_at, false)}</td>
        <td class="num">${w.closed_at ? fmtDate(w.closed_at, false) : '—'}</td>
        <td class="num">${w.days_open === null ? 'open' : esc(w.days_open)}</td>
      </tr>`).join('')}</tbody></table>`
      : '<p class="prose">Nothing recorded in this range.</p>')}`;
}

function renderAdmin(d) {
  const isReports = d.kind === 'reports';
  const label = isReports ? 'Inspection Reports Summary' : 'Work Orders Summary';

  document.title = `${label} — ConstructFlow`;
  el('pr-bar-what').textContent = label;

  el('page').innerHTML = `
    ${docHead({
      kind: label, code: '', sub: filterLine(d.filters),
      who: '', team: d.team_name, when: d.generated_at,
    })}

    ${isReports ? renderAdminReports(d) : renderAdminWorkOrders(d)}

    <footer class="doc-foot">
      <span>Generated by ${esc(d.generated_by)} · ${fmtDate(d.generated_at)}</span>
      <span>ConstructFlow · ${esc(d.team_name)}</span>
    </footer>`;

  el('pr-state').hidden = true;
  el('sheet').hidden    = false;
  fitToPage();
}

// ── Load ────────────────────────────────────────────────────

let DOC = null;

async function load() {
  // Three shapes behind one page: a single record, a filtered team
  // summary, or the workload ranking.
  if (TYPE === 'admin')    return loadAdmin();
  if (TYPE === 'workload') return loadWorkload();

  if (!KIND_LABEL[TYPE] || !ID) {
    el('pr-state').textContent = 'Nothing to print — this link is missing a document.';
    el('pr-state').classList.add('err');
    return;
  }

  let data;
  try {
    const res = await fetch(`api/summary.php?action=document&type=${encodeURIComponent(TYPE)}&id=${encodeURIComponent(ID)}`,
                            { credentials: 'include' });
    data = await res.json();
  } catch (e) {
    el('pr-state').textContent = 'Cannot reach the server. Check your connection and try again.';
    el('pr-state').classList.add('err');
    return;
  }

  if (!data.success) {
    el('pr-state').textContent = data.message || 'This document is not available to you.';
    el('pr-state').classList.add('err');
    return;
  }

  DOC = data.data;

  if ((DOC.photos || []).length) {
    el('pr-photos-wrap').hidden = false;
    el('pr-photos').addEventListener('change', e => render(DOC, e.target.checked));
  }

  render(DOC, false);
}

async function loadAdmin(){
  const kind = PARAMS.get('kind') === 'workorders' ? 'workorders' : 'reports';
  const q    = new URLSearchParams({ action: kind });
  ['from','to','severity','status'].forEach(k => {
    const v = PARAMS.get(k);
    if (v) q.set(k, v);
  });

  let data;
  try {
    const res = await fetch('api/summary.php?' + q.toString(), { credentials:'include' });
    data = await res.json();
  } catch (e) {
    el('pr-state').textContent = 'Cannot reach the server. Check your connection and try again.';
    el('pr-state').classList.add('err');
    return;
  }

  if (!data.success) {
    el('pr-state').textContent = data.message || 'This summary is not available to you.';
    el('pr-state').classList.add('err');
    return;
  }

  DOC = data.data;
  renderAdmin(DOC);
}

// ── Workload sheet ──────────────────────────────────────────
// The ranking as paper. The weights and where they come from are printed
// under the tables, so the sheet justifies its own ordering instead of
// asking the reader to take it on trust.

function wlSheetTable(rows, mode, lastCol) {
  if (!rows.length) return '<p class="prose">Nothing finished in this range.</p>';

  const sorted = mode === 'count'
    ? [...rows].sort((a, b) => b.done - a.done || b.score - a.score || a.name.localeCompare(b.name))
    : rows;

  return `<table>
    <thead><tr>
      <th style="width:9mm">#</th><th>Name</th>
      <th style="width:18mm">${mode === 'count' ? 'Completed' : 'Score'}</th>
      <th style="width:18mm">${mode === 'count' ? 'Score' : 'Completed'}</th>
      <th style="width:10mm">L</th><th style="width:10mm">M</th>
      <th style="width:10mm">H</th><th style="width:10mm">C</th>
      <th style="width:18mm">${esc(lastCol)}</th>
    </tr></thead>
    <tbody>${sorted.map((r, i) => `<tr>
      <td class="num">${i + 1}</td>
      <td>${esc(r.name)}</td>
      <td><b>${esc(mode === 'count' ? r.done : r.score)}</b></td>
      <td class="num">${esc(mode === 'count' ? r.score : r.done)}</td>
      <td class="num">${esc(r.low)}</td>
      <td class="num">${esc(r.moderate)}</td>
      <td class="num">${esc(r.high)}</td>
      <td class="num">${esc(r.critical)}</td>
      <td class="num">${esc(lastCol === 'As lead' ? r.lead : (r.verified ?? 0))}</td>
    </tr>`).join('')}</tbody></table>`;
}

function renderWorkloadSheet(d, mode) {
  const w = d.weights;
  document.title = 'Crew Workload — ConstructFlow';
  el('pr-bar-what').textContent = 'Crew Workload';

  el('page').innerHTML = `
    ${docHead({
      kind: 'Crew Workload', code: '',
      sub: `${filterLine(d.filters)} · ranked by ${
        mode === 'count' ? 'number of jobs completed' : 'severity-weighted score'}`,
      who: '', team: d.team_name, when: d.generated_at,
    })}

    ${section(`Field workers — ${esc(d.totals.worker_orders)} work orders closed, ${esc(d.totals.worker_assignments)} assignments, ${esc(d.totals.worker_points)} points`,
              wlSheetTable(d.workers, mode, 'As lead'))}

    ${section(`Field inspectors — ${esc(d.totals.inspector_reports)} reports submitted, ${esc(d.totals.inspector_points)} points`,
              wlSheetTable(d.inspectors, mode, 'Verified'))}

    ${section('How the score works', `<div class="engine">
      <div class="eng-top">
        <div class="eng-fig">Low<b>${esc(w.low)}</b></div>
        <div class="eng-fig">Moderate<b>${esc(w.moderate)}</b></div>
        <div class="eng-fig">High<b>${esc(w.high)}</b></div>
        <div class="eng-fig">Critical<b>${esc(w.critical)}</b></div>
      </div>
      <div class="eng-note">${esc(d.weight_note)}
        Ranking on a plain count would place ten minor findings above three critical ones;
        weighting by severity ties the order to the same matrix that classifies the work.
        Only closed work orders count towards a field worker's total, and an inspector's
        unit of work is a submitted report.</div>
    </div>`)}

    <footer class="doc-foot">
      <span>Generated by ${esc(d.generated_by)} · ${fmtDate(d.generated_at)}</span>
      <span>ConstructFlow · ${esc(d.team_name)}</span>
    </footer>`;

  el('pr-state').hidden = true;
  el('sheet').hidden    = false;
  fitToPage();
}

async function loadWorkload() {
  const mode = PARAMS.get('mode') === 'count' ? 'count' : 'weighted';
  const q    = new URLSearchParams({ action: 'workload' });
  ['from', 'to'].forEach(k => { const v = PARAMS.get(k); if (v) q.set(k, v); });

  let data;
  try {
    const res = await fetch('api/summary.php?' + q.toString(), { credentials: 'include' });
    data = await res.json();
  } catch (e) {
    el('pr-state').textContent = 'Cannot reach the server. Check your connection and try again.';
    el('pr-state').classList.add('err');
    return;
  }

  if (!data.success) {
    el('pr-state').textContent = data.message || 'This summary is not available to you.';
    el('pr-state').classList.add('err');
    return;
  }

  DOC = data.data;
  renderWorkloadSheet(DOC, mode);
}

load();
