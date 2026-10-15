<?php
/**
 * ConstructFlow — Severity Classification Engine (v2)
 *
 * Rule-based expert system. Takes free-text inspection findings and
 * returns a RECOMMENDED severity level for supervisor review.
 *
 * It does not learn. Every output traces to rows in the knowledge base
 * (DefectTypes, DefectCriteria, ElementTypes, LexiconTerms,
 * SeverityMatrix). Adding a defect type or a word is a data change,
 * never a code change.
 *
 * Read severity_engine_design.md before modifying this file.
 *
 * Usage:
 *   $kb         = KnowledgeBase::load($pdo, 'post_completion');
 *   $classifier = new SeverityClassifier($kb);
 *   $result     = $classifier->classify("wide crack on the beam, work continuing below");
 */


// ============================================================
// Text normalisation — used for BOTH the inspector's text and the
// lexicon terms, so the two sides are always compared the same way.
// ============================================================
final class TextNormalizer
{
    /** Marks a clause break (full stop, comma, semicolon, line break). */
    public const BOUNDARY = '|';

    /**
     * Filler words dropped before matching. Kept deliberately short:
     * words that carry meaning ("no", "not", "in", "of", "for", "and")
     * are NOT here.
     */
    private const STOPWORDS = [
        'the' => 1, 'a' => 1, 'an' => 1, 'is' => 1, 'are' => 1, 'was' => 1,
        'were' => 1, 'be' => 1, 'been' => 1, 'being' => 1, 'has' => 1,
        'have' => 1, 'had' => 1, 'may' => 1,
        // report filler: "leakage noted" means the same as "leakage"
        'observed' => 1, 'noted' => 1, 'found' => 1, 'noticed' => 1,
        // Tagalog filler / markers
        'ang' => 1, 'ng' => 1, 'sa' => 1, 'mga' => 1, 'yung' => 1, 'ay' => 1,
    ];

    /** @return string[] stemmed tokens, with BOUNDARY at clause breaks */
    public static function tokens(string $text): array
    {
        $t = mb_strtolower($text, 'UTF-8');
        // "no 5", "no. 5" and "no of cracks" mean "number", not a negation.
        $t = preg_replace('/\bno\.?\s*(?=\d|of\b)/u', 'number ', $t);
        // Sentence punctuation followed by a space (or the end) and line
        // breaks become clause boundaries. "0.3mm" is left alone.
        $t = preg_replace('/[.!?;:,]+(?=\s|$)/u', ' | ', $t);
        $t = preg_replace('/[\r\n]+/', ' | ', $t);
        // Anything else that is not a letter or digit becomes a space,
        // so "full-depth" == "full depth" and "non-structural" splits.
        $t = preg_replace('/[^\p{L}\p{N}|]+/u', ' ', $t);

        $out = [];
        foreach (preg_split('/\s+/', trim($t)) as $w) {
            if ($w === '') {
                continue;
            }
            if ($w === self::BOUNDARY) {
                if ($out && end($out) !== self::BOUNDARY) {
                    $out[] = self::BOUNDARY;
                }
                continue;
            }
            if (isset(self::STOPWORDS[$w])) {
                continue;
            }
            $out[] = Stemmer::stem($w);
        }
        while ($out && $out[0] === self::BOUNDARY) {
            array_shift($out);
        }
        while ($out && end($out) === self::BOUNDARY) {
            array_pop($out);
        }
        return $out;
    }
}


// ============================================================
// Stemmer
//
// Light suffix stripper for construction English:
//   cracks / cracked / cracking  -> crack
//   requires / required          -> requir
//   footings / footing           -> foot
// Rules: plural first, then -ing/-ed/-ly, then a trailing "e".
// Nothing is stripped if fewer than 4 letters would remain, which is
// what stops "noted" becoming the negation "not".
// ============================================================
final class Stemmer
{
    private const MIN_STEM = 4;

