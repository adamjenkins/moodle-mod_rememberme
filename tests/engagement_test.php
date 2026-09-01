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

use mod_rememberme\local\scheduler;

/**
 * Tests for weekly credit: what counts, and what a wrong answer costs.
 *
 * Weekly credit used to be one point per attempt, which meant a learner could
 * clear a whole week by answering a single question wrongly over and over,
 * because a wrong answer brings the question straight back. These tests pin the
 * three rules that close that: credit is per distinct question, an answer too
 * fast to have been read does not count, and a wrong answer buys a short
 * learning step rather than a mark.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\scheduler
 */
final class engagement_test extends \advanced_testcase {
    /** @var \stdClass The activity instance. */
    protected \stdClass $instance;

    /** @var \stdClass The learner. */
    protected \stdClass $student;

    /** @var array Question bank entry ids in the pool. */
    protected array $entries = [];

    /**
     * Build a course with five questions and a learner.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/questionlib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();

        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $category = question_get_default_category(\context_module::instance($qbank->cmid)->id);

        $qgen = $generator->get_plugin_generator('core_question');
        foreach (['e1', 'e2', 'e3', 'e4', 'e5'] as $idnumber) {
            $question = $qgen->create_question(
                'shortanswer',
                null,
                ['category' => $category->id, 'idnumber' => $idnumber]
            );
            $this->entries[] = (int)$DB->get_field(
                'question_versions',
                'questionbankentryid',
                ['questionid' => $question->id]
            );
        }

        $module = $generator->create_module('rememberme', ['course' => $course->id]);
        $generator->get_plugin_generator('mod_rememberme')
            ->create_band((int)$module->id, (int)$category->id, 0);

        $this->instance = $DB->get_record('rememberme', ['id' => $module->id], '*', MUST_EXIST);

        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $course->id, 'student');
    }

    /**
     * Read this week's stored row.
     *
     * @param scheduler $scheduler The scheduler.
     * @param int $now The moment to read for.
     * @return \stdClass The week record.
     */
    protected function week_record(scheduler $scheduler, int $now): \stdClass {
        global $DB;

        return $DB->get_record('rememberme_weeks', [
            'rememberme' => $this->instance->id,
            'userid' => $this->student->id,
            'weekno' => $scheduler->get_weeks()->week_for($now),
        ], '*', MUST_EXIST);
    }

    /**
     * Repeating one question cannot stand in for covering the queue.
     *
     * This is the measured exploit: seven wrong attempts on one question used to
     * clear a target of five while four questions were never touched.
     *
     * @return void
     */
    public function test_one_question_cannot_satisfy_a_whole_week(): void {
        $scheduler = new scheduler($this->instance);
        $now = time();
        $weekno = $scheduler->get_weeks()->week_for($now);
        $target = (int)$scheduler->ensure_week_snapshot((int)$this->student->id, $weekno, $now)->snapshottarget;
        $this->assertGreaterThan(1, $target, 'the fixture needs a target worth gaming');

        for ($i = 0; $i < $target + 3; $i++) {
            $scheduler->record_attempt(
                (int)$this->student->id,
                $this->entries[0],
                1,
                'shortanswer',
                0.0,
                5000,
                1,
                $now + $i * HOURSECS
            );
        }

        $week = $this->week_record($scheduler, $now);
        $this->assertSame(1, (int)$week->completed, 'one question is worth one point, however often it is answered');
        $this->assertLessThan(1.0, (float)$week->fraction, 'hammering one question must not clear the week');
    }

    /**
     * Answering different questions does earn credit.
     *
     * The counterpart to the test above: the rule must still let honest work
     * through, or it has simply broken the activity.
     *
     * @return void
     */
    public function test_distinct_questions_each_earn_credit(): void {
        $scheduler = new scheduler($this->instance);
        $now = time();

        foreach ($this->entries as $index => $entry) {
            $scheduler->record_attempt(
                (int)$this->student->id,
                $entry,
                1,
                'shortanswer',
                1.0,
                5000,
                1,
                $now + $index
            );
        }

        $week = $this->week_record($scheduler, $now);
        $this->assertSame(count($this->entries), (int)$week->completed);
    }

