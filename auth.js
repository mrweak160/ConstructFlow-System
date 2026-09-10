const API_BASE = 'api/auth.php';

// Where each team role lands after sign-in.
const ROLE_PAGE = {
  field_inspector: 'inspector.html',
  supervisor:      'supervisor.html',
  field_worker:    'fieldworker.html',
  administrator:   'admin.html',
};
let fgEmail  = '';
let csrfToken = '';

// ── HTTP ──────────────────────────────────────────────────
async function post(action, body) {
  try {
    const res = await fetch(`${API_BASE}?action=${action}`, {
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

// ── SMALL DOM HELPERS ─────────────────────────────────────
const el = id => document.getElementById(id);

function showErr(id, msg) {
  const box = el(id);
  if (!box) return;
  box.textContent = msg;
  box.classList.add('show');
}
function clearErr(id) {
  const box = el(id);
  if (!box) return;
  box.textContent = '';
  box.classList.remove('show');
}
function bannerEl() {
  return el('l-banner') || el('ct-banner') || el('sa-banner');
}
function banner(msg, kind = 'ok') {
  const b = bannerEl();
  if (!b) return;
  b.textContent = msg;
  b.className = `auth-banner show auth-banner-${kind}`;
}
function clearBanner() {
  const b = bannerEl();
  if (b) b.className = 'auth-banner';
}

/** Disables a button and swaps its label for a spinner while awaiting. */
function busy(btnId, on, labelWhenBusy = 'Working…') {
  const b = el(btnId);
  if (!b) return;
  if (on) {
    b.dataset.label = b.textContent;
    b.disabled = true;
    b.innerHTML = `<span class="auth-spin"></span>${labelWhenBusy}`;
  } else {
    b.disabled = false;
    b.textContent = b.dataset.label || b.textContent;
  }
}

function isEmail(v) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
}

// ── MODALS ────────────────────────────────────────────────
function openAuthModal(id) {
  el(id).classList.add('open');
  const first = el(id).querySelector('input:not([disabled]), select');
  if (first) setTimeout(() => first.focus(), 60);
}
function closeAuthModal(id) {
  el(id).classList.remove('open');
}

function openForgot() {
  clearBanner();
  fgEmail = '';
  ['f-email','f-pass','f-pass2'].forEach(i => { if (el(i)) el(i).value = ''; });
  ['fg-err-1','fg-err-2','fg-err-3'].forEach(clearErr);
  clearCode('fg-code');
  paintRules('f-pass', 'fg-rules');
  gotoStep('fg', 1);
  // Carry over whatever they already typed on the login form.
  const typed = el('l-email').value.trim();
  if (typed) el('f-email').value = typed;
  openAuthModal('modal-forgot');
}

// Close on backdrop click and on Escape.
document.querySelectorAll('.auth-overlay').forEach(ov => {
  ov.addEventListener('click', e => { if (e.target === ov) ov.classList.remove('open'); });
});
document.addEventListener('keydown', e => {
  if (e.key !== 'Escape') return;
  document.querySelectorAll('.auth-overlay.open').forEach(o => o.classList.remove('open'));
});

// ── STEP INDICATOR ────────────────────────────────────────
const STEP_SUBS = {
  ct: {
    1: 'Tell us who you are.',
    2: 'Enter the code we emailed you.',
    3: 'Choose a password.',
    4: 'Name your team.',
  },
  fg: { 
    1: "Forgot your password? We'll email you a code.",
    2: 'Enter the code we emailed you.',
    3: 'Choose a new password.',
  },
};

function gotoStep(prefix, step) {
  const dots  = el(`${prefix}-steps`).querySelectorAll('.auth-step');
  const total = dots.length;

  for (let i = 1; i <= total; i++) {
    const pane = el(`${prefix}-pane-${i}`);
    if (pane) pane.classList.toggle('active', i === step);
  }
  dots.forEach(s => {
    const n = Number(s.dataset.step);
    s.classList.toggle('current', n === step);
    s.classList.toggle('done', n < step);
    s.querySelector('.auth-step-dot').textContent = n < step ? '✓' : n;
  });

  const sub = el(`${prefix}-sub`);
  if (sub && STEP_SUBS[prefix] && STEP_SUBS[prefix][step]) {
    sub.textContent = STEP_SUBS[prefix][step];
  }

  const pane  = el(`${prefix}-pane-${step}`);
  const first = pane ? pane.querySelector('input:not([disabled]), select') : null;
  if (first) setTimeout(() => first.focus(), 80);
}

// ── SEGMENTED CODE INPUT ──────────────────────────────────
function initCodeInput(wrapId) {
  const wrap = el(wrapId);
  if (!wrap) return;
  const boxes = [...wrap.querySelectorAll('input')];

  boxes.forEach((box, i) => {
    box.addEventListener('input', () => {
      box.value = box.value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
      box.classList.toggle('filled', !!box.value);
      wrap.classList.remove('shake');
      if (box.value && i < boxes.length - 1) boxes[i + 1].focus();
      // Auto-submit once all six are filled — saves a tap on mobile.
      if (readCode(wrapId).length === 6) {
        const fn = window[wrap.dataset.target];
        if (typeof fn === 'function') setTimeout(fn, 120);
      }
    });

    box.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !box.value && i > 0) {
        boxes[i - 1].focus();
        boxes[i - 1].value = '';
        boxes[i - 1].classList.remove('filled');
        e.preventDefault();
      }
      if (e.key === 'ArrowLeft'  && i > 0)                { boxes[i - 1].focus(); e.preventDefault(); }
      if (e.key === 'ArrowRight' && i < boxes.length - 1) { boxes[i + 1].focus(); e.preventDefault(); }
    });

    box.addEventListener('paste', e => {
      e.preventDefault();
      const text = (e.clipboardData || window.clipboardData).getData('text')
                     .replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
      boxes.forEach((b, j) => {
        if (j >= i && text[j - i]) {
          b.value = text[j - i];
          b.classList.add('filled');
        }
      });
      const next = Math.min(i + text.length, boxes.length - 1);
      boxes[next].focus();
      if (readCode(wrapId).length === 6) {
        const fn = window[wrap.dataset.target];
        if (typeof fn === 'function') setTimeout(fn, 120);
      }
    });
  });
}

