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

namespace mod_rememberme;

use mod_rememberme\local\bands;
use mod_rememberme\local\scheduler;

/**
 * Tests for multi category bands, the widened draw, and punctuality.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\pool
 * @covers     \mod_rememberme\local\bands
 * @covers     \mod_rememberme\local\scheduler
 */
final class bands_pool_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var array Question categories, keyed by label. */
    protected array $categories = [];

    /** @var \stdClass The learner. */
    protected \stdClass $student;

    /**
     * Build a course with a bank holding three categories.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/questionlib.php');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();

        $qbank = $generator->create_module('qbank', ['course' => $this->course->id]);
        $context = \context_module::instance($qbank->cmid);
        $default = question_get_default_category($context->id);

        $qgen = $generator->get_plugin_generator('core_question');

        // Three categories: two that will share a band, one for the next band.
        foreach (['alpha', 'beta', 'gamma'] as $label) {
            $category = $qgen->create_question_category([
                'contextid' => $context->id,
                'parent' => $default->id,
                'name' => $label,
            ]);
            $this->categories[$label] = $category;
            foreach ([1, 2] as $n) {
                $qgen->create_question(
                    'shortanswer',
                    null,
                    ['category' => $category->id, 'idnumber' => $label . $n]
                );
            }
        }

        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Build an instance with the given bands.
     *
     * @param array $bands Lists of category labels, one list per band.
     * @param array $settings Instance overrides.
     * @return \stdClass The instance record.
     */
    protected function make_instance(array $bands, array $settings = []): \stdClass {
        global $DB;

        $module = $this->getDataGenerator()->create_module(
            'rememberme',
            ['course' => $this->course->id] + $settings
        );
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_rememberme');

        $bandnumber = 1;
        foreach ($bands as $labels) {
            $sortorder = 0;
            foreach ($labels as $label) {
                $gen->create_band(
                    (int)$module->id,
                    (int)$this->categories[$label]->id,
                    $sortorder,
                    false,
                    $bandnumber
                );
                $sortorder++;
            }
            $bandnumber++;
        }

        return $DB->get_record('rememberme', ['id' => $module->id], '*', MUST_EXIST);
    }

    /**
     * One band can draw on several categories.
     *
     * @return void
     */
    public function test_a_band_may_hold_several_categories(): void {
        $instance = $this->make_instance([['alpha', 'beta'], ['gamma']]);
        $pool = (new scheduler($instance))->get_pool();

        $this->assertSame(2, $pool->get_band_count(), 'two bands, not three rows');
        $this->assertCount(4, $pool->get_entries_in_band(1), 'band one holds both categories');
        $this->assertCount(2, $pool->get_entries_in_band(2));
    }

    /**
     * New items come from the current band and every band below it.
     *
     * A learner who moved on before finishing an earlier band would otherwise
     * never be shown what they skipped.
     *
     * @return void
     */
    public function test_new_items_come_from_lower_bands_too(): void {
        global $DB;

        $instance = $this->make_instance([['alpha'], ['beta']]);
        $scheduler = new scheduler($instance);

        // Put the learner on band two without having seen band one.
        $scheduler->ensure_band_state((int)$this->student->id, time());
        $DB->set_field(
            'rememberme_bandstate',
            'bandlevel',
            2,
            ['rememberme' => $instance->id, 'userid' => $this->student->id]
        );

        $queue = $scheduler->get_due_questions((int)$this->student->id);
        $this->assertCount(4, $queue, 'both bands are drawn from, not just the current one');

        $bands = array_unique(array_map(static fn($entry): int => $entry->bandlevel, $queue));
        $this->assertContains(2, $bands, 'the current band is offered');
    }

    /**
     * The exhausted mode unlocks once nothing in the band is unseen.
     *
     * @return void
     */
    public function test_exhausted_mode_unlocks_when_nothing_is_unseen(): void {
        [$level, $reason] = bands::evaluate(bands::MODE_EXHAUSTED, 1, 3, [
            'banditemcount' => 4,
            'unseeninband' => 2,
        ]);
        $this->assertSame(1, $level, 'still items nobody has met');
        $this->assertSame(bands::REASON_NONE, $reason);

        [$level, $reason] = bands::evaluate(bands::MODE_EXHAUSTED, 1, 3, [
            'banditemcount' => 4,
            'unseeninband' => 0,
        ]);
        $this->assertSame(2, $level);
        $this->assertSame(bands::REASON_EXHAUSTED, $reason);
    }

    /**
     * Seeing an item counts however badly it went.
     *
     * The exhausted gate is about coverage, not performance, so a wrong answer
     * still means the question has been met.
     *
     * @return void
     */
    public function test_exhausted_mode_ignores_how_well_items_went(): void {
        $instance = $this->make_instance([['alpha'], ['beta']], ['unlockmode' => bands::MODE_EXHAUSTED]);
        $scheduler = new scheduler($instance);
        $now = time();

        $entries = array_keys($scheduler->get_pool()->get_entries_in_band(1));
        foreach ($entries as $entry) {
            $scheduler->record_attempt((int)$this->student->id, (int)$entry, 1, 'shortanswer', 0.0, 5000, 1, $now);
        }

        $this->assertSame(
            2,
            $scheduler->evaluate_bands((int)$this->student->id, $now),
            'every question in the band was seen, even though all were answered wrongly'
        );
    }

    /**
     * An empty band cannot unlock anything, so nobody is advanced by accident.
     *
     * @return void
     */
    public function test_exhausted_mode_needs_a_non_empty_band(): void {
        [$level, $reason] = bands::evaluate(bands::MODE_EXHAUSTED, 1, 3, [
            'banditemcount' => 0,
            'unseeninband' => 0,
        ]);
        $this->assertSame(1, $level);
        $this->assertSame(bands::REASON_NONE, $reason);
    }

    /**
     * Answering items when they fall due earns grace; leaving them stale does not.
     *
     * @return void
     */
    public function test_punctuality_earns_grace_and_lateness_does_not(): void {
        $instance = $this->make_instance([['alpha', 'beta', 'gamma']], ['ontimegrace' => 0.5]);
        $scheduler = new scheduler($instance);
        $now = time();

        $entries = array_keys($scheduler->get_pool()->get_all_entries());
        $this->assertCount(6, $entries);

        // Meet every item once, so each acquires a due date.
        foreach ($entries as $entry) {
            $scheduler->record_attempt((int)$this->student->id, (int)$entry, 1, 'shortanswer', 1.0, 5000, 1, $now);
        }

        // Answer each one again the moment it falls due.
        for ($round = 0; $round < 2; $round++) {
            foreach ($entries as $entry) {
                $state = $scheduler->get_state((int)$this->student->id, (int)$entry);
                $scheduler->record_attempt(
                    (int)$this->student->id,
                    (int)$entry,
                    1,
                    'shortanswer',
                    1.0,
                    5000,
                    1,
                    (int)$state->duedate
                );
            }
        }

        [$punctual, $judged] = $scheduler->ontime_counts((int)$this->student->id);
        $this->assertGreaterThanOrEqual(scheduler::ONTIME_MIN_SAMPLES, $judged);
        $this->assertSame($judged, $punctual, 'every answer was given as the item fell due');
        $this->assertEqualsWithDelta(0.5, $scheduler->ontime_grace((int)$this->student->id), 1.0E-9);
    }

    /**
     * A learner who lets everything go stale earns none of it.
     *
     * @return void
     */
    public function test_lateness_earns_no_grace(): void {
        $instance = $this->make_instance([['alpha', 'beta', 'gamma']], ['ontimegrace' => 0.5]);
        $scheduler = new scheduler($instance);
        $now = time();

        $entries = array_keys($scheduler->get_pool()->get_all_entries());
        foreach ($entries as $entry) {
            $scheduler->record_attempt((int)$this->student->id, (int)$entry, 1, 'shortanswer', 1.0, 5000, 1, $now);
        }

        // Come back a fortnight after each item was due.
        for ($round = 0; $round < 2; $round++) {
            foreach ($entries as $entry) {
                $state = $scheduler->get_state((int)$this->student->id, (int)$entry);
                $scheduler->record_attempt(
                    (int)$this->student->id,
                    (int)$entry,
                    1,
                    'shortanswer',
                    1.0,
                    5000,
                    1,
                    (int)$state->duedate + 14 * DAYSECS
                );
            }
        }

        [$punctual, $judged] = $scheduler->ontime_counts((int)$this->student->id);
        $this->assertGreaterThan(0, $judged);
        $this->assertSame(0, $punctual);
        $this->assertEqualsWithDelta(0.0, $scheduler->ontime_grace((int)$this->student->id), 1.0E-9);
    }

    /**
     * Too little history earns nothing, so a couple of lucky answers prove nothing.
     *
     * @return void
     */
    public function test_punctuality_needs_enough_history(): void {
        $instance = $this->make_instance([['alpha']], ['ontimegrace' => 0.5]);
        $scheduler = new scheduler($instance);
        $now = time();

        $entries = array_keys($scheduler->get_pool()->get_entries_in_band(1));
        foreach ($entries as $entry) {
            $scheduler->record_attempt((int)$this->student->id, (int)$entry, 1, 'shortanswer', 1.0, 5000, 1, $now);
            $state = $scheduler->get_state((int)$this->student->id, (int)$entry);
            $scheduler->record_attempt(
                (int)$this->student->id,
                (int)$entry,
                1,
                'shortanswer',
                1.0,
                5000,
                1,
                (int)$state->duedate
            );
        }

        [, $judged] = $scheduler->ontime_counts((int)$this->student->id);
        $this->assertLessThan(scheduler::ONTIME_MIN_SAMPLES, $judged);
        $this->assertEqualsWithDelta(0.0, $scheduler->ontime_grace((int)$this->student->id), 1.0E-9);
    }

    /**
     * Setting the reward to zero switches it off entirely.
     *
     * @return void
     */
    public function test_punctuality_reward_can_be_disabled(): void {
        $instance = $this->make_instance([['alpha', 'beta', 'gamma']], ['ontimegrace' => 0]);
        $scheduler = new scheduler($instance);
        $now = time();

        foreach (array_keys($scheduler->get_pool()->get_all_entries()) as $entry) {
            $scheduler->record_attempt((int)$this->student->id, (int)$entry, 1, 'shortanswer', 1.0, 5000, 1, $now);
        }

        $this->assertEqualsWithDelta(0.0, $scheduler->ontime_grace((int)$this->student->id), 1.0E-9);
    }
}
