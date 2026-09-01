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

namespace mod_rememberme\output;

use mod_rememberme\local\bands;
use mod_rememberme\local\scheduler;

/**
 * Builds the template contexts for the four teacher facing reports.
 *
 * Escaping contract, which every one of the report templates relies on:
 * this class escapes at the sink and hands the templates strings that are
 * already safe to emit. Learner names go through s(), because fullname()
 * escapes at no layer, and question names go through format_string(), which
 * both filters and escapes. The templates therefore render those two fields
 * with a triple brace; anything they render with a double brace is a value
 * this class left raw, and Moodle's Mustache engine escapes it with s()
 * (lib/classes/output/renderer_base.php:122). Escaping in PHP and rendering
 * with a double brace would double encode an apostrophe in a learner's name,
 * so the two must not be mixed on one field.
 *
 * The reports are deliberately aggregate rather than per attempt. A teacher
 * acting on this data is looking for a defective question or a stalled
 * learner, not for an audit trail; the review log carries the audit trail.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_renderer_helper {
    /** @var string The per question difficulty report. */
    public const MODE_DIFFICULTY = 'difficulty';

    /** @var string The per learner coverage, retention and forecast report. */
    public const MODE_COVERAGE = 'coverage';

    /** @var string The cohort band progression report. */
    public const MODE_BANDS = 'bands';

    /** @var string The weekly completion matrix. */
    public const MODE_WEEKS = 'weeks';

    /**
     * Mean difficulty at or above which an item is worth a teacher's attention.
     *
     * Difficulty runs 1 to 10. An item sitting above 7 for the cohort is
     * usually defective rather than conceptually hard, which is the whole
     * point of this report.
     *
     * @var float
     */
    public const DIFFICULTY_FLAG = 7.0;

    /**
     * How many learners must have attempted an item before it can be flagged.
     *
     * One learner having trouble with a question is one learner having
     * trouble. Flagging on a single data point would bury the real signal.
     *
     * @var int
     */
    public const FLAG_MIN_LEARNERS = 3;

    /** @var int How many days of upcoming review load the forecast covers. */
    public const FORECAST_DAYS = 14;

    /** @var \stdClass The activity instance record. */
    protected \stdClass $instance;

    /** @var \core\context\module The module context, used for format_string and for the learner list. */
    protected \core\context\module $context;

    /** @var int The active group, or 0 for all participants. */
    protected int $groupid;

    /** @var scheduler The scheduler, which owns the pool, the clock and the week calculator. */
    protected scheduler $scheduler;

    /** @var array|null Cached learner records, keyed by user id. */
    protected ?array $learners = null;

    /** @var array|null Cached pool entries, keyed by questionbankentryid. */
    protected ?array $entries = null;

    /**
     * Constructor.
     *
     * @param \stdClass $instance The rememberme instance record.
     * @param \core\context\module $context The module context.
     * @param int $groupid The active group id, or 0 for every participant.
     */
    public function __construct(\stdClass $instance, \core\context\module $context, int $groupid = 0) {
        $this->instance = $instance;
        $this->context = $context;
        $this->groupid = $groupid;
        $this->scheduler = new scheduler($instance);
    }

    /**
     * The report modes, in tab order.
     *
     * @return array List of mode names.
     */
    public static function modes(): array {
        return [self::MODE_DIFFICULTY, self::MODE_COVERAGE, self::MODE_BANDS, self::MODE_WEEKS];
    }

    /**
     * Whether a requested mode is one this report knows about.
     *
     * @param string $mode The mode from the URL.
     * @return bool True if the mode is valid.
     */
    public static function is_valid_mode(string $mode): bool {
        return in_array($mode, self::modes(), true);
    }

    /**
     * The template name for a mode.
     *
     * @param string $mode A valid mode name.
     * @return string The full template name.
     */
    public static function template_for(string $mode): string {
        return 'mod_rememberme/report_' . $mode;
    }

    /**
     * The participants this report covers.
     *
     * Everybody who may answer questions is listed, including learners who have
     * never started: a teacher looking for who has fallen behind needs the
     * empty rows more than the full ones.
     *
     * @return array User records keyed by user id.
     */
    protected function get_learners(): array {
        if ($this->learners === null) {
            $this->learners = get_enrolled_users(
                $this->context,
                'mod/rememberme:attempt',
                $this->groupid,
                'u.*',
                null,
                0,
                0,
                true
            );
        }
        return $this->learners;
    }

    /**
     * The whole question pool for this instance, keyed by bank entry id.
     *
     * @return array Entry records carrying name, qtype and questionid.
     */
    protected function get_entries(): array {
        if ($this->entries === null) {
            $this->entries = $this->scheduler->get_pool()->get_all_entries();
        }
        return $this->entries;
    }

    /**
     * A learner's display name, escaped ready for a template triple brace.
     *
     * @param \stdClass $user The user record.
     * @return string The escaped full name.
     */
    protected function learner_name(\stdClass $user): string {
        return s(fullname($user));
    }

    /**
     * An SQL fragment restricting a query to the learners this report covers.
     *
     * Returns null when no restriction is needed, so the common ungrouped case
     * does not pay for an IN list of the whole cohort.
     *
     * @return array Two element list of an SQL fragment and its parameters, or [null, []].
     */
    protected function learner_restriction(): array {
        global $DB;

        if (empty($this->groupid)) {
            return [null, []];
        }
        $userids = array_keys($this->get_learners());
        if (empty($userids)) {
            // No members: force an empty result rather than silently dropping the filter.
            return ['1 = 0', []];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ru');
        return ['s.userid ' . $insql, $params];
    }

    /**
     * Context for the per question difficulty report.
     *
     * @return array Template context.
     */
    public function difficulty_context(): array {
        global $DB;

        $params = ['instanceid' => $this->instance->id];
        [$restrict, $restrictparams] = $this->learner_restriction();
        $where = 's.rememberme = :instanceid';
        if ($restrict !== null) {
            $where .= ' AND ' . $restrict;
            $params += $restrictparams;
        }

        // One schedule row per learner per item is guaranteed by the unique
        // index rememberme-userid-qbe, so COUNT is already a learner count.
        $sql = "SELECT s.questionbankentryid,
                       COUNT(1) AS learners,
                       AVG(s.difficulty) AS meandifficulty,
                       AVG(s.stability) AS meanstability,
                       AVG(s.lapses) AS meanlapses,
                       SUM(s.reps) AS totalreps
                  FROM {rememberme_schedule} s
                 WHERE {$where}
              GROUP BY s.questionbankentryid";

        $aggregates = $DB->get_records_sql($sql, $params);
        $entries = $this->get_entries();

        $rows = [];
        foreach ($aggregates as $aggregate) {
            $entryid = (int)$aggregate->questionbankentryid;
            $meandifficulty = (float)$aggregate->meandifficulty;
            $learners = (int)$aggregate->learners;
            if (isset($entries[$entryid])) {
                $name = format_string($entries[$entryid]->name, true, ['context' => $this->context]);
            } else {
                // The item still has memory state but has left the pool: the
                // category was unbound, or every ready version was deleted.
                $name = s(get_string('questiongone', 'rememberme'));
            }
            $rows[] = [
                'questionname' => $name,
                'learners' => $learners,
                'meandifficulty' => format_float($meandifficulty, 2),
                'meandifficultyraw' => $meandifficulty,
                'meanlapses' => format_float((float)$aggregate->meanlapses, 2),
                'meanstability' => format_float((float)$aggregate->meanstability, 2),
                'reps' => (int)$aggregate->totalreps,
                'flagged' => $meandifficulty >= self::DIFFICULTY_FLAG && $learners >= self::FLAG_MIN_LEARNERS,
            ];
        }

        // Worst first: the top of this table is the teacher's work list.
        usort($rows, function (array $a, array $b): int {
            return $b['meandifficultyraw'] <=> $a['meandifficultyraw'];
        });
        foreach ($rows as $index => $unused) {
            unset($rows[$index]['meandifficultyraw']);
        }

        $flagged = 0;
        foreach ($rows as $row) {
            if ($row['flagged']) {
                $flagged++;
            }
        }

        return [
            'hasrows' => !empty($rows),
            'rows' => array_values($rows),
            'flaggedcount' => $flagged,
            'hasflagged' => $flagged > 0,
            'threshold' => format_float(self::DIFFICULTY_FLAG, 1),
            'minlearners' => self::FLAG_MIN_LEARNERS,
        ];
    }

    /**
     * Context for the per learner coverage and retention report, with the forecast.
     *
     * @return array Template context.
     */
    public function coverage_context(): array {
        global $DB;

        $floor = (float)$this->instance->stabilityfloor;
        $pooltotal = count($this->get_entries());

        $sql = "SELECT s.userid,
                       COUNT(1) AS seen,
                       SUM(CASE WHEN s.stability >= :floor THEN 1 ELSE 0 END) AS established,
                       AVG(s.stability) AS meanstability,
                       SUM(s.lapses) AS lapses,
                       SUM(s.reps) AS reps
                  FROM {rememberme_schedule} s
                 WHERE s.rememberme = :instanceid
              GROUP BY s.userid";

        $aggregates = $DB->get_records_sql($sql, [
            'instanceid' => $this->instance->id,
            'floor' => $floor,
        ]);

        $rows = [];
        foreach ($this->get_learners() as $userid => $user) {
            $aggregate = $aggregates[$userid] ?? null;
            $seen = $aggregate ? (int)$aggregate->seen : 0;
            $established = $aggregate ? (int)$aggregate->established : 0;
            $rows[] = [
                'learner' => $this->learner_name($user),
                'seen' => $seen,
                'poolseen' => $pooltotal > 0 ? format_float(100.0 * $seen / $pooltotal, 0) : '0',
                'established' => $established,
                'poolestablished' => $pooltotal > 0 ? format_float(100.0 * $established / $pooltotal, 0) : '0',
                'meanstability' => $aggregate ? format_float((float)$aggregate->meanstability, 2) : '0.00',
                'lapses' => $aggregate ? (int)$aggregate->lapses : 0,
                'started' => $seen > 0,
            ];
        }

        return [
            'hasrows' => !empty($rows),
            'rows' => $rows,
            'pooltotal' => $pooltotal,
            'stabilityfloor' => format_float($floor, 2),
            'forecast' => $this->forecast_context(),
        ];
    }

    /**
     * The review load forecast, as a count of items falling due on each of the coming days.
     *
     * The forecast is cohort wide and derived from the cached duedate column
     * rather than recomputed from stability. That column is written suspension
     * aware, so a holiday already shows up as a flat stretch here.
     *
     * @param int|null $now Current time, or null for the real one.
     * @return array Forecast context.
     */
    public function forecast_context(?int $now = null): array {
        global $DB;

        // The forecast is deliberately activity wide rather than group filtered.
        // It is an anonymous count of work arriving, carrying no individual
        // detail, and the load a teacher has to plan around is the whole
        // activity's load whichever group they happen to be looking at.
        $now = $now ?? time();
        $daystart = usergetmidnight($now);
        $rangeend = $daystart + self::FORECAST_DAYS * DAYSECS;

        $counts = array_fill(0, self::FORECAST_DAYS, 0);
        $recordset = $DB->get_recordset_select(
            'rememberme_schedule',
            'rememberme = :instanceid AND duedate >= :rangestart AND duedate < :rangeend',
            [
                'instanceid' => $this->instance->id,
                'rangestart' => $daystart,
                'rangeend' => $rangeend,
            ],
            '',
            'id, duedate'
        );
        foreach ($recordset as $record) {
            $offset = (int)floor(((int)$record->duedate - $daystart) / DAYSECS);
            if ($offset >= 0 && $offset < self::FORECAST_DAYS) {
                $counts[$offset]++;
            }
        }
        $recordset->close();

        $overdue = $DB->count_records_select(
            'rememberme_schedule',
            'rememberme = :instanceid AND duedate > 0 AND duedate < :daystart',
            ['instanceid' => $this->instance->id, 'daystart' => $daystart]
        );

        $max = max(1, max($counts), $overdue);
        $days = [];
        foreach ($counts as $offset => $count) {
            $days[] = [
                'label' => s(userdate($daystart + $offset * DAYSECS, get_string('strftimedateshort', 'langconfig'))),
                'count' => $count,
                'width' => (int)round(100 * $count / $max),
            ];
        }

        return [
            'days' => $days,
            'overdue' => $overdue,
            'overduewidth' => (int)round(100 * $overdue / $max),
            'hasload' => $overdue > 0 || array_sum($counts) > 0,
        ];
    }

    /**
     * Context for the cohort band progression report.
     *
     * The reason column is the point of this report. A band reached by
     * backstop means the learner never met the threshold and the syllabus was
     * handed to them anyway, which is the one row a teacher should act on.
     *
     * @return array Template context.
     */
    public function bands_context(): array {
        global $DB;

        $bandcount = $this->scheduler->get_pool()->get_band_count();
        $states = $DB->get_records('rememberme_bandstate', ['rememberme' => $this->instance->id], '', '*');
        $bystate = [];
        foreach ($states as $state) {
            $bystate[(int)$state->userid] = $state;
        }

        $dateformat = get_string('strftimedatefullshort', 'langconfig');
        $rows = [];
        $backstopped = 0;
        foreach ($this->get_learners() as $userid => $user) {
            $state = $bystate[$userid] ?? null;
            $reason = $state ? (string)$state->reason : bands::REASON_NONE;
            $isbackstop = ($reason === bands::REASON_BACKSTOP);
            if ($isbackstop) {
                $backstopped++;
            }
            $rows[] = [
                'learner' => $this->learner_name($user),
                'bandlevel' => $state ? (int)$state->bandlevel : 0,
                'started' => (bool)$state,
                'reason' => s($this->reason_label($reason)),
                'isbackstop' => $isbackstop,
                'bandsince' => $state && $state->bandsince
                    ? s(userdate((int)$state->bandsince, $dateformat))
                    : '',
                'firstsession' => $state && $state->firstsession
                    ? s(userdate((int)$state->firstsession, $dateformat))
                    : '',
            ];
        }

        return [
            'hasrows' => !empty($rows),
            'rows' => $rows,
            'bandcount' => $bandcount,
            'backstopped' => $backstopped,
            'hasbackstopped' => $backstopped > 0,
        ];
    }

    /**
     * The human readable label for a band unlock reason.
     *
     * @param string $reason One of the bands class REASON constants.
     * @return string The localised label, unescaped.
     */
    protected function reason_label(string $reason): string {
        $known = [
            bands::REASON_NONE,
            bands::REASON_TIME,
            bands::REASON_MASTERY,
            bands::REASON_BACKSTOP,
            bands::REASON_SUSPENSION_LIMIT,
        ];
        if (!in_array($reason, $known, true)) {
            // A reason written by a future version of the plugin, or by hand.
            return $reason;
        }
        return get_string('bandreason_' . $reason, 'rememberme');
    }

    /**
     * Context for the weekly completion matrix.
     *
     * @return array Template context.
     */
    public function weeks_context(): array {
        global $DB;

        $weekcalc = $this->scheduler->get_weeks();
        $activeweeks = max(0, (int)$this->instance->activeweeks);

        $columns = [];
        for ($week = 1; $week <= $activeweeks; $week++) {
            $columns[] = [
                'weekno' => $week,
                'label' => s(get_string('weekno', 'rememberme', $week)),
                'suspended' => $weekcalc->is_week_suspended($week),
            ];
        }

        $records = $DB->get_records('rememberme_weeks', ['rememberme' => $this->instance->id], 'weekno ASC');
        $byuser = [];
        foreach ($records as $record) {
            $byuser[(int)$record->userid][(int)$record->weekno] = $record;
        }

        $rows = [];
        foreach ($this->get_learners() as $userid => $user) {
            $cells = [];
            $gracetotal = 0.0;
            foreach ($columns as $column) {
                $week = $column['weekno'];
                $record = $byuser[$userid][$week] ?? null;
                // A week is out of the denominator if the course wide calendar
                // says so, or if the learner's own stored row was marked
                // suspended when it was scored.
                $suspended = $column['suspended'] || ($record && !empty($record->suspended));
                $grace = $record ? (float)$record->graceapplied : 0.0;
                $gracetotal += $grace;
                $cells[] = [
                    'hasrecord' => (bool)$record,
                    'suspended' => $suspended,
                    'fraction' => $record ? format_float((float)$record->fraction, 2) : '',
                    'completed' => $record ? (int)$record->completed : 0,
                    'target' => $record ? (int)$record->snapshottarget : 0,
                    'hasgrace' => $grace > 0,
                    'grace' => format_float($grace, 2),
                ];
            }
            $rows[] = [
                'learner' => $this->learner_name($user),
                'cells' => $cells,
                'gracetotal' => format_float($gracetotal, 2),
            ];
        }

        return [
            'hasrows' => !empty($rows) && !empty($columns),
            'columns' => $columns,
            'rows' => $rows,
        ];
    }
}