    /**
     * An answer too fast to have been read earns nothing.
     *
     * @return void
     */
    public function test_an_unread_answer_does_not_count(): void {
        $scheduler = new scheduler($this->instance);
        $now = time();

        foreach ($this->entries as $index => $entry) {
            $scheduler->record_attempt(
                (int)$this->student->id,
                $entry,
                1,
                'shortanswer',
                1.0,
                50,
                1,
                $now + $index
            );
        }

        $week = $this->week_record($scheduler, $now);
        $this->assertSame(0, (int)$week->completed, 'clicking through must earn nothing');
    }

    /**
     * Those attempts are still recorded, because the log is a complete record.
     *
     * Refusing the credit is a grading decision. Refusing to write down what
     * happened would be a different and worse thing.
     *
     * @return void
     */
    public function test_an_unread_answer_is_still_logged_and_still_schedules(): void {
        global $DB;

        $scheduler = new scheduler($this->instance);
        $now = time();
        $scheduler->record_attempt(
            (int)$this->student->id,
            $this->entries[0],
            1,
            'shortanswer',
            1.0,
            50,
            1,
            $now
        );

        $this->assertSame(1, $DB->count_records('rememberme_review_log', [
            'rememberme' => $this->instance->id,
            'userid' => $this->student->id,
        ]));
        $this->assertNotNull($scheduler->get_state((int)$this->student->id, $this->entries[0]));
    }

    /**
     * The engagement rule fails open when latency was never measured.
     *
     * @return void
     */
    public function test_missing_latency_still_counts(): void {
        $this->assertTrue(scheduler::is_engaged(null));
        $this->assertTrue(scheduler::is_engaged(scheduler::MIN_ENGAGED_LATENCY));
        $this->assertFalse(scheduler::is_engaged(scheduler::MIN_ENGAGED_LATENCY - 1));
    }

    /**
     * A wrong answer brings the question back in the same sitting.
     *
     * @return void
     */
    public function test_a_wrong_answer_returns_within_the_learning_step(): void {
        $scheduler = new scheduler($this->instance);
        $now = time();

        $record = $scheduler->record_attempt(
            (int)$this->student->id,
            $this->entries[0],
            1,
            'shortanswer',
            0.0,
            5000,
            1,
            $now
        );

        $this->assertGreaterThan(0, (int)$record->learningdue);
        $this->assertSame($now + scheduler::LEARNING_STEP, (int)$record->learningdue);

        $stored = $scheduler->get_state((int)$this->student->id, $this->entries[0]);
        $this->assertFalse($scheduler->is_due($stored, $now + 60), 'not due one minute later');
        $this->assertTrue(
            $scheduler->is_due($stored, $now + scheduler::LEARNING_STEP),
            'due once the learning step has elapsed, whatever its stability implies'
        );
    }

    /**
     * Answering it correctly releases it onto its normal schedule.
     *
     * @return void
     */
    public function test_a_correct_answer_clears_the_learning_step(): void {
        $scheduler = new scheduler($this->instance);
        $now = time();

        $scheduler->record_attempt(
            (int)$this->student->id,
            $this->entries[0],
            1,
            'shortanswer',
            0.0,
            5000,
            1,
            $now
        );
        $record = $scheduler->record_attempt(
            (int)$this->student->id,
            $this->entries[0],
            1,
            'shortanswer',
            1.0,
            5000,
            1,
            $now + scheduler::LEARNING_STEP
        );

        $this->assertSame(0, (int)$record->learningdue, 'a correct answer ends the learning step');
        $this->assertGreaterThan(
            $now + scheduler::LEARNING_STEP * 2,
            (int)$record->duedate,
            'and the item goes back on its own schedule'
        );
    }

    /**
     * A question the learner cannot get right stops returning every ten minutes.
     *
     * Without this the activity fills up with one unanswerable question.
     *
     * @return void
     */
    public function test_a_persistently_failed_question_leaves_the_learning_step(): void {
        $scheduler = new scheduler($this->instance);
        $now = time();

        $record = null;
        for ($i = 0; $i < scheduler::LEECH_LAPSES + 1; $i++) {
            $record = $scheduler->record_attempt(
                (int)$this->student->id,
                $this->entries[0],
                1,
                'shortanswer',
                0.0,
                5000,
                1,
                $now + $i * scheduler::LEARNING_STEP
            );
        }

        $this->assertGreaterThanOrEqual(scheduler::LEECH_LAPSES, (int)$record->lapses);
        $this->assertSame(
            0,
            (int)$record->learningdue,
            'past the lapse threshold the question returns to its normal schedule'
        );
    }
}