    public static function stem(string $word): string
    {
        // Only plain a-z words are stemmed; Tagalog words mostly pass
        // through unchanged, and so do numbers.
        if (!preg_match('/^[a-z]+$/', $word) || strlen($word) <= self::MIN_STEM) {
            return $word;
        }
        $w = $word;

        // Step 1: plurals
        if (self::ends($w, 'ies') && strlen($w) - 3 >= self::MIN_STEM - 1) {
            $w = substr($w, 0, -3) . 'y';
        } elseif (self::ends($w, 'es') && preg_match('/(s|x|z|ch|sh)es$/', $w)
                  && strlen($w) - 2 >= self::MIN_STEM) {
            $w = substr($w, 0, -2);
        } elseif (self::ends($w, 's') && !preg_match('/(ss|us|is)$/', $w)
                  && strlen($w) - 1 >= self::MIN_STEM) {
            $w = substr($w, 0, -1);
        }

        // Step 2: -ingly / -edly / -ing / -ed / -ly
        foreach (['ingly', 'edly', 'ing', 'ed', 'ly'] as $suf) {
            if (self::ends($w, $suf) && strlen($w) - strlen($suf) >= self::MIN_STEM) {
                $w = substr($w, 0, -strlen($suf));
                // chipping -> chip, but spalling -> spall (l, s, z kept)
                $n = strlen($w);
                if ($suf !== 'ly' && $n > self::MIN_STEM && $w[$n - 1] === $w[$n - 2]
                    && !in_array($w[$n - 1], ['l', 's', 'z', 'a', 'e', 'i', 'o', 'u'], true)) {
                    $w = substr($w, 0, -1);
                }
                break;
            }
        }

        // Step 3: trailing "e" (damage/damaged -> damag)
        if (self::ends($w, 'e') && strlen($w) - 1 >= self::MIN_STEM) {
            $w = substr($w, 0, -1);
        }
        return $w;
    }

    private static function ends(string $w, string $suffix): bool
    {
        return substr($w, -strlen($suffix)) === $suffix;
    }
}


// ============================================================
// Knowledge base — loaded once per request from the DB
// ============================================================
final class KnowledgeBase
{
    public const SEVERITY_RANK = ['low' => 1, 'moderate' => 2, 'high' => 3, 'critical' => 4];

    public array $terms          = [];   // normalised phrase => list of entries
    public int   $maxWords       = 1;
    public array $matrix         = [];   // [d][c] => severity
    public array $criteria       = [];   // criterion_id => ['defect_type_id','d_level']
    public array $defaultD       = [];   // defect_type_id => d_level
    public array $availableD     = [];   // defect_type_id => [d levels that exist]
    public array $defectNames    = [];   // defect_type_id => name
    public array $elementC       = [];   // element_type_id => c_level
    public ?int  $defaultElement = null;
    public ?int  $generalDefectType = null;

    /**
     * @param string $phase 'construction' or 'post_completion'
     */
    public static function load(PDO $pdo, string $phase): self
    {
        $kb = new self();

        $phaseCol = $phase === 'post_completion'
            ? 'applies_post_completion'
            : 'applies_construction';

        // --- matrix ---
        foreach ($pdo->query('SELECT d_level, c_level, severity FROM SeverityMatrix')
                     ->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $kb->matrix[(int)$r['d_level']][(int)$r['c_level']] = $r['severity'];
        }

        // --- defect types active for this phase ---
        $activeTypes = [];
        foreach ($pdo->query(
            "SELECT defect_type_id, name, is_general
               FROM DefectTypes
              WHERE is_active = 1 AND {$phaseCol} = 1"
        )->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['defect_type_id'];
            $activeTypes[$id]     = true;
            $kb->defectNames[$id] = $r['name'];
            if ((int)$r['is_general'] === 1) {
                $kb->generalDefectType = $id;
            }
        }

        // --- criteria ---
        foreach ($pdo->query(
            'SELECT criterion_id, defect_type_id, d_level, is_default FROM DefectCriteria'
        )->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $tid = (int)$r['defect_type_id'];
            $d   = (int)$r['d_level'];
            $kb->criteria[(int)$r['criterion_id']] = ['defect_type_id' => $tid, 'd_level' => $d];
            $kb->availableD[$tid][] = $d;
            if ((int)$r['is_default'] === 1) {
                $kb->defaultD[$tid] = $d;
            }
        }
        foreach ($kb->availableD as &$levels) {
            sort($levels);
        }
        unset($levels);

        // --- elements ---
        foreach ($pdo->query(
            'SELECT element_type_id, c_level, is_default FROM ElementTypes WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $eid = (int)$r['element_type_id'];
            $kb->elementC[$eid] = (int)$r['c_level'];
            if ((int)$r['is_default'] === 1) {
                $kb->defaultElement = $eid;
            }
        }

        // --- lexicon ---
        $seen = [];
        foreach ($pdo->query(
            'SELECT term, kind, defect_type_id, criterion_id, element_type_id,
                    implies_severity, priority, allow_fuzzy
               FROM LexiconTerms WHERE is_active = 1'
        )->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $defectType = $r['defect_type_id']  !== null ? (int)$r['defect_type_id']  : null;
            $criterion  = $r['criterion_id']    !== null ? (int)$r['criterion_id']    : null;
            $element    = $r['element_type_id'] !== null ? (int)$r['element_type_id'] : null;

            // Phase gate: words for defect types not used in this phase
            // are not loaded at all.
            if ($r['kind'] === 'defect' && !isset($activeTypes[$defectType])) {
                continue;
            }
            if ($r['kind'] === 'condition') {
                $c = $kb->criteria[$criterion] ?? null;
                if ($c === null || !isset($activeTypes[$c['defect_type_id']])) {
                    continue;
                }
            }
            if ($r['kind'] === 'element' && !isset($kb->elementC[$element])) {
                continue;
            }

            $tokens = TextNormalizer::tokens($r['term']);
            if (!$tokens || in_array(TextNormalizer::BOUNDARY, $tokens, true)) {
                continue;
            }
            $key = implode(' ', $tokens);

            // "crack" and "cracking" normalise to the same key; keep one.
            $signature = $key . '#' . $r['kind'] . '#' . $defectType . '#' . $criterion
                       . '#' . $element . '#' . $r['implies_severity'];
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;

            $kb->terms[$key][] = [
                'kind'        => $r['kind'],
                'defect_type' => $defectType,
                'criterion'   => $criterion,
                'element'     => $element,
                'implies'     => $r['implies_severity'],
                'priority'    => (int)$r['priority'],
                'allow_fuzzy' => (int)$r['allow_fuzzy'] === 1,
                'words'       => count($tokens),
                'display'     => $r['term'],
            ];
            $kb->maxWords = max($kb->maxWords, count($tokens));
        }

        return $kb;
    }
}


// ============================================================
// Classifier
// ============================================================
final class SeverityClassifier
{
    private const NEGATION_WINDOW = 3;
    /** A negation stops at these words (already stemmed). */
    private const NEGATION_STOPS  = ['but', 'however', 'although', 'though', 'except',
                                     'and', 'pero', 'ngunit', 'subalit'];
    /** Typo matching only for words at least this long (stemmed). */
    private const FUZZY_MIN_LENGTH = 6;

