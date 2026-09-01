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
 * Tests for the suspension aware clock.
 *
 * The headline case is the one the design warns about: an item due in three
 * days that meets a week long break must come due on the day the learner
 * returns, not four days before it.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\effective_time
 */
final class effective_time_test extends \basic_testcase {
    /** @var int An arbitrary fixed base time, so tests never depend on the clock. */
    protected const BASE = 1750000000;

    /**
     * With no windows, effective time is just wall clock time.
     */
    public function test_no_windows_means_wall_clock(): void {
        $clock = new effective_time([]);
        $this->assertSame(10 * DAYSECS, $clock->effective_elapsed(self::BASE, self::BASE + 10 * DAYSECS));
        $this->assertEqualsWithDelta(10.0, $clock->effective_days(self::BASE, self::BASE + 10 * DAYSECS), 1.0E-9);
    }

    /**
     * A window fully inside the span is subtracted.
     */
    public function test_window_inside_span_is_subtracted(): void {
        $clock = new effective_time([
            ['timestart' => self::BASE + 2 * DAYSECS, 'timeend' => self::BASE + 5 * DAYSECS],
        ]);
        $this->assertSame(
            7 * DAYSECS,
            $clock->effective_elapsed(self::BASE, self::BASE + 10 * DAYSECS)
        );
    }

    /**
     * A window partly overlapping the span contributes only its overlap.
     */
    public function test_partial_overlap_counts_only_the_overlap(): void {
        $clock = new effective_time([
            ['timestart' => self::BASE + 8 * DAYSECS, 'timeend' => self::BASE + 20 * DAYSECS],
        ]);
        // Span ends at day 10, so only 2 days of the window overlap.
        $this->assertSame(
            8 * DAYSECS,
            $clock->effective_elapsed(self::BASE, self::BASE + 10 * DAYSECS)
        );
    }

    /**
     * A window entirely outside the span changes nothing.
     */
    public function test_window_outside_span_is_ignored(): void {
        $clock = new effective_time([
            ['timestart' => self::BASE + 50 * DAYSECS, 'timeend' => self::BASE + 60 * DAYSECS],
        ]);
        $this->assertSame(
            10 * DAYSECS,
            $clock->effective_elapsed(self::BASE, self::BASE + 10 * DAYSECS)
        );
    }

    /**
     * Overlapping windows are merged, so their intersection is not double counted.
     *
     * Without merging, a learner would gain effective time that never existed
     * and items would come due early.
     */
    public function test_overlapping_windows_are_merged(): void {
        $clock = new effective_time([
            ['timestart' => self::BASE + 2 * DAYSECS, 'timeend' => self::BASE + 6 * DAYSECS],
            ['timestart' => self::BASE + 4 * DAYSECS, 'timeend' => self::BASE + 8 * DAYSECS],
        ]);
        $this->assertCount(1, $clock->get_windows());
        // The union is days 2 to 8, i.e. 6 suspended days out of 10.
        $this->assertSame(
            4 * DAYSECS,
            $clock->effective_elapsed(self::BASE, self::BASE + 10 * DAYSECS)
        );
    }

    /**
     * Adjacent windows merge into one span.
     */
    public function test_adjacent_windows_are_merged(): void {
        $clock = new effective_time([
            ['timestart' => self::BASE, 'timeend' => self::BASE + 2 * DAYSECS],
            ['timestart' => self::BASE + 2 * DAYSECS, 'timeend' => self::BASE + 4 * DAYSECS],
        ]);
        $this->assertCount(1, $clock->get_windows());
    }

    /**
     * Windows given out of order are handled.
     */
    public function test_windows_are_sorted(): void {
        $clock = new effective_time([
            ['timestart' => self::BASE + 20 * DAYSECS, 'timeend' => self::BASE + 22 * DAYSECS],
            ['timestart' => self::BASE + 2 * DAYSECS, 'timeend' => self::BASE + 4 * DAYSECS],
        ]);
        $this->assertSame(
            26 * DAYSECS,
            $clock->effective_elapsed(self::BASE, self::BASE + 30 * DAYSECS)
        );
    }

    /**
     * A zero length or reversed window is discarded rather than trusted.
     */
    public function test_degenerate_windows_are_discarded(): void {
        $clock = new effective_time([
            ['timestart' => self::BASE + 5 * DAYSECS, 'timeend' => self::BASE + 5 * DAYSECS],
            ['timestart' => self::BASE + 9 * DAYSECS, 'timeend' => self::BASE + 3 * DAYSECS],
        ]);
        $this->assertSame([], $clock->get_windows());
    }

