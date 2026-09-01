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
 * Tests for per learner band unlocking.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\bands
 */
final class bands_test extends \basic_testcase {
    /**
     * Time mode advances one band per interval, from the learner's first session.
     */
    public function test_time_mode_advances_one_band_per_interval(): void {
        $this->assertSame(1, bands::level_for_time(0.0, 7.0, 5));
        $this->assertSame(1, bands::level_for_time(6.9, 7.0, 5));
        $this->assertSame(2, bands::level_for_time(7.0, 7.0, 5));
        $this->assertSame(3, bands::level_for_time(14.0, 7.0, 5));
    }

    /**
     * Time mode never runs past the last band.
     */
    public function test_time_mode_is_capped_at_the_last_band(): void {
        $this->assertSame(5, bands::level_for_time(365.0, 7.0, 5));
    }

    /**
     * A learner who starts late is on their own clock.
     *
     * The count runs from the learner's first session, not from course start, so
     * somebody joining in week three is not immediately handed four bands.
     */
    public function test_late_starter_is_not_handed_every_band(): void {
        // Three weeks into the course, but this is their first day.
        $this->assertSame(1, bands::level_for_time(0.0, 7.0, 10));
    }

    /**
     * Unseen items count against the mastery threshold.
     *
     * A band cannot qualify until most of it has actually been attempted.
     */
    public function test_unseen_items_count_against_mastery(): void {
        // Seven established items, but the band holds twenty.
        $stabilities = array_fill(0, 7, 20.0);
        $this->assertFalse(bands::meets_mastery($stabilities, 20, 14.0, 0.7));

        // Fourteen of twenty established clears a 70 per cent threshold.
        $stabilities = array_fill(0, 14, 20.0);
        $this->assertTrue(bands::meets_mastery($stabilities, 20, 14.0, 0.7));
    }

    /**
     * Items below the stability floor do not count as established.
     */
    public function test_items_below_the_floor_do_not_count(): void {
        $stabilities = array_fill(0, 20, 13.9);
        $this->assertFalse(bands::meets_mastery($stabilities, 20, 14.0, 0.7));
    }

    /**
     * Null stabilities, meaning unseen items, never count as established.
     */
    public function test_null_stabilities_never_count(): void {
        $stabilities = array_fill(0, 20, null);
        $this->assertFalse(bands::meets_mastery($stabilities, 20, 14.0, 0.7));
    }

    /**
     * A handful of persistently lapsing items must not block a band forever.
     *
     * This is why the proportion sits well under 100 per cent.
     */
    public function test_a_few_stubborn_items_do_not_block_a_band(): void {
        $stabilities = array_merge(array_fill(0, 17, 30.0), array_fill(0, 3, 0.5));
        $this->assertTrue(bands::meets_mastery($stabilities, 20, 14.0, 0.7));
    }

    /**
     * Mastery mode unlocks when the threshold is met.
     */
    public function test_mastery_mode_unlocks_on_threshold(): void {
        [$level, $reason] = bands::evaluate(bands::MODE_MASTERY, 1, 5, [
            'stabilities' => array_fill(0, 15, 20.0),
            'banditemcount' => 20,
            'stabilityfloor' => 14.0,
            'proportion' => 0.7,
            'effectivedaysonband' => 10.0,
            'backstopdays' => 21.0,
        ]);
        $this->assertSame(2, $level);
        $this->assertSame(bands::REASON_MASTERY, $reason);
    }

    /**
     * A struggling learner is eventually advanced by the backstop.
     *
     * Without it, the learner who most needs syllabus coverage is the one who
     * never gets it.
     */
    public function test_backstop_advances_a_stalled_learner(): void {
        $stalled = [
            'stabilities' => array_fill(0, 2, 20.0),
            'banditemcount' => 20,
            'stabilityfloor' => 14.0,
            'proportion' => 0.7,
            'backstopdays' => 21.0,
        ];

        // Still within the backstop period: no unlock.
        [$level, $reason] = bands::evaluate(bands::MODE_MASTERY, 1, 5, $stalled + ['effectivedaysonband' => 20.0]);
        $this->assertSame(1, $level);
        $this->assertSame(bands::REASON_NONE, $reason);

        // Past it: unlocked, and flagged as a backstop advance so a teacher can act.
        $stalled['effectivedaysonband'] = 21.0;
        [$level, $reason] = bands::evaluate(bands::MODE_MASTERY, 1, 5, $stalled);
        $this->assertSame(2, $level);
        $this->assertSame(bands::REASON_BACKSTOP, $reason);
    }

    /**
     * The backstop runs on effective time, so a break does not trigger it.
     */
    public function test_backstop_uses_effective_time(): void {
        // Thirty wall clock days, but only ten of them effective after a break.
        [$level, $reason] = bands::evaluate(bands::MODE_MASTERY, 1, 5, [
            'stabilities' => [],
            'banditemcount' => 20,
            'effectivedaysonband' => 10.0,
            'backstopdays' => 21.0,
        ]);
        $this->assertSame(1, $level);
        $this->assertSame(bands::REASON_NONE, $reason);
    }

    /**
     * At most one band unlocks per suspension window.
     *
     * A learner who unlocked several bands over a fortnight break would meet
     * every new item coming due in the first days of term, recreating the wall
     * that suspension windows exist to prevent.
     */
    public function test_only_one_band_unlocks_per_suspension_window(): void {
        // Time mode says they have earned three bands' worth of time.
        [$level, $reason] = bands::evaluate(bands::MODE_TIME, 1, 5, [
            'effectivedayssincefirst' => 21.0,
            'intervaldays' => 7.0,
            'insuspension' => true,
            'unlockedinthiswindow' => false,
        ]);
        $this->assertSame(2, $level, 'a break may advance a learner by at most one band');
        $this->assertSame(bands::REASON_TIME, $reason);
    }

    /**
     * A second unlock inside the same window is refused.
     */
    public function test_second_unlock_in_a_window_is_refused(): void {
        [$level, $reason] = bands::evaluate(bands::MODE_MASTERY, 2, 5, [
            'stabilities' => array_fill(0, 20, 30.0),
            'banditemcount' => 20,
            'stabilityfloor' => 14.0,
            'proportion' => 0.7,
            'insuspension' => true,
            'unlockedinthiswindow' => true,
        ]);
        $this->assertSame(2, $level);
        $this->assertSame(bands::REASON_SUSPENSION_LIMIT, $reason);
    }

    /**
     * Outside a suspension window, time mode may advance several bands at once.
     */
    public function test_outside_suspension_time_mode_may_skip_ahead(): void {
        [$level, $reason] = bands::evaluate(bands::MODE_TIME, 1, 5, [
            'effectivedayssincefirst' => 21.0,
            'intervaldays' => 7.0,
        ]);
        $this->assertSame(4, $level);
        $this->assertSame(bands::REASON_TIME, $reason);
    }

    /**
     * The final band never unlocks anything further.
     */
    public function test_final_band_is_terminal(): void {
        [$level, $reason] = bands::evaluate(bands::MODE_MASTERY, 5, 5, [
            'stabilities' => array_fill(0, 20, 100.0),
            'banditemcount' => 20,
        ]);
        $this->assertSame(5, $level);
        $this->assertSame(bands::REASON_NONE, $reason);
    }

    /**
     * An empty band does not hold anybody up.
     */
    public function test_empty_band_does_not_block(): void {
        $this->assertTrue(bands::meets_mastery([], 0, 14.0, 0.7));
    }
}