    public function __construct(private KnowledgeBase $kb) {}

    public function classify(string $text): array
    {
        $tokens  = TextNormalizer::tokens($text);
        $matches = $this->matchTerms($tokens);

        // Words no lexicon term accounted for. Used by the coverage
        // report to show what vocabulary is missing.
        $covered = [];
        foreach ($matches as $m) {
            for ($k = $m['pos']; $k < $m['pos'] + ($m['fuzzy'] ? 1 : $m['words']); $k++) {
                $covered[$k] = true;
            }
        }
        $unmatched = [];
        foreach ($tokens as $i => $t) {
            if ($t !== TextNormalizer::BOUNDARY && !isset($covered[$i])) {
                $unmatched[] = $t;
            }
        }

        [$matches, $negated] = $this->applyNegation($matches, $tokens);

        $confidence  = 100;
        $notes       = [];
        $forceReview = false;

        // A single condition word right after a measurement ("0.2 mm
        // wide", "5 cm deep") is a measurement, not a description. The
        // engine has no sourced width/depth thresholds, so it does not
        // grade measurements: the word is set aside and the supervisor
        // checks the figure.
        $measured = [];
        foreach ($matches as $i => $m) {
            if ($m['kind'] === 'condition' && $m['words'] === 1 && $m['pos'] > 0
                && $this->isMeasurement($tokens[$m['pos'] - 1])) {
                $measured[$m['display']] = true;
                unset($matches[$i]);
            }
        }
        $matches = array_values($matches);
        if ($measured) {
            $forceReview = true;
            $notes[] = 'measurement given ("' . implode('", "', array_keys($measured))
                     . '"); the engine does not grade measurements, check the figure against the tolerance';
        }

        // ---------- defect type ----------
        // Explicit defect words compete first. If two defect types are
        // named, the one with the higher resolved level is classified
        // and the other is flagged; the engine never silently keeps the
        // milder reading.
        $explicit     = $this->ofKind($matches, 'defect');
        $conditions   = $this->ofKind($matches, 'condition');
        $defectTypeId = null;
        $defectPos    = null;

        if ($explicit) {
            $byType = $this->groupByType($explicit);
            $defectTypeId = $this->pickType($byType, $conditions);
            $best         = $byType[$defectTypeId][0];
            $defectPos    = $best['pos'];

            if ($best['fuzzy']) {
                $confidence -= 15;
                $notes[] = "defect word matched approximately (\"{$best['display']}\")";
            }
            if (count($byType) > 1) {
                $confidence -= 15;
                $others = array_map(fn($t) => $this->kb->defectNames[$t] ?? '?',
                                    array_diff(array_keys($byType), [$defectTypeId]));
                $notes[] = 'also mentions ' . implode(', ', $others) . '; classified as '
                         . ($this->kb->defectNames[$defectTypeId] ?? '?')
                         . ' (the higher level, or the more specific phrase on a tie);'
                         . ' file separately if these are separate findings';
            }
        } elseif ($conditions) {
            // No defect named, but a condition word points at one
            // ("damp patch on the ceiling" -> water leakage, level 1).
            $byType = $this->groupByType($conditions, true);
            $defectTypeId = $this->pickType($byType, $conditions);
            $defectPos    = $byType[$defectTypeId][0]['pos'];
            $confidence  -= 15;
            $notes[] = 'defect type inferred from a condition word ("'
                     . $byType[$defectTypeId][0]['display'] . '")';
        }

        if ($defectTypeId === null || $defectTypeId === $this->kb->generalDefectType) {
            $defectTypeId = $this->kb->generalDefectType;
            $confidence  -= 50;
            $notes[]      = 'no listed defect type recognised; general classification used';
        }

        // ---------- D ----------
        $dLevel = $this->conditionLevel($defectTypeId, $conditions);
        $conditionMatched = $dLevel !== null;
        if ($dLevel === null) {
            $dLevel = $this->kb->defaultD[$defectTypeId] ?? 2;
            $confidence -= 20;
            $notes[] = 'no condition described; default defect level used';
        }

        // Intensifiers / dampeners: net effect capped at one level.
        $net = count($this->ofKind($matches, 'intensifier'))
             - count($this->ofKind($matches, 'dampener'));
        $dLevel += max(-1, min(1, $net));
        $dLevel  = $this->clampToAvailable($dLevel, $defectTypeId);

        // A multi-word condition for a DIFFERENT defect type that points
        // higher ("crack ... section loss") is never discarded silently.
        foreach ($conditions as $m) {
            $crit = $this->kb->criteria[$m['criterion']] ?? null;
            if ($crit && $crit['defect_type_id'] !== $defectTypeId
                && $m['words'] >= 2 && $crit['d_level'] > $dLevel) {
                $forceReview = true;
                $notes[] = "also mentions \"{$m['display']}\" (level {$crit['d_level']} for "
                         . ($this->kb->defectNames[$crit['defect_type_id']] ?? '?')
                         . '); check which is the main finding';
                break;
            }
        }

        // ---------- C ----------
        $elementMatches = $this->ofKind($matches, 'element');
        if ($elementMatches) {
            // nearest element to the defect wins
            if ($defectPos !== null) {
                usort($elementMatches, fn($a, $b) =>
                    abs($a['pos'] - $defectPos) <=> abs($b['pos'] - $defectPos)
                    ?: $a['pos'] <=> $b['pos']);
            }
            $elementId = $elementMatches[0]['element'];
            $cLevel    = $this->kb->elementC[$elementId];

            // A competing element with HIGHER consequence is present.
            // Do not silently take it; do not silently ignore it.
            foreach ($elementMatches as $m) {
                if ($this->kb->elementC[$m['element']] > $cLevel) {
                    $confidence -= 20;
                    $notes[] = 'more than one element mentioned; confirm which is affected';
                    break;
                }
            }
        } else {
            $elementId = $this->kb->defaultElement;
            $cLevel    = $this->kb->elementC[$elementId] ?? 2;
            $confidence -= 25;
            $notes[] = 'no element identified; default consequence used';
        }

        // ---------- exposure raises C, never D ----------
        $exposure = (bool)$this->ofKind($matches, 'exposure');
        if ($exposure) {
            $cLevel = min(4, $cLevel + 1);
        }

        // ---------- matrix lookup (never D * C) ----------
        $severity = $this->kb->matrix[$dLevel][$cLevel] ?? null;

        // ---------- verdict cross-check ----------
        // The inspector's own conclusion never changes the result. A gap
        // of two bands or more is flagged; one band is normal expert
        // disagreement (survey: 3 of 8 scenarios, never more than one).
        $verdictSeverity = null;
        $verdictTerm     = null;
        foreach ($this->ofKind($matches, 'verdict') as $m) {
            $rank = KnowledgeBase::SEVERITY_RANK[$m['implies']] ?? 0;
            if ($verdictSeverity === null || $rank > KnowledgeBase::SEVERITY_RANK[$verdictSeverity]) {
                $verdictSeverity = $m['implies'];
                $verdictTerm     = $m['display'];
            }
        }
        $verdictConflict = false;
        if ($verdictSeverity !== null && $severity !== null) {
            $gap = KnowledgeBase::SEVERITY_RANK[$verdictSeverity]
                 - KnowledgeBase::SEVERITY_RANK[$severity];
            if (abs($gap) >= 2) {
                $verdictConflict = true;
                $notes[] = "inspector's remark \"{$verdictTerm}\" points to {$verdictSeverity}, "
                         . "but the description classifies as {$severity}; supervisor should review";
            }
        }

        $confidence = max(0, min(100, $confidence));

        return [
            'defect_type_id'   => $defectTypeId,
            'element_type_id'  => $elementId,
            'd_level'          => $dLevel,
            'c_level'          => $cLevel,
            'severity'         => $severity,
            'confidence'       => $confidence,
            'needs_review'     => $confidence < 80 || $verdictConflict || $forceReview,
            'prefill'          => $confidence >= 50,
            'exposure'         => $exposure,
            'verdict_severity' => $verdictSeverity,
            'verdict_conflict' => $verdictConflict,
            'defect_types_detected' => array_values(array_unique(array_map(
                fn($m) => $m['defect_type'], $explicit))),
            'matched_terms'    => array_values(array_unique(array_map(
                fn($m) => $m['display'], $matches))),
            'negated_terms'    => array_values(array_unique(array_map(
                fn($m) => $m['display'], $negated))),
            'defect_listed'    => $defectTypeId !== $this->kb->generalDefectType,
            'condition_matched'=> $conditionMatched,
            'element_matched'  => (bool)$elementMatches,
            'unmatched_words'  => $unmatched,
            'notes'            => $notes,
        ];
    }

