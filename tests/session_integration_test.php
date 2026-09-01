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
use mod_rememberme\local\session;

/**
 * End to end tests: a real question bank, a real question engine usage, a real answer.
 *
 * The unit tests cover the memory model in isolation. These cover the seam that
 * unit tests cannot reach: that a question actually loads out of a 5.x question
 * bank, that the question engine grades it, and that the grade turns into stored
 * memory state.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\session
 * @covers     \mod_rememberme\local\scheduler
 * @covers     \mod_rememberme\local\pool
 */
final class session_integration_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var \stdClass The activity instance. */
    protected \stdClass $instance;

    /** @var \context_module The module context. */
    protected \context_module $context;

    /** @var \stdClass The learner. */
    protected \stdClass $student;

    /** @var array The created questions, keyed by idnumber. */
    protected array $questions = [];

    /**
     * Build a course with a question bank, questions, and a bound activity.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();

        // Question banks are mod_qbank instances in Moodle 5.x, and a question
        // category lives in that module's context.
        $qbank = $generator->create_module('qbank', ['course' => $this->course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        $category = question_get_default_category($qbankcontext->id);

        $qgen = $generator->get_plugin_generator('core_question');
        foreach (['q1', 'q2', 'q3'] as $idnumber) {
            $this->questions[$idnumber] = $qgen->create_question(
                'shortanswer',
                null,
                ['category' => $category->id, 'idnumber' => $idnumber]
            );
        }

        $module = $generator->create_module('rememberme', ['course' => $this->course->id]);
        $generator->get_plugin_generator('mod_rememberme')
            ->create_band((int)$module->id, (int)$category->id, 0);

        $this->instance = $module;
        $this->context = \context_module::instance($module->cmid);

        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Reload the instance record with all of its columns.
     *
     * @return \stdClass The instance.
     */
    protected function instance_record(): \stdClass {
        global $DB;

        return $DB->get_record('rememberme', ['id' => $this->instance->id], '*', MUST_EXIST);
    }

    /**
     * The pool resolves questions out of a real 5.x question bank.
     */
    public function test_pool_resolves_questions_from_the_bank(): void {
        $scheduler = new scheduler($this->instance_record());
        $entries = $scheduler->get_pool()->get_all_entries();

        $this->assertCount(3, $entries, 'all three bank entries should be in the pool');

        foreach ($entries as $qbeid => $entry) {
            $this->assertGreaterThan(0, (int)$qbeid);
            $this->assertGreaterThan(0, (int)$entry->questionid);
            $this->assertSame('shortanswer', $entry->qtype);
        }
    }

    /**
     * A brand new learner is offered new items from the first band.
     */
    public function test_new_learner_gets_new_items(): void {
        $scheduler = new scheduler($this->instance_record());
        $queue = $scheduler->get_due_questions((int)$this->student->id);

        $this->assertCount(3, $queue);
        foreach ($queue as $entry) {
            $this->assertTrue($entry->isnew, 'an unseen item must be offered as new');
            $this->assertSame(1, $entry->bandlevel);
        }
    }

    /**
     * The per day cap limits how many new items are introduced.
     */
    public function test_new_items_are_capped_per_day(): void {
        global $DB;

        $DB->set_field('rememberme', 'newperday', 2, ['id' => $this->instance->id]);

        $scheduler = new scheduler($this->instance_record());
        $queue = $scheduler->get_due_questions((int)$this->student->id);

        $this->assertCount(2, $queue, 'the new item cap must bound the session');
    }

    /**
     * A session builds a real question engine usage with a slot per question.
     */
    public function test_session_builds_a_question_usage(): void {
        global $DB;

        $session = new session($this->instance_record(), $this->context);
        $this->assertTrue($session->start((int)$this->student->id));

        $record = $session->get_record();
        $this->assertGreaterThan(0, (int)$record->uniqueid);
        $this->assertSame(3, (int)$record->itemcount);

        // The usage is genuinely persisted and reloadable.
        $quba = \question_engine::load_questions_usage_by_activity((int)$record->uniqueid);
        $this->assertCount(3, $quba->get_slots());
        $this->assertSame('mod_rememberme', $quba->get_owning_component());

        $slots = $DB->get_records('rememberme_slot', ['sessionid' => $record->id]);
        $this->assertCount(3, $slots);
    }

    /**
     * Answering correctly creates memory state, a review log row, and a due date.
     *
     * This is the whole point of the plugin, so it is asserted at every layer
     * rather than trusting that no exception was thrown.
     */
    public function test_correct_answer_creates_memory_state(): void {
        global $DB;

        $now = time();
        $session = new session($this->instance_record(), $this->context);
        $this->assertTrue($session->start((int)$this->student->id, $now));

        $slot = $session->next_slot();
        $this->assertNotNull($slot);

        $prefix = $session->get_quba()->get_field_prefix($slot);
        $result = $session->process_response($slot, [$prefix . 'answer' => 'frog'], $now);

        $this->assertTrue($result['correct'], 'frog is the correct answer to the core test question');
        $this->assertEqualsWithDelta(1.0, $result['fraction'], 1.0E-9);

        $schedule = $DB->get_records('rememberme_schedule', [
            'rememberme' => $this->instance->id,
            'userid' => $this->student->id,
        ]);
        $this->assertCount(1, $schedule, 'exactly one item should have been scheduled');

        $record = reset($schedule);
        $this->assertGreaterThan(0, (float)$record->stability, 'a correct answer must build stability');
        $this->assertGreaterThan(0, (float)$record->difficulty);
        $this->assertSame(1, (int)$record->reps);
        $this->assertSame(0, (int)$record->lapses);
        $this->assertGreaterThan($now, (int)$record->duedate, 'the item must be scheduled into the future');
        $this->assertSame('learning', $record->state);

        // The review log is non negotiable: it is the only history there is.
        $logs = $DB->get_records('rememberme_review_log', [
            'rememberme' => $this->instance->id,
            'userid' => $this->student->id,
        ]);
        $this->assertCount(1, $logs);
        $log = reset($logs);
        $this->assertSame('shortanswer', $log->qtype);
        $this->assertEqualsWithDelta(1.0, (float)$log->fraction, 1.0E-9);
        $this->assertEqualsWithDelta((float)$record->stability, (float)$log->stabilityafter, 1.0E-6);
        $this->assertGreaterThan(0, (int)$log->weekno);
    }

    /**
     * A wrong answer records a lapse and brings the item back sooner.
     */
    public function test_wrong_answer_records_a_lapse(): void {
        global $DB;

        $now = time();
        $session = new session($this->instance_record(), $this->context);
        $this->assertTrue($session->start((int)$this->student->id, $now));

        $slot = $session->next_slot();
        $prefix = $session->get_quba()->get_field_prefix($slot);
        $result = $session->process_response($slot, [$prefix . 'answer' => 'definitely wrong'], $now);

        $this->assertFalse($result['correct']);

        $record = $DB->get_record('rememberme_schedule', [
            'rememberme' => $this->instance->id,
            'userid' => $this->student->id,
        ], '*', MUST_EXIST);

        $this->assertSame(0, (int)$record->reps);
        $this->assertSame(1, (int)$record->lapses);
        $this->assertSame('relearning', $record->state);

        // A first attempt failure seeds a low stability, so the item returns
        // within the day rather than being parked for a week.
        $this->assertLessThan(
            $now + DAYSECS,
            (int)$record->duedate,
            'a failed new item must come back the same day'
        );
    }

    /**
     * The schedule is keyed on the bank entry, so editing a question keeps it.
     *
     * This is the behaviour that makes a mid course typo fix safe: it must not
     * orphan every learner's memory state for that question.
     */
    public function test_schedule_survives_a_new_question_version(): void {
        global $DB;

        $now = time();
        $session = new session($this->instance_record(), $this->context);
        $session->start((int)$this->student->id, $now);
        $slot = $session->next_slot();
        $prefix = $session->get_quba()->get_field_prefix($slot);
        $session->process_response($slot, [$prefix . 'answer' => 'frog'], $now);

        $record = $DB->get_record('rememberme_schedule', [
            'rememberme' => $this->instance->id,
            'userid' => $this->student->id,
        ], '*', MUST_EXIST);
        $qbeid = (int)$record->questionbankentryid;
        $originalstability = (float)$record->stability;

        // Simulate an edit: a new version row under the same bank entry.
        $oldversion = $DB->get_record('question_versions', ['questionbankentryid' => $qbeid], '*', MUST_EXIST);
        $newquestionid = $DB->insert_record('question', (object)[
            'parent' => 0,
            'name' => 'Edited question',
            'questiontext' => 'Edited',
            'questiontextformat' => FORMAT_HTML,
            'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML,
            'defaultmark' => 1,
            'penalty' => 0.3333333,
            'qtype' => 'shortanswer',
            'length' => 1,
            'stamp' => make_unique_id_code(),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('question_versions', (object)[
            'questionbankentryid' => $qbeid,
            'version' => (int)$oldversion->version + 1,
            'questionid' => $newquestionid,
            'status' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
        ]);

        // The memory state is untouched, and the pool now serves the new version.
        $scheduler = new scheduler($this->instance_record());
        $state = $scheduler->get_state((int)$this->student->id, $qbeid);
        $this->assertNotNull($state, 'editing a question must not orphan the schedule record');
        $this->assertEqualsWithDelta($originalstability, (float)$state->stability, 1.0E-9);

        $resolved = $scheduler->get_pool()->resolve_entry($qbeid);
        $this->assertSame($newquestionid, (int)$resolved->questionid, 'the latest ready version should be served');
    }

    /**
     * A suspension window defers a due date to the day the learner returns.
     */
    public function test_suspension_window_defers_the_due_date(): void {
        global $DB;

        $now = time();

        // A break starting in one hour and lasting a fortnight.
        $this->getDataGenerator()->get_plugin_generator('mod_rememberme')
            ->create_suspension((int)$this->instance->id, $now + HOURSECS, $now + HOURSECS + 14 * DAYSECS);

        $session = new session($this->instance_record(), $this->context);
        $session->start((int)$this->student->id, $now);
        $slot = $session->next_slot();
        $prefix = $session->get_quba()->get_field_prefix($slot);
        $session->process_response($slot, [$prefix . 'answer' => 'frog'], $now);

        $record = $DB->get_record('rememberme_schedule', [
            'rememberme' => $this->instance->id,
            'userid' => $this->student->id,
        ], '*', MUST_EXIST);

        // A correct new item is due in about three days of working time. With a
        // fortnight of suspended time in the way, that has to land after the
        // break rather than inside it.
        $this->assertGreaterThan(
            $now + 14 * DAYSECS,
            (int)$record->duedate,
            'the due date must not fall inside a suspension window'
        );
    }

    /**
     * The weekly target is frozen when the week is first touched.
     */
    public function test_week_snapshot_is_frozen(): void {
        global $DB;

        $now = time();
        $scheduler = new scheduler($this->instance_record());
        $weekno = $scheduler->get_weeks()->week_for($now);

        $week = $scheduler->ensure_week_snapshot((int)$this->student->id, $weekno, $now);
        $target = (int)$week->snapshottarget;
        $this->assertGreaterThan(0, $target);

        // Answering does not enlarge this week's target.
        $session = new session($this->instance_record(), $this->context);
        $session->start((int)$this->student->id, $now);
        $slot = $session->next_slot();
        $prefix = $session->get_quba()->get_field_prefix($slot);
        $session->process_response($slot, [$prefix . 'answer' => 'frog'], $now);

        $after = $DB->get_record('rememberme_weeks', [
            'rememberme' => $this->instance->id,
            'userid' => $this->student->id,
            'weekno' => $weekno,
        ], '*', MUST_EXIST);

        $this->assertSame($target, (int)$after->snapshottarget, 'the weekly target must never grow mid week');
        $this->assertSame(1, (int)$after->completed);
    }

    /**
     * Answering the same slot twice is refused rather than double counted.
     */
    public function test_a_slot_cannot_be_answered_twice(): void {
        $now = time();
        $session = new session($this->instance_record(), $this->context);
        $session->start((int)$this->student->id, $now);
        $slot = $session->next_slot();
        $prefix = $session->get_quba()->get_field_prefix($slot);
        $session->process_response($slot, [$prefix . 'answer' => 'frog'], $now);

        $this->expectException(\moodle_exception::class);
        $session->process_response($slot, [$prefix . 'answer' => 'frog'], $now);
    }

    /**
     * Grading is adherence, not accuracy.
     *
     * A learner who answers everything wrong but keeps up with the schedule must
     * not be graded below one who answers everything right. Accuracy is the
     * scheduler's input signal and must never leak into the grade.
     */
    public function test_grade_ignores_accuracy(): void {
        $now = time();

        $wronglearner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($wronglearner->id, $this->course->id, 'student');

        foreach ([[$this->student, 'frog'], [$wronglearner, 'wrong']] as [$user, $answer]) {
            $session = new session($this->instance_record(), $this->context);
            $session->start((int)$user->id, $now);
            while (($slot = $session->next_slot()) !== null) {
                $prefix = $session->get_quba()->get_field_prefix($slot);
                $session->process_response($slot, [$prefix . 'answer' => $answer], $now);
            }
        }

        $scheduler = new scheduler($this->instance_record());
        $right = $scheduler->final_grade((int)$this->student->id, $now);
        $wrong = $scheduler->final_grade((int)$wronglearner->id, $now);

        $this->assertEqualsWithDelta(
            $right['proportion'],
            $wrong['proportion'],
            1.0E-9,
            'accuracy must not affect the grade at all'
        );
    }
}
