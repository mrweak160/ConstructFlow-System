// Dashboard charts: reports by severity, by status, and per week. Drawn as
// plain HTML and SVG, so they work offline and follow the theme. Colours are
// the --sev-1..4 ramp and --chart-bar in style.css.
//
// Severity wears the badges' colours, stepped apart in lightness so
// neighbouring levels stay distinct for colour-blind readers; the badge
// icon beside each bar carries the grade as well. Every value is printed or sits in a hidden
// table, so the hover tooltip never holds the only copy of a number.

const CH_SEV = ['low', 'moderate', 'high', 'critical'];
const CH_SEV_NAME = { low: 'Low', moderate: 'Moderate', high: 'High', critical: 'Critical' };
const CH_REPORT_STATUS = [
  ['pending', 'Pending review'], ['assigned', 'Assigned'], ['in_progress', 'In progress'],
  ['completed', 'Completed'], ['rejected', 'Closed'],
];
// Work orders have more states; the rarer ones show only when present.
const CH_WO_STATUS = [
  ['pending', 'Pending', true], ['in_progress', 'In progress', true], ['on_hold', 'On hold'],
  ['rework', 'Needs rework'], ['pending_supervisor', 'Awaiting supervisor'],
  ['pending_inspector', 'Awaiting inspector'], ['pending_closure', 'For final review'],
  ['completed', 'Completed', true],
];

const chDate = v => new Date(String(v).replace(' ', 'T'));

/**
 * The three charts of one page. ids: prefix of its elements, e.g. 'sup'
 * fills sup-ch-sev, sup-ch-status and sup-ch-trend. opts: { kind:
 * 'reports' | 'workorders', dateKey, from, to, scope } where scope is the
 * subtitle for the two bar charts. Passing from (even null) means the page
 * has a date filter; without it the trend shows the last 8 weeks.
 */
function renderDashCharts(ids, rows, opts = {}) {
  const wo   = opts.kind === 'workorders';
  const unit = wo ? 'work orders' : 'reports';
  const set  = (id, text) => { const e = document.getElementById(id); if (e) e.textContent = text; };

  set(`${ids}-ch-sev-title`,    wo ? 'Work orders by priority' : 'Reports by severity');
  set(`${ids}-ch-status-title`, wo ? 'Work orders by status' : 'Reports by status');
  set(`${ids}-ch-trend-title`,  wo ? 'Work orders over time' : 'Reports over time');
  set(`${ids}-ch-sev-sub`,      opts.scope || `All ${unit}`);
  set(`${ids}-ch-status-sub`,   opts.scope || `All ${unit}`);

  const count = (key, val) => rows.filter(r => r[key] === val).length;
  chartBars(document.getElementById(`${ids}-ch-sev`), CH_SEV.map((s, i) => ({
    name: CH_SEV_NAME[s], value: count('severity', s), color: `--sev-${i + 1}`,
    label: `<span class="badge badge-${s}">${CH_SEV_NAME[s]}</span>`,
  })), unit);

  const states = (wo ? CH_WO_STATUS : CH_REPORT_STATUS)
    .map(([s, name, keep]) => ({ name, value: count('status', s), keep: keep || !wo }))
    .filter(s => s.keep || s.value > 0);
  chartBars(document.getElementById(`${ids}-ch-status`), states.map(s => ({
    ...s, color: '--chart-bar', label: esc(s.name),
  })), unit);

  chartTrend(document.getElementById(`${ids}-ch-trend`), rows, {
    dateKey: opts.dateKey || (wo ? 'created_at' : 'submitted_at'),
    ranged: 'from' in opts, from: opts.from, to: opts.to, unit,
    subtitle: document.getElementById(`${ids}-ch-trend-sub`),
  });
}

/** Horizontal bars, one per item, the value at the tip. */
function chartBars(el, items, unit) {
  if (!el) return;
  const total = items.reduce((s, i) => s + i.value, 0);
  if (!total) { el.innerHTML = `<div class="ch-empty">No ${esc(unit)} to show.</div>`; return; }
  const max = Math.max(...items.map(i => i.value));
  el._tips = items.map(i => ({
    title: i.name, rows: [{ name: unit, value: `${i.value} · ${Math.round(i.value / total * 100)}%` }],
  }));
  el.innerHTML = `<div class="ch-bars">${items.map((i, n) => `
    <div class="ch-bar-row" data-tip="${n}" tabindex="0">
      <div class="ch-bar-label">${i.label}</div>
      <div class="ch-track">
        <div class="ch-bar" style="--w:${(i.value / max).toFixed(4)};background:var(${i.color})"></div>
        <span class="ch-bar-val">${i.value}<span> · ${Math.round(i.value / total * 100)}%</span></span>
      </div>
    </div>`).join('')}</div>`;
}