    // --------------------------------------------------------
    /**
     * Longest phrase first, with token consumption.
     * This is what makes "exposed rebar" beat "exposed", and what
     * stops the bare "no" in "no corrosion" from firing as negation.
     * Phrases never cross a clause boundary.
     */
    private function matchTerms(array $tokens): array
    {
        $count    = count($tokens);
        $consumed = [];
        foreach ($tokens as $i => $t) {
            $consumed[$i] = ($t === TextNormalizer::BOUNDARY);
        }
        $matches = [];

        for ($n = min($this->kb->maxWords, $count); $n >= 1; $n--) {
            for ($i = 0; $i + $n <= $count; $i++) {
                $free = true;
                for ($k = $i; $k < $i + $n; $k++) {
                    if ($consumed[$k]) { $free = false; break; }
                }
                if (!$free) { continue; }

                $hit = $this->lookup(implode(' ', array_slice($tokens, $i, $n)), $n);
                if ($hit === null) { continue; }

                foreach ($hit['entries'] as $entry) {
                    $matches[] = $entry + ['pos' => $i, 'fuzzy' => $hit['fuzzy']];
                }
                for ($k = $i; $k < $i + $n; $k++) { $consumed[$k] = true; }
            }
        }

        usort($matches, fn($a, $b) => $a['pos'] <=> $b['pos']);
        return $matches;
    }

