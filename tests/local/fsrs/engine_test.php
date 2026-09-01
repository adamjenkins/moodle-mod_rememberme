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

namespace mod_rememberme\local\fsrs;

/**
 * Tests for the FSRS-style memory model.
 *
 * These assert the model's defining behaviours rather than specific numbers, so
 * that re-tuning the weights does not falsify the suite. The one thing they pin
 * down hard is the SM-2 departure: a lapse must reduce stability without
 * discarding it.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\fsrs\engine
 */
final class engine_test extends \basic_testcase {
    /** @var engine The engine under test. */
    protected engine $engine;

    /**
     * Set up a default engine.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->engine = new engine();
    }

    /**
     * Retrievability is 1 at zero elapsed time and decays monotonically.
     */
    public function test_retrievability_decays_monotonically(): void {
        $stability = 10.0;
        $this->assertEqualsWithDelta(1.0, $this->engine->retrievability($stability, 0.0), 1.0E-9);

        $previous = 1.0;
        foreach ([1, 5, 10, 30, 100, 365] as $days) {
            $r = $this->engine->retrievability($stability, (float)$days);
            $this->assertLessThan($previous, $r, "retrievability should fall by day {$days}");
            $this->assertGreaterThan(0.0, $r);
            $previous = $r;
        }
    }

    /**
     * At exactly the stability, retrievability equals the target retention.
     *
     * This is the definition of stability, so it is worth pinning: stability is
     * the number of days at which recall probability reaches the target.
     */
    public function test_retrievability_at_stability_equals_target(): void {
        $params = new parameters(null, 0.9);
        $engine = new engine($params);
        $interval = $engine->interval_for(10.0);
        $this->assertEqualsWithDelta(0.9, $engine->retrievability(10.0, $interval), 1.0E-6);
    }

    /**
     * A larger stability always yields a longer interval.
     */
    public function test_interval_scales_with_stability(): void {
        $previous = 0.0;
        foreach ([1.0, 5.0, 20.0, 100.0, 400.0] as $stability) {
            $interval = $this->engine->interval_for($stability);
            $this->assertGreaterThan($previous, $interval);
            $previous = $interval;
        }
    }

    /**
     * A lower target retention means longer intervals and fewer reviews.
     */
    public function test_lower_retention_lengthens_intervals(): void {
        $strict = new engine(new parameters(null, 0.95));
        $relaxed = new engine(new parameters(null, 0.80));
        $this->assertGreaterThan($strict->interval_for(20.0), $relaxed->interval_for(20.0));
    }

    /**
     * A first attempt that fails seeds weaker memory than one that succeeds.
     */
    public function test_seeding_orders_by_first_rating(): void {
        $again = $this->engine->seed(rating::AGAIN);
        $hard = $this->engine->seed(rating::HARD);
        $good = $this->engine->seed(rating::GOOD);
        $easy = $this->engine->seed(rating::EASY);

        $this->assertLessThan($hard->get_stability(), $again->get_stability());
        $this->assertLessThan($good->get_stability(), $hard->get_stability());
        $this->assertLessThan($easy->get_stability(), $good->get_stability());

        // A first attempt failure also seeds elevated difficulty.
        $this->assertGreaterThan($easy->get_difficulty(), $again->get_difficulty());
    }

    /**
     * A lapse reduces stability without discarding it.
     *
     * This is the whole reason for choosing an FSRS-style model over SM-2. A
     * long known item that slips once must return sooner than a brand new one,
     * not be reset to day one.
     */
    public function test_lapse_is_not_destructive(): void {
        $mature = new memory_state(200.0, 5.0);
        $lapsed = $this->engine->update($mature, rating::AGAIN, 210.0);

        // Stability fell.
        $this->assertLessThan($mature->get_stability(), $lapsed->get_stability());
        // But it is emphatically not zero, and not the seed value either.
        $this->assertGreaterThan(0.0, $lapsed->get_stability());
        $this->assertGreaterThan(
            $this->engine->seed(rating::AGAIN)->get_stability(),
            $lapsed->get_stability(),
            'a lapsed mature item must retain more memory than a brand new one'
        );
    }