    /**
     * The design's headline case: a due date lands on the day the learner returns.
     *
     * An item due in three effective days, where a week long break starts
     * tomorrow, must come due three working days after the break ends, not
     * during it.
     */
    public function test_due_date_lands_after_the_break_not_during_it(): void {
        $breakstart = self::BASE + 1 * DAYSECS;
        $breakend = self::BASE + 8 * DAYSECS;
        $clock = new effective_time([
            ['timestart' => $breakstart, 'timeend' => $breakend],
        ]);

        $due = $clock->add_effective_seconds(self::BASE, 3 * DAYSECS);

        // One effective day passes before the break, two more after it ends.
        $this->assertSame($breakend + 2 * DAYSECS, $due);
        $this->assertGreaterThan($breakend, $due, 'the item must not fall due inside the break');
        // And the round trip is consistent.
        $this->assertSame(3 * DAYSECS, $clock->effective_elapsed(self::BASE, $due));
    }

    /**
     * Advancing from inside a window starts counting when the window ends.
     */
    public function test_advancing_from_inside_a_window(): void {
        $clock = new effective_time([
            ['timestart' => self::BASE, 'timeend' => self::BASE + 7 * DAYSECS],
        ]);
        $due = $clock->add_effective_seconds(self::BASE + 2 * DAYSECS, 3 * DAYSECS);
        $this->assertSame(self::BASE + 7 * DAYSECS + 3 * DAYSECS, $due);
    }

    /**
     * Advancing across several windows accumulates all of their skips.
     */
    public function test_advancing_across_several_windows(): void {
        $clock = new effective_time([
            ['timestart' => self::BASE + 2 * DAYSECS, 'timeend' => self::BASE + 4 * DAYSECS],
            ['timestart' => self::BASE + 6 * DAYSECS, 'timeend' => self::BASE + 9 * DAYSECS],
        ]);
        // Need 6 effective days: 2 before window one, 2 between, 2 after window two.
        $due = $clock->add_effective_seconds(self::BASE, 6 * DAYSECS);
        $this->assertSame(self::BASE + 11 * DAYSECS, $due);
        $this->assertSame(6 * DAYSECS, $clock->effective_elapsed(self::BASE, $due));
    }

    /**
     * add_effective_seconds is the exact inverse of effective_elapsed.
     *
     * This round trip property is what makes suspension windows retroactively
     * editable, so it is worth asserting across a spread of cases.
     */
    public function test_add_and_elapsed_are_inverse(): void {
        $clock = new effective_time([
            ['timestart' => self::BASE + 3 * DAYSECS, 'timeend' => self::BASE + 10 * DAYSECS],
            ['timestart' => self::BASE + 20 * DAYSECS, 'timeend' => self::BASE + 25 * DAYSECS],
        ]);
        foreach ([0, 1, 5, 12, 30, 100] as $days) {
            $seconds = $days * DAYSECS;
            $due = $clock->add_effective_seconds(self::BASE, $seconds);
            $this->assertSame(
                $seconds,
                $clock->effective_elapsed(self::BASE, $due),
                "round trip failed for {$days} days"
            );
        }
    }

    /**
     * Reversed or zero spans yield no elapsed time rather than a negative one.
     */
    public function test_reversed_span_is_zero(): void {
        $clock = new effective_time([]);
        $this->assertSame(0, $clock->effective_elapsed(self::BASE + DAYSECS, self::BASE));
        $this->assertSame(0, $clock->effective_elapsed(self::BASE, self::BASE));
    }

    /**
     * Suspension detection and window lookup agree with each other.
     */
    public function test_suspension_detection(): void {
        $clock = new effective_time([
            ['timestart' => self::BASE + 2 * DAYSECS, 'timeend' => self::BASE + 5 * DAYSECS],
        ]);
        $this->assertFalse($clock->is_suspended_at(self::BASE));
        $this->assertTrue($clock->is_suspended_at(self::BASE + 3 * DAYSECS));
        // The end is exclusive, so the moment a window ends work resumes.
        $this->assertFalse($clock->is_suspended_at(self::BASE + 5 * DAYSECS));

        $this->assertNull($clock->window_at(self::BASE));
        $this->assertNotNull($clock->window_at(self::BASE + 3 * DAYSECS));
    }

    /**
     * Suspended proportion drives the more than half suspended week rule.
     */
    public function test_suspended_proportion(): void {
        $clock = new effective_time([
            ['timestart' => self::BASE, 'timeend' => self::BASE + 5 * DAYSECS],
        ]);
        $this->assertEqualsWithDelta(
            5.0 / 7.0,
            $clock->suspended_proportion(self::BASE, self::BASE + WEEKSECS),
            1.0E-9
        );
        $this->assertEqualsWithDelta(
            0.0,
            $clock->suspended_proportion(self::BASE + 10 * DAYSECS, self::BASE + 17 * DAYSECS),
            1.0E-9
        );
    }
}