function readCode(wrapId) {
  return [...el(wrapId).querySelectorAll('input')].map(b => b.value).join('');
}
function clearCode(wrapId) {
  const wrap = el(wrapId);
  if (!wrap) return;
  wrap.querySelectorAll('input').forEach(b => { b.value = ''; b.classList.remove('filled'); });
  wrap.classList.remove('shake');
}
function rejectCode(wrapId) {
  const wrap = el(wrapId);
  wrap.classList.add('shake');
  setTimeout(() => {
    clearCode(wrapId);
    wrap.querySelector('input').focus();
  }, 340);
}

initCodeInput('fg-code');

// ── PASSWORD RULES ────────────────────────────────────────
function checkRules(pass) {
  return {
    len:   pass.length >= 8,
    upper: /[A-Z]/.test(pass),
    lower: /[a-z]/.test(pass),
    num:   /[0-9]/.test(pass),
  };
}

function paintRules(inputId, rulesId) {
  const pass = el(inputId) ? el(inputId).value : '';
  const r = checkRules(pass);
  el(rulesId).querySelectorAll('.auth-rule').forEach(row => {
    row.classList.toggle('met', !!r[row.dataset.rule]);
  });
}
function passwordOk(pass) {
  const r = checkRules(pass);
  return r.len && r.upper && r.lower && r.num;
}
const POLICY_TEXT = 'Password must contain at least 8 characters, 1 uppercase letter, 1 lowercase letter, and 1 number.';

// ── RESEND COOLDOWN ───────────────────────────────────────
function startCooldown(btnId, seconds = 30) {
  const b = el(btnId);
  if (!b) return;
  let left = seconds;
  b.disabled = true;
  const label = 'Resend code';
  b.textContent = `Resend in ${left}s`;
  const tick = setInterval(() => {
    left -= 1;
    if (left <= 0) {
      clearInterval(tick);
      b.disabled = false;
      b.textContent = label;
    } else {
      b.textContent = `Resend in ${left}s`;
    }
  }, 1000);
}

