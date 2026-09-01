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

use mod_rememberme\local\fsrs\rating;

/**
 * Tests for the objective grade to rating mapping.
 *
 * The load bearing property is that latency can only ever separate good from
 * easy. A slow correct answer is still recall, and must never be recorded as a
 * lapse.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\default_grade_mapper
 */
final class default_grade_mapper_test extends \basic_testcase {
    /**
     * A wrong answer is a lapse.
     */
    public function test_wrong_answer_is_a_lapse(): void {
        $mapper = new default_grade_mapper();
        $this->assertSame(rating::AGAIN, $mapper->map(0.0, null, null));
    }

    /**
     * A negatively marked answer is a lapse, not something stranger.
     *
     * Some question types score below zero, so the mapping must handle it.
     */
    public function test_negative_fraction_is_a_lapse(): void {
        $mapper = new default_grade_mapper();
        $this->assertSame(rating::AGAIN, $mapper->map(-0.33, null, null));
    }

    /**
     * Partial credit below the threshold is a lapse; at or above it is hard.
     */
    public function test_partial_credit_respects_the_threshold(): void {
        $mapper = new default_grade_mapper(0.5);
        $this->assertSame(rating::AGAIN, $mapper->map(0.4, null, null));
        $this->assertSame(rating::HARD, $mapper->map(0.5, null, null));
        $this->assertSame(rating::HARD, $mapper->map(0.9, null, null));
    }

    /**
     * The threshold is configurable, because different courses want different ones.
     */
    public function test_threshold_is_configurable(): void {
        $strict = new default_grade_mapper(0.8);
        $this->assertSame(rating::AGAIN, $strict->map(0.7, null, null));
        $this->assertSame(rating::HARD, $strict->map(0.8, null, null));
    }

    /**
     * With no latency data the mapping degrades to binary, and that is correct.
     *
     * The design requires the system to work in this mode, so it is the default
     * path rather than an untested branch.
     */
    public function test_binary_mode_is_complete_on_its_own(): void {
        $mapper = new default_grade_mapper();
        $this->assertSame(rating::AGAIN, $mapper->map(0.0, null, null));
        $this->assertSame(rating::GOOD, $mapper->map(1.0, null, null));
    }

    /**
     * Latency separates good from easy when it is available.
     */
    public function test_latency_separates_good_from_easy(): void {
        $mapper = new default_grade_mapper();
        // Faster than their median: easy.
        $this->assertSame(rating::EASY, $mapper->map(1.0, 3.0, 6.0));
        // Slower than their median: good.
        $this->assertSame(rating::GOOD, $mapper->map(1.0, 9.0, 6.0));
    }

    /**
     * Latency can never turn a correct answer into a lapse.
     *
     * This is the property the design is emphatic about, so it is asserted
     * across an extreme spread rather than a single case.
     */
    public function test_latency_never_creates_a_lapse(): void {
        $mapper = new default_grade_mapper();
        foreach ([0.001, 1.0, 60.0, 3600.0, 86400.0] as $latency) {
            $result = $mapper->map(1.0, $latency, 5.0);
            $this->assertTrue(
                rating::is_success($result),
                "a correct answer taking {$latency}s was recorded as a lapse"
            );
        }
    }

    /**
     * Unusable latency data falls back to good rather than guessing.
     */
    public function test_unusable_latency_falls_back_to_good(): void {
        $mapper = new default_grade_mapper();
        $this->assertSame(rating::GOOD, $mapper->map(1.0, 5.0, null));
        $this->assertSame(rating::GOOD, $mapper->map(1.0, null, 5.0));
        $this->assertSame(rating::GOOD, $mapper->map(1.0, 5.0, 0.0));
        $this->assertSame(rating::GOOD, $mapper->map(1.0, INF, 5.0));
    }

    /**
     * Latency can be switched off entirely for a deployment that prefers simplicity.
     */
    public function test_latency_can_be_disabled(): void {
        $mapper = new default_grade_mapper(0.5, false);
        $this->assertSame(rating::GOOD, $mapper->map(1.0, 0.1, 100.0));
    }

    /**
     * A fraction above 1.0 is still simply correct.
     *
     * Some behaviours report a maximum fraction greater than one, so this must
     * not fall through the partial credit branch.
     */
    public function test_fraction_above_one_is_correct(): void {
        $mapper = new default_grade_mapper();
        $this->assertSame(rating::GOOD, $mapper->map(1.5, null, null));
    }

    /**
     * A non finite fraction is treated as wrong rather than trusted.
     */
    public function test_non_finite_fraction_is_a_lapse(): void {
        $mapper = new default_grade_mapper();
        $this->assertSame(rating::AGAIN, $mapper->map(NAN, null, null));
    }

    /**
     * The mapper is swappable, which the design requires for research use.
     */
    public function test_mapper_is_an_interface(): void {
        $mapper = new default_grade_mapper();
        $this->assertInstanceOf(grade_mapper::class, $mapper);
    }
}