/** Monday of the week d falls in, at midnight. */
function chWeekStart(d) {
  const m = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  m.setDate(m.getDate() - ((m.getDay() + 6) % 7));
  return m;
}

/**
 * Columns per week, stacked by severity. Spans longer than 14 weeks are
 * counted per month instead, so the columns stay wide enough to read.
 * Without a range it shows the last 8 weeks.
 */
function chartTrend(el, rows, opts) {
  if (!el) return;
  el._trendArgs = [rows, opts];
  if (!el._observed && window.ResizeObserver) {
    el._observed = true;
    let lastW = 0;
    new ResizeObserver(() => {
      const w = el.clientWidth;
      if (w && Math.abs(w - lastW) > 2) { lastW = w; requestAnimationFrame(() => chartTrend(el, ...el._trendArgs)); }
    }).observe(el);
  }

  const dated = rows.map(r => ({ d: chDate(r[opts.dateKey]), s: r.severity })).filter(r => !isNaN(r.d));
  // A page with a date filter (ranged) shows that range, from the first
  // record when it has no start; a dashboard shows the last 8 weeks.
  const today = new Date();
  const end   = opts.ranged && opts.to ? chDate(opts.to) : today;
  const start = !opts.ranged ? new Date(today.getFullYear(), today.getMonth(), today.getDate() - 7 * 7)
              : opts.from ? chDate(opts.from)
              : dated.length ? new Date(Math.min(...dated.map(r => +r.d))) : today;
  const monthly = (end - start) / 864e5 > 98;

  const buckets = [];
  if (monthly) {
    for (let m = new Date(start.getFullYear(), start.getMonth(), 1); m <= end; m.setMonth(m.getMonth() + 1)) {
      buckets.push({ start: new Date(m), end: new Date(m.getFullYear(), m.getMonth() + 1, 1) });
    }
  } else {
    for (let w = chWeekStart(start); w <= end; w.setDate(w.getDate() + 7)) {
      const e = new Date(w); e.setDate(e.getDate() + 7);
      buckets.push({ start: new Date(w), end: e });
    }
  }
  buckets.forEach(b => {
    b.n = Object.fromEntries(CH_SEV.map(s => [s, 0]));
    dated.forEach(r => { if (r.d >= b.start && r.d < b.end && b.n[r.s] !== undefined) b.n[r.s]++; });
    b.total = CH_SEV.reduce((t, s) => t + b.n[s], 0);
    b.short = monthly ? b.start.toLocaleDateString('en-GB', { month: 'short' })
                      : b.start.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
    b.long  = monthly ? b.start.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' })
                      : 'Week of ' + b.start.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
  });

  if (opts.subtitle) {
    opts.subtitle.textContent = (monthly ? 'Per month' : 'Per week')
      + (opts.ranged ? '' : ', last 8 weeks')
      + (opts.unit === 'work orders' ? ', by priority' : ', by severity');
  }
  const legend = `<div class="ch-legend">${CH_SEV.map((s, i) =>
    `<span><i style="background:var(--sev-${i + 1})"></i>${CH_SEV_NAME[s]}</span>`).join('')}</div>`;
  if (!buckets.some(b => b.total)) {
    el._tips = [];
    el.innerHTML = `${legend}<div class="ch-empty">No ${esc(opts.unit)} in this period.</div>`;
    return;
  }

  // Geometry, in CSS pixels of the card as it is now.
  const W = Math.max(260, el.clientWidth || 600), plotH = 150, top = 16, axisH = 22, left = 30;
  const H = top + plotH + axisH, plotW = W - left - 4;
  const peak = Math.max(...buckets.map(b => b.total));
  const step = [1, 2, 5, 10, 20, 25, 50, 100, 200, 500].find(s => peak / s <= 4) || Math.ceil(peak / 4);
  const max  = Math.ceil(peak / step) * step;
  const yOf  = v => top + plotH - (v / max) * plotH;
  const band = plotW / buckets.length;
  const colW = Math.min(24, band * 0.6);
  const every = Math.ceil(buckets.length / Math.max(1, Math.floor(plotW / 46)));   // x labels that fit

  let svg = '';
  for (let v = 0; v <= max; v += step) {
    const y = yOf(v).toFixed(1);
    svg += `<line class="ch-grid" x1="${left}" x2="${W - 4}" y1="${y}" y2="${y}"/>`
         + `<text class="ch-tick" x="${left - 6}" y="${y}" dy=".32em" text-anchor="end">${v}</text>`;
  }
  buckets.forEach((b, i) => {
    const cx = left + band * i + band / 2, x = cx - colW / 2;
    let base = top + plotH, segs = '', first = true;
    const topSev = [...CH_SEV].reverse().find(s => b.n[s]);
    CH_SEV.forEach((s, k) => {
      if (!b.n[s]) return;
      const h = (b.n[s] / max) * plotH, y = base - h;
      // A 2px gap of card colour between segments, none at the baseline.
      const bottom = first ? base : base - 2;
      const r = s === topSev ? Math.min(4, bottom - y) : 0;
      if (bottom - y > 0.5) {
        segs += `<path fill="var(--sev-${k + 1})" d="M${x},${bottom}V${y + r}Q${x},${y} ${x + r},${y}H${x + colW - r}Q${x + colW},${y} ${x + colW},${y + r}V${bottom}Z"/>`;
      }
      base = y; first = false;
    });
    const total = b.total && buckets.length <= 12
      ? `<text class="ch-cap" x="${cx}" y="${yOf(b.total) - 5}" text-anchor="middle">${b.total}</text>` : '';
    const lab = i % every === 0
      ? `<text class="ch-tick" x="${cx}" y="${top + plotH + 15}" text-anchor="middle">${esc(b.short)}</text>` : '';
    svg += `<g class="ch-col" data-tip="${i}" tabindex="0">`
         + `<rect class="ch-hit" x="${left + band * i}" y="${top}" width="${band}" height="${plotH}"/>${segs}${total}</g>${lab}`;
  });
  svg += `<line class="ch-axis" x1="${left}" x2="${W - 4}" y1="${top + plotH}" y2="${top + plotH}"/>`;

  el._tips = buckets.map(b => ({
    title: b.long,
    rows: [...CH_SEV].reverse().map(s => ({ name: CH_SEV_NAME[s], value: b.n[s], color: `--sev-${CH_SEV.indexOf(s) + 1}` }))
      .concat([{ name: 'Total', value: b.total }]),
  }));
  el.innerHTML = `${legend}
    <svg class="ch-svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" role="img"
         aria-label="${esc(opts.unit)} per ${monthly ? 'month' : 'week'} by severity; values in the table that follows">${svg}</svg>
    <div class="sr-only"><table><caption>${esc(cap(opts.unit))} per ${monthly ? 'month' : 'week'}</caption>
      <thead><tr><th>${monthly ? 'Month' : 'Week of'}</th>${CH_SEV.map(s => `<th>${CH_SEV_NAME[s]}</th>`).join('')}<th>Total</th></tr></thead>
      <tbody>${buckets.map(b => `<tr><td>${esc(b.long)}</td>${CH_SEV.map(s => `<td>${b.n[s]}</td>`).join('')}<td>${b.total}</td></tr>`).join('')}</tbody>
    </table></div>`;
}