    /**
     * The more established the item was, the more it retains through a lapse.
     */
    public function test_lapse_retains_more_for_stronger_items(): void {
        $weak = $this->engine->update(new memory_state(5.0, 5.0), rating::AGAIN, 6.0);
        $strong = $this->engine->update(new memory_state(300.0, 5.0), rating::AGAIN, 310.0);
        $this->assertGreaterThan($weak->get_stability(), $strong->get_stability());
    }

    /**
     * A lapse never lengthens the interval.
     */
    public function test_lapse_never_increases_stability(): void {
        foreach ([0.5, 2.0, 10.0, 50.0, 365.0] as $stability) {
            $before = new memory_state($stability, 5.0);
            $after = $this->engine->update($before, rating::AGAIN, $stability);
            $this->assertLessThanOrEqual(
                $before->get_stability() + 1.0E-9,
                $after->get_stability(),
                "lapse increased stability from {$stability}"
            );
        }
    }

    /**
     * A success never shortens the interval.
     */
    public function test_success_never_decreases_stability(): void {
        foreach ([rating::HARD, rating::GOOD, rating::EASY] as $r) {
            foreach ([0.5, 2.0, 10.0, 50.0, 365.0] as $stability) {
                $before = new memory_state($stability, 5.0);
                $after = $this->engine->update($before, $r, $stability);
                $this->assertGreaterThanOrEqual(
                    $before->get_stability() - 1.0E-9,
                    $after->get_stability(),
                    "rating {$r} shortened stability from {$stability}"
                );
            }
        }
    }

    /**
     * Recalling something nearly forgotten teaches the model more.
     *
     * Reviewing at low retrievability must grow stability more than reviewing
     * the same item immediately, which is the spacing effect the whole system
     * exists to exploit.
     */
    public function test_low_retrievability_success_grows_stability_more(): void {
        $state = new memory_state(20.0, 5.0);
        $justseen = $this->engine->update($state, rating::GOOD, 0.5);
        $nearlyforgotten = $this->engine->update($state, rating::GOOD, 40.0);

        $this->assertGreaterThan(
            $justseen->get_stability(),
            $nearlyforgotten->get_stability(),
            'a late successful review should be worth more than an immediate one'
        );
    }

    /**
     * Easy grows stability more than good, which grows it more than hard.
     */
    public function test_rating_orders_stability_growth(): void {
        $state = new memory_state(20.0, 5.0);
        $hard = $this->engine->update($state, rating::HARD, 20.0)->get_stability();
        $good = $this->engine->update($state, rating::GOOD, 20.0)->get_stability();
        $easy = $this->engine->update($state, rating::EASY, 20.0)->get_stability();

        $this->assertLessThan($good, $hard);
        $this->assertLessThan($easy, $good);
    }

    /**
     * Difficulty rises on a lapse and falls on an easy success.
     */
    public function test_difficulty_moves_with_rating(): void {
        $state = new memory_state(20.0, 5.0);
        $this->assertGreaterThan(
            $state->get_difficulty(),
            $this->engine->update($state, rating::AGAIN, 20.0)->get_difficulty()
        );
        $this->assertLessThan(
            $state->get_difficulty(),
            $this->engine->update($state, rating::EASY, 20.0)->get_difficulty()
        );
    }

    /**
     * Difficulty stays inside its bounds however long the review history runs.
     *
     * Without mean reversion, a long run of one rating would drive every item to
     * a bound and the difficulty signal would stop carrying information.
     */
    public function test_difficulty_stays_bounded_under_sustained_ratings(): void {
        foreach (rating::all() as $r) {
            $state = new memory_state(10.0, 5.0);
            for ($i = 0; $i < 500; $i++) {
                $state = $this->engine->update($state, $r, 10.0);
            }
            $this->assertGreaterThanOrEqual(parameters::MIN_DIFFICULTY, $state->get_difficulty());
            $this->assertLessThanOrEqual(parameters::MAX_DIFFICULTY, $state->get_difficulty());
        }
    }