// ══════════════════════════════════════════════════════════
// LOGIN
// ══════════════════════════════════════════════════════════
async function doLogin() {
  const email = el('l-email').value.trim();
  const pass  = el('l-pass').value;

  clearErr('l-err');
  clearBanner();

  if (!email)            { showErr('l-err', 'Enter your email address.'); el('l-email').focus(); return; }
  if (!isEmail(email))   { showErr('l-err', 'Enter a valid email address.'); el('l-email').focus(); return; }
  if (!pass)             { showErr('l-err', 'Enter your password.'); el('l-pass').focus(); return; }

  busy('l-submit', true, 'Signing in…');
  const res = await post('login', { email, password: pass });
  busy('l-submit', false);

  if (!res.success) {
    showErr('l-err', res.message || 'Invalid email or password.');
    el('l-pass').value = '';
    return;
  }

  csrfToken = res.data.csrf_token || '';

  if (window.PENDING_CLAIM) {
    const claim = await post('invite_claim', { token: window.PENDING_CLAIM });
    window.PENDING_CLAIM = null;
    if (claim.success) {
      window.history.replaceState({}, '', 'login.html');
      window.location.href = ROLE_PAGE[claim.data.role] || 'team.html';
      return;
    }
    banner(claim.message, 'err');
    return;
  }


  routeAfterLogin(res.data);
}

/* Decides where a freshly authenticated user goes */
function routeAfterLogin(data) {
  const memberships = data.memberships || [];
  const active  = memberships.filter(m => m.status === 'active');
  const pending = memberships.filter(m => m.status === 'pending');

  if (active.length === 1) {
    const page = ROLE_PAGE[active[0].role];
    // A member who's been approved but not yet given a job role has no dashboard to go to yet.
    window.location.href = page || 'team.html';
    return;
  }
  if (active.length > 1) {
    window.location.href = 'team.html';   // team picker
    return;
  }
  if (pending.length) {
    el('pend-team').textContent = `Waiting on ${pending[0].team_name}`;
    openAuthModal('modal-pending');
    return;
  }
  window.location.href = 'team.html';     // create or join
}

async function signOutFromPending() {
  await post('logout', {});
  closeAuthModal('modal-pending');
  el('l-pass').value = '';
  banner('Signed out.', 'ok');
}

// Enter submits from either login field.
['l-email', 'l-pass'].forEach(id => {
  const box = el(id);
  if (box) box.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
});

