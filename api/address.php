<?php
// ConstructFlow — Address Reference API (PSGC)
//
// Three lazy steps. Nothing is preloaded: the browser asks for provinces once,
// then for the municipalities of one province, then for the barangays of one
// municipality. The heaviest possible response is Manila's 897 barangays (~32 KB);
// the average municipality returns about 25 rows (~1 KB).

require_once __DIR__ . '/../config/helpers.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method !== 'GET') {
    json_response(false, 'Method not allowed.', [], 405);
}

// Reference data is only served to signed-in users, like the rest of the API.
requireAuth();

// This data never changes between deployments, so let the browser keep it.
// After the first pick of a given province the next one costs no request at all.
header('Cache-Control: private, max-age=86400');

/** PSGC codes are fixed-width digit strings; reject anything else before it reaches SQL. */
function psgcCode(string $value, int $length): string {
    $value = trim($value);
    if (strlen($value) !== $length || !ctype_digit($value)) {
        json_response(false, 'Invalid location code.', [], 400);
    }
    return $value;
}

// ── GET ?action=provinces ────────────────────────────────────
// The only call made on page load. 84 rows: 81 provinces, Metro Manila,
// and the two province-level cities (Cotabato City, City of Isabela).
//
if ($action === 'provinces') {
    $rows = getDB()
        ->query('SELECT province_code, province_name FROM RefProvince ORDER BY province_name')
        ->fetchAll();

    json_response(true, '', ['provinces' => $rows]);
}

// ── GET ?action=cities&province=0722 ─────────────────────────
// Fired only once a province is chosen. Largest response is Cebu, 53 rows.
//
if ($action === 'cities') {
    $province = psgcCode($_GET['province'] ?? '', 4);

    $stmt = getDB()->prepare(
        'SELECT city_code, city_name FROM RefCity WHERE province_code = ? ORDER BY city_name'
    );
    $stmt->execute([$province]);

    json_response(true, '', ['cities' => $stmt->fetchAll()]);
}

// ── GET ?action=barangays&city=137404 ────────────────────────
// Fired only once a municipality is chosen.
//
if ($action === 'barangays') {
    $city = psgcCode($_GET['city'] ?? '', 6);

    $stmt = getDB()->prepare(
        'SELECT brgy_code, brgy_name FROM RefBarangay WHERE city_code = ? ORDER BY brgy_name'
    );
    $stmt->execute([$city]);

    json_response(true, '', ['barangays' => $stmt->fetchAll()]);
}

json_response(false, 'Unknown action.', [], 400);
