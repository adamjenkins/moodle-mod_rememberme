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
 * Tests for grace credit allocation.
 *
 * The design's worked example is asserted directly: a learner holding a 1.0
 * balance can rescue a single missed week, or patch a 0.9 week and a 0.8 week
 * and still hold 0.7 in reserve.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\grace
 */
final class grace_test extends \basic_testcase {
    /**
     * A full balance rescues one entirely missed week.
     */
    public function test_missed_week_costs_a_full_point(): void {
        $result = grace::allocate([1 => 1.0, 2 => 0.0, 3 => 1.0], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['fractions'][2], 1.0E-9);
        $this->assertEqualsWithDelta(1.0, $result['spent'], 1.0E-9);
        $this->assertEqualsWithDelta(0.0, $result['remaining'], 1.0E-9);
    }

    /**
     * The design's worked example: two near misses cost only the gaps they fill.
     */
    public function test_two_near_misses_cost_only_their_gaps(): void {
        $result = grace::allocate([1 => 0.9, 2 => 0.8, 3 => 1.0], 1.0);

        $this->assertEqualsWithDelta(1.0, $result['fractions'][1], 1.0E-9);
        $this->assertEqualsWithDelta(1.0, $result['fractions'][2], 1.0E-9);
        // 0.1 + 0.2 spent, 0.7 still in reserve.
        $this->assertEqualsWithDelta(0.3, $result['spent'], 1.0E-9);
        $this->assertEqualsWithDelta(0.7, $result['remaining'], 1.0E-9);
    }

    /**
     * Cheapest gaps are filled first, which maximises weeks brought to 1.0.
     *
     * With a 0.5 balance and gaps of 0.1, 0.2 and 0.9, the right answer is to
     * fix the two cheap weeks and leave the expensive one, not to sink the whole
     * balance into a gap it cannot close.
     */
    public function test_cheapest_gaps_are_filled_first(): void {
        $result = grace::allocate([1 => 0.9, 2 => 0.8, 3 => 0.1], 0.5);

        $this->assertEqualsWithDelta(1.0, $result['fractions'][1], 1.0E-9);
        $this->assertEqualsWithDelta(1.0, $result['fractions'][2], 1.0E-9);
        $this->assertEqualsWithDelta(0.1, $result['fractions'][3], 1.0E-9, 'the expensive week is left alone');
        $this->assertEqualsWithDelta(0.3, $result['spent'], 1.0E-9);
    }

    /**
     * Balance is never part spent on a gap it cannot close.
     *
     * A part filled week earns no extra point, so spending there would waste
     * balance a later cheaper gap could have used.
     */
    public function test_balance_is_not_wasted_on_an_unclosable_gap(): void {
        $result = grace::allocate([1 => 0.0, 2 => 0.95], 0.5);

        $this->assertEqualsWithDelta(0.0, $result['fractions'][1], 1.0E-9);
        $this->assertEqualsWithDelta(1.0, $result['fractions'][2], 1.0E-9);
        $this->assertEqualsWithDelta(0.05, $result['spent'], 1.0E-9);
        $this->assertEqualsWithDelta(0.45, $result['remaining'], 1.0E-9);
    }

    /**
     * A zero balance changes nothing.
     */
    public function test_zero_balance_is_a_no_op(): void {
        $fractions = [1 => 0.5, 2 => 0.0, 3 => 1.0];
        $result = grace::allocate($fractions, 0.0);
        $this->assertEqualsWithDelta(0.5, $result['fractions'][1], 1.0E-9);
        $this->assertEqualsWithDelta(0.0, $result['fractions'][2], 1.0E-9);
        $this->assertEqualsWithDelta(0.0, $result['spent'], 1.0E-9);
        $this->assertSame([], $result['log']);
    }

    /**
     * A learner with no shortfalls gains nothing, which is the correct outcome.
     */
    public function test_clean_record_spends_nothing(): void {
        $result = grace::allocate([1 => 1.0, 2 => 1.0], 2.0);
        $this->assertEqualsWithDelta(0.0, $result['spent'], 1.0E-9);
        $this->assertEqualsWithDelta(2.0, $result['remaining'], 1.0E-9);
    }

    /**
     * Every deduction is logged so a teacher can explain any grade.
     */
    public function test_every_deduction_is_logged(): void {
        $result = grace::allocate([1 => 0.9, 2 => 0.8], 1.0);

        $this->assertCount(2, $result['log']);
        $byweek = [];
        foreach ($result['log'] as $entry) {
            $byweek[$entry->week] = $entry;
        }
        $this->assertEqualsWithDelta(0.1, $byweek[1]->amount, 1.0E-9);
        $this->assertEqualsWithDelta(0.9, $byweek[1]->fractionbefore, 1.0E-9);
        $this->assertEqualsWithDelta(1.0, $byweek[1]->fractionafter, 1.0E-9);
        $this->assertEqualsWithDelta(0.2, $byweek[2]->amount, 1.0E-9);
    }

    /**
     * Earned grace is capped at the initial grant, so a break cannot be farmed.
     */
    public function test_earned_grace_is_capped_at_the_initial_grant(): void {
        $this->assertEqualsWithDelta(1.5, grace::cap_balance(1.0, 0.5), 1.0E-9);
        $this->assertEqualsWithDelta(2.0, grace::cap_balance(1.0, 1.0), 1.0E-9);
        // However much extra work is done, a 1.0 grant tops out at 2.0.
        $this->assertEqualsWithDelta(2.0, grace::cap_balance(1.0, 50.0), 1.0E-9);
    }

    /**
     * The earn rate is defined against sessions of work, not raw question count.
     */
    public function test_grace_earn_rate(): void {
        // One session's worth of items earns a quarter point.
        $this->assertEqualsWithDelta(0.25, grace::earned_from_work(20, 20, 0.25), 1.0E-9);
        // Four sessions repair one missed week.
        $this->assertEqualsWithDelta(1.0, grace::earned_from_work(80, 20, 0.25), 1.0E-9);
        // No work, no grace.
        $this->assertEqualsWithDelta(0.0, grace::earned_from_work(0, 20, 0.25), 1.0E-9);
    }

    /**
     * Out of range fractions are clamped rather than trusted.
     */
    public function test_out_of_range_fractions_are_clamped(): void {
        $result = grace::allocate([1 => 1.7, 2 => -0.4], 1.0);
        $this->assertEqualsWithDelta(1.0, $result['fractions'][1], 1.0E-9);
        // The negative week was clamped to 0.0 and then rescued for exactly 1.0.
        $this->assertEqualsWithDelta(1.0, $result['fractions'][2], 1.0E-9);
        $this->assertEqualsWithDelta(1.0, $result['spent'], 1.0E-9);
    }
}