// ── One tooltip for every chart ─────────────────────────────
// Built with textContent: names come from the data.
const chTip = (() => {
  const tip = document.createElement('div');
  tip.className = 'ch-tip';
  tip.hidden = true;
  document.addEventListener('DOMContentLoaded', () => document.body.appendChild(tip));

  const show = (mark, x, y) => {
    const chart = mark.closest('[data-chart]');
    const data  = chart && chart._tips && chart._tips[Number(mark.dataset.tip)];
    if (!data) return;
    tip.replaceChildren();
    const h = document.createElement('div');
    h.className = 'ch-tip-title';
    h.textContent = data.title;
    tip.appendChild(h);
    data.rows.forEach(r => {
      const row = document.createElement('div');
      row.className = 'ch-tip-row';
      const key = document.createElement('i');
      if (r.color) key.style.background = `var(${r.color})`;
      const v = document.createElement('b');
      v.textContent = r.value;
      const n = document.createElement('span');
      n.textContent = r.name;
      row.append(key, v, n);
      tip.appendChild(row);
    });
    tip.hidden = false;
    const w = tip.offsetWidth, ht = tip.offsetHeight;
    tip.style.left = Math.max(8, Math.min(x + 14, innerWidth - w - 8)) + 'px';
    tip.style.top  = Math.max(8, (y + 14 + ht > innerHeight ? y - ht - 10 : y + 14)) + 'px';
  };
  const hide = () => { tip.hidden = true; };

  document.addEventListener('pointermove', e => {
    const mark = e.target.closest && e.target.closest('[data-chart] [data-tip]');
    if (mark) show(mark, e.clientX, e.clientY); else if (!tip.hidden) hide();
  });
  document.addEventListener('focusin', e => {
    const mark = e.target.closest && e.target.closest('[data-chart] [data-tip]');
    if (!mark) return hide();
    const r = mark.getBoundingClientRect();
    show(mark, r.left + r.width / 2, r.top + 4);
  });
  document.addEventListener('focusout', hide);
  addEventListener('scroll', hide, true);
  return { hide };
})();
