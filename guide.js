// "How severity works": the engine explained from its own knowledge base.
// Everything below is read live from api/guide.php, so a word added to the
// lexicon appears here without changing this page.

const SEV_LABEL = { low: 'Low', moderate: 'Moderate', high: 'High', critical: 'Critical' };
const ROLE_PAGE = { field_inspector: 'inspector.html', supervisor: 'supervisor.html',
                    field_worker: 'fieldworker.html', administrator: 'admin.html' };
const KIND_TEXT = {
  negation:    'Cancels the words after it in the same clause',
  intensifier: 'Raises the defect level by one',
  dampener:    'Lowers the defect level by one',
  exposure:    'People at risk: raises the consequence (C) by one',
};
let CSRF = '';
let WORDS = [];
let FILTER = 'all';
let ELEMENT_C = {};

const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const badge = sev => sev ? `<span class="badge badge-${esc(sev)}">${esc(SEV_LABEL[sev] || sev)}</span>` : '—';

async function start(){
  const me = await fetch('api/auth.php?action=me', { credentials: 'include' }).then(r => r.json()).catch(() => null);
  if(!me || !me.success){ location.replace('login.html'); return; }
  CSRF = me.data.csrf_token || '';
  const home = ROLE_PAGE[me.data.role] || 'team.html';
  $('gd-back').href = home;
  $('gd-home').href = home;

  const kb = await fetch('api/guide.php?action=knowledge', { credentials: 'include' }).then(r => r.json()).catch(() => null);
  if(!kb || !kb.success){
    $('gd-matrix').innerHTML = '<tr><td>Could not load the knowledge base. Please reload the page.</td></tr>';
    return;
  }
  renderMatrix(kb.data.matrix);
  renderDefects(kb.data.defects);
  renderElements(kb.data.elements);
  const cOf = ELEMENT_C = Object.fromEntries(kb.data.elements.map(e => [e.name, Number(e.c_level)]));
  WORDS = kb.data.words.map(w => ({ ...w, ...describe(w, cOf) }));
  renderFilters();
  renderWords();
}

function renderMatrix(m){
  const head = ['C1 Cosmetic', 'C2 Functional', 'C3 Secondary / safety', 'C4 Load-bearing'];
  let html = '<thead><tr><th>Defect level</th>' + head.map(h => `<th>${h}</th>`).join('') + '</tr></thead><tbody>';
  for(let d = 4; d >= 1; d--){
    html += `<tr><td>D${d}${d === 4 ? ' (most serious)' : d === 1 ? ' (least serious)' : ''}</td>`;
    for(let c = 1; c <= 4; c++) html += `<td>${badge(m[d] && m[d][c])}</td>`;
    html += '</tr>';
  }
  $('gd-matrix').innerHTML = html + '</tbody>';
}

function renderDefects(list){
  $('gd-defects').classList.remove('gd-loading');
  $('gd-defects').innerHTML = list.map(t => {
    const phase = t.construction && t.after ? 'during and after construction'
                : t.construction ? 'during construction only' : 'after completion only';
    const lv = [1, 2, 3, 4].map(d => {
      const l = t.levels.find(x => x.d === d);
      return l ? `<div class="${l.default ? 'def' : ''}"><b>D${d}${l.default ? ' · default' : ''}</b>${esc(l.text)}</div>`
               : `<div class="none"><b>D${d}</b>Not used</div>`;
    }).join('');
    return `<div class="gd-defect"><h3>${esc(t.name)} <span>· ${phase}</span></h3><div class="gd-lvl">${lv}</div></div>`;
  }).join('');
}

function renderElements(list){
  $('gd-elements').innerHTML = '<thead><tr><th>Building part</th><th>C</th><th>Group</th></tr></thead><tbody>'
    + list.map(e => `<tr><td>${esc(e.name)}${Number(e.is_default) ? ' <span class="gd-src">(used when no part is named)</span>' : ''}</td>`
      + `<td>C${esc(e.c_level)}</td><td>${esc(e.category)}</td></tr>`).join('') + '</tbody>';
}

