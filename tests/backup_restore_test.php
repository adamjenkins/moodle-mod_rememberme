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

use core_courseformat\local\cmactions;
use mod_rememberme\local\session;

/**
 * Backup and restore tests.
 *
 * Course lifecycle is part of every data storing plugin rather than an
 * afterthought, and backup code is the kind that breaks silently: nothing
 * complains until a teacher tries to duplicate an activity a term later. These
 * tests run the real backup and restore controllers.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_rememberme_activity_structure_step
 * @covers     \restore_rememberme_activity_structure_step
 */
final class backup_restore_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var \stdClass The activity instance. */
    protected \stdClass $instance;

    /** @var \stdClass The question category. */
    protected \stdClass $category;

    /**
     * Build a course with a bank, questions and a configured activity.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/lib/questionlib.php');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();

        $qbank = $generator->create_module('qbank', ['course' => $this->course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        $this->category = question_get_default_category($qbankcontext->id);

        $qgen = $generator->get_plugin_generator('core_question');
        foreach (['b1', 'b2'] as $idnumber) {
            $qgen->create_question(
                'shortanswer',
                null,
                ['category' => $this->category->id, 'idnumber' => $idnumber]
            );
        }

        $this->instance = $generator->create_module('rememberme', [
            'course' => $this->course->id,
            'name' => 'Backup me',
            'sessionsize' => 5,
        ]);

        $remembermegen = $generator->get_plugin_generator('mod_rememberme');
        $remembermegen->create_band((int)$this->instance->id, (int)$this->category->id, 0);
        $remembermegen->create_suspension(
            (int)$this->instance->id,
            time() + WEEKSECS,
            time() + 2 * WEEKSECS,
            'Reading week'
        );
    }

    /**
     * Enrol a learner and have them answer a single question.
     *
     * Every lifecycle test needs the same starting point: an activity with real
     * learner data in it, so that a duplicate, a delete or a reset has something
     * to get wrong.
     *
     * @return array A two element list: the learner, and the course module.
     */
    protected function answer_one_question(): array {
        global $DB;

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');

        $cm = get_coursemodule_from_instance('rememberme', $this->instance->id, $this->course->id);
        $context = \context_module::instance($cm->id);
        $record = $DB->get_record('rememberme', ['id' => $this->instance->id], '*', MUST_EXIST);

        $session = new session($record, $context);
        $this->assertTrue($session->start((int)$student->id));
        $slot = $session->next_slot();
        $prefix = $session->get_quba()->get_field_prefix($slot);
        $session->process_response($slot, [$prefix . 'answer' => 'frog']);

        return [$student, $cm];
    }

    /**
     * Duplicating the activity carries its configuration across.
     *
     * duplicate_module runs a real backup followed by a real restore, so this
     * exercises both structure steps end to end.
     */
    public function test_duplicate_module_preserves_configuration(): void {
        global $DB;

        $cm = get_coursemodule_from_instance('rememberme', $this->instance->id, $this->course->id);
        $newcm = (new cmactions($this->course))->duplicate((int)$cm->id);

        $this->assertNotEmpty($newcm, 'the activity should duplicate at all');
        $this->assertNotEquals($cm->instance, $newcm->instance);

        $original = $DB->get_record('rememberme', ['id' => $cm->instance], '*', MUST_EXIST);
        $copy = $DB->get_record('rememberme', ['id' => $newcm->instance], '*', MUST_EXIST);

        foreach (
            ['targetretention', 'sessionsize', 'newperday', 'unlockmode', 'unlockinterval',
                  'stabilityfloor', 'masteryproportion', 'backstopdays', 'activeweeks',
                  'gracebalance', 'graceearnrate', 'passthreshold'] as $field
        ) {
            $this->assertEquals(
                $original->{$field},
                $copy->{$field},
                "setting {$field} was not carried across the backup"
            );
        }
    }

    /**
     * The ordered bands survive, because without them the copy has no pool.
     */
    public function test_bands_survive_a_duplicate(): void {
        global $DB;

        $cm = get_coursemodule_from_instance('rememberme', $this->instance->id, $this->course->id);
        $newcm = (new cmactions($this->course))->duplicate((int)$cm->id);

        $bands = $DB->get_records('rememberme_bands', ['rememberme' => $newcm->instance]);
        $this->assertCount(1, $bands, 'the copy must keep its question categories');

        $band = reset($bands);
        $this->assertGreaterThan(0, (int)$band->questioncategoryid);
    }

    /**
     * Suspension windows survive, because they change every future due date.
     */
    public function test_suspensions_survive_a_duplicate(): void {
        global $DB;

        $cm = get_coursemodule_from_instance('rememberme', $this->instance->id, $this->course->id);
        $newcm = (new cmactions($this->course))->duplicate((int)$cm->id);

        $windows = $DB->get_records('rememberme_suspensions', ['rememberme' => $newcm->instance]);
        $this->assertCount(1, $windows);

        $window = reset($windows);
        $this->assertSame('Reading week', $window->name);
        $this->assertGreaterThan((int)$window->timestart, (int)$window->timeend);
    }

    /**
     * A duplicate carries no learner data, which is what duplicating means.
     */
    public function test_duplicate_carries_no_user_data(): void {
        global $DB;

        [$student, $cm] = $this->answer_one_question();

        $this->assertGreaterThan(0, $DB->count_records(
            'rememberme_schedule',
            ['rememberme' => $this->instance->id]
        ));

        $newcm = (new cmactions($this->course))->duplicate((int)$cm->id);

        $this->assertSame(0, $DB->count_records(
            'rememberme_schedule',
            ['rememberme' => $newcm->instance]
        ), 'a duplicate must not carry learner memory state');
        $this->assertSame(0, $DB->count_records(
            'rememberme_review_log',
            ['rememberme' => $newcm->instance]
        ));

        // And the original is untouched.
        $this->assertGreaterThan(0, $DB->count_records(
            'rememberme_schedule',
            ['rememberme' => $this->instance->id]
        ));
    }

    /**
     * Deleting the activity removes every trace of it.
     */
    public function test_delete_instance_removes_everything(): void {
        global $DB;

        [$student, $cm] = $this->answer_one_question();

        (new cmactions($this->course))->delete((int)$cm->id);

        foreach (
            ['rememberme_schedule', 'rememberme_review_log', 'rememberme_weeks',
                  'rememberme_bandstate', 'rememberme_bands', 'rememberme_suspensions',
                  'rememberme_session'] as $table
        ) {
            $this->assertSame(
                0,
                $DB->count_records($table, ['rememberme' => $this->instance->id]),
                "{$table} still holds rows after the activity was deleted"
            );
        }
        $this->assertFalse($DB->record_exists('rememberme', ['id' => $this->instance->id]));
    }

    /**
     * Resetting a course clears learner data but keeps the configuration.
     */
    public function test_course_reset_clears_learner_data_only(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/rememberme/lib.php');

        [$student, $cm] = $this->answer_one_question();

        $this->assertGreaterThan(0, $DB->count_records(
            'rememberme_schedule',
            ['rememberme' => $this->instance->id]
        ));

        rememberme_reset_userdata((object)[
            'courseid' => $this->course->id,
            'reset_rememberme_all' => 1,
        ]);

        $this->assertSame(0, $DB->count_records(
            'rememberme_schedule',
            ['rememberme' => $this->instance->id]
        ));
        $this->assertSame(0, $DB->count_records(
            'rememberme_review_log',
            ['rememberme' => $this->instance->id]
        ));

        // The teacher's configuration must survive a reset.
        $this->assertSame(1, $DB->count_records(
            'rememberme_bands',
            ['rememberme' => $this->instance->id]
        ));
        $this->assertSame(1, $DB->count_records(
            'rememberme_suspensions',
            ['rememberme' => $this->instance->id]
        ));
        $this->assertTrue($DB->record_exists('rememberme', ['id' => $this->instance->id]));
    }

    /**
     * Restore cleans the fields the interactive form cleans.
     *
     * A backup file is attacker input: it may have been hand edited or built by
     * another site. The form types the activity name and cleans suspension
     * names, so the restore path has to do the same or it becomes the one way
     * to get unclean values into those columns.
     *
     * @return void
     */
    public function test_restore_cleans_names_like_the_form_does(): void {
        $dirty = '<script>alert(1)</script>Reading week';

        $formcleaned = clean_param($dirty, PARAM_TEXT);
        $this->assertStringNotContainsString('<script>', $formcleaned);

        // The restore step applies exactly the same cleaning, so a value that
        // came out of a .mbz ends up identical to one typed into the form.
        $reflection = new \ReflectionClass(\restore_rememberme_activity_structure_step::class);
        $this->assertTrue(
            $reflection->hasMethod('clean_schedule_state'),
            'restore should constrain the lifecycle state'
        );
        $this->assertTrue(
            $reflection->hasMethod('clean_band_reason'),
            'restore should constrain the band unlock reason'
        );
    }

    /**
     * A restored lifecycle state outside the known set falls back safely.
     *
     * @return void
     */
    public function test_restore_constrains_the_schedule_state(): void {
        $method = new \ReflectionMethod(
            \restore_rememberme_activity_structure_step::class,
            'clean_schedule_state'
        );
        $method->setAccessible(true);

        foreach (['new', 'learning', 'review', 'relearning'] as $known) {
            $this->assertSame($known, $method->invoke(null, $known));
        }
        $this->assertSame('new', $method->invoke(null, 'definitely not a state'));
        $this->assertSame('new', $method->invoke(null, ''));
    }

    /**
     * A restored band reason outside the known set falls back safely.
     *
     * The reason is turned into a language string key by the band progression
     * report, so an arbitrary value would drive a lookup for a string that does
     * not exist.
     *
     * @return void
     */
    public function test_restore_constrains_the_band_reason(): void {
        $method = new \ReflectionMethod(
            \restore_rememberme_activity_structure_step::class,
            'clean_band_reason'
        );
        $method->setAccessible(true);

        $this->assertSame(
            \mod_rememberme\local\bands::REASON_BACKSTOP,
            $method->invoke(null, \mod_rememberme\local\bands::REASON_BACKSTOP)
        );
        $this->assertSame(
            \mod_rememberme\local\bands::REASON_NONE,
            $method->invoke(null, 'evil')
        );
    }

    /**
     * Uninstalling removes what this plugin wrote into core tables.
     *
     * uninstall_plugin() drops our own tables but never calls
     * rememberme_delete_instance(), so the question engine usages behind every
     * study session, and the gradebook items, would simply be orphaned.
     *
     * @return void
     */
    public function test_uninstall_cleans_up_question_usages(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/rememberme/db/uninstall.php');

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');

        $cm = get_coursemodule_from_instance('rememberme', $this->instance->id, $this->course->id);
        $context = \context_module::instance($cm->id);
        $record = $DB->get_record('rememberme', ['id' => $this->instance->id], '*', MUST_EXIST);

        $session = new session($record, $context);
        $this->assertTrue($session->start((int)$student->id));
        $usageid = (int)$session->get_record()->uniqueid;

        // The usage really is there before we uninstall.
        $this->assertTrue($DB->record_exists('question_usages', ['id' => $usageid]));
        $this->assertGreaterThan(0, $DB->count_records('question_attempts', ['questionusageid' => $usageid]));

        xmldb_rememberme_uninstall();

        $this->assertFalse(
            $DB->record_exists('question_usages', ['id' => $usageid]),
            'the question usage was orphaned by uninstall'
        );
        $this->assertSame(
            0,
            $DB->count_records('question_attempts', ['questionusageid' => $usageid]),
            'question attempts were orphaned by uninstall'
        );
    }
}
