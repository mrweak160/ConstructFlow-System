# ConstructFlow — Backend Setup Guide

## Folder Structure
```
constructflow/
├── index.html          ← entry point, redirects to login.html
├── login.html           ← login screen
├── inspector.html        ← Field Inspector portal
├── supervisor.html       ← Supervisor portal
├── fieldworker.html      ← Field Worker portal
├── admin.html            ← Administrator portal
├── style.css            ← shared stylesheet (all pages)
├── app.js               ← shared app logic (all pages)
├── api.js              ← frontend API connector (not yet wired in — see note below)
├── api/
│   ├── auth.php        ← login, logout, change password
│   ├── reports.php     ← inspection reports
│   ├── workorders.php  ← work orders and field work updates
│   ├── users.php       ← user management (admin)
│   └── logs.php        ← audit trail (admin)
├── config/
│   ├── db.php          ← database connection (edit this)
│   └── helpers.php     ← shared functions
├── uploads/
│   └── photos/         ← inspection photos stored here
├── database.sql        ← run this first in phpMyAdmin
└── .htaccess           ← Apache config
```

Each HTML page loads `style.css` and `app.js`. On the four role pages, `app.js`
checks that someone is logged in (and on the right page for their role)
before rendering anything, redirecting back to `login.html` otherwise.

## Setup Steps

### 1. Install XAMPP (local development)
Download from https://www.apachefriends.org and install.
Start Apache and MySQL from the XAMPP Control Panel.

### 2. Create the database
- Open phpMyAdmin: http://localhost/phpmyadmin
- Click "New" → name it `constructflow` → click Create
- Click the `constructflow` database → click "Import"
- Choose `database.sql` → click Go

### 3. Configure the database connection
Open `config/db.php` and set:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'constructflow');
define('DB_USER', 'root');   // your MySQL username
define('DB_PASS', '');       // your MySQL password (blank for XAMPP default)
```

### 4. Copy files to XAMPP
Copy the entire `constructflow/` folder to:
```
C:/xampp/htdocs/constructflow/
```

### 5. Open in browser
Go to: http://localhost/constructflow/ (or /index.html) — it redirects
straight to `login.html`.

### 6. Connecting api.js (optional, not done yet)
The app currently runs on a localStorage-backed demo data layer (see
`app.js`), the same as before — none of the pages call `api.php` yet.
`api.js` is a ready-made connector for your PHP backend; wiring it in
means replacing the `DB.*` calls in `app.js` with the matching
`API.*` calls (e.g. `DB.get('reports')` → `API.listReports()`) once
you're ready to move off demo data.

## Default Login Credentials
| Role | Email | Password |
|------|-------|----------|
| Administrator | admin@constructflow.com | Password123! |
| Field Inspector | inspector@constructflow.com | Password123! |
| Supervisor | supervisor@constructflow.com | Password123! |
| Field Worker | fieldworker@constructflow.com | Password123! |

**Change all passwords immediately after first login.**

## How the workflow works
1. **Field Inspector** logs in → submits inspection report with photo + geotag
2. **Supervisor** logs in → sees pending report → assigns to a Field Worker
3. **Field Worker** logs in → sees assigned work order in queue → updates status
4. **Supervisor/Admin** → see updated status on dashboard in real time
5. **Administrator** → manages users, views audit trail, exports reports

## For hosting (after defense)
- Upload all files to your PHP hosting provider
- Import `database.sql` via phpMyAdmin on your host
- Update `config/db.php` with your host's MySQL credentials
- Update `BASE_URL` in `api.js` if the folder structure changes
