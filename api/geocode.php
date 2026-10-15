<?php

require_once __DIR__ . '/../config/helpers.php';
startSecureSession();
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, 'Invalid action.', [], 400);
}

$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : null;
if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    json_response(false, 'Valid coordinates are required.', [], 400);
}

// ── 1. Ask OpenStreetMap ────────────────────────────────────
$url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&addressdetails=1'
     . '&lat=' . rawurlencode((string)$lat) . '&lon=' . rawurlencode((string)$lng);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_USERAGENT      => 'ConstructFlow/1.0 (academic capstone project)',
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw === false || $code !== 200) {
    json_response(false, 'Address lookup unavailable. Please type the address.', [], 502);
}

$a = json_decode($raw, true)['address'] ?? [];

$candidates = array_values(array_filter([
    $a['quarter'] ?? '', $a['village'] ?? '', $a['suburb'] ?? '', $a['neighbourhood'] ?? '',
]));
$osmCity     = $a['city'] ?? $a['town'] ?? $a['municipality'] ?? '';
$osmProvince = $a['state'] ?? $a['province'] ?? '';

// ── 2. Validate against PSGC ────────────────────────────────
function psgcNorm(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\([^)]*\)/u', ' ', $s);                    // "(Capital)"
    $s = preg_replace('/^(city of|municipality of)\s+/u', '', $s);
    $s = preg_replace('/\s+city$/u', '', $s);
    $s = preg_replace('/[^a-z0-9ñ ]/u', ' ', $s);
    return preg_replace('/\s+/', ' ', trim($s));
}
// OSM and PSGC disagree on spelling (Mabanognog vs Mabanogbog). Tolerance
// scales with length so short names must match exactly.
function psgcNear(string $x, string $y): bool {
    if ($x === $y) return true;
    $len = min(strlen($x), strlen($y));
    if ($len < 6) return false;
    return levenshtein($x, $y) <= ($len >= 10 ? 2 : 1);
}

$db = getDB();
$res = ['province'=>null,'city'=>null,'barangay'=>null,
        'province_code'=>null,'city_code'=>null,'brgy_code'=>null,'fuzzy'=>false];

$cityN = psgcNorm($osmCity);
if ($cityN !== '') {
    // Province can be absent (Manila returns no state), so the city is the anchor.
    $rows = $db->query(
        'SELECT c.city_code, c.city_name, p.province_code, p.province_name
           FROM RefCity c JOIN RefProvince p ON c.province_code = p.province_code'
    );
    // Collect every city of that name, then let the province settle it.
    // Taking the first row was wrong: "San Fernando" alone names places in
    // La Union, Pampanga, Cebu, Masbate, Romblon and Bukidnon, so whichever
    // the table happened to return first was recorded as the location. If the
    // province cannot settle it, nothing is claimed — an unvalidated address
    // the inspector is asked to confirm beats a confidently wrong one.
    $hits = [];
    foreach ($rows as $r) {
        if (psgcNorm($r['city_name']) === $cityN) $hits[] = $r;
    }
    if ($osmProvince !== '') {
        $provN = psgcNorm($osmProvince);
        $hits  = array_values(array_filter(
            $hits,
            fn($r) => psgcNear(psgcNorm($r['province_name']), $provN)
        ));
    }
    if (count($hits) === 1) {
        $r = $hits[0];
        $res['province'] = $r['province_name']; $res['province_code'] = $r['province_code'];
        $res['city']     = $r['city_name'];     $res['city_code']     = $r['city_code'];
    }
}

if ($res['city_code']) {
    $st = $db->prepare('SELECT brgy_code, brgy_name FROM RefBarangay WHERE city_code = ?');
    $st->execute([$res['city_code']]);
    $brgys = $st->fetchAll();
    // Candidate order dominates match quality: a fuzzy hit on `village`
    // beats an exact hit on `quarter`.
    // Exactness outranks candidate order. The old loop ran both passes on
    // each candidate in turn, so a fuzzy hit on `quarter` beat an exact hit
    // on `village` — which is how a neighbouring barangay with a similar
    // name ended up on the report.
    foreach ([true, false] as $exact) {
        foreach ($candidates as $cand) {
            $candN = psgcNorm($cand);
            if ($candN === '') continue;
            foreach ($brgys as $b) {
                $bn = psgcNorm($b['brgy_name']);
                if ($exact ? $bn === $candN : psgcNear($bn, $candN)) {
                    $res['barangay']  = $b['brgy_name'];
                    $res['brgy_code'] = $b['brgy_code'];
                    $res['fuzzy']     = !$exact;
                    break 3;
                }
            }
        }
    }
}

// ── 3. Fall back to OSM's own words if PSGC could not confirm ──
$validated = $res['brgy_code'] !== null;
$province  = $res['province'] ?? $osmProvince;
$city      = $res['city']     ?? $osmCity;
$barangay  = $res['barangay'] ?? ($candidates[0] ?? '');

// PSGC keeps administrative suffixes like "(Capital)" in the official
// name. They are correct in the code, but noise on a report.
$display = fn(?string $s) => $s === null ? null : trim(preg_replace("/\s*\([^)]*\)/", "", $s));
$parts = array_values(array_filter([$display($province), $display($city), $display($barangay)]));
if (!$parts) {
    json_response(false, 'No address found for that location.', [], 404);
}

json_response(true, 'Address found.', [
    'address_text'  => implode(', ', $parts),
    'province'      => $province,
    'city'          => $city,
    'barangay'      => $barangay,
    'province_code' => $res['province_code'],
    'city_code'     => $res['city_code'],
    'brgy_code'     => $res['brgy_code'],
    'validated'     => $validated,
    'fuzzy'         => $res['fuzzy'],
]);