    private function lookup(string $phrase, int $n): ?array
    {
        if (isset($this->kb->terms[$phrase])) {
            return ['entries' => $this->kb->terms[$phrase], 'fuzzy' => false];
        }
        // Typo matching: single words only, long words only, same first
        // letter. Short words ("small" vs "spall", "stain" vs "stair")
        // produce false hits, so they must match exactly.
        $len = strlen($phrase);
        if ($n > 1 || $len < self::FUZZY_MIN_LENGTH) {
            return null;
        }
        $maxDist = $len <= 8 ? 1 : 2;

        $best = null; $bestDist = PHP_INT_MAX;
        foreach ($this->kb->terms as $key => $entries) {
            $key = (string)$key;
            if (strpos($key, ' ') !== false || $key[0] !== $phrase[0]) { continue; }
            if (strlen($key) < self::FUZZY_MIN_LENGTH || abs(strlen($key) - $len) > $maxDist) { continue; }
            foreach ($entries as $e) {
                if (!$e['allow_fuzzy']) { continue 2; }
            }
            $d = levenshtein($phrase, $key);
            if ($d <= $maxDist && $d < $bestDist) {
                $best = $entries; $bestDist = $d;
            }
        }
        return $best === null ? null : ['entries' => $best, 'fuzzy' => true];
    }

