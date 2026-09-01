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

/**
 * Course wide week boundaries and the frozen weekly target.
 *
 * Two rules here are load bearing.
 *
 * First, week boundaries are course wide: a fixed weekday and time, identical
 * for every learner regardless of when they enrolled. This differs on purpose
 * from Mode A band unlocking, which runs from each learner's first session.
 * Unlocking is a per learner pacing mechanism; grading is a calendar.
 *
 * Second, and this is the one that is broken if done the obvious way, the
 * week's denominator is frozen when the week starts. It is the items due at
 * that moment plus the new items the learner may draw that week. Items that
 * become due again during the week, because the learner answered them and the
 * interval was short, do not enlarge that week's target; they roll into next
 * week's snapshot.
 *
 * Under a rolling denominator that recomputes as items come due, a learner who
 * answers everything asked of them can never reach 100 per cent, because each
 * answer breeds a further review inside the same week. The finish line recedes
 * as they approach it. A diligent learner doing every scheduled item scores
 * 1.00 under the snapshot rule and around 0.60 under a rolling one,
 * indefinitely. See the test suite, which asserts exactly that.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class weeks {
    /** @var int A week that is more than this proportion suspended counts as fully suspended. */
    public const SUSPENDED_WEEK_THRESHOLD = 0.5;

    /** @var int Course wide start of week one, unix timestamp. */
    protected int $coursestart;

    /** @var int Number of active weeks in the course. */
    protected int $activeweeks;

    /** @var effective_time Suspension aware clock. */
    protected effective_time $clock;

    /**
     * Constructor.
     *
     * @param int $coursestart Unix timestamp of the start of week one, course wide.
     * @param int $activeweeks How many weeks the course runs for.
     * @param effective_time $clock The suspension aware clock.
     */
    public function __construct(int $coursestart, int $activeweeks, effective_time $clock) {
        $this->coursestart = $coursestart;
        $this->activeweeks = max(0, $activeweeks);
        $this->clock = $clock;
    }

    /**
     * The week number containing a moment.
     *
     * Week numbering starts at 1. A moment before the course start returns 0,
     * and a moment after the last active week returns activeweeks plus one.
     *
     * @param int $time Unix timestamp.
     * @return int The week number.
     */
    public function week_for(int $time): int {
        if ($time < $this->coursestart) {
            return 0;
        }
        return (int)floor(($time - $this->coursestart) / WEEKSECS) + 1;
    }

    /**
     * The start and end timestamps of a week.
     *
     * @param int $week The week number, 1 based.
     * @return array Two element list of start and end unix timestamps.
     */
    public function week_bounds(int $week): array {
        $start = $this->coursestart + ($week - 1) * WEEKSECS;
        return [$start, $start + WEEKSECS];
    }

    /**
     * Whether a week is removed from the grading denominator entirely.
     *
     * A week more than half suspended counts as fully suspended. The design
     * offers proportional scaling as an alternative; this rule was chosen
     * because a learner can be told "that week did not count" and understand it,
     * where a scaled target of 13.4 items invites a dispute.
     *
     * @param int $week The week number.
     * @return bool True if the week is excluded from grading.
     */
    public function is_week_suspended(int $week): bool {
        [$start, $end] = $this->week_bounds($week);
        return $this->clock->suspended_proportion($start, $end) > self::SUSPENDED_WEEK_THRESHOLD;
    }

    /**
     * All week numbers that count toward the grade.
     *
     * @return array List of week numbers.
     */
    public function graded_weeks(): array {
        $weeks = [];
        for ($week = 1; $week <= $this->activeweeks; $week++) {
            if (!$this->is_week_suspended($week)) {
                $weeks[] = $week;
            }
        }
        return $weeks;
    }

    /**
     * Score one week from its frozen target and the work actually done.
     *
     * A week with nothing due scores 1.0 automatically: a learner is never
     * penalised for being on top of their reviews.
     *
     * @param int $target The snapshot target, frozen at the start of the week.
     * @param int $completed Items completed during the week.
     * @return float Fraction between 0 and 1.
     */
    public static function score_week(int $target, int $completed): float {
        if ($target <= 0) {
            return 1.0;
        }
        return min(1.0, max(0.0, $completed / $target));
    }

    /**
     * The final course grade as a proportion, after grace has been allocated.
     *
     * Grading is adherence, never accuracy. No fraction earned on any individual
     * question reaches this calculation, because grading accuracy would
     * contaminate the correctness signal the scheduler depends on by rewarding
     * guess avoidance and answer lookup.
     *
     * @param array $weeklyfractions Fractions keyed by week number, graded weeks only.
     * @param float $gracebalance The learner's grace balance.
     * @return array Result with keys proportion, fractions, gracespent and gracelog.
     */
    public static function final_proportion(array $weeklyfractions, float $gracebalance): array {
        if (empty($weeklyfractions)) {
            return [
                'proportion' => 1.0,
                'fractions' => [],
                'gracespent' => 0.0,
                'gracelog' => [],
            ];
        }

        $allocated = grace::allocate($weeklyfractions, $gracebalance);
        $total = array_sum($allocated['fractions']);

        return [
            'proportion' => $total / count($allocated['fractions']),
            'fractions' => $allocated['fractions'],
            'gracespent' => $allocated['spent'],
            'gracelog' => $allocated['log'],
        ];
    }

    /**
     * The learner's current streak of consecutive cleared weeks.
     *
     * Progress feedback is a personal streak, visible only to that learner. A
     * cohort leaderboard is deliberately not offered: because the due queue is
     * capped and driven by the learner's own memory state, the learner with the
     * most reviews is the one with the most lapses, so a leaderboard would rank
     * learners roughly inversely to how well they know the material.
     *
     * @param array $weeklyfractions Fractions keyed by week number, in week order.
     * @param int $uptoweek Count the streak ending at this week.
     * @return int Consecutive weeks cleared.
     */
    public static function streak(array $weeklyfractions, int $uptoweek): int {
        $streak = 0;
        for ($week = $uptoweek; $week >= 1; $week--) {
            if (!isset($weeklyfractions[$week])) {
                // A suspended week neither breaks nor extends a streak.
                continue;
            }
            if ($weeklyfractions[$week] >= 1.0) {
                $streak++;
            } else {
                break;
            }
        }
        return $streak;
    }
}
