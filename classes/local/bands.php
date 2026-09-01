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
 * Band unlocking: when a learner earns access to the next question category.
 *
 * The question pool is not flat. A teacher binds an ordered sequence of
 * categories, and new items are drawn only from the currently unlocked band.
 * Unlocking is per learner, so two students in the same course can be on
 * different bands.
 *
 * Crucially, unlocking gates *introduction*, not revision. Once an item has been
 * seen it competes for review on memory state alone, whichever band it came
 * from, and a band once unlocked stays unlocked.
 *
 * This class is the decision logic only, expressed over primitives so it can be
 * unit tested without a database. Reading the learner's state and writing the
 * result is the scheduler's job.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bands {
    /** @var int One band unlocks per configured interval, from the learner's first session. */
    public const MODE_TIME = 0;

    /** @var int The next band unlocks when the current one is sufficiently established. */
    public const MODE_MASTERY = 1;

    /**
     * @var int The next band unlocks once nothing in the current one is unseen.
     *
     * Coverage rather than mastery: the learner has met every question in the
     * band at least once, whether or not they answered any of them well. It
     * paces introduction without asking anybody to prove anything, which suits a
     * course where the point is to have seen the whole syllabus.
     */
    public const MODE_EXHAUSTED = 2;

    /** @var string The learner had not earned an unlock. */
    public const REASON_NONE = 'none';

    /** @var string Unlocked because the configured interval elapsed. */
    public const REASON_TIME = 'time';

    /** @var string Unlocked by meeting the mastery threshold. */
    public const REASON_MASTERY = 'mastery';

    /** @var string Unlocked because nothing in the band was left unseen. */
    public const REASON_EXHAUSTED = 'exhausted';

    /** @var string Unlocked by the backstop, having stalled on one band. */
    public const REASON_BACKSTOP = 'backstop';

    /** @var string Held back because a band has already unlocked in this suspension window. */
    public const REASON_SUSPENSION_LIMIT = 'suspensionlimit';

    /**
     * Decide the band a learner should be on, in time based mode.
     *
     * Counted from the learner's first session rather than from course start, so
     * a learner who begins in week three is on their own clock and is not handed
     * four bands at once. Runs on effective time, so a band does not unlock
     * during a break the learner is not working through.
     *
     * @param float $effectivedayssincefirst Effective days since the learner's first session.
     * @param float $intervaldays Days between unlocks.
     * @param int $bandcount How many bands the activity has.
     * @return int The band level earned, 1 based.
     */
    public static function level_for_time(
        float $effectivedayssincefirst,
        float $intervaldays,
        int $bandcount
    ): int {
        if ($bandcount <= 0) {
            return 0;
        }
        if ($intervaldays <= 0.0) {
            return $bandcount;
        }
        $earned = (int)floor(max(0.0, $effectivedayssincefirst) / $intervaldays) + 1;
        return max(1, min($bandcount, $earned));
    }

    /**
     * Whether the current band meets the mastery threshold.
     *
     * Unseen items count against the threshold: an item with no schedule record
     * has no stability, so it sits in the denominator and cannot clear the
     * floor. A band therefore cannot qualify until most of it has actually been
     * attempted, which is the intent.
     *
     * The proportion must be well under 100 per cent, or a handful of
     * persistently lapsing items would hold a learner on one band indefinitely.
     *
     * @param array $stabilities Stability per item in the band; use null or omit for unseen items.
     * @param int $banditemcount Total items in the band, including unseen ones.
     * @param float $stabilityfloor Stability in days at which an item counts as established.
     * @param float $proportion Share of the band that must be established, 0 to 1.
     * @return bool True if the threshold is met.
     */
    public static function meets_mastery(
        array $stabilities,
        int $banditemcount,
        float $stabilityfloor,
        float $proportion
    ): bool {
        if ($banditemcount <= 0) {
            // An empty band cannot hold anybody up.
            return true;
        }
        $established = 0;
        foreach ($stabilities as $stability) {
            if ($stability !== null && $stability >= $stabilityfloor) {
                $established++;
            }
        }
        return ($established / $banditemcount) >= $proportion;
    }

    /**
     * Decide whether a learner unlocks the next band right now.
     *
     * Evaluated at session build time rather than on a cron schedule, so an
     * unlock takes effect in the session where it is earned rather than the
     * following day.
     *
     * @param int $mode One of MODE_TIME or MODE_MASTERY.
     * @param int $currentlevel The band the learner is on, 1 based.
     * @param int $bandcount How many bands the activity has.
     * @param array $context Evaluation inputs; see the keys read below.
     * @return array Two element list of the new level and the reason constant.
     */
    public static function evaluate(int $mode, int $currentlevel, int $bandcount, array $context): array {
        if ($bandcount <= 0) {
            return [0, self::REASON_NONE];
        }
        if ($currentlevel >= $bandcount) {
            // Already on the final band; nothing left to unlock.
            return [$currentlevel, self::REASON_NONE];
        }

        $wanted = $currentlevel;
        $reason = self::REASON_NONE;

        if ($mode === self::MODE_EXHAUSTED) {
            // Nothing left to meet for the first time. Note this asks about
            // unseen items only: an item answered once counts as seen however
            // badly it went, because revision is not what this gate is about.
            $banditemcount = (int)($context['banditemcount'] ?? 0);
            $unseen = (int)($context['unseeninband'] ?? 0);
            if ($banditemcount > 0 && $unseen === 0) {
                $wanted = $currentlevel + 1;
                $reason = self::REASON_EXHAUSTED;
            }
        } else if ($mode === self::MODE_TIME) {
            $earned = self::level_for_time(
                (float)($context['effectivedayssincefirst'] ?? 0.0),
                (float)($context['intervaldays'] ?? 7.0),
                $bandcount
            );
            if ($earned > $currentlevel) {
                $wanted = $earned;
                $reason = self::REASON_TIME;
            }
        } else {
            $meets = self::meets_mastery(
                $context['stabilities'] ?? [],
                (int)($context['banditemcount'] ?? 0),
                (float)($context['stabilityfloor'] ?? 14.0),
                (float)($context['proportion'] ?? 0.7)
            );
            if ($meets) {
                $wanted = $currentlevel + 1;
                $reason = self::REASON_MASTERY;
            } else {
                // The backstop makes mastery mode a pacing preference rather than
                // a hard gate. Without it a struggling learner can sit on band one
                // for the whole course and never see most of the syllabus, which
                // is the worst outcome for the learner who most needs coverage.
                $onband = (float)($context['effectivedaysonband'] ?? 0.0);
                $backstop = (float)($context['backstopdays'] ?? 21.0);
                if ($backstop > 0.0 && $onband >= $backstop) {
                    $wanted = $currentlevel + 1;
                    $reason = self::REASON_BACKSTOP;
                }
            }
        }

        if ($wanted <= $currentlevel) {
            return [$currentlevel, self::REASON_NONE];
        }

        // At most one band per suspension window, however long the window is and
        // however much work is done inside it.
        //
        // New items drawn during a break schedule normally: the paused clock
        // defers existing reviews, but it cannot defer an item that did not exist
        // yet. A learner who unlocked three bands and drew ninety new items over a
        // fortnight would meet all ninety coming due in the first days of term,
        // recreating exactly the wall that suspension windows exist to prevent.
        if (!empty($context['insuspension'])) {
            if (!empty($context['unlockedinthiswindow'])) {
                return [$currentlevel, self::REASON_SUSPENSION_LIMIT];
            }
            $wanted = min($wanted, $currentlevel + 1);
        }

        return [min($bandcount, $wanted), $reason];
    }
}