    /**
     * A negation cancels any term starting in the next few words,
     * stopping early at a clause break or a conjunction.
     *   "no cracks but spalling on the beam" -> crack cancelled
     *   "no leak. wide crack on the beam"    -> crack survives
     *   "no workers below"                   -> exposure cancelled
     *
     * @return array{0: array, 1: array} [kept matches, negated matches]
     */
    private function applyNegation(array $matches, array $tokens): array
    {
        $suppressed = [];
        foreach ($matches as $m) {
            if ($m['kind'] !== 'negation') { continue; }
            for ($k = $m['pos'] + 1; $k <= $m['pos'] + self::NEGATION_WINDOW; $k++) {
                if (!isset($tokens[$k]) || $tokens[$k] === TextNormalizer::BOUNDARY
                    || in_array($tokens[$k], self::NEGATION_STOPS, true)) {
                    break;
                }
                $suppressed[$k] = true;
            }
        }

        $kept = []; $negated = [];
        foreach ($matches as $m) {
            if ($m['kind'] === 'negation') { continue; }
            if (isset($suppressed[$m['pos']])) {
                $negated[] = $m;
            } else {
                $kept[] = $m;
            }
        }
        return [$kept, $negated];
    }

    /** Group defect (or condition) matches by the defect type they point at. */
    private function groupByType(array $matches, bool $fromConditions = false): array
    {
        $byType = [];
        foreach ($matches as $m) {
            $type = $fromConditions
                ? ($this->kb->criteria[$m['criterion']]['defect_type_id'] ?? null)
                : $m['defect_type'];
            if ($type !== null) {
                $byType[$type][] = $m;
            }
        }
        // within a type: exact before approximate, longer phrase first, earliest first
        foreach ($byType as &$list) {
            usort($list, fn($a, $b) =>
                [$a['fuzzy'], -$a['words'], $a['pos']] <=> [$b['fuzzy'], -$b['words'], $b['pos']]);
        }
        unset($list);
        return $byType;
    }

    /**
     * Choose among candidate defect types: highest resolved level
     * first, then priority, then the longer phrase, then the earliest.
     */
    private function pickType(array $byType, array $conditions): int
    {
        $ranked = [];
        foreach ($byType as $type => $list) {
            $d = $this->conditionLevel($type, $conditions) ?? ($this->kb->defaultD[$type] ?? 2);
            $ranked[] = [$type, $d, $list[0]['priority'], $list[0]['words'], $list[0]['pos']];
        }
        usort($ranked, fn($a, $b) =>
            [$b[1], $b[2], $b[3], $a[4]] <=> [$a[1], $a[2], $a[3], $b[4]]);
        return (int)$ranked[0][0];
    }

    /** Highest level among the condition words that belong to this type. */
    private function conditionLevel(?int $defectTypeId, array $conditions): ?int
    {
        $level = null;
        foreach ($conditions as $m) {
            $crit = $this->kb->criteria[$m['criterion']] ?? null;
            if ($crit && $crit['defect_type_id'] === $defectTypeId) {
                $level = max($level ?? 0, $crit['d_level']);
            }
        }
        return $level;
    }

    /** "3mm", "0", "2", "mm", "cm", "inch" ... (tokens are already stemmed) */
    private function isMeasurement(string $token): bool
    {
        return (bool)preg_match('/^\d+(mm|cm|m|in)?$/', $token)
            || in_array($token, ['mm', 'cm', 'm', 'ft', 'inch', 'millimeter', 'centimeter', 'meter'], true);
    }

    private function ofKind(array $matches, string $kind): array
    {
        return array_values(array_filter($matches, fn($m) => $m['kind'] === $kind));
    }

    /** Clamp D to 1..4 AND to the levels that exist for this type. */
    private function clampToAvailable(int $d, ?int $defectTypeId): int
    {
        $d = max(1, min(4, $d));
        $available = $this->kb->availableD[$defectTypeId] ?? [1, 2, 3, 4];
        if (in_array($d, $available, true)) { return $d; }

        $closest = $available[0]; $bestDist = PHP_INT_MAX;
        foreach ($available as $lvl) {
            $dist = abs($lvl - $d);
            if ($dist < $bestDist) { $closest = $lvl; $bestDist = $dist; }
        }
        return $closest;
    }
}
