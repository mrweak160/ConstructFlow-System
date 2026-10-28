<?php
// ConstructFlow — How the severity engine works
// knowledge · try
//
// Read-only. "knowledge" returns what the engine knows: the severity matrix,
// the defect levels, the building parts and every word in the lexicon.
// "try" classifies a sample finding and saves nothing.

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/SeverityClassifier.php';
startSecureSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST') {
    verifyCsrf();
}

/** A word's recorded source, as a short label a reader can trace. */
function sourceLabel(?string $note): string
{
    $n = (string)$note;
    return match (true) {
        str_starts_with($n, 'Paper Table 4c')        => 'Defect criteria (Table 17)',
        str_contains($n, 'DPWH')                     => 'DPWH Std. Specifications Vol. IV',
        str_starts_with($n, 'ICRI')                  => 'ICRI Concrete Repair Terminology (2022)',
        str_contains($n, 'ICRI')                     => 'Team research file; ICRI (2022)',
        str_contains($n, 'ACI 364')                  => 'ACI 364.6T-02',
        str_starts_with($n, 'Survey')                => 'Severity validation survey',
        str_contains($n, 'researcher-developed')     => 'Researcher-developed (Tagalog / inspector phrase)',
        str_starts_with($n, 'Team research file')    => 'Team research file',
        default                                      => 'Researcher-developed',
    };
}

// ── GET ?action=knowledge ────────────────────────────────────
if ($method === 'GET' && $action === 'knowledge') {
    requireTeam();
    $db = getDB();

    $matrix = [];
    foreach ($db->query('SELECT d_level, c_level, severity FROM SeverityMatrix') as $r) {
        $matrix[(int)$r['d_level']][(int)$r['c_level']] = $r['severity'];
    }

    $types = [];
    foreach ($db->query(
        'SELECT defect_type_id, code, name, applies_construction, applies_post_completion, is_general
           FROM DefectTypes WHERE is_active = 1 ORDER BY sort_order'
    ) as $r) {
        $types[(int)$r['defect_type_id']] = [
            'code'         => $r['code'],
            'name'         => $r['name'],
            'construction' => (bool)$r['applies_construction'],
            'after'        => (bool)$r['applies_post_completion'],
            'general'      => (bool)$r['is_general'],
            'levels'       => [],
        ];
    }
    foreach ($db->query('SELECT defect_type_id, d_level, descriptor, is_default FROM DefectCriteria ORDER BY d_level') as $r) {
        $id = (int)$r['defect_type_id'];
        if (isset($types[$id])) {
            $types[$id]['levels'][] = ['d' => (int)$r['d_level'], 'text' => $r['descriptor'], 'default' => (bool)$r['is_default']];
        }
    }

    $elements = $db->query(
        'SELECT code, name, c_level, category, is_default FROM ElementTypes WHERE is_active = 1 ORDER BY c_level DESC, name'
    )->fetchAll();

    // One row per word: a phrase such as "hairline crack" is stored twice
    // (it names the defect and sets its level) and is shown once.
    $byCode    = array_column(array_values($types), 'name', 'code');
    $elemNames = array_column($elements, 'name', 'code');
    $words = [];
    foreach ($db->query(
        'SELECT term, kind, target_code, implies_severity, source_note
           FROM LexiconTerms WHERE is_active = 1 ORDER BY term'
    ) as $r) {
        $key = mb_strtolower($r['term']);
        $w   = $words[$key] ?? ['term' => $r['term'], 'defect' => null, 'level' => null,
                                'element' => null, 'kind' => null, 'implies' => null,
                                'source' => sourceLabel($r['source_note'])];
        switch ($r['kind']) {
            case 'defect':
                $w['defect'] = $byCode[$r['target_code']] ?? $r['target_code'];
                break;
            case 'condition':
                [$code, $lvl] = array_pad(explode(':', (string)$r['target_code']), 2, null);
                $w['defect'] = $w['defect'] ?? ($byCode[$code] ?? $code);
                $w['level']  = (int)$lvl;
                break;
            case 'element':
                $w['element'] = $elemNames[$r['target_code']] ?? $r['target_code'];
                break;
            default:   // negation, intensifier, dampener, exposure, verdict
                $w['kind']    = $r['kind'];
                $w['implies'] = $r['implies_severity'];
        }
        $words[$key] = $w;
    }

    json_response(true, 'Knowledge base retrieved.', [
        'matrix'   => $matrix,
        'defects'  => array_values($types),
        'elements' => $elements,
        'words'    => array_values($words),
    ]);
}

// ── POST ?action=try ─────────────────────────────────────────
// Body: { text, phase }. Nothing is saved.
if ($method === 'POST' && $action === 'try') {
    requireTeam();
    $body  = getBody();
    $text  = clean($body['text'] ?? '');
    $phase = ($body['phase'] ?? '') === 'post_completion' ? 'post_completion' : 'construction';
    if (mb_strlen($text) < 3) {
        json_response(false, 'Type a finding to try, for example "Deep crack on the column".', [], 400);
    }
    requireMaxLength(['Finding' => [$text, 1000]]);

    $db  = getDB();
    $kb  = KnowledgeBase::load($db, $phase);
    $cls = (new SeverityClassifier($kb))->classify($text);

    $name = fn(string $sql, $id) => $id ? ($db->query($sql . (int)$id)->fetchColumn() ?: null) : null;
    // A finding with no listed defect is filed at Moderate at least, the same
    // rule reports.php applies, so the supervisor still reassesses it.
    $filed = (!$cls['defect_listed'] && $cls['severity'] === 'low') ? 'moderate' : $cls['severity'];

    json_response(true, 'Classified (nothing was saved).', [
        'severity'     => $cls['severity'],
        'filed_as'     => $filed,
        'defect_type'  => $name('SELECT name FROM DefectTypes WHERE defect_type_id = ', $cls['defect_type_id']),
        'element'      => $name('SELECT name FROM ElementTypes WHERE element_type_id = ', $cls['element_type_id']),
        'd_level'      => $cls['d_level'],
        'c_level'      => $cls['c_level'],
        'exposure'     => $cls['exposure'],
        'needs_review' => $cls['needs_review'] || $filed !== $cls['severity'],
        'matched'      => $cls['matched_terms'],
        'negated'      => $cls['negated_terms'],
        'notes'        => $cls['notes'],
    ]);
}

json_response(false, 'Invalid action.', [], 400);
