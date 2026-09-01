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
use mod_rememberme\local\fsrs\rating;

/**
 * Tests for week boundaries, the frozen target, and final grading.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\weeks
 */
final class weeks_test extends \basic_testcase {
    /** @var int Fixed base time so tests never depend on the wall clock. */
    protected const BASE = 1750000000;

    /**
     * Build a weeks helper with no suspensions.
     *
     * @param int $activeweeks Number of active weeks.
     * @param array $windows Suspension windows.
     * @return weeks The helper.
     */
    protected function make_weeks(int $activeweeks = 15, array $windows = []): weeks {
        return new weeks(self::BASE, $activeweeks, new effective_time($windows));
    }

    /**
     * Week numbering is course wide and starts at one.
     */
    public function test_week_numbering(): void {
        $weeks = $this->make_weeks();
        $this->assertSame(0, $weeks->week_for(self::BASE - 1));
        $this->assertSame(1, $weeks->week_for(self::BASE));
        $this->assertSame(1, $weeks->week_for(self::BASE + WEEKSECS - 1));
        $this->assertSame(2, $weeks->week_for(self::BASE + WEEKSECS));
        $this->assertSame(15, $weeks->week_for(self::BASE + 14 * WEEKSECS));
    }

    /**
     * Week bounds are contiguous and a week long.
     */
    public function test_week_bounds(): void {
        $weeks = $this->make_weeks();
        [$start, $end] = $weeks->week_bounds(3);
        $this->assertSame(self::BASE + 2 * WEEKSECS, $start);
        $this->assertSame($end - $start, WEEKSECS);

        [$nextstart] = $weeks->week_bounds(4);
        $this->assertSame($end, $nextstart, 'weeks must be contiguous');
    }

    /**
     * A week with nothing due scores automatically.
     *
     * A learner is never penalised for being on top of their reviews.
     */
    public function test_empty_week_scores_full_marks(): void {
        $this->assertEqualsWithDelta(1.0, weeks::score_week(0, 0), 1.0E-9);
    }

    /**
     * Partial completion earns a proportional fraction, not zero.
     */
    public function test_partial_credit(): void {
        $this->assertEqualsWithDelta(0.6, weeks::score_week(20, 12), 1.0E-9);
        $this->assertEqualsWithDelta(0.0, weeks::score_week(20, 0), 1.0E-9);
    }

    /**
     * Doing more than the target does not score above 1.0.
     */
    public function test_score_is_capped_at_one(): void {
        $this->assertEqualsWithDelta(1.0, weeks::score_week(20, 40), 1.0E-9);
    }

    /**
     * A week more than half suspended is removed from the denominator.
     */
    public function test_mostly_suspended_week_is_excluded(): void {
        // Five of week two's seven days are suspended.
        $weekstart = self::BASE + WEEKSECS;
        $weeks = $this->make_weeks(4, [
            ['timestart' => $weekstart, 'timeend' => $weekstart + 5 * DAYSECS],
        ]);

        $this->assertFalse($weeks->is_week_suspended(1));
        $this->assertTrue($weeks->is_week_suspended(2));
        $this->assertSame([1, 3, 4], $weeks->graded_weeks());
    }

    /**
     * A week less than half suspended still counts at full target.
     */
    public function test_barely_suspended_week_still_counts(): void {
        $weekstart = self::BASE + WEEKSECS;
        $weeks = $this->make_weeks(3, [
            ['timestart' => $weekstart, 'timeend' => $weekstart + 2 * DAYSECS],
        ]);
        $this->assertFalse($weeks->is_week_suspended(2));
        $this->assertSame([1, 2, 3], $weeks->graded_weeks());
    }

    /**
     * A fifteen week course with a two week break is graded out of thirteen.
     */
    public function test_two_week_break_is_removed_from_the_denominator(): void {
        $breakstart = self::BASE + 5 * WEEKSECS;
        $weeks = $this->make_weeks(15, [
            ['timestart' => $breakstart, 'timeend' => $breakstart + 2 * WEEKSECS],
        ]);
        $this->assertCount(13, $weeks->graded_weeks());
    }

    /**
     * Grading is adherence: a perfect record is 1.0 and grace is untouched.
     */
    public function test_final_proportion_of_a_perfect_record(): void {
        $result = weeks::final_proportion([1 => 1.0, 2 => 1.0, 3 => 1.0], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['proportion'], 1.0E-9);
        $this->assertEqualsWithDelta(0.0, $result['gracespent'], 1.0E-9);
    }

    /**
     * Grace is applied across the whole course, not week by week.
     */
    public function test_final_proportion_applies_grace(): void {
        // Without grace this is (1.0 + 0.0 + 1.0) / 3 = 0.667.
        $result = weeks::final_proportion([1 => 1.0, 2 => 0.0, 3 => 1.0], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['proportion'], 1.0E-9);
        $this->assertEqualsWithDelta(1.0, $result['gracespent'], 1.0E-9);
        $this->assertCount(1, $result['gracelog']);
    }

    /**
     * A course with every week suspended does not divide by zero.
     */
    public function test_fully_suspended_course_does_not_divide_by_zero(): void {
        $result = weeks::final_proportion([], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['proportion'], 1.0E-9);
    }

    /**
     * Streaks count consecutive cleared weeks and break on a shortfall.
     */
    public function test_streak_counting(): void {
        $fractions = [1 => 1.0, 2 => 1.0, 3 => 0.5, 4 => 1.0, 5 => 1.0];
        $this->assertSame(2, weeks::streak($fractions, 5));
        $this->assertSame(0, weeks::streak($fractions, 3));
        $this->assertSame(2, weeks::streak($fractions, 2));
    }

