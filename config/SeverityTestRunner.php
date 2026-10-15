<?php
/**
 * ConstructFlow — Severity Engine test runner
 *
 * Runs every active row in ClassificationTests through the classifier
 * and records the outcome in TestRuns / TestResults.
 *
 *   anchor     — expected answer came from practitioners (the survey).
 *                This is the Chapter 4 accuracy evidence.
 *   regression — expected answer is the engine's own earlier output.
 *                Internal quality control only. Never quote it as
 *                accuracy.
 *
 * Run it after every knowledge base change (see tools/run_severity_tests.php)
 * and link the run_id in LexiconChangelog.verified_by_run.
 */

require_once __DIR__ . '/SeverityClassifier.php';

final class SeverityTestRunner
{
    /**
     * @return array{run_id:int, total:int, passed:int, failed:int, rows:array}
     */
    public static function run(PDO $pdo, string $triggerNote = '', ?int $userId = null): array
    {
        $tests = $pdo->query(
            'SELECT * FROM ClassificationTests WHERE is_active = 1
              ORDER BY test_kind, test_code'
        )->fetchAll(PDO::FETCH_ASSOC);

        $pdo->prepare('INSERT INTO TestRuns (run_by, trigger_note) VALUES (?, ?)')
            ->execute([$userId, $triggerNote !== '' ? $triggerNote : null]);
        $runId = (int)$pdo->lastInsertId();

        $insert = $pdo->prepare(
            'INSERT INTO TestResults
               (run_id, test_id, actual_defect_type_id, actual_element_type_id,
                actual_d, actual_c, actual_severity, band_delta, passed, matched_terms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $classifiers = [];   // one knowledge base per phase
        $rows = [];
        $passed = 0;

        foreach ($tests as $t) {
            $phase = $t['phase'];
            $classifiers[$phase] ??= new SeverityClassifier(KnowledgeBase::load($pdo, $phase));
            $r = $classifiers[$phase]->classify($t['description']);

            $rank     = KnowledgeBase::SEVERITY_RANK;
            $delta    = $r['severity'] !== null
                      ? $rank[$r['severity']] - $rank[$t['expected_severity']]
                      : null;
            $ok       = $delta !== null && abs($delta) <= (int)$t['tolerance_bands'];
            $passed  += $ok ? 1 : 0;

            $insert->execute([
                $runId, $t['test_id'], $r['defect_type_id'], $r['element_type_id'],
                $r['d_level'], $r['c_level'], $r['severity'], $delta, $ok ? 1 : 0,
                implode(', ', $r['matched_terms']),
            ]);

            $rows[] = [
                'code'          => $t['test_code'],
                'kind'          => $t['test_kind'],
                'description'   => $t['description'],
                'expected'      => $t['expected_severity'],
                'actual'        => $r['severity'],
                'delta'         => $delta,
                'passed'        => $ok,
                'expected_dc'   => $t['expected_d'] !== null ? "D{$t['expected_d']}/C{$t['expected_c']}" : '',
                'actual_dc'     => "D{$r['d_level']}/C{$r['c_level']}",
                'defect_match'  => $t['expected_defect_type_id'] === null
                                   || (int)$t['expected_defect_type_id'] === (int)$r['defect_type_id'],
                'confidence'    => $r['confidence'],
                'needs_review'  => $r['needs_review'],
                'matched_terms' => $r['matched_terms'],
                'notes'         => $r['notes'],
            ];
        }

        $total = count($tests);
        $pdo->prepare('UPDATE TestRuns SET total_tests = ?, passed = ?, failed = ? WHERE run_id = ?')
            ->execute([$total, $passed, $total - $passed, $runId]);

        return ['run_id' => $runId, 'total' => $total, 'passed' => $passed,
                'failed' => $total - $passed, 'rows' => $rows];
    }

    /** Plain-text table for the command line. */
    public static function format(array $result): string
    {
        $out = sprintf("Run #%d: %d/%d passed\n\n", $result['run_id'], $result['passed'], $result['total']);
        foreach ($result['rows'] as $r) {
            $out .= sprintf(
                "%-4s %-10s %-9s expected %-8s got %-8s %-9s conf %3d%s%s\n       \"%s\"\n       terms: %s\n",
                $r['passed'] ? 'PASS' : 'FAIL',
                $r['code'], $r['kind'], $r['expected'], $r['actual'] ?? '-',
                $r['actual_dc'], $r['confidence'],
                $r['defect_match'] ? '' : '  [defect type differs]',
                $r['needs_review'] ? '  [review]' : '',
                $r['description'],
                implode(', ', $r['matched_terms']) ?: '(none)'
            );
            foreach ($r['notes'] as $n) {
                $out .= "       - {$n}\n";
            }
        }
        return $out;
    }
}
