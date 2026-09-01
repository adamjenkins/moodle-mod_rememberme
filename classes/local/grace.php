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
 * Grace credit: a pool of fractional credit, not a count of whole weeks.
 *
 * Grace tops a week's fraction up toward 1.0 and costs exactly the size of the
 * gap it fills. A missed week costs 1.0 to rescue; a week scored 0.9 costs 0.1.
 * Charging a whole week to close a 0.1 gap would be such poor value that
 * learners would hoard grace rather than use it, which defeats its purpose.
 *
 * Allocation happens at final grade calculation, never week by week, so the
 * whole course can be optimised at once. The optimum is simple: since every
 * week's point is worth the same, filling the cheapest gaps first maximises the
 * number of weeks brought to 1.0.
 *
 * This class is pure arithmetic with no Moodle dependency.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class grace {
    /** @var float Tolerance below which a gap is not worth recording. */
    protected const EPSILON = 1.0E-6;

    /**
     * Allocate a grace balance across a course's weekly fractions.
     *
     * @param array $fractions Weekly fractions keyed by week number, each 0 to 1.
     * @param float $balance The learner's available grace balance.
     * @return array Result with keys fractions, spent, remaining and log.
     */
    public static function allocate(array $fractions, float $balance): array {
        $balance = max(0.0, $balance);
        $remaining = $balance;
        $result = [];
        $gaps = [];

        foreach ($fractions as $week => $fraction) {
            $fraction = min(1.0, max(0.0, (float)$fraction));
            $result[$week] = $fraction;
            $gap = 1.0 - $fraction;
            if ($gap > self::EPSILON) {
                $gaps[$week] = $gap;
            }
        }

        // Cheapest gaps first: every week's point is worth the same, so this
        // maximises how many weeks reach 1.0 for a given balance.
        asort($gaps);

        $log = [];
        foreach ($gaps as $week => $gap) {
            if ($remaining <= self::EPSILON) {
                break;
            }
            if ($gap > $remaining + self::EPSILON) {
                // Not enough left to close this one. Skip it rather than part
                // filling: a part filled week earns no extra point, so spending
                // here would waste balance that a later cheaper gap could use.
                continue;
            }
            $remaining -= $gap;
            $result[$week] = 1.0;
            $log[] = (object)[
                'week' => $week,
                'amount' => $gap,
                'fractionbefore' => 1.0 - $gap,
                'fractionafter' => 1.0,
            ];
        }

        return [
            'fractions' => $result,
            'spent' => $balance - $remaining,
            'remaining' => $remaining,
            'log' => $log,
        ];
    }

    /**
     * Cap an earned grace balance.
     *
     * Earned grace must be bounded, or a determined learner farms a long break
     * and buys back an entire absent semester. The ceiling is the initial grant,
     * so a 1.0 grant can rise to at most 2.0 in total.
     *
     * @param float $initialgrant The grant configured for the activity.
     * @param float $earned Grace earned through voluntary work.
     * @return float The usable total balance.
     */
    public static function cap_balance(float $initialgrant, float $earned): float {
        $initialgrant = max(0.0, $initialgrant);
        $earned = max(0.0, $earned);
        return $initialgrant + min($initialgrant, $earned);
    }

    /**
     * Grace earned by voluntary work during a suspension window.
     *
     * Rate limited against a session's worth of items rather than raw question
     * count, so the incentive is returning during the break rather than
     * answering as many questions as possible, which would degrade the
     * correctness signal the whole model depends on.
     *
     * Both new draws and reviews count, because the paused clock means little is
     * genuinely due during a break.
     *
     * @param int $itemsanswered Items answered inside suspension windows.
     * @param int $sessionsize The activity's configured session size.
     * @param float $persession Grace earned per session's worth of items.
     * @return float Grace earned, before capping.
     */
    public static function earned_from_work(int $itemsanswered, int $sessionsize, float $persession = 0.25): float {
        if ($itemsanswered <= 0 || $sessionsize <= 0 || $persession <= 0.0) {
            return 0.0;
        }
        return ($itemsanswered / $sessionsize) * $persession;
    }
}