    /**
     * Stability stays finite and in range under a long random history.
     *
     * A NAN or INF written to the database would poison every later calculation
     * for that item, so the clamping is not decorative.
     */
    public function test_stability_stays_finite_under_long_history(): void {
        $state = new memory_state(1.0, 5.0);
        $ratings = [rating::GOOD, rating::AGAIN, rating::EASY, rating::HARD, rating::GOOD, rating::GOOD];
        for ($i = 0; $i < 300; $i++) {
            $r = $ratings[$i % count($ratings)];
            $elapsed = $this->engine->interval_for($state->get_stability());
            $state = $this->engine->update($state, $r, $elapsed);
            $this->assertTrue(is_finite($state->get_stability()));
            $this->assertTrue(is_finite($state->get_difficulty()));
            $this->assertGreaterThanOrEqual(parameters::MIN_STABILITY, $state->get_stability());
            $this->assertLessThanOrEqual(parameters::MAX_STABILITY, $state->get_stability());
        }
    }

    /**
     * Repeated successful reviews reach the default 14 day mastery floor in
     * roughly three to four reviews, as the design predicts.
     */
    public function test_successful_reviews_reach_stability_floor_in_expected_range(): void {
        $state = $this->engine->seed(rating::GOOD);
        $reviews = 0;
        while ($state->get_stability() < 14.0 && $reviews < 20) {
            $elapsed = $this->engine->interval_for($state->get_stability());
            $state = $this->engine->update($state, rating::GOOD, $elapsed);
            $reviews++;
        }
        $this->assertLessThan(20, $reviews, 'should reach the 14 day floor at all');
        $this->assertLessThanOrEqual(
            6,
            $reviews,
            'the design predicts roughly three to four consecutive successes to clear a 14 day floor'
        );
    }

    /**
     * Clearing the stability floor takes real elapsed time, not just volume.
     *
     * This is what makes mastery based unlocking a consolidation gate rather
     * than a volume gate: it cannot be rushed by cramming.
     */
    public function test_stability_floor_cannot_be_reached_by_cramming(): void {
        $state = $this->engine->seed(rating::GOOD);
        $elapseddays = 0.0;
        // Answer the same item twenty times in one sitting.
        for ($i = 0; $i < 20; $i++) {
            $state = $this->engine->update($state, rating::GOOD, 0.0);
            $elapseddays += 0.0;
        }
        $this->assertLessThan(
            14.0,
            $state->get_stability(),
            'cramming an item in a single session must not clear a 14 day stability floor'
        );
    }

    /**
     * Fuzz stays within the requested proportion and never returns zero.
     */
    public function test_fuzz_stays_within_bounds(): void {
        for ($i = 0; $i < 200; $i++) {
            $fuzzed = $this->engine->fuzz_interval(100.0, 0.05);
            $this->assertGreaterThanOrEqual(95.0 - 1.0E-9, $fuzzed);
            $this->assertLessThanOrEqual(105.0 + 1.0E-9, $fuzzed);
        }
    }

    /**
     * Seeded fuzz is deterministic, so a test can assert an exact due date.
     */
    public function test_seeded_fuzz_is_deterministic(): void {
        $first = $this->engine->fuzz_interval(100.0, 0.05, 12345);
        $second = $this->engine->fuzz_interval(100.0, 0.05, 12345);
        $this->assertSame($first, $second);
    }

    /**
     * Malformed parameters fall back to the defaults rather than exploding.
     *
     * A bad weight set must never take a live session down mid answer.
     */
    public function test_malformed_parameters_fall_back_to_defaults(): void {
        $short = new parameters([1.0, 2.0], null);
        $this->assertSame(parameters::DEFAULT_WEIGHTS, $short->get_weights());

        $nonnumeric = new parameters(array_fill(0, parameters::WEIGHT_COUNT, 'x'), null);
        $this->assertSame(parameters::DEFAULT_WEIGHTS, $nonnumeric->get_weights());

        $badretention = new parameters(null, 1.5);
        $this->assertSame(parameters::DEFAULT_RETENTION, $badretention->get_retention());
    }

    /**
     * An invalid rating is treated as a lapse rather than trusted.
     */
    public function test_invalid_rating_is_treated_as_a_lapse(): void {
        $state = new memory_state(20.0, 5.0);
        $bogus = $this->engine->update($state, 99, 20.0);
        $lapse = $this->engine->update($state, rating::AGAIN, 20.0);
        $this->assertTrue($bogus->equals($lapse));
    }
}
