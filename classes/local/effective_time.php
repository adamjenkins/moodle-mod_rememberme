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
 * Time as the scheduler experiences it, with suspension windows removed.
 *
 * A teacher can declare suspension windows: semester breaks, reading weeks,
 * public holidays. During one of those, the scheduling clock must not tick. If
 * it did, every item would fall due across a two week break and the learner
 * would return to a wall of overdue reviews created purely by a holiday somebody
 * else declared, producing a spike of lapses that corrupts the difficulty
 * estimates for the whole cohort.
 *
 * The naive fix is to batch shift stored due dates when a window ends. That is
 * wrong three ways: it breaks for anyone who enrols mid window, it is
 * unrecoverable if the teacher later edits the window, and it silently corrupts
 * the elapsed times already written to the review log.
 *
 * So instead, suspended time simply does not exist. Every scheduling
 * calculation in this plugin routes through this class, and there is no code
 * path that takes a raw wall clock difference for scheduling purposes. That
 * makes windows retroactively editable and correct for mid window enrolments,
 * at the cost of a slightly more expensive due calculation.
 *
 * This class has no Moodle dependency and is unit testable on its own.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class effective_time {
    /** @var array Merged, sorted suspension windows as timestart and timeend pairs. */
    protected array $windows;

    /**
     * Constructor.
     *
     * @param array $windows Objects or arrays carrying timestart and timeend unix timestamps.
     */
    public function __construct(array $windows = []) {
        $this->windows = self::normalise($windows);
    }

    /**
     * Sort and merge overlapping windows.
     *
     * Merging matters: two windows that overlap would otherwise have their
     * intersection subtracted twice, so a learner would gain effective time that
     * never existed and items would come due early.
     *
     * @param array $windows Raw windows.
     * @return array Sorted, merged, non-overlapping windows.
     */
    protected static function normalise(array $windows): array {
        $clean = [];
        foreach ($windows as $window) {
            $start = (int)(is_object($window) ? ($window->timestart ?? 0) : ($window['timestart'] ?? 0));
            $end = (int)(is_object($window) ? ($window->timeend ?? 0) : ($window['timeend'] ?? 0));
            if ($end > $start) {
                $clean[] = ['timestart' => $start, 'timeend' => $end];
            }
        }
        if (empty($clean)) {
            return [];
        }
        usort($clean, function ($a, $b) {
            return $a['timestart'] <=> $b['timestart'];
        });

        $merged = [];
        $current = array_shift($clean);
        foreach ($clean as $window) {
            if ($window['timestart'] <= $current['timeend']) {
                $current['timeend'] = max($current['timeend'], $window['timeend']);
            } else {
                $merged[] = $current;
                $current = $window;
            }
        }
        $merged[] = $current;
        return $merged;
    }

    /**
     * Get the merged windows.
     *
     * @return array The windows.
     */
    public function get_windows(): array {
        return $this->windows;
    }

    /**
     * Total suspended seconds intersecting a span.
     *
     * @param int $from Start of the span, unix timestamp.
     * @param int $to End of the span, unix timestamp.
     * @return int Suspended seconds within the span.
     */
    public function suspended_overlap(int $from, int $to): int {
        if ($to <= $from) {
            return 0;
        }
        $total = 0;
        foreach ($this->windows as $window) {
            if ($window['timeend'] <= $from) {
                continue;
            }
            if ($window['timestart'] >= $to) {
                break;
            }
            $overlapstart = max($from, $window['timestart']);
            $overlapend = min($to, $window['timeend']);
            if ($overlapend > $overlapstart) {
                $total += $overlapend - $overlapstart;
            }
        }
        return $total;
    }

    /**
     * Elapsed seconds with suspended time removed.
     *
     * effective_elapsed(t1, t2) = (t2 - t1) - suspended_overlap(t1, t2)
     *
     * @param int $from Start, unix timestamp.
     * @param int $to End, unix timestamp.
     * @return int Effective elapsed seconds, never negative.
     */
    public function effective_elapsed(int $from, int $to): int {
        if ($to <= $from) {
            return 0;
        }
        return max(0, ($to - $from) - $this->suspended_overlap($from, $to));
    }

    /**
     * Elapsed time in days with suspended time removed.
     *
     * This is the figure the memory model consumes.
     *
     * @param int $from Start, unix timestamp.
     * @param int $to End, unix timestamp.
     * @return float Effective elapsed days.
     */
    public function effective_days(int $from, int $to): float {
        return $this->effective_elapsed($from, $to) / DAYSECS;
    }

    /**
     * The wall clock moment at which a given amount of effective time will have passed.
     *
     * This is the inverse of effective_elapsed, and it is what turns a computed
     * interval into a stored due date. An item due in three effective days that
     * meets a week long break becomes due on the day the learner returns, not
     * four days before it.
     *
     * @param int $from Start, unix timestamp.
     * @param float $seconds Effective seconds to advance by.
     * @return int Wall clock unix timestamp.
     */
    public function add_effective_seconds(int $from, float $seconds): int {
        $remaining = max(0.0, $seconds);
        $cursor = $from;

        foreach ($this->windows as $window) {
            if ($window['timeend'] <= $cursor) {
                continue;
            }
            if ($window['timestart'] > $cursor) {
                // Effective time runs normally up to the start of this window.
                $available = $window['timestart'] - $cursor;
                if ($remaining <= $available) {
                    return (int)round($cursor + $remaining);
                }
                $remaining -= $available;
                $cursor = $window['timestart'];
            }
            // Skip the suspended span entirely: no effective time passes inside it.
            $cursor = $window['timeend'];
        }

        return (int)round($cursor + $remaining);
    }

    /**
     * Whether a moment falls inside a suspension window.
     *
     * @param int $time Unix timestamp.
     * @return bool True if suspended.
     */
    public function is_suspended_at(int $time): bool {
        foreach ($this->windows as $window) {
            if ($time >= $window['timestart'] && $time < $window['timeend']) {
                return true;
            }
        }
        return false;
    }

    /**
     * The window containing a moment, if any.
     *
     * Used to enforce the rule that at most one band may unlock per suspension
     * window, however long the window is.
     *
     * @param int $time Unix timestamp.
     * @return array|null The window, or null if not suspended.
     */
    public function window_at(int $time): ?array {
        foreach ($this->windows as $window) {
            if ($time >= $window['timestart'] && $time < $window['timeend']) {
                return $window;
            }
        }
        return null;
    }

    /**
     * The proportion of a span that is suspended.
     *
     * Used by the weekly grading rule, where a week more than half suspended is
     * treated as fully suspended.
     *
     * @param int $from Start, unix timestamp.
     * @param int $to End, unix timestamp.
     * @return float Proportion between 0 and 1.
     */
    public function suspended_proportion(int $from, int $to): float {
        if ($to <= $from) {
            return 0.0;
        }
        return $this->suspended_overlap($from, $to) / ($to - $from);
    }
}