// Wraps every password field on the page with a show/hide eye.
function initPasswordToggles(){
  document.querySelectorAll('input[type="password"]').forEach(inp => {
    if(inp.parentElement.classList.contains('pw-wrap')) return;   // already wrapped

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

// ══════════════════════════════════════════════════════════
// FORGOT PASSWORD
// ══════════════════════════════════════════════════════════
async function forgotStart() {
  const email = el('f-email').value.trim();
  clearErr('fg-err-1');

  if (!isEmail(email)) { showErr('fg-err-1', 'Enter a valid email address.'); return; }

  busy('fg-btn-1', true, 'Sending code…');
  const res = await post('forgot_start', { email });
  busy('fg-btn-1', false);

  if (!res.success) { showErr('fg-err-1', res.message); return; }

  fgEmail = email;
  el('fg-email-echo').textContent = email;
  clearCode('fg-code');
  gotoStep('fg', 2);
  startCooldown('fg-resend');
}

async function forgotVerify() {
  const code = readCode('fg-code');
  clearErr('fg-err-2');

  if (code.length !== 6) { showErr('fg-err-2', 'Enter all 6 characters of the code.'); return; }

  busy('fg-btn-2', true, 'Verifying…');
  const res = await post('forgot_verify', { email: fgEmail, code });
  busy('fg-btn-2', false);

  if (!res.success) {
    showErr('fg-err-2', res.message);
    rejectCode('fg-code');
    return;
  }

  gotoStep('fg', 3);
}

async function forgotResend() {
  clearErr('fg-err-2');
  const res = await post('forgot_resend', { email: fgEmail });
  if (!res.success) { showErr('fg-err-2', res.message); return; }
  clearCode('fg-code');
  startCooldown('fg-resend');
}

async function forgotReset() {
  const pass    = el('f-pass').value;
  const confirm = el('f-pass2').value;

  clearErr('fg-err-3');

  if (!passwordOk(pass)) { showErr('fg-err-3', POLICY_TEXT); el('f-pass').focus(); return; }
  if (pass !== confirm)  { showErr('fg-err-3', 'Passwords do not match.'); el('f-pass2').focus(); return; }

  busy('fg-btn-3', true, 'Resetting…');
  const res = await post('forgot_reset', {
    email: fgEmail, new_password: pass, confirm_password: confirm,
  });
  busy('fg-btn-3', false);

  if (!res.success) { showErr('fg-err-3', res.message); return; }

  closeAuthModal('modal-forgot');
  banner('Your password has been reset. You can now log in.', 'ok');
  el('l-email').value = fgEmail;
  el('l-pass').value = '';
  el('l-pass').focus();
}

// ── LEGAL DOCUMENT MODAL ──────────────────────────────────
async function openLegalModal(which) {
  const url     = which === 'terms' ? 'terms.html' : 'privacy.html';
  const heading = which === 'terms' ? 'Terms of Service' : 'Privacy Policy';

  el('legal-heading').textContent = heading;
  const area = el('legal-scroll-area');
  area.innerHTML = '<p>Loading…</p>';

  const btn = el('legal-close-btn');
  btn.disabled = true;
  btn.textContent = 'Scroll to the bottom to close';

  openAuthModal('modal-legal');

  try {
    const res  = await fetch(url);
    const html = await res.text();
    const doc  = new DOMParser().parseFromString(html, 'text/html');
    const body = doc.getElementById('legal-content');
    area.innerHTML = body ? body.innerHTML : '<p>Could not load this document.</p>';
  } catch (e) {
    area.innerHTML = '<p>Could not load this document. Check your connection and try again.</p>';
  }

  // Reset scroll position and re-check on every scroll event.
  area.scrollTop = 0;
  area.onscroll = () => checkLegalScrollEnd(area, btn);
  // Some short content may already fit without scrolling at all —
  // check once immediately so it isn't stuck disabled forever.
  setTimeout(() => checkLegalScrollEnd(area, btn), 100);
}

function checkLegalScrollEnd(area, btn) {
  const reachedEnd = area.scrollTop + area.clientHeight >= area.scrollHeight - 4;
  if (reachedEnd) {
    btn.disabled = false;
    btn.textContent = 'Close';
  }
}

// ══════════════════════════════════════════════════════════
// ON LOAD
// ══════════════════════════════════════════════════════════
// Someone already signed in shouldn't sit on the login page.
(async function bounceIfSignedIn() {
  if (!el('l-email')) return;
  try {
    const res  = await fetch(`${API_BASE}?action=me`, { credentials: 'include' });
    const data = await res.json();
    if (data.success) routeAfterLogin(data.data);
  } catch (e) {
    /* offline or server down */
  }
})();

if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

let ctEmail = '';
let ctPass  = '';

async function ctStart() {
  const first   = el('ct-first').value.trim();
  const last    = el('ct-last').value.trim();
  const gender  = el('ct-gender').value;
  const birth   = el('ct-birth').value;
  const address = el('ct-address').value.trim();
  const email   = el('ct-email').value.trim();

  clearErr('ct-err-1');
  if (!first || !last)      { showErr('ct-err-1', 'Enter your first and last name.'); return; }
  if (!gender)              { showErr('ct-err-1', 'Select a gender option.'); return; }
  if (!birth)               { showErr('ct-err-1', 'Enter your birthdate.'); el('ct-birth').focus(); return; }
  if (address.length < 5)   { showErr('ct-err-1', 'Enter your address.'); el('ct-address').focus(); return; }
  if (!isEmail(email))      { showErr('ct-err-1', 'Enter a valid email address.'); return; }

  busy('ct-btn-1', true, 'Sending code…');
  const res = await post('register_start', {
    first_name: first, last_name: last, gender,
    birthdate: birth, address, email,
  });
  busy('ct-btn-1', false);

  if (!res.success) { showErr('ct-err-1', res.message); return; }

  ctEmail = email;
  el('ct-email-echo').textContent = email;
  clearCode('ct-code');
  gotoStep('ct', 2);
  startCooldown('ct-resend');
}

async function ctVerify() {
  const code = readCode('ct-code');
  clearErr('ct-err-2');
  if (code.length !== 6) { showErr('ct-err-2', 'Enter all 6 characters.'); return; }

  busy('ct-btn-2', true, 'Verifying…');
  const res = await post('register_verify', { email: ctEmail, code });
  busy('ct-btn-2', false);

  if (!res.success) { showErr('ct-err-2', res.message); rejectCode('ct-code'); return; }

  gotoStep('ct', 3);
}

async function ctResend() {
  const res = await post('register_resend', { email: ctEmail });
  if (!res.success) { showErr('ct-err-2', res.message); return; }
  clearCode('ct-code');
  banner('A new code is on its way.', 'ok');
  startCooldown('ct-resend');
}

// Client-side only. Nothing is sent until step 4 submits everything.
function ctPassword() {
  const pass    = el('ct-pass').value;
  const confirm = el('ct-pass2').value;

  clearErr('ct-err-3');
  if (!passwordOk(pass)) { showErr('ct-err-3', POLICY_TEXT); el('ct-pass').focus(); return; }
  if (pass !== confirm)  { showErr('ct-err-3', 'Passwords do not match.'); el('ct-pass2').focus(); return; }

  ctPass = pass;
  gotoStep('ct', 4);
}

async function ctComplete() {
  
  if (!el('ct-terms-agree').checked) {
    showErr('ct-err-4', 'You must agree to the Terms of Service and Privacy Policy to continue.');
    return;
  }

  const team = el('ct-team').value.trim();
  const desc = el('ct-desc').value.trim();

  clearErr('ct-err-4');
  if (team.length < 2) { showErr('ct-err-4', 'Enter a name for your team.'); el('ct-team').focus(); return; }

  busy('ct-btn-4', true, 'Creating…');
  const res = await post('register_complete', {
    email: ctEmail,
    password: ctPass,
    confirm_password: ctPass,
    team_name: team,
    team_description: desc,
    terms_accepted: true,
  });
  busy('ct-btn-4', false);

  if (!res.success) { showErr('ct-err-4', res.message); return; }

  // Clear the password out of memory now that it has been used.
  ctPass = '';
  window.location.href = 'login.html?created=1';
}


// ══════════════════════════════════════════════════════════
// SET UP ACCOUNT  (setup-account.html, prefix "sa")
//
// Reached from the invitation email as setup-account.html?invite=TOKEN.
// Name and birthdate arrive prefilled from the invitation but stay
// editable, so an administrator's typo can be corrected. The email is
// disabled: the invitation was delivered to one address, and that is
// what identifies the account.
// ══════════════════════════════════════════════════════════

let saToken = '';

function saPane(which) {
  ['load', 'dead', 'known', '1', '2'].forEach(k => {
    const p = el('sa-pane-' + k);
    if (p) p.classList.toggle('active', k === which);
  });
  // The stepper only makes sense once we know the invitation is good.
  const steps = el('sa-steps');
  if (steps) steps.style.display = (which === '1' || which === '2') ? 'flex' : 'none';
}

async function saLoad(token) {
  saToken = token;
  saPane('load');

  const res = await fetch(`${API_BASE}?action=invite_lookup&token=${encodeURIComponent(token)}`, {
    credentials: 'include',
  }).then(r => r.json()).catch(() => ({ success: false, message: 'Cannot reach the server.' }));

  if (!res.success) {
    el('sa-sub').textContent      = '';
    el('sa-dead-msg').textContent = res.message;
    saPane('dead');
    return;
  }

  const d = res.data;
  el('sa-sub').textContent = `${d.team_name} · ${d.role_label}`;

  if (d.existing_account) {
    el('sa-inviter2').textContent = d.inviter_name;
    el('sa-team2').textContent    = d.team_name;
    el('sa-role2').textContent    = d.role_label;
    el('sa-email2').textContent   = d.email;
    el('sa-login-link').href =
      'login.html?claim=' + encodeURIComponent(saToken) + '&email=' + encodeURIComponent(d.email);
    saPane('known');
    return;
  }

  el('sa-inviter').textContent    = d.inviter_name;
  el('sa-team').textContent       = d.team_name;
  el('sa-role').textContent       = d.role_label;
  el('sa-email').value            = d.email;
  el('sa-email-echo').textContent = d.email;
  el('sa-first').value            = d.first_name || '';
  el('sa-last').value             = d.last_name  || '';
  el('sa-birth').value            = d.birthdate  || '';

  saPane('1');
  gotoStep('sa', 1);
}

function saNext() {
  const first   = el('sa-first').value.trim();
  const last    = el('sa-last').value.trim();
  const birth   = el('sa-birth').value;
  const gender  = el('sa-gender').value;
  const address = el('sa-address').value.trim();

  clearErr('sa-err-1');
  if (!first || !last)    { showErr('sa-err-1', 'Enter your first and last name.'); return; }
  if (!birth)             { showErr('sa-err-1', 'Enter your birthdate.'); el('sa-birth').focus(); return; }
  if (!gender)            { showErr('sa-err-1', 'Select a gender option.'); el('sa-gender').focus(); return; }
  if (address.length < 5) { showErr('sa-err-1', 'Enter your address.'); el('sa-address').focus(); return; }

  gotoStep('sa', 2);
}

async function saComplete() {
  if (!el('sa-terms-agree').checked) {
    showErr('sa-err-2', 'You must agree to the Terms of Service and Privacy Policy to continue.');
    return;
  }
  
  const pass    = el('sa-pass').value;
  const confirm = el('sa-pass2').value;

  clearErr('sa-err-2');
  if (!passwordOk(pass)) { showErr('sa-err-2', POLICY_TEXT); el('sa-pass').focus(); return; }
  if (pass !== confirm)  { showErr('sa-err-2', 'Passwords do not match.'); el('sa-pass2').focus(); return; }

  busy('sa-btn-2', true, 'Creating account…');
  const res = await post('invite_accept', {
    token: saToken,
    first_name: el('sa-first').value.trim(),
    last_name:  el('sa-last').value.trim(),
    birthdate:  el('sa-birth').value,
    gender:     el('sa-gender').value,
    address:    el('sa-address').value.trim(),
    password: pass,
    confirm_password: confirm,
    terms_accepted: true,
  });
  busy('sa-btn-2', false);

  if (!res.success) {
    // The address gained an account while this page was open.
    if (res.data && res.data.existing_account) { saPane('known'); return; }
    showErr('sa-err-2', res.message);
    return;
  }

  window.location.href = 'login.html?joined=1';
}

// Birthdate pickers can never offer today or later.
(function capBirthdates() {
  const today = new Date().toISOString().slice(0, 10);
  ['ct-birth', 'sa-birth'].forEach(id => { if (el(id)) el(id).max = today; });
})();

initCodeInput('ct-code');

(function bootSetupAccount() {
  if (!el('sa-pane-load')) return;
  const token = new URLSearchParams(window.location.search).get('invite');
  if (!token) {
    el('sa-sub').textContent      = '';
    el('sa-dead-msg').textContent = 'This page needs an invitation link. Ask your administrator to send you one.';
    saPane('dead');
    return;
  }
  saLoad(token.trim());
})();

// login.html: confirmation banners after arriving from another flow.
(function loginArrivalBanners() {
  if (!el('l-email')) return;
  const q = new URLSearchParams(window.location.search);
  if (q.get('created')) banner('Your team is ready. Log in to invite your crew.', 'ok');
  if (q.get('joined'))  banner("You're all set. Log in to get started.", 'ok');
})();

(function loginClaimInvite() {
  const box = el('l-email');
  if (!box) return;                       // not the login page

  const q     = new URLSearchParams(window.location.search);
  const token = q.get('claim');
  if (!token) return;

  const email = q.get('email');
  if (email) box.value = email;
  banner('Log in to accept your invitation.', 'ok');
  el('l-pass').focus();

  window.PENDING_CLAIM = token;
})();

initPasswordToggles();