    /**
     * A suspended week neither breaks nor extends a streak.
     */
    public function test_suspended_week_does_not_break_a_streak(): void {
        // Week 3 is absent from the array because it was suspended.
        $fractions = [1 => 1.0, 2 => 1.0, 4 => 1.0];
        $this->assertSame(3, weeks::streak($fractions, 4));
    }

    /**
     * The snapshot rule is not bookkeeping pedantry: the alternative is broken.
     *
     * This simulates a genuinely diligent learner across one week using the real
     * memory model rather than invented numbers. The learner logs in every day
     * and answers everything the scheduler puts in front of them, but they are
     * human and get roughly one in four wrong. Those lapses are the mechanism
     * that matters: a lapsed item's stability drops, so it falls due again
     * within hours, inside the same week.
     *
     * Under the snapshot rule the learner scores 1.00, because the target was
     * fixed when the week began. Under a rolling denominator that grows every
     * time an answered item comes due again, they cannot reach 1.00 however much
     * they do: each answer breeds a further review before the week is out, and
     * the finish line recedes as they approach it.
     *
     * @return void
     */
    public function test_diligent_learner_scores_full_marks_under_snapshot_but_not_rolling(): void {
        $engine = new engine();
        $clock = new effective_time([]);
        $weekstart = self::BASE;
        $weekend = $weekstart + WEEKSECS;
        $itemcount = 30;

        $items = [];
        for ($i = 0; $i < $itemcount; $i++) {
            $items[$i] = ['state' => null, 'due' => $weekstart, 'lastreviewed' => $weekstart];
        }

        // The target is frozen now, at the start of the week, and never revisited.
        $snapshottarget = 0;
        foreach ($items as $item) {
            if ($item['due'] <= $weekstart) {
                $snapshottarget++;
            }
        }
        $this->assertSame($itemcount, $snapshottarget);

        // The learner logs in once a day, every day, and clears everything the
        // scheduler shows them. That is the intended habit for this activity:
        // visited briefly and often, not camped on.
        //
        // A first attempt that fails seeds a stability of about 0.4 days, so a
        // question got wrong comes back roughly ten hours later, inside the same
        // day. That is what leaves work outstanding when the week closes, and it
        // is the mechanism a rolling denominator charges the learner for.
        $answered = 0;
        $step = DAYSECS;
        $passes = (int)(WEEKSECS / $step);

        for ($pass = 0; $pass < $passes; $pass++) {
            $now = $weekstart + (int)($pass * $step);
            foreach ($items as $i => $item) {
                if ($item['due'] > $now) {
                    continue;
                }

                // A fixed pattern rather than randomness, so the test is
                // deterministic: every fourth answer is wrong.
                $rating = (($answered % 4) === 3) ? rating::AGAIN : rating::GOOD;

                if ($item['state'] === null) {
                    $state = $engine->seed($rating);
                } else {
                    $elapsed = $clock->effective_days($item['lastreviewed'], $now);
                    $state = $engine->update($item['state'], $rating, $elapsed);
                }

                $interval = $engine->interval_for($state->get_stability());
                $due = (int)round($now + $interval * DAYSECS);

                $items[$i] = [
                    'state' => $state,
                    'lastreviewed' => $now,
                    'due' => $due,
                ];
                $answered++;
            }
        }

        // Work that came due again inside the week and is still outstanding when
        // the week closes. Under a rolling rule this counts against the learner.
        $outstanding = 0;
        foreach ($items as $item) {
            if ($item['due'] <= $weekend) {
                $outstanding++;
            }
        }

        // Under the snapshot rule the learner scores full marks, which is the
        // honest answer: they did everything that was asked of them.
        $snapshotfraction = weeks::score_week($snapshottarget, $answered);
        $this->assertEqualsWithDelta(
            1.0,
            $snapshotfraction,
            1.0E-9,
            'a learner who answered everything asked of them must score 1.00'
        );

        // Sanity checks that the simulation actually exercised the mechanism,
        // rather than passing because nothing happened.
        $this->assertGreaterThan(
            $itemcount,
            $answered,
            'the simulation is only meaningful if items came due again inside the week'
        );
        $this->assertGreaterThan(
            0,
            $outstanding,
            'the simulation is only meaningful if work remained outstanding at week end'
        );

        // Under a rolling denominator the same learner is punished for diligence.
        $rollingtarget = $answered + $outstanding;
        $rollingfraction = weeks::score_week($rollingtarget, $answered);

        $this->assertLessThan(
            1.0,
            $rollingfraction,
            'a rolling denominator punishes a learner who did everything asked of them'
        );
        $this->assertGreaterThan(
            $rollingfraction,
            $snapshotfraction,
            'snapshot must score the diligent learner higher than rolling'
        );
    }

    /**
     * A lapsed item returns inside the same week, which is what breaks rolling.
     *
     * This isolates the mechanism the test above relies on, so that a future
     * change to the memory model cannot quietly turn that test vacuous.
     *
     * @return void
     */
    public function test_a_lapse_brings_an_item_back_within_the_week(): void {
        $engine = new engine();

        // An established item that the learner has just got wrong.
        $lapsed = $engine->update(new \mod_rememberme\local\fsrs\memory_state(20.0, 5.0), rating::AGAIN, 20.0);
        $interval = $engine->interval_for($lapsed->get_stability());

        $this->assertLessThan(
            7.0,
            $interval,
            'a lapsed item must come back inside the same week, or the queue never recovers'
        );
    }

    /**
     * A genuine partial effort reads honestly under the snapshot rule.
     *
     * Sixty per cent of the week's queue should read as roughly 0.6, which is
     * the property a learner facing progress indicator depends on.
     */
    public function test_partial_effort_reads_honestly(): void {
        $this->assertEqualsWithDelta(0.6, weeks::score_week(30, 18), 1.0E-9);
    }
}