/** What a word does, in plain words, and which filter it belongs to. */
function describe(w, cOf){
  if(w.kind === 'verdict') return { group: 'verdict', meaning: `Inspector's conclusion; points to ${SEV_LABEL[w.implies] || w.implies}. Only compared with the result, never changes it` };
  if(w.kind) return { group: w.kind === 'exposure' ? 'exposure' : w.kind === 'negation' ? 'negation' : 'modifier', meaning: KIND_TEXT[w.kind] || w.kind };
  if(w.element) return { group: 'element', meaning: `Building part: ${w.element} (C${cOf[w.element] ?? '?'})` };
  if(w.level) return { group: 'condition', meaning: `${w.defect}, level D${w.level}` };
  return { group: 'defect', meaning: `Names ${w.defect} (level comes from a condition word, or the default)` };
}

const FILTERS = [
  ['all', 'All'], ['defect', 'Defect words'], ['condition', 'Condition words (D)'], ['element', 'Building parts (C)'],
  ['exposure', 'People at risk'], ['negation', 'Cancelling words'], ['modifier', 'Raise / lower'], ['verdict', 'Inspector conclusions'],
];
function renderFilters(){
  $('gd-filters').innerHTML = FILTERS.map(([k, label]) =>
    `<button type="button" class="filter-btn${k === FILTER ? ' active' : ''}" data-f="${k}">${label}</button>`).join('');
  $('gd-filters').querySelectorAll('button').forEach(b => b.addEventListener('click', () => { FILTER = b.dataset.f; renderFilters(); renderWords(); }));
}

function renderWords(){
  const q = $('gd-search').value.trim().toLowerCase();
  const rows = WORDS.filter(w => (FILTER === 'all' || w.group === FILTER)
    && (!q || w.term.toLowerCase().includes(q) || w.meaning.toLowerCase().includes(q)));
  $('gd-count').textContent = `${rows.length} of ${WORDS.length} words`;
  $('gd-words').innerHTML = rows.length
    ? rows.map(w => `<tr><td>${esc(w.term)}</td><td>${esc(w.meaning)}</td><td class="gd-src">${esc(w.source)}</td></tr>`).join('')
    : '<tr><td colspan="3">No word matches. If inspectors use it, ask an administrator to add it to the knowledge base.</td></tr>';
}

async function tryIt(){
  const text = $('gd-text').value.trim();
  const out = $('gd-result');
  if(text.length < 3){ $('gd-text').focus(); return; }
  const btn = $('gd-try-btn');
  btn.disabled = true;
  const res = await fetch('api/guide.php?action=try', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify({ text, phase: $('gd-phase').value }),
  }).then(r => r.json()).catch(() => ({ success: false, message: 'Could not reach the server.' }));
  btn.disabled = false;
  out.classList.add('show');
  if(!res.success){ out.innerHTML = `<p class="gd-sub">${esc(res.message)}</p>`; return; }
  const r = res.data;
  // "Raised" only when people at risk actually moved C; a column is already C4.
  const base = ELEMENT_C[r.element];
  const exposure = !r.exposure ? '' : base && base < r.c_level ? ` (raised from C${base}: people at risk)` : ' (people at risk noted; already the highest)';
  const filedNote = r.filed_as !== r.severity ? ` · would be filed as ${badge(r.filed_as)} for the supervisor to reassess` : '';
  out.innerHTML = `
    <div class="gd-res-top">Recommended severity ${badge(r.severity)}${filedNote}${r.needs_review ? ' · <b>flagged for review</b>' : ''}</div>
    <div class="gd-res-grid">
      <div><b>Defect and level</b>${esc(r.defect_type || 'Other (not listed)')} · D${esc(r.d_level)}</div>
      <div><b>Building part and consequence</b>${esc(r.element || '—')} · C${esc(r.c_level)}${exposure}</div>
    </div>
    <div style="margin-top:12px"><b class="gd-src">Words it recognized</b>
      <div class="gd-chips">${r.matched.length ? r.matched.map(m => `<span class="gd-chip">${esc(m)}</span>`).join('') : '<span class="gd-src">none</span>'}
      ${r.negated.map(m => `<span class="gd-chip off" title="Cancelled by a negation">${esc(m)}</span>`).join('')}</div></div>
    ${r.notes.length ? `<div class="gd-note">${r.notes.map(esc).join('<br>')}</div>` : ''}
    <p class="gd-sub" style="margin:10px 0 0">Grid: row D${esc(r.d_level)}, column C${esc(r.c_level)}. Nothing was saved.</p>`;
}

$('gd-search').addEventListener('input', renderWords);
$('gd-try-btn').addEventListener('click', tryIt);
$('gd-text').addEventListener('keydown', e => { if(e.key === 'Enter' && (e.ctrlKey || e.metaKey)) tryIt(); });
start();
