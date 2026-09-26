<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_rememberme\local;

use mod_rememberme\local\fsrs\engine;
use mod_rememberme\local\fsrs\memory_state;
use mod_rememberme\local\fsrs\parameters;
use mod_rememberme\local\fsrs\rating;

/**
 * The scheduler service.
 *
 * Everything else in the plugin talks to the scheduling model through this
 * class. The memory mathematics lives behind it in the fsrs namespace with no
 * Moodle dependency at all, which is what makes it unit testable in isolation
 * and swappable later.
 *
 * Scheduling here is lazy and query driven. There is deliberately no nightly job
 * that precomputes due queues: such a job would be wrong the moment a learner
 * did an extra session.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduler {
    /** @var int Minimum latency samples before latency is trusted to refine a rating. */
    public const LATENCY_MIN_SAMPLES = 8;

    /** @var int Latency above this many milliseconds means the attempt was left open. */
    public const LATENCY_MAX_TRUSTED = 600000;

    /**
     * @var int Below this many milliseconds an answer was not read, only clicked.
     *
     * Deliberately conservative. The cost of setting it too high is refusing to
     * count a fast learner's honest answer, which is worse than letting the odd
     * gamed one through, so this sits well below any plausible reading time
     * rather than at the average.
     */
    public const MIN_ENGAGED_LATENCY = 500;

    /** @var int Seconds before an item answered wrongly comes back. */
    public const LEARNING_STEP = 600;

    /**
     * @var int How long after falling due an answer still counts as punctual.
     *
     * A day, because the activity is meant to be visited daily and expecting
     * anyone to answer within the hour would reward availability rather than
     * habit.
     */
    public const ONTIME_WINDOW = DAYSECS;

    /**
     * @var int Punctual answers needed before punctuality is scored at all.
     *
     * Below this the proportion is noise: two lucky answers should not earn a
     * term's insurance.
     */
    public const ONTIME_MIN_SAMPLES = 10;

    /**
     * @var int Lapses after which the short learning step is abandoned.
     *
     * Without this an item the learner simply cannot get right would return
     * every ten minutes forever, crowding out everything else. Past this point
     * it goes back on its normal schedule and shows up in the difficulty report,
     * which is where a badly worded question belongs.
     */
    public const LEECH_LAPSES = 8;

    /** @var \stdClass The activity instance. */
    protected \stdClass $instance;

    /** @var engine The memory model. */
    protected engine $engine;

    /** @var pool The question pool. */
    protected pool $pool;

    /** @var effective_time|null Cached suspension aware clock. */
    protected ?effective_time $clock = null;

    /** @var grade_mapper|null Cached grade mapper. */
    protected ?grade_mapper $mapper = null;

    /** @var array Cached latency medians, keyed by userid and qtype. */
    protected array $latencymedians = [];

    /**
     * Constructor.
     *
     * @param \stdClass $instance The rememberme instance record.
     * @param engine|null $engine Memory model, or null to build one from the instance settings.
     * @param grade_mapper|null $mapper Grade mapper, or null for the default.
     */
    public function __construct(\stdClass $instance, ?engine $engine = null, ?grade_mapper $mapper = null) {
        $this->instance = $instance;
        $this->engine = $engine ?? new engine(new parameters(null, (float)$instance->targetretention));
        $this->pool = new pool($instance);
        $this->mapper = $mapper;
    }

    /**
     * Get the question pool resolver.
     *
     * @return pool The pool.
     */
    public function get_pool(): pool {
        return $this->pool;
    }

    /**
     * Get the activity instance record.
     *
     * @return \stdClass The instance.
     */
    public function get_instance(): \stdClass {
        return $this->instance;
    }

    /**
     * Get the activity instance id.
     *
     * @return int The instance id.
     */
    public function get_instance_id(): int {
        return (int)$this->instance->id;
    }

    /**
     * Get the memory model.
     *
     * @return engine The engine.
     */
    public function get_engine(): engine {
        return $this->engine;
    }

    /**
     * The suspension aware clock for this instance.
     *
     * Cached per request: there will be a handful of windows at most, and every
     * scheduling calculation needs them.
     *
     * @return effective_time The clock.
     */
    public function get_clock(): effective_time {
        global $DB;

        if ($this->clock === null) {
            $windows = $DB->get_records(
                'rememberme_suspensions',
                ['rememberme' => $this->instance->id],
                'timestart ASC',
                'id, timestart, timeend'
            );
            $this->clock = new effective_time(array_values($windows));
        }
        return $this->clock;
    }

    /**
     * The grade to rating mapper for this instance.
     *
     * @return grade_mapper The mapper.
     */
    public function get_mapper(): grade_mapper {
        if ($this->mapper === null) {
            $this->mapper = new default_grade_mapper(
                (float)$this->instance->passthreshold,
                !empty($this->instance->uselatency)
            );
        }
        return $this->mapper;
    }

    /**
     * The week calculator for this instance.
     *
     * @return weeks The calculator.
     */
    public function get_weeks(): weeks {
        return new weeks(
            (int)$this->instance->coursestart,
            (int)$this->instance->activeweeks,
            $this->get_clock()
        );
    }

    /**
     * Get the stored memory state for one learner and one item.
     *
     * @param int $userid The learner.
     * @param int $questionbankentryid The item.
     * @return \stdClass|null The schedule record, or null if the item has never been attempted.
     */
    public function get_state(int $userid, int $questionbankentryid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('rememberme_schedule', [
            'rememberme' => $this->instance->id,
            'userid' => $userid,
            'questionbankentryid' => $questionbankentryid,
        ]);
        return $record ?: null;
    }

    /**
     * The interval currently implied by a schedule record, in effective days.
     *
     * Derived from stability every time rather than stored, so re-tuning the
     * model does not leave stale intervals behind. The stored fuzz factor keeps
     * the schedule reproducible without freezing the interval itself.
     *
     * @param \stdClass $record A schedule record.
     * @return float Interval in days.
     */
    public function interval_for_record(\stdClass $record): float {
        return $this->engine->interval_for((float)$record->stability) * (float)$record->fuzzfactor;
    }

    /**
     * Whether an item is due, measured in effective time.
     *
     * This is the authoritative test. The cached duedate column exists only so
     * the hot query can use an index; it is never trusted on its own, because a
     * teacher may have edited the suspension windows since it was written.
     *
     * @param \stdClass $record A schedule record.
     * @param int $now Current time.
     * @return bool True if the item is due.
     */
    public function is_due(\stdClass $record, int $now): bool {
        // An item in a learning step returns at that moment instead, because it
        // was answered wrongly and has not yet been answered right. The moment
        // was already made suspension aware when it was written.
        $learningdue = (int)($record->learningdue ?? 0);
        if ($learningdue > 0) {
            return $now >= $learningdue;
        }

        $elapsed = $this->get_clock()->effective_days((int)$record->lastreviewed, $now);
        return $elapsed >= $this->interval_for_record($record);
    }

    /**
     * Whether an attempt counts as the learner engaging with the question.
     *
     * An answer submitted faster than anyone could read the question is a click,
     * not recall. Such attempts are still recorded and still move the memory
     * state, because the review log is a complete record of what happened, but
     * they do not count toward the week: otherwise a learner can clear a weekly
     * target by hammering the submit button.
     *
     * Attempts with no latency measurement count. A missing measurement is not
     * evidence of anything, and refusing to count it would penalise a learner
     * for a server side gap.
     *
     * @param int|null $latencyms Milliseconds taken to answer.
     * @return bool True if the attempt counts toward weekly completion.
     */
    public static function is_engaged(?int $latencyms): bool {
        if ($latencyms === null) {
            return true;
        }
        return $latencyms >= self::MIN_ENGAGED_LATENCY;
    }

    /**
     * How overdue an item is, relative to its own stability.
     *
     * Ordering by this rather than by raw due date prioritises the items closest
     * to being forgotten. An item overdue by two days with a stability of three
     * days is far more urgent than one overdue by two days with a stability of
     * two hundred.
     *
     * @param \stdClass $record A schedule record.
     * @param int $now Current time.
     * @return float Urgency, higher is more urgent.
     */
    public function urgency(\stdClass $record, int $now): float {
        $elapsed = $this->get_clock()->effective_days((int)$record->lastreviewed, $now);
        $overdue = $elapsed - $this->interval_for_record($record);
        return $overdue / max(1.0, (float)$record->stability);
    }

    /**
     * Build the queue of questions for a session.
     *
     * Due reviews first, ordered by urgency, then new items drawn at random from
     * the unlocked bands, subject to the per day cap.
     *
     * @param int $userid The learner.
     * @param int|null $limit Maximum items, or null for the instance session size.
     * @param int|null $now Current time, or null for now.
     * @return array List of queue entries carrying questionbankentryid, questionid, qtype, isnew and bandlevel.
     */
    public function get_due_questions(int $userid, ?int $limit = null, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $limit = $limit ?? (int)$this->instance->sessionsize;
        if ($limit <= 0) {
            return [];
        }

        // Unlocking is evaluated at session build time, not on cron, so an
        // unlock takes effect in the session where it is earned.
        $bandlevel = $this->evaluate_bands($userid, $now);

        $entries = $this->pool->get_all_entries();
        if (empty($entries)) {
            return [];
        }

        $queue = [];

        // Reviews. Not band restricted: bands gate introduction, not revision.
        $candidates = $DB->get_records_select(
            'rememberme_schedule',
            'rememberme = :instanceid AND userid = :userid AND duedate <= :now',
            ['instanceid' => $this->instance->id, 'userid' => $userid, 'now' => $now],
            '',
            '*'
        );

        $due = [];
        foreach ($candidates as $record) {
            if (!isset($entries[$record->questionbankentryid])) {
                // The question has left the pool. Leave the record alone; the
                // maintenance task decides whether to prune it.
                continue;
            }
            if (!$this->is_due($record, $now)) {
                // The cached due date was optimistic, most likely because a
                // suspension window was added after it was written.
                continue;
            }
            $due[] = $record;
        }

        usort($due, function ($a, $b) use ($now) {
            return $this->urgency($b, $now) <=> $this->urgency($a, $now);
        });

        foreach ($due as $record) {
            if (count($queue) >= $limit) {
                break;
            }
            $entry = $entries[$record->questionbankentryid];
            $queue[] = (object)[
                'questionbankentryid' => (int)$record->questionbankentryid,
                'questionid' => (int)$entry->questionid,
                'qtype' => $entry->qtype,
                'isnew' => false,
                'bandlevel' => (int)$record->bandlevel,
            ];
        }

        if (count($queue) >= $limit) {
            return $queue;
        }

        // Top up with new items, drawn only from the currently unlocked band.
        // Without the per day cap an eager learner front loads hundreds of new
        // items and is buried in reviews a week later.
        $newallowed = max(0, (int)$this->instance->newperday - $this->count_new_today($userid, $now));
        if ($newallowed <= 0) {
            return $queue;
        }

        $seen = $DB->get_fieldset_select(
            'rememberme_schedule',
            'questionbankentryid',
            'rememberme = :instanceid AND userid = :userid',
            ['instanceid' => $this->instance->id, 'userid' => $userid]
        );
        $seen = array_flip(array_map('intval', $seen));

        // New items come from the current band and every band below it. A
        // learner who moved on before exhausting an earlier band still has
        // unseen questions there, and leaving them stranded would mean the
        // syllabus was never covered even though the learner kept up.
        $bandentries = $this->pool->get_entries_up_to_band($bandlevel);

        // Draw the new items at random rather than in pool order. The pool
        // comes back in a stable order, so without this every learner meets the
        // same questions in the same sequence and, worse, a learner who never
        // clears their daily allowance only ever sees the front of the list:
        // the tail of a large band would go unseen indefinitely while the
        // unlock rules waited for it. Shuffling the unseen candidates rather
        // than the whole pool keeps the work proportional to what is left.
        $unseen = [];
        foreach ($bandentries as $qbeid => $entry) {
            if (!isset($seen[(int)$qbeid])) {
                $unseen[] = [(int)$qbeid, $entry];
            }
        }
        shuffle($unseen);

        foreach ($unseen as [$qbeid, $entry]) {
            if (count($queue) >= $limit || $newallowed <= 0) {
                break;
            }
            $queue[] = (object)[
                'questionbankentryid' => (int)$qbeid,
                'questionid' => (int)$entry->questionid,
                'qtype' => $entry->qtype,
                'isnew' => true,
                'bandlevel' => $bandlevel,
            ];
            $newallowed--;
        }

        return $queue;
    }

    /**
     * How many new items the learner has already drawn today.
     *
     * @param int $userid The learner.
     * @param int $now Current time.
     * @return int Count of new items introduced today.
     */
    protected function count_new_today(int $userid, int $now): int {
        global $DB;

        // The same course wide day that study days are counted on.
        [$daystart] = $this->day_bounds($now);
        return $DB->count_records_select(
            'rememberme_schedule',
            'rememberme = :instanceid AND userid = :userid AND timecreated >= :daystart',
            ['instanceid' => $this->instance->id, 'userid' => $userid, 'daystart' => $daystart]
        );
    }

    /**
     * Record one graded attempt and reschedule the item.
     *
     * Called on question grading rather than on session submission, so a learner
     * who abandons a session still keeps the scheduling effect of the questions
     * they did answer.
     *
     * @param int $userid The learner.
     * @param int $questionbankentryid The item.
     * @param int $questionid The question version actually served.
     * @param string $qtype The question type.
     * @param float $fraction The objective grade.
     * @param int|null $latencyms Milliseconds taken to answer, or null if not measured.
     * @param int $bandlevel The band this item came from.
     * @param int|null $now Current time, or null for now.
     * @return \stdClass The updated schedule record.
     */
    public function record_attempt(
        int $userid,
        int $questionbankentryid,
        int $questionid,
        string $qtype,
        float $fraction,
        ?int $latencyms = null,
        int $bandlevel = 1,
        ?int $now = null
    ): \stdClass {
        global $DB;

        $now = $now ?? time();
        $clock = $this->get_clock();

        $weekno = $this->get_weeks()->week_for($now);

        $existing = $this->get_state($userid, $questionbankentryid);

        // Some behaviours and question types report fractions outside 0 to 1.
        // Clamp for storage, but map the rating from the raw value so that a
        // negatively marked answer is still recognised as wrong.
        $storedfraction = min(1.0, max(0.0, $fraction));

        $latency = $this->trustworthy_latency($latencyms);
        $median = $latency === null ? null : $this->get_latency_median($userid, $qtype);
        $rating = $this->get_mapper()->map($fraction, $latency, $median);

        if ($existing === null) {
            $elapseddays = 0.0;
            $retrievability = 0.0;
            $before = new memory_state(0.0, 0.0);
            $after = $this->engine->seed($rating);
        } else {
            $elapseddays = $clock->effective_days((int)$existing->lastreviewed, $now);
            $before = new memory_state((float)$existing->stability, (float)$existing->difficulty);
            $retrievability = $this->engine->retrievability($before->get_stability(), $elapseddays);
            $after = $this->engine->update($before, $rating, $elapseddays);
        }

        $fuzzfactor = $this->new_fuzz_factor();
        $interval = $this->engine->interval_for($after->get_stability()) * $fuzzfactor;
        $duedate = $clock->add_effective_seconds($now, $interval * DAYSECS);

        // A wrong answer puts the item into a short learning step, so the
        // learner meets it again in the same sitting rather than tomorrow. This
        // is scheduling, not grading: getting it wrong costs time and repetition,
        // never marks, so there is nothing to gain by looking the answer up.
        //
        // An item that has lapsed many times stops getting the short step,
        // because otherwise a question the learner cannot answer would return
        // every ten minutes indefinitely and crowd out everything else.
        $lapses = ($existing ? (int)$existing->lapses : 0) + (rating::is_success($rating) ? 0 : 1);
        $learningdue = 0;
        if (!rating::is_success($rating) && $lapses < self::LEECH_LAPSES) {
            $learningdue = $clock->add_effective_seconds($now, self::LEARNING_STEP);
            // The cached due date has to agree, or the indexed prefilter would
            // never surface the item for the learning step to apply to.
            $duedate = $learningdue;
        }

        $record = (object)[
            'rememberme' => $this->instance->id,
            'userid' => $userid,
            'questionbankentryid' => $questionbankentryid,
            'stability' => $after->get_stability(),
            'difficulty' => $after->get_difficulty(),
            'fuzzfactor' => $fuzzfactor,
            'state' => self::next_state($existing, $rating),
            'bandlevel' => $existing ? (int)$existing->bandlevel : $bandlevel,
            'lastreviewed' => $now,
            'duedate' => $duedate,
            'learningdue' => $learningdue,
            'timemodified' => $now,
        ];

        if ($existing === null) {
            $record->reps = rating::is_success($rating) ? 1 : 0;
            $record->lapses = rating::is_success($rating) ? 0 : 1;
            $record->timecreated = $now;
            $record->id = $DB->insert_record('rememberme_schedule', $record);
        } else {
            $record->id = $existing->id;
            $record->reps = (int)$existing->reps + (rating::is_success($rating) ? 1 : 0);
            $record->lapses = (int)$existing->lapses + (rating::is_success($rating) ? 0 : 1);
            $DB->update_record('rememberme_schedule', $record);
        }

        $insuspension = $clock->is_suspended_at($now);

        // The review log is not optional. It is the only way to re-tune the
        // model against real data, reschedule retrospectively if the constants
        // change, or debug a scheduler that has drifted, because the live state
        // carries no history at all.
        $DB->insert_record('rememberme_review_log', (object)[
            'rememberme' => $this->instance->id,
            'userid' => $userid,
            'questionbankentryid' => $questionbankentryid,
            'questionid' => $questionid,
            'qtype' => $qtype,
            'rating' => $rating,
            'fraction' => $storedfraction,
            'elapseddays' => $elapseddays,
            'retrievability' => $retrievability,
            'stabilitybefore' => $before->get_stability(),
            'difficultybefore' => $before->get_difficulty(),
            'stabilityafter' => $after->get_stability(),
            'difficultyafter' => $after->get_difficulty(),
            'latency' => $latencyms,
            'weekno' => $weekno,
            'insuspension' => $insuspension ? 1 : 0,
            // What the item was due at, as it stood when answered. Kept on the
            // row because the schedule record moves on immediately afterwards,
            // so punctuality cannot be reconstructed from live state later.
            'wasdue' => $existing ? (int)$existing->duedate : 0,
            'timecreated' => $now,
        ]);

        $this->record_week_progress($userid, $weekno, $insuspension, $now);

        return $record;
    }

    /**
     * Decide the next lifecycle state for an item.
     *
     * @param \stdClass|null $existing The previous schedule record.
     * @param int $rating The rating just recorded.
     * @return string One of new, learning, review or relearning.
     */
    protected static function next_state(?\stdClass $existing, int $rating): string {
        if ($existing === null) {
            return rating::is_success($rating) ? 'learning' : 'relearning';
        }
        if (!rating::is_success($rating)) {
            return 'relearning';
        }
        if ($existing->state === 'learning' || $existing->state === 'relearning') {
            return 'review';
        }
        return 'review';
    }

    /**
     * A fresh jitter multiplier.
     *
     * Applied at storage time rather than query time, so the stored schedule is
     * stable and reproducible. Without jitter, items introduced together stay
     * clustered together forever and produce recurring workload spikes.
     *
     * @return float A multiplier close to 1.
     */
    protected function new_fuzz_factor(): float {
        return 1.0 + ((mt_rand(0, 2000) / 2000.0) * 2.0 - 1.0) * 0.05;
    }

    /**
     * Discard a latency measurement that cannot be trusted.
     *
     * An attempt left open across a long gap tells us nothing about recall
     * speed, so it must not be allowed to influence the rating.
     *
     * @param int|null $latencyms Raw latency in milliseconds.
     * @return float|null Latency in seconds, or null if unusable.
     */
    protected function trustworthy_latency(?int $latencyms): ?float {
        if ($latencyms === null || $latencyms <= 0 || $latencyms > self::LATENCY_MAX_TRUSTED) {
            return null;
        }
        return $latencyms / 1000.0;
    }

    /**
     * The learner's rolling median latency for a question type.
     *
     * Latency must be normalised per learner and per question type: a short
     * answer item with typed input is inherently slower than a two option
     * multiple choice, so comparing against a global constant would
     * systematically mislabel whole question types.
     *
     * Returns null until there are enough samples, which collapses the mapping
     * to its binary form.
     *
     * @param int $userid The learner.
     * @param string $qtype The question type.
     * @return float|null Median latency in seconds, or null if there is not enough data.
     */
    public function get_latency_median(int $userid, string $qtype): ?float {
        global $DB;

        $key = $userid . ':' . $qtype;
        if (array_key_exists($key, $this->latencymedians)) {
            return $this->latencymedians[$key];
        }

        $latencies = $DB->get_fieldset_select(
            'rememberme_review_log',
            'latency',
            'rememberme = :instanceid AND userid = :userid AND qtype = :qtype AND latency IS NOT NULL',
            ['instanceid' => $this->instance->id, 'userid' => $userid, 'qtype' => $qtype]
        );

        $median = null;
        if (count($latencies) >= self::LATENCY_MIN_SAMPLES) {
            $latencies = array_map('intval', $latencies);
            sort($latencies);
            $count = count($latencies);
            $middle = (int)floor($count / 2);
            if ($count % 2 === 0) {
                $median = (($latencies[$middle - 1] + $latencies[$middle]) / 2.0) / 1000.0;
            } else {
                $median = $latencies[$middle] / 1000.0;
            }
        }

        $this->latencymedians[$key] = $median;
        return $median;
    }

    /**
     * Rescore the week containing an answer, after the answer was logged.
     *
     * @param int $userid The learner.
     * @param int $weekno The week number.
     * @param bool $insuspension Whether this was voluntary work during a break.
     * @param int $now Current time.
     */
    protected function record_week_progress(int $userid, int $weekno, bool $insuspension, int $now): void {
        // Work during a suspension window is scored like any other, but a
        // week more than half suspended is out of the denominator anyway, so
        // it cannot lose grade credit. It feeds the grace pool instead, at
        // final grade calculation.
        if ($this->rescore_week($userid, $weekno, $now) !== null) {
            $this->progress_changed($userid);
        }
    }

    /**
     * Whether a week number falls inside the graded calendar.
     *
     * @param int $weekno The week number.
     * @return bool True for weeks one to activeweeks.
     */
    public function is_graded_week(int $weekno): bool {
        return $weekno >= 1 && $weekno <= (int)$this->instance->activeweeks;
    }

    /**
     * The study days a week needs, as the activity is configured now.
     *
     * @return int Between one and seven.
     */
    public function required_study_days(): int {
        return min(7, max(1, (int)($this->instance->studydays ?? 3)));
    }

    /**
     * The end of term, never later than the last graded week.
     *
     * @return int Unix timestamp.
     */
    public function term_end(): int {
        $start = (int)$this->instance->coursestart;
        $end = $start + (int)$this->instance->activeweeks * WEEKSECS;
        $termend = (int)($this->instance->termend ?? 0);
        return $termend > $start ? min($end, $termend) : $end;
    }

    /**
     * The share of a week that is open for study: inside the term and not suspended.
     *
     * @param int $weekno The week number.
     * @return float Between 0 and 1.
     */
    public function open_fraction(int $weekno): float {
        [$start, $end] = $this->get_weeks()->week_bounds($weekno);
        $start = max($start, (int)$this->instance->coursestart);
        $end = min($end, $this->term_end());
        if ($end <= $start) {
            return 0.0;
        }
        $open = ($end - $start) - $this->get_clock()->suspended_overlap($start, $end);
        return max(0.0, min(1.0, $open / WEEKSECS));
    }

    /**
     * The study days a week actually needs, given how much of it is open.
     *
     * A full week needs the full number. A week shortened by a break, or cut
     * off by the end of term, needs the same share of it, rounded up so a
     * shortened week still asks for something, and never less than one day. A
     * week that is mostly suspended never gets here: it is not graded at all.
     *
     * @param int $base Study days a full week needs.
     * @param int $weekno The week number.
     * @return int Between one and the base.
     */
    public function effective_required(int $base, int $weekno): int {
        $base = max(1, $base);
        $open = $this->open_fraction($weekno);
        if ($open >= 1.0 - 1.0E-9) {
            return $base;
        }
        // The small allowance stops a week that is open for exactly two
        // sevenths of 7 days from rounding up to three on floating point noise.
        return max(1, min($base, (int)ceil($base * $open - 1.0E-6)));
    }

    /**
     * The course wide day containing a moment.
     *
     * Days are anchored to the start of week one, the same way weeks are, so
     * every week is exactly seven of them and a day never straddles two weeks.
     * The per day cap on new items resets on the same boundary, so "today"
     * means one thing everywhere in the activity.
     *
     * @param int $time Unix timestamp.
     * @return array Two element list of start and end unix timestamps.
     */
    public function day_bounds(int $time): array {
        $coursestart = (int)$this->instance->coursestart;
        $start = $coursestart + (int)floor(($time - $coursestart) / DAYSECS) * DAYSECS;
        return [$start, $start + DAYSECS];
    }

    /**
     * Which day of its week a moment falls on.
     *
     * @param int $weekno The week number.
     * @param int $time Unix timestamp inside that week.
     * @return int Zero to six.
     */
    protected function day_index(int $weekno, int $time): int {
        [$weekstart] = $this->get_weeks()->week_bounds($weekno);
        return min(6, max(0, (int)floor(($time - $weekstart) / DAYSECS)));
    }

    /**
     * Distinct questions genuinely engaged with on each day of a week.
     *
     * Distinct, so repeating one question cannot stand in for covering the
     * queue: a wrong answer brings the item straight back, and counting
     * attempts once let seven answers to one question clear a target of five.
     * Engaged, so answers submitted too fast to have been read do not count.
     * Read from the review log by timestamp rather than kept as a running
     * total, so the figure can always be recomputed from what happened, and so
     * a change to the week one start date regroups past answers correctly.
     *
     * @param int $userid The learner.
     * @param int $weekno The week number.
     * @return array Seven counts, keyed zero to six.
     */
    public function day_counts(int $userid, int $weekno): array {
        global $DB;

        [$start, $end] = $this->get_weeks()->week_bounds($weekno);
        $rows = $DB->get_recordset_select(
            'rememberme_review_log',
            'rememberme = :instanceid AND userid = :userid AND timecreated >= :start AND timecreated < :end
                AND (latency IS NULL OR latency >= :minlatency)',
            [
                'instanceid' => $this->instance->id,
                'userid' => $userid,
                'start' => $start,
                'end' => $end,
                'minlatency' => self::MIN_ENGAGED_LATENCY,
            ],
            '',
            'id, questionbankentryid, timecreated'
        );

        $seen = array_fill(0, 7, []);
        foreach ($rows as $row) {
            $day = min(6, max(0, (int)floor(((int)$row->timecreated - $start) / DAYSECS)));
            $seen[$day][(int)$row->questionbankentryid] = true;
        }
        $rows->close();

        return array_map('count', $seen);
    }

    /**
     * How many days of a week count as study days.
     *
     * A day counts when the learner answered a session's worth of different
     * questions, or reached a point where nothing more could be offered. The
     * second half matters: a learner with little due must not be marked down
     * for the queue being short, only for not turning up.
     *
     * @param array $daycounts Seven counts from day_counts().
     * @param int $clearedmask Bit per day on which nothing more could be offered.
     * @return int Days that count, zero to seven.
     */
    public function count_study_days(array $daycounts, int $clearedmask): int {
        $needed = max(1, (int)$this->instance->sessionsize);
        $days = 0;
        for ($day = 0; $day < 7; $day++) {
            if (($daycounts[$day] ?? 0) >= $needed || ($clearedmask & (1 << $day))) {
                $days++;
            }
        }
        return $days;
    }

    /**
     * The score a week earned under the rule it was worked under before study days.
     *
     * @param \stdClass $week The week record.
     * @return float Fraction between 0 and 1.
     */
    public static function legacy_fraction(\stdClass $week): float {
        return weeks::score_week((int)$week->snapshottarget, (int)$week->completed);
    }

    /**
     * Recompute one week's study days and score from the log and stored flags.
     *
     * Idempotent: it derives everything from the review log and the cleared
     * day flags, so running it twice, or long after the fact, gives the same
     * answer.
     *
     * A week that began before study day grading was switched on keeps the
     * better of its old and new scores. Learners worked those weeks without
     * being told days mattered, and a change of rule must not take marks away
     * retrospectively.
     *
     * @param int $userid The learner.
     * @param int $weekno The week number.
     * @param int $now Current time.
     * @param bool $create Whether to create the week record if it does not exist yet.
     * @return \stdClass|null The updated week record, or null outside the graded calendar.
     */
    public function rescore_week(int $userid, int $weekno, int $now, bool $create = true): ?\stdClass {
        global $DB;

        if (!$this->is_graded_week($weekno)) {
            return null;
        }

        if ($create) {
            $week = $this->ensure_week_record($userid, $weekno, $now);
        } else {
            $week = $DB->get_record('rememberme_weeks', [
                'rememberme' => $this->instance->id,
                'userid' => $userid,
                'weekno' => $weekno,
            ]);
            if (!$week) {
                return null;
            }
        }

        $base = (int)$week->daysrequired > 0 ? (int)$week->daysrequired : $this->required_study_days();
        $required = $this->effective_required($base, $weekno);
        $studied = $this->count_study_days($this->day_counts($userid, $weekno), (int)$week->clearedmask);
        $fraction = weeks::score_days($required, $studied);

        // Only a record the item count release wrote carries an old score. A
        // record created since has a legacy target of zero, which the old rule
        // would read as "nothing was due" and score as a full week.
        [$weekstart] = $this->get_weeks()->week_bounds($weekno);
        $switchedat = (int)($this->instance->studydaysfrom ?? 0);
        if ($switchedat > 0 && $weekstart < $switchedat && (int)$week->snapshottaken < $switchedat) {
            $fraction = max($fraction, self::legacy_fraction($week));
        }

        $week->daysrequired = $base;
        $week->daysstudied = $studied;
        $week->fraction = $fraction;
        $week->timemodified = $now;
        $DB->update_record('rememberme_weeks', (object)[
            'id' => $week->id,
            'daysrequired' => $base,
            'daysstudied' => $studied,
            'fraction' => $fraction,
            'timemodified' => $now,
        ]);

        return $week;
    }

    /**
     * Record that nothing more could be offered to the learner right now.
     *
     * Called when a session cannot start because the queue is empty: every
     * review due has been answered and today's new items have been drawn. That
     * is everything the activity asked of the learner today, so the day counts
     * however few questions it took.
     *
     * @param int $userid The learner.
     * @param int|null $now Current time, or null for now.
     */
    public function mark_day_cleared(int $userid, ?int $now = null): void {
        global $DB;

        $now = $now ?? time();
        $weekno = $this->get_weeks()->week_for($now);
        if (!$this->is_graded_week($weekno)) {
            return;
        }

        $week = $this->ensure_week_record($userid, $weekno, $now);
        $bit = 1 << $this->day_index($weekno, $now);
        if ((int)$week->clearedmask & $bit) {
            return;
        }

        $DB->set_field('rememberme_weeks', 'clearedmask', (int)$week->clearedmask | $bit, ['id' => $week->id]);
        $this->rescore_week($userid, $weekno, $now);
        $this->progress_changed($userid);
    }

    /**
     * Let the gradebook and completion know the learner's standing moved.
     *
     * @param int $userid The learner.
     */
    protected function progress_changed(int $userid): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/rememberme/lib.php');

        rememberme_user_progress_changed($this->instance, $userid);
    }

    /**
     * The learner's progress toward today counting as a study day.
     *
     * @param int $userid The learner.
     * @param int|null $now Current time, or null for now.
     * @return array With done, target and counts.
     */
    public function today_progress(int $userid, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $target = max(1, (int)$this->instance->sessionsize);
        $weekno = $this->get_weeks()->week_for($now);
        if (!$this->is_graded_week($weekno)) {
            return ['done' => 0, 'target' => $target, 'counts' => false];
        }

        $done = $this->day_counts($userid, $weekno)[$this->day_index($weekno, $now)];
        $mask = (int)$DB->get_field('rememberme_weeks', 'clearedmask', [
            'rememberme' => $this->instance->id,
            'userid' => $userid,
            'weekno' => $weekno,
        ]);
        $cleared = (bool)($mask & (1 << $this->day_index($weekno, $now)));

        return [
            'done' => $done,
            'target' => $target,
            'counts' => $cleared || $done >= $target,
        ];
    }

    /**
     * Get this week's record, creating it if this is the first visit.
     *
     * The study days required are frozen on the record when it is created, so
     * a teacher changing the setting mid week does not move the finish line
     * under a learner who is already working toward it.
     *
     * @param int $userid The learner.
     * @param int $weekno The week number.
     * @param int $now Current time.
     * @return \stdClass The week record.
     */
    public function ensure_week_record(int $userid, int $weekno, int $now): \stdClass {
        global $DB;

        $week = $DB->get_record('rememberme_weeks', [
            'rememberme' => $this->instance->id,
            'userid' => $userid,
            'weekno' => $weekno,
        ]);
        if ($week) {
            return $week;
        }

        $record = (object)[
            'rememberme' => $this->instance->id,
            'userid' => $userid,
            'weekno' => $weekno,
            // The item count target this replaced. Left at zero on new rows.
            'snapshottarget' => 0,
            'snapshottaken' => $now,
            'completed' => 0,
            'fraction' => 0.0,
            'graceapplied' => 0.0,
            'suspended' => $this->get_weeks()->is_week_suspended($weekno) ? 1 : 0,
            'daysrequired' => $this->required_study_days(),
            'daysstudied' => 0,
            'clearedmask' => 0,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('rememberme_weeks', $record);
        return $record;
    }

    /**
     * The schedule records currently due for a learner.
     *
     * @param int $userid The learner.
     * @param int $now Current time.
     * @return array Due schedule records.
     */
    public function due_records(int $userid, int $now): array {
        global $DB;

        $candidates = $DB->get_records_select(
            'rememberme_schedule',
            'rememberme = :instanceid AND userid = :userid AND duedate <= :now',
            ['instanceid' => $this->instance->id, 'userid' => $userid, 'now' => $now]
        );

        $due = [];
        foreach ($candidates as $record) {
            if ($this->is_due($record, $now)) {
                $due[$record->id] = $record;
            }
        }
        return $due;
    }

    /**
     * How many pool items the learner has never attempted.
     *
     * @param int $userid The learner.
     * @return int Count of unseen items.
     */
    public function count_unseen(int $userid): int {
        global $DB;

        $total = count($this->pool->get_all_entries());
        $seen = $DB->count_records('rememberme_schedule', [
            'rememberme' => $this->instance->id,
            'userid' => $userid,
        ]);
        return max(0, $total - $seen);
    }

    /**
     * Evaluate band unlocking for a learner and persist any change.
     *
     * @param int $userid The learner.
     * @param int $now Current time.
     * @return int The learner's current band level.
     */
    public function evaluate_bands(int $userid, int $now): int {
        global $DB;

        $bandcount = $this->pool->get_band_count();
        if ($bandcount <= 0) {
            return 0;
        }

        $state = $this->ensure_band_state($userid, $now);
        $clock = $this->get_clock();
        $window = $clock->window_at($now);
        $insuspension = $window !== null;

        $context = [
            'effectivedayssincefirst' => $clock->effective_days((int)$state->firstsession, $now),
            'intervaldays' => (float)$this->instance->unlockinterval,
            'effectivedaysonband' => $clock->effective_days((int)$state->bandsince, $now),
            'backstopdays' => (float)$this->instance->backstopdays,
            'stabilityfloor' => (float)$this->instance->stabilityfloor,
            'proportion' => (float)$this->instance->masteryproportion,
            'insuspension' => $insuspension,
            'unlockedinthiswindow' => $insuspension
                && (int)$state->lastunlockwindow === (int)$window['timestart'],
        ];

        $mode = (int)$this->instance->unlockmode;
        if ($mode === bands::MODE_MASTERY || $mode === bands::MODE_EXHAUSTED) {
            $bandentries = $this->pool->get_entries_in_band((int)$state->bandlevel);
            $context['banditemcount'] = count($bandentries);
            $stabilities = $this->stabilities_for_entries($userid, array_keys($bandentries));
            $context['stabilities'] = $stabilities;
            // An unseen item has no stability at all, which is exactly what the
            // exhausted gate asks about.
            $context['unseeninband'] = count(array_filter(
                $stabilities,
                static fn($stability): bool => $stability === null
            ));
        }

        [$newlevel, $reason] = bands::evaluate(
            $mode,
            (int)$state->bandlevel,
            $bandcount,
            $context
        );

        if ($newlevel > (int)$state->bandlevel) {
            $DB->update_record('rememberme_bandstate', (object)[
                'id' => $state->id,
                'bandlevel' => $newlevel,
                'reason' => $reason,
                'bandsince' => $now,
                'lastunlockwindow' => $insuspension ? (int)$window['timestart'] : (int)$state->lastunlockwindow,
                'timemodified' => $now,
            ]);
            return $newlevel;
        }

        return (int)$state->bandlevel;
    }

    /**
     * The stabilities a learner has for a set of items, with unseen items as null.
     *
     * Unseen items must appear as null rather than be omitted, because they
     * count against the mastery threshold: a band cannot qualify until most of
     * it has actually been attempted.
     *
     * @param int $userid The learner.
     * @param array $questionbankentryids The items.
     * @return array Stabilities, one entry per item, null where unseen.
     */
    protected function stabilities_for_entries(int $userid, array $questionbankentryids): array {
        global $DB;

        if (empty($questionbankentryids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($questionbankentryids, SQL_PARAMS_NAMED, 'qbe');
        $params['instanceid'] = $this->instance->id;
        $params['userid'] = $userid;

        $records = $DB->get_records_select(
            'rememberme_schedule',
            "rememberme = :instanceid AND userid = :userid AND questionbankentryid {$insql}",
            $params,
            '',
            'questionbankentryid, stability'
        );

        $stabilities = [];
        foreach ($questionbankentryids as $qbeid) {
            $stabilities[] = isset($records[$qbeid]) ? (float)$records[$qbeid]->stability : null;
        }
        return $stabilities;
    }

    /**
     * Get a learner's band state, creating it on first session.
     *
     * @param int $userid The learner.
     * @param int $now Current time.
     * @return \stdClass The band state record.
     */
    public function ensure_band_state(int $userid, int $now): \stdClass {
        global $DB;

        $state = $DB->get_record('rememberme_bandstate', [
            'rememberme' => $this->instance->id,
            'userid' => $userid,
        ]);
        if ($state) {
            return $state;
        }

        // Time based unlocking counts from the learner's first session, not from
        // course start, so somebody joining in week three is on their own clock
        // rather than being handed four bands at once.
        $state = (object)[
            'rememberme' => $this->instance->id,
            'userid' => $userid,
            'bandlevel' => 1,
            'reason' => bands::REASON_NONE,
            'firstsession' => $now,
            'bandsince' => $now,
            'lastunlockwindow' => 0,
            'timemodified' => $now,
        ];
        $state->id = $DB->insert_record('rememberme_bandstate', $state);
        return $state;
    }

    /**
     * Recompute every cached due date for this instance.
     *
     * Called when a teacher edits the suspension windows. The stored memory
     * state is never touched: only the denormalised duedate column, which exists
     * purely so the hot query can use an index, is refreshed.
     *
     * @return int How many records were updated.
     */
    public function refresh_cached_due_dates(): int {
        global $DB;

        $clock = $this->get_clock();
        $records = $DB->get_recordset('rememberme_schedule', ['rememberme' => $this->instance->id]);
        $updated = 0;
        foreach ($records as $record) {
            if ((int)($record->learningdue ?? 0) > 0) {
                // An item mid learning step is governed by that moment, so its
                // cached date is recomputed from the step rather than from the
                // interval its stability implies.
                $duedate = $clock->add_effective_seconds((int)$record->lastreviewed, self::LEARNING_STEP);
                if ($duedate !== (int)$record->learningdue) {
                    $DB->set_field('rememberme_schedule', 'learningdue', $duedate, ['id' => $record->id]);
                    $DB->set_field('rememberme_schedule', 'duedate', $duedate, ['id' => $record->id]);
                    $updated++;
                }
                continue;
            }

            $interval = $this->interval_for_record($record);
            $duedate = $clock->add_effective_seconds((int)$record->lastreviewed, $interval * DAYSECS);
            if ($duedate !== (int)$record->duedate) {
                $DB->set_field('rememberme_schedule', 'duedate', $duedate, ['id' => $record->id]);
                $updated++;
            }
        }
        $records->close();
        return $updated;
    }

    /**
     * The learner's final grade proportion for this activity.
     *
     * Grading is schedule adherence, never accuracy. Grading accuracy would
     * contaminate the correctness signal the whole algorithm depends on, by
     * rewarding guess avoidance and answer lookup.
     *
     * @param int $userid The learner.
     * @param int|null $now Current time, or null for now.
     * @return array Result carrying proportion, fractions, gracespent and gracelog.
     */
    public function final_grade(int $userid, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $weeks = $this->get_weeks();
        $graded = $weeks->graded_weeks();

        $records = $DB->get_records('rememberme_weeks', [
            'rememberme' => $this->instance->id,
            'userid' => $userid,
        ], 'weekno ASC', 'weekno, fraction, snapshottarget, completed');

        $currentweek = $weeks->week_for($now);
        $fractions = [];
        foreach ($graded as $weekno) {
            if ($weekno > $currentweek) {
                // A week that has not happened yet is not a shortfall.
                continue;
            }
            $fraction = isset($records[$weekno]) ? (float)$records[$weekno]->fraction : 0.0;
            if ($weekno == $currentweek && $fraction < 1.0) {
                // Nor is the week in progress, until it has been earned in
                // full: otherwise every grade dips on the first day of every
                // week, for learners who have done nothing wrong.
                continue;
            }
            $fractions[$weekno] = $fraction;
        }

        $balance = grace::cap_balance(
            (float)$this->instance->gracebalance,
            $this->earned_grace($userid)
        );

        return weeks::final_proportion($fractions, $balance);
    }

    /**
     * Grace the learner has earned, before the cap is applied.
     *
     * Two sources, both rewarding effort rather than accuracy: voluntary work
     * during a break, and answering items when they fall due rather than
     * letting them go stale.
     *
     * @param int $userid The learner.
     * @return float Earned grace, before capping.
     */
    public function earned_grace(int $userid): float {
        global $DB;

        $fromwork = grace::earned_from_work(
            $this->count_break_study($userid),
            (int)$this->instance->sessionsize,
            (float)$this->instance->graceearnrate
        );

        return $fromwork + $this->ontime_grace($userid);
    }

    /**
     * How much study the learner has done during breaks, for earning grace.
     *
     * Study counts as done during a break if it was inside a suspension window,
     * or anywhere in a week that is not graded because it is mostly suspended:
     * a learner who comes back on the open days of a break week is doing
     * exactly the voluntary work grace exists to reward, and without this it
     * earned nothing at all, since the week itself is out of the grade.
     *
     * It counts different questions answered properly on each day, the same
     * measure as a study day, so repeating one question or tapping through
     * earns nothing. Answers given before study day grading was switched on are
     * counted the way they were then, every answer inside a window, so no
     * learner's grace shrinks because the rule changed.
     *
     * @param int $userid The learner.
     * @return int Answers that earn grace.
     */
    public function count_break_study(int $userid): int {
        global $DB;

        $switchedat = (int)($this->instance->studydaysfrom ?? 0);
        $count = 0;
        if ($switchedat > 0) {
            $count += $DB->count_records_select(
                'rememberme_review_log',
                'rememberme = :instanceid AND userid = :userid AND insuspension = 1 AND timecreated < :switchedat',
                ['instanceid' => $this->instance->id, 'userid' => $userid, 'switchedat' => $switchedat]
            );
        }

        $rows = $DB->get_recordset_select(
            'rememberme_review_log',
            'rememberme = :instanceid AND userid = :userid AND timecreated >= :switchedat
                AND (latency IS NULL OR latency >= :minlatency)',
            [
                'instanceid' => $this->instance->id,
                'userid' => $userid,
                'switchedat' => $switchedat,
                'minlatency' => self::MIN_ENGAGED_LATENCY,
            ],
            '',
            'id, questionbankentryid, timecreated'
        );

        $weeks = $this->get_weeks();
        $clock = $this->get_clock();
        $breakweeks = [];
        $seen = [];
        foreach ($rows as $row) {
            $time = (int)$row->timecreated;
            $weekno = $weeks->week_for($time);
            $breakweeks[$weekno] ??= $this->is_graded_week($weekno) && $weeks->is_week_suspended($weekno);
            if (!$breakweeks[$weekno] && !$clock->is_suspended_at($time)) {
                continue;
            }
            [$daystart] = $this->day_bounds($time);
            $seen[$daystart . ':' . $row->questionbankentryid] = true;
        }
        $rows->close();

        return $count + count($seen);
    }

    /**
     * How punctually the learner answers items that have fallen due.
     *
     * This is the measure of a study habit that cannot be faked by turning up.
     * Opening the activity is free, so counting visits would reward the
     * appearance of diligence; answering an item close to when it came due is
     * only possible by actually returning while the queue is fresh, and it is
     * precisely the behaviour that makes spaced repetition work. A learner who
     * saves everything for one sitting a fortnight fails it by construction,
     * because their items sat overdue for days.
     *
     * Items being met for the first time are excluded: they were never due, so
     * there is no punctuality to judge.
     *
     * @param int $userid The learner.
     * @return array Two element list of punctual answers and answers that could be judged.
     */
    public function ontime_counts(int $userid): array {
        global $DB;

        $sql = "SELECT COUNT(1) AS judged,
                       SUM(CASE WHEN timecreated <= wasdue + :window THEN 1 ELSE 0 END) AS punctual
                  FROM {rememberme_review_log}
                 WHERE rememberme = :instanceid
                   AND userid = :userid
                   AND wasdue > 0
                   AND (latency IS NULL OR latency >= :minlatency)";

        $row = $DB->get_record_sql($sql, [
            'window' => self::ONTIME_WINDOW,
            'instanceid' => $this->instance->id,
            'userid' => $userid,
            'minlatency' => self::MIN_ENGAGED_LATENCY,
        ]);

        return [(int)($row->punctual ?? 0), (int)($row->judged ?? 0)];
    }

    /**
     * Grace earned by answering items when they fall due.
     *
     * Paid in grace rather than in marks on purpose. Grace only ever fills a
     * gap, so a learner who never has a bad week gains nothing from it, and it
     * cannot lift anybody above a full mark. That makes it a reward for the
     * habit without turning the habit into a second thing to be graded on.
     *
     * @param int $userid The learner.
     * @return float Grace earned through punctuality.
     */
    public function ontime_grace(int $userid): float {
        $maximum = (float)($this->instance->ontimegrace ?? 0);
        if ($maximum <= 0.0) {
            return 0.0;
        }

        [$punctual, $judged] = $this->ontime_counts($userid);
        if ($judged < self::ONTIME_MIN_SAMPLES) {
            return 0.0;
        }

        return $maximum * ($punctual / $judged);
    }
}
