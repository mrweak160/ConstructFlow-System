<?php
/**
 * Lexicon coverage report.
 *
 * Runs a text file of real inspection descriptions (one per line)
 * through the classifier and reports how much of each the engine
 * understood, plus the most common words it did not recognise. Those
 * words are the candidates for the next knowledge base update.
 *
 *   XAMPP:  C:\xampp\php\php.exe tools\severity_coverage.php remarks.txt post_completion
 *   Linux:  php tools/severity_coverage.php remarks.txt construction
 *
 * The phase argument is optional (default: construction).
 * Command line only: over the web this file returns 404.
 *
 * Use real, anonymised remarks (past inspection reports, punch lists,
 * NCRs). Do NOT use the survey anchor sentences: the anchors are the
 * accuracy evidence and must not feed the vocabulary.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/SeverityClassifier.php';

$file  = $argv[1] ?? null;
$phase = ($argv[2] ?? 'construction') === 'post_completion' ? 'post_completion' : 'construction';
if ($file === null || !is_readable($file)) {
    fwrite(STDERR, "Usage: php tools/severity_coverage.php <file.txt> [construction|post_completion]\n");
    exit(2);
}

$lines = array_values(array_filter(array_map('trim', file($file)), fn($l) => $l !== ''));
echo SeverityCoverage::report(getDB(), $lines, $phase);


final class SeverityCoverage
{
    public static function report(PDO $pdo, array $lines, string $phase): string
    {
        $clf = new SeverityClassifier(KnowledgeBase::load($pdo, $phase));
        $n = count($lines);
        if ($n === 0) {
            return "No descriptions found.\n";
        }

        $defect = $element = $condition = $review = 0;
        $missed = [];     // stem => [count, example]
        $noDefect = [];

        foreach ($lines as $line) {
            $r = $clf->classify($line);
            $defect    += $r['defect_listed'] ? 1 : 0;
            $element   += $r['element_matched'] ? 1 : 0;
            $condition += $r['condition_matched'] ? 1 : 0;
            $review    += $r['needs_review'] ? 1 : 0;
            if (!$r['defect_listed']) {
                $noDefect[] = $line;
            }
            foreach (array_unique($r['unmatched_words']) as $w) {
                if (strlen($w) < 3 || ctype_digit($w)) { continue; }
                $missed[$w][0] = ($missed[$w][0] ?? 0) + 1;
                $missed[$w][1] ??= $line;
            }
        }

        uasort($missed, fn($a, $b) => $b[0] <=> $a[0]);
        $pct = fn($x) => sprintf('%3d%%', round(100 * $x / $n));

        $out  = "Lexicon coverage, {$n} descriptions ({$phase})\n\n";
        $out .= $pct($defect)    . "  a listed defect type was recognised\n";
        $out .= $pct($condition) . "  a condition word set the level (otherwise the default level was used)\n";
        $out .= $pct($element)   . "  an element was recognised (otherwise C defaulted to 2)\n";
        $out .= $pct($review)    . "  flagged for supervisor review\n\n";

        $out .= "Most common unrecognised words (stemmed), with a first example:\n";
        foreach (array_slice($missed, 0, 30, true) as $w => [$count, $example]) {
            $out .= sprintf("  %-18s x%-3d  \"%s\"\n", $w, $count, mb_strimwidth($example, 0, 70, '...'));
        }

        if ($noDefect) {
            $out .= "\nNo listed defect recognised in:\n";
            foreach (array_slice($noDefect, 0, 20) as $l) {
                $out .= "  - {$l}\n";
            }
        }
        $out .= "\nMany unrecognised words are filler and can be ignored. Add only words\n"
              . "that name a defect, a condition, an element or a conclusion, with a source.\n";
        return $out;
    }
}
