# ConstructFlow — Handover

*Last updated 28 October 2026. Replaces the September handover (`Downloads\HANDOVER.md`), which predates the severity engine and everything after it.*

**Do not upload this file to the live site.** It describes the security setup. It holds no passwords or keys, so it is safe in the Git repository.

**How to use it:** sections 1–4 explain what the system is and how it is put together. Section 5 is the severity engine. Sections 6–8 cover running, deploying and testing it. Section 9 is the troubleshooting runbook. Section 10 is the history of what was built and fixed, and section 11 lists the rules that must not be broken.

---

## Contents
1. [What ConstructFlow is](#1-what-constructflow-is)
2. [Where everything is](#2-where-everything-is)
3. [Technology and why](#3-technology-and-why)
4. [How the system is built](#4-how-the-system-is-built)
5. [The rule-based severity engine](#5-the-rule-based-severity-engine)
6. [Running it locally and testing](#6-running-it-locally-and-testing)
7. [Deploying to Hostinger](#7-deploying-to-hostinger)
8. [Email, notifications and reminders](#8-email-notifications-and-reminders)
9. [Troubleshooting runbook](#9-troubleshooting-runbook)
10. [What we did: history of changes and fixes](#10-what-we-did-history-of-changes-and-fixes)
11. [Rules and decisions that must not be broken](#11-rules-and-decisions-that-must-not-be-broken)
12. [Open items](#12-open-items)

---

## 1. What ConstructFlow is

**ConstructFlow: A Rule-Based AI Severity Classification and Work Order Prioritization System for Small Construction Teams.** A capstone project of Tarlac State University, College of Computer Studies, A.Y. 2025–2026, by Calma, Delos Santos, Dizon and Failma. Technical adviser: Axel Millet.

It takes a construction defect from the inspector's report to a verified repair:

1. A **Supervisor** creates an inspection task for a **Field Inspector**.
2. The inspector describes the finding in their own words (English, Tagalog or Taglish), attaches up to 10 photos and the GPS location.
3. A **rule-based AI** (an expert system, not machine learning) reads the description and **recommends** a severity: Low, Moderate, High or Critical.
4. The supervisor keeps or overrides that recommendation (both are recorded) and turns the finding into a **work order** with a crew and a deadline.
5. **Field Workers** update progress with photos.
6. A three-stage check closes it: the supervisor reviews, an inspector confirms on site, and the supervisor closes it.

The **Administrator** runs the team (members, roles, reports, the activity log) but does not do the inspection work.

**Roles belong to team membership, not to the account.** One person can be a supervisor on one team and a worker on another. Every request is checked on the server against the person's role in the active team.

---

## 2. Where everything is

| What | Where |
|---|---|
| Project (source of truth) | `C:\xampp\htdocs\constructflow` |
| Git repository | `github.com/mrweak160/ConstructFlow-System`, branch `main` (Mark pushes) |
| Live site | `https://powderblue-hyena-124442.hostingersite.com` (Hostinger shared hosting) |
| Local database | `constructflow` on XAMPP MariaDB (uses the **real** email key; see section 11) |
| Throwaway test copy | `C:\xampp\htdocs\cf_ui3` with database `cf_ui3` (email key blanked) |
| Test server for the copy | `php -S 127.0.0.1:8122 -t C:/xampp/htdocs/cf_ui3` |
| Test accounts (copy only) | `admin@`, `sup@`, `insp@`, `insp2@`, `work@`, `work2@test.local` (shared test password; not written here) |
| Live upload kit (Oct 28) | `C:\Users\Mark\Downloads\ConstructFlow Live Upload\` (see section 7) |
| Paper | `Downloads\Capstone 2 - Paper (V2).pdf` / `.docx` |
| Defense deck (example) | `Downloads\ConstructFlow - Final Defense (Example).pptx` |

---

## 3. Technology and why

| Layer | Technology | Why |
|---|---|---|
| Server | **PHP 8.2** locally, **8.3** on Hostinger. No framework. | The shared hosting runs PHP only, with no Python or Node runtime. That is also why the AI is rule-based. |
| Database | **MariaDB / MySQL** (InnoDB, utf8mb4) | Standard on XAMPP and Hostinger. |
| Front end | **HTML, CSS, plain JavaScript**. No framework, no build step. | Light on phones; the files you edit are the files that run. |
| App shell | **Progressive Web App** (`manifest.json`, `sw.js`) | Installs on a phone home screen like an app. |
| Email | **Brevo** HTTP API | Verification codes, invitations, hand-off emails, reminders. |
| Location | Browser **Geolocation API**, then **OpenStreetMap Nominatim** (reverse geocoding) | GPS to "Province, City, Barangay". |
| Address check | **PSGC** (Philippine Standard Geographic Code) tables in the database | Validates and picks province, city and barangay. |
| Fonts | **Inter**, self-hosted (`fonts/`) | Allowed by the security policy (no external fonts). |
| Hosting | **Hostinger** shared web hosting, HTTPS | The client's budget. |

There are no outside JavaScript libraries. Charts (`charts.js`) are drawn in plain HTML and SVG.

---

## 4. How the system is built

### 4.1 File map

```
*.html            One page per role: admin, supervisor, inspector, fieldworker.
                  Sign-in: login, create-team, setup-account, team (team gate).
                  Public: index (landing), terms, privacy. Tools: guide, print.
app.js            The logic of all four role pages (one large file, sectioned).
auth.js / team.js Sign-in, registration, invitation and team-gate pages.
charts.js         The three dashboard charts (severity, status, weekly trend).
guide.js          "How severity works" page (reads the knowledge base live).
print.js/.css     Printable documents: report, work order, case file, summaries.
style.css         All app styling, light and dark themes.
sw.js             Service worker (offline cache). Bump CACHE on every release.
api/              One PHP file per resource; ?action= picks the operation.
config/           helpers.php (shared functions — the most important file),
                  SeverityClassifier.php (the engine), SeverityTestRunner.php,
                  logseal.php (sealed log), mailer.php, notify.php,
                  mail_config.php, db.php + secrets.php (real credentials,
                  never committed or uploaded), *.example.php (templates).
tools/            Command-line jobs: backup, reminders, engine tests, coverage.
db/               database.sql (fresh install), upgrade_live.sql (live upgrades),
                  address_reference.sql (PSGC data), live_step1/2 (Oct 28 kit).
storage/          mail.log (local), log_key.php (log-sealing key). Never served.
uploads/          photos/, reports/, avatars/. Never committed.
```

### 4.2 API reference

All endpoints return JSON `{ success, message, data }`. Every POST needs the CSRF token in the `X-CSRF-Token` header. Roles are checked on the server for every call.

| File | Actions | Who |
|---|---|---|
| `api/auth.php` | `login`, `logout`, `me`, `register_start/verify/resend/complete`, `forgot_start/verify/resend/reset`, `invite_lookup/accept/claim`, `switch_team`, `change_password`, `account_summary`, `delete_account` | Public / signed in |
| `api/teams.php` | `create`, `detail`, `members`, `member_profile`, `invite`, `invites`, `resend_invite`, `revoke_invite`, `assign_role`, `remove_member`, `former_members`, `erase_member`, `update_team`, `delete_team` | Mostly Administrator |
| `api/tasks.php` | `create`, `list`, `update`, `reassign`, `cancel`, `close` | Supervisor (list: all roles, own tasks for inspectors) |
| `api/reports.php` | `submit`, `edit`, `list`, `detail` | Inspector submits/edits; supervisor and admin read |
| `api/workorders.php` | `create`, `list`, `detail`, `update_status`, `update_details`, `update_assignees`, `verify`, `reassign_verifier` | Supervisor creates; workers update; verification by supervisor and inspector |
| `api/summary.php` | `document` (printouts), `reports`, `workorders`, `workload` | Printouts for anyone with access; summaries for supervisor and admin |
| `api/users.php` | `list`, `self_edit`, `avatar_upload`, `avatar_remove` | Signed in |
| `api/logs.php` | `list`, `export` (CSV), `verify` (seal check) | Administrator |
| `api/guide.php` | `knowledge`, `try` (classifies without saving) | Any team member |
| `api/address.php` | `provinces`, `cities`, `barangays` (PSGC) | Signed in |
| `api/geocode.php` | Reverse geocoding through Nominatim | Signed in |

### 4.3 Database (27 tables)

| Group | Tables |
|---|---|
| Identity | `Users`, `PendingRegistrations`, `PasswordResetCodes`, `LoginAttempts` |
| Teams | `Teams`, `TeamMembers` (role and status live here), `Invitations` |
| The work | `InspectionTasks`, `InspectionReports`, `PhotoEvidence`, `WorkOrders`, `WorkOrderAssignees`, `FieldWorkUpdates`, `FieldWorkPhotos`, `GeneratedReports` |
| Audit | `ActivityLogs`, `LogChains` (sealed log) |
| Severity engine | `DefectTypes`, `DefectCriteria`, `ElementTypes`, `LexiconTerms`, `SeverityMatrix`, `ClassificationTests`, `TestRuns`, `TestResults`, `LexiconChangelog` |
| Addresses | `RefProvince`, `RefCity`, `RefBarangay` (from `address_reference.sql`) |

**Statuses**
- **Task:** `assigned` → `submitted` → `closed`, or `cancelled`.
- **Report:** `pending` → `assigned` → `in_progress` → `completed`, or `rejected`, which the screens show as **"Closed"** (the task was closed without a work order).
- **Work order:** `pending`, `in_progress`, `on_hold`, `rework`, `pending_supervisor`, `pending_inspector`, `pending_closure`, `completed`.

### 4.4 Security model

- **Sessions:** cookie `HttpOnly`, `SameSite=Strict`, `Secure` on HTTPS. The role is re-read from the database on every request, never trusted from the session.
- **CSRF:** a token issued at sign-in and required on every POST. A missing token in an expired session returns **401 "Your sign-in has expired"**.
- **Sign-in throttle:** 5 failed attempts within 15 minutes locks that email for 15 minutes.
- **Passwords:** at least 8 characters, with an upper case letter, a lower case letter and a number; stored with bcrypt.
- **Codes and invitations:** 6-character email codes expire after 10 minutes. Invitation links are single-use and last 72 hours.
- **Team isolation:** every query is limited to the active team. An inspector sees only their own reports; a worker only their own crews.
- **Uploads:** JPG, PNG or WEBP only, at most 10 MB each and 10 per report. The browser shrinks photos to 1600 px first; over-size requests get **413 "photos are too large"**.
- **Output:** everything is escaped (`esc()` in JS); CSV exports are guarded against formula injection.
- **Headers (`.htaccess`):** a strict Content-Security-Policy (self only), `X-Frame-Options DENY`, `nosniff`, no directory listing. `storage/` and `db/` are denied entirely.
- **Sealed activity log:** each team's log is a chain signed with HMAC-SHA256 (`config/logseal.php`, key in `storage/log_key.php`). Admin → Activity Logs → Verify detects edits or deletions.
- **Account erasure:** self-service deletion or the admin's "erase former member" wipes personal details. The name stays on old records, marked "(deleted)".
- **Guards:** a team can never lose its last administrator or last supervisor. A member with open work cannot be removed or have their role changed. The team owner cannot be removed or demoted.

### 4.5 Front-end notes

- **`app.js`** is sectioned by role. Views switch with `switchView(screen, view)`, and `refreshActiveView()` re-draws the current view after any action. `rowOpen()` makes rows clickable without stealing clicks from their buttons.
- **One-tap guard:** a `window.fetch` wrapper disables the clicked button while its request runs, which stops double submissions.
- **Report drafts** are kept in `localStorage` per task until submitted.
- **Theme:** light by default; dark through the settings menu (`cf_theme`). In light mode the severity badges are green `#15803d`, gold `#c8a10c`, orange `#e0590f` and red `#9b1c1c`.
- **Charts (`charts.js`):** `renderDashCharts(prefix, rows, opts)` draws severity bars, status bars and a stacked weekly trend (monthly over long ranges). The axis rescales automatically. Colours were checked for colour-blind readers.
- **Printing (`print.html` + `print.js`):**
  - Types: `report`, `workorder`, `task`, `case`, `admin` (summaries) and `workload`.
  - One-page documents scale to fill the page (`fitToPage`). Long ones split into real pages on screen (`paginate`), so the preview shows exactly where the printer cuts.
  - The summaries print in A4 landscape.
- **Service worker:** `sw.js` caches the app. **Increase `CACHE` (e.g. `constructflow-v162` → `v163`) on every release**, or users keep the old files.

---

## 5. The rule-based severity engine

### 5.1 Design (paper section 4.3.2.3, pp. 103–111)

The engine is an **expert system**, a form of symbolic AI. It has a **knowledge base** (database tables) and an **inference engine** (`config/SeverityClassifier.php`). It does not learn and needs no training data. Every result can be traced to the words it matched.

The steps:
1. **Read the words:** lower-case the text, drop filler words (the, ang, sa…), trim word endings (cracks / cracking → crack). English, Tagalog and Taglish are all handled.
2. **Defect level, D1–D4:** a defect word names the type (crack, bitak, honeycombing, tagas). A condition word sets the level (hairline = 1, wide = 4). With no condition, the type's default level applies (usually D2).
3. **Consequence level, C1–C4:** the building part (column, beam, slab = 4; paint = 1). If no part is named, it uses "finishes affecting use" (C2) and **never assumes a load-bearing part**, with one exception: **settlement** is read as the footing (C4), because settlement is the foundation itself moving (`IMPLIED_ELEMENT`). Words about people at risk (workers below, residents) raise C by one.
4. **Modifiers:** severe or malaki raises D by one, and minor or maliit lowers it, capped at one step. **Negation** (no, walang, hindi, wala…) cancels everything after it **up to the end of its clause**, stopping at a comma or at but, and, however, pero, ngunit… ("clause scope", after NegEx).
5. **Matrix lookup:** D and C point to one cell of `SeverityMatrix`. They are **never multiplied** (Cox, 2008).

**Review flag (`InspectionReports.needs_review`):** set when there is
- no listed defect type, or no building part named;
- a misspelled defect word;
- a second defect type, or a more serious part, also mentioned;
- a measurement (it is not graded);
- an inspector's verdict ("stop work", "for NCR") two or more levels away from the result.

**There is no confidence score.** The adviser rejected it on 22 October; see section 11.

### 5.2 Knowledge base today
- **10 defect types**, **14 building parts**, the 4×4 matrix.
- **521 vocabulary entries (428 distinct words)**, each with its source: IEC 31010:2019, ASTM D6433-24, AASHTO MBEI, ACI 224.1R, ICRI (2022), DPWH Std. Specs Vol. IV, Cox (2008), or "researcher-developed" for the Tagalog and Taglish terms.
- **45 tests**: 8 survey anchors (the practitioners' expected levels) and 37 regression tests. **All 45 pass.**
- The knowledge base is **global** (shared by all teams) and **read-only in the app**. It is extended through SQL, as a defense demonstration.

### 5.3 How to change the engine safely
1. Add the rows in SQL (`LexiconTerms`, with `kind`, the target and a **source note**). Copy the pattern of sections 6 and 8 in `db/upgrade_live.sql`.
2. Add a regression test row (`ClassificationTests`) that pins the new behaviour.
3. Add a `LexiconChangelog` row saying what changed and why.
4. Run the tests:
   ```
   C:\xampp\php\php.exe tools\run_severity_tests.php "what you changed"
   ```
   Every test must still pass; the run is recorded in `TestRuns`.
5. Put the same SQL into `database.sql` (fresh installs) and a new numbered section of `upgrade_live.sql` (live).
6. Check the result on the **How severity works** page (Try it).
7. Never invent a number. Every level must trace to a source in the paper.

`tools/severity_coverage.php` runs a file of real descriptions and reports which words the engine did not recognize, which is how to find missing vocabulary.

---

## 6. Running it locally and testing

### 6.1 Local setup
1. Start Apache and MySQL in XAMPP. The project runs at `http://localhost/constructflow/`.
2. On a new machine:
   - copy `config/db.example.php` → `config/db.php` and `config/secrets.example.php` → `config/secrets.php`, and fill them in;
   - import `db/database.sql`, then `db/address_reference.sql`.
3. `config/mail_config.php` decides local versus production (`APP_ENV`, `APP_URL`, `APP_HTTPS`, `MAIL_DRIVER`).

### 6.2 How changes were made and tested (the workflow that worked)
1. **Write the change as a patch script** in a scratch folder. These are PHP scripts using the `F()` / `rep()` pattern: an exact old block replaced with a new block, all or nothing. They preserve Windows line endings, and they must call **`unset($t)` before the write loop**.
2. **Apply it to the test copy** (`cf_ui3`) first, and test it there:
   - as each role, in the browser;
   - at screen sizes from 360 px (phone) to 1920 px;
   - in light and dark mode.
3. **Back up** the affected project files (and any database tables) before applying.
4. **Apply the same patch to the project**, then compare it with the test copy (`diff --strip-trailing-cr`) to confirm they are identical.
5. **Bump `sw.js` `CACHE`**.

### 6.3 Tests to run after a change
- **Engine:** `tools/run_severity_tests.php`. All 45 must pass.
- **Black box:** the test-case tables in Chapter 4 of the paper (FI, SV, FW, AD, AU, SC series).
- **Layout:** each role's screens and pop-ups at 360, 390, 768, 1024, 1366 and 1920 px wide, plus a phone held sideways (740 × 360). Check:
  - the page never gets wider or taller than the window;
  - nothing sticks out of its panel;
  - no text is cut off.
- **Security:** signed out, every API returns 401; on Apache, `db/` and `storage/` return 403.

---

## 7. Deploying to Hostinger

### 7.1 Never upload
`config/db.php`, `config/secrets.php`, `config/mail_config.php` (live has its own), `db/`, `storage/` (except `storage/.htaccess`), `uploads/` contents, `.git/`, `mailtest.php`, `HANDOVER.md`, `README.md`.

**Never import `db/database.sql` on live.** It drops every table. Live upgrades only go through `db/upgrade_live.sql`, whose sections are safe to run twice.

### 7.2 The pending upload (prepared 28 October)
Live currently matches git commit `c6a4151`. Its database is at section 4 of `upgrade_live.sql`. The kit is in `Downloads\ConstructFlow Live Upload\`.

1. **Back up the live database:** phpMyAdmin → select the database → Export → Quick → SQL.
2. **Run step 1:** phpMyAdmin → select the database → Import → `STEP 2 - live_step1_before_upload.sql`. These are sections 5–8. They only **add** things, so the old code keeps working.
3. **Upload the files:** File Manager → `public_html` → upload `STEP 3 - constructflow_update.zip` → Extract **into `public_html` itself** (not a new subfolder), replacing files → delete the zip. It holds 27 files, including the new `charts.js`, `guide.html`, `guide.js`, `api/guide.php`, `tools/reminders.php` and `storage/.htaccess`.
4. **Run step 2:** Import `STEP 4 - live_step2_after_upload.sql`. This drops the old confidence column, and must come **after** the upload, because the old code still wrote to it.
5. **Set up the cron job** (hPanel → Advanced → Cron Jobs, custom), daily at **0 23 \* \* \*** (7:00 AM Philippine time if the server uses UTC):
   ```
   /usr/bin/php /home/<user>/domains/powderblue-hyena-124442.hostingersite.com/public_html/tools/reminders.php
   ```
6. **Check the site:** press Ctrl+Shift+R, then check the charts, the guide, submitting a report and the "Closed" label.
7. **Clean up:** delete `Downloads\public_html.zip`. It contains the live passwords and the log key.

### 7.3 Every later release
- Run the new `upgrade_live.sql` sections first, if they only add things. If a section removes something the old code uses, split it into before and after the upload, as above.
- Upload only the changed files, and bump `sw.js` `CACHE`.

### 7.4 Backups
- **Database:** `tools/backup.php` writes a compressed SQL dump. It can run as a cron job too.
- **Log key:** keep a copy of `storage/log_key.php` from live. Without it, a restored log reports every entry as "changed".

---

## 8. Email, notifications and reminders

- **`config/mailer.php`** sends through Brevo. If the key is empty or the driver is unknown, it **falls back to writing `storage/mail.log`**, which is how test copies work.
- **`config/notify.php`** sends the **hand-off emails**: whoever now holds a piece of work is emailed (a task to inspect, a report to review, a repair, a site check, a closure). They are queued and sent after the response, so a slow mail service never blocks the user. Nobody is emailed about their own action.
- **`tools/reminders.php`** is a daily command-line job. It emails whoever holds a task or work order **due within 3 days**, and again when it becomes overdue. It sends once per due date: changing the date or the assignee re-arms it (`due_reminder_sent_at`, `deadline_reminder_sent_at`). It refuses to run from a browser.
- **Do not run reminders or test notifications on the local `constructflow` database.** It holds the real Brevo key and real team members' addresses. Use `cf_ui3`.

---

## 9. Troubleshooting runbook

| Symptom | Likely cause | What to do |
|---|---|---|
| Page shows old behaviour after an upload | The service worker served cached files | Bump `CACHE` in `sw.js` and upload it; users press Ctrl+Shift+R |
| Blank page or a broken screen | A JavaScript error | Open DevTools (F12) → Console; the first red error names the file and line |
| "Your sign-in has expired" (401) | The session ended (browser closed or timed out) | Sign in again. Expected behaviour. |
| "Invalid security token" | The CSRF token didn't match (an old tab) | Reload the page |
| "Photos are too large" (413) | The request exceeded PHP's `post_max_size` | Fewer photos; on the server raise `upload_max_filesize` / `post_max_size` |
| Emails not arriving | Brevo key missing or wrong, sender not verified in Brevo, or `MAIL_DRIVER` | Check `config/secrets.php` and Brevo's log; locally, look in `storage/mail.log` |
| GPS gives no address | Nominatim is unreachable or limited, or location permission was denied | The inspector picks the address from the Province → City → Barangay lists; it still saves |
| Activity log "Verify" reports changes | A database was copied between servers (different `log_key.php`), or the log really was edited | Copy the database together with its key; if neither happened, investigate the listed entries |
| A severity looks wrong | A word is missing from the vocabulary, or negation or the clause split changed the meaning | Put the sentence into **How severity works → Try it** to see which words matched; add vocabulary (section 5.3); the supervisor overrides in the meantime |
| A report is flagged for review | One of the listed conditions (section 5.1) | Expected; the supervisor checks it |
| Reminders not sent | Cron not set, wrong path or server time, or already sent for that date | Check the cron output in hPanel; run the command once by hand; changing the date re-arms it |
| Can't remove a member or change a role | Open work orders or inspections, last admin or supervisor, or the team owner | Reassign the work first; the message says which rule blocked it |
| Delete team is greyed out | Other active members remain | Remove them first; only the last member can delete |
| 500 error on live | A PHP error | hPanel → Advanced → Error logs; check the PHP version (8.2+) |
| XAMPP MySQL won't start | A port conflict or a crashed table | Check XAMPP's MySQL log. **Never delete or replace files in `C:\xampp\mysql\data`**; restore from a backup instead |
| A page is wider than the phone screen | A new element with a fixed width | Re-run the layout checks (section 6.3); the full-width panels are intentional |

**General approach to any bug:**
1. Reproduce it on the test copy, not on live.
2. Read the console and the PHP error log.
3. Fix it with a patch script, test it, back up, then apply.
4. Add a regression test if the engine was involved.

---

## 10. What we did: history of changes and fixes

**August–September 2026 (git history)**
- Initial build: the four role portals, tasks, reports with photos and GPS, work orders, dashboards, activity log.
- Teams: multi-team roles, business-owner registration with email verification, invitation onboarding, password reset by code, sign-in throttling, security fixes.
- Profiles with photos, a view/edit split, schema import fixes, more hardening; dashboard redesign.
- On 9 September, ownership transfer and team deletion were built and then removed at Mark's request.

**24 September — the severity engine installed.** Knowledge base, vocabulary, inference engine and test runner. The engine's defect type replaced the manual issue-type dropdown; the supervisor override is recorded.

**Mid-October**
- **Hardening:** the sealed activity log, hand-off emails and backups. Two-step sign-in was built and then removed (too many emails).
- **Additions:** contact numbers, print image and time fixes, notifications.
- **Last commit:** `c6a4151`. Live matches this commit.

**Around 22 October — validation and the adviser's changes**
- Engine sources documented; Table 41 (expert agreement, F-01 to F-16) built and retested against local and live.
- **Confidence score removed** (adviser): replaced by the stored review flag with plain conditions.
- Fixes from the paper's Table 37 and a full button, visual and security retest: green avatar fix, work-order modal fixes, "Close Task" moved and coloured red.

**26 October**
- **Engine:** negation changed from "next three words" to clause scope; `hindi` and `wala` added.
- **Reports:** photos shrunk in the browser; report drafts; clear 401 and 413 messages; a global JSON error handler; field length limits; one-tap guard; longer, tap-to-dismiss messages.
- **Tasks and reminders:**
  - a closed task's report shows as **"Closed"**;
  - due-date reminders (`tools/reminders.php`) and a due-soon tag;
  - the last-supervisor guard.
- **Server:** `storage/` and `db/` locked down.

**27 October — panel recommendations (update list U1–U5) and polish**
- **U1:** "Contact us" replaced by the **How severity works** guide: the steps, the matrix, defect levels, building parts, the full vocabulary with sources, and a Try-it box.
- **U2:** the admin can open and print single reports and work orders.
- **U3:** **charts** on the supervisor, inspector and admin screens. Light-mode severity colours made to match the badges and checked for colour-blind readers.
- **U4:** a **Former members** list; the admin can erase a removed member's personal details. Self-deletion now shares the same code.
- **Team management:** another admin's role can be changed (never the owner's); the owner cannot be removed; **delete team** for its last member (the team is hidden, records kept).
- **Printouts:**
  - summaries gained location with a GPS map link, phase and D/C levels, and print in A4 landscape;
  - long documents are split into real pages on screen.
- **Labels:** "Closed – No Action" renamed **"Closed"** everywhere.
- **Bug fixes:**
  - the chart's hidden table made the page taller than the window (fixed);
  - the sidebar was unreachable on short screens (now scrolls);
  - the pending list had an empty strip at the bottom;
  - an extra space was added under the guide link.
- **Engine:**
  - **F-07** "missing handrail / guardrail / railing" now reads as safety-critical work missing (D4) on the railing;
  - **SURVEY-8** settlement now reads the foundation;
  - tests went from 42/43 to **45/45**.
- **U5 (client folders):** fully built and tested, then **reverted** at Mark's request. The patch is kept for later (section 12).

**28 October**
- **Live upload prepared:** files compared with live, a split two-step SQL plan rehearsed on a copy, and the upload kit built.
- **Defense:** an example final-defense deck made; the paper-versus-system differences listed.

---

## 11. Rules and decisions that must not be broken

1. **No invented numbers** (adviser). Every level, weight or threshold must trace to a source in the paper. **Never reintroduce a confidence percentage.**
2. **The methodology stays as written.** The engine recommends; the **supervisor makes the final decision**.
3. **The knowledge base is global and read-only in the app.** It changes through SQL, with a changelog row and a test.
4. **Never import `database.sql` on live.** Use `upgrade_live.sql` sections only.
5. **Never upload** the credential files, `db/`, `storage/` (except its `.htaccess`), `uploads/`, `.git/`, `mailtest.php` or this file.
6. **Never test emails or run reminders on the local `constructflow` database.** It holds the real Brevo key. Use `cf_ui3`.
7. **Never sign in to real users' accounts** for testing (for example, Harvey Calma's), and never test against live data.
8. **Never delete files in `C:\xampp\mysql\data`.**
9. **Commits:** short, plain messages with **no Claude co-author line**. Mark pushes `main` himself.
10. **Every patch** has `unset($t)` before its write loop. After multi-page patches, check each page's `<title>` and `data-page`.
11. **Bump `sw.js` `CACHE`** on every release.

---

## 12. Open items

- **Upload to live** using the kit in section 7.2, including the cron job, then delete `public_html.zip`.
- **Commit to git.** Everything since `c6a4151` is uncommitted.
- **Paper updates:**
  - counts: 521 entries / 428 words, 37 regression and 45 total tests;
  - post-validation notes for F-07 and SURVEY-8, plus the settlement exception to "never assumes a load-bearing part";
  - negation as clause scope; the leftover confidence percentages on pp. 146–147;
  - Appendix B "Table 4c" → Table 17; the Conclusions' expert-validation numbers to match Table 42 (19 cases);
  - the table-of-contents page numbers (about 2 pages off from Chapter 4);
  - move the built items (charts, guide, admin printing, former members, team deletion) out of Recommendations.
- **U5 client folders:** the tested patch is saved in `Downloads\ConstructFlow U5 Folders (saved patch)\`. To re-apply:
  1. Run `php u5.php <app root>` on a test copy first; it adds `api/folders.php` and edits 15 files.
  2. Import `u5.sql`, which becomes upgrade section 9.
  3. Test, then apply to the project.
  4. Bump `sw.js` `CACHE` past v162; the patch itself sets v161.
- **Not covered yet:** iPhone and Safari testing; saving reports offline when there is no signal; a fresh validation of the engine with more practitioners.
- **Optional:** transfer of team ownership (the owner is currently fixed to the creator).
