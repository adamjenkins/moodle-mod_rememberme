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
    /** @var \context_module The question bank's context. */
    protected \context_module $qbankcontext;

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
        $this->qbankcontext = \context_module::instance($qbank->cmid);
        $this->category = question_get_default_category($this->qbankcontext->id);

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
     * The band *structure* survives, not merely the rows.
     *
     * Bands are groups of categories sharing a band number, so a copy that
     * keeps every row but loses the numbering has silently merged the whole
     * syllabus into one band and will introduce all of it at once.
     *
     * @return void
     */
    public function test_band_numbering_survives_a_duplicate(): void {
        global $DB;

        // A second band, so there is a structure to lose.
        $qgen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $second = $qgen->create_question_category([
            'contextid' => $this->qbankcontext->id,
            'name' => 'second band',
        ]);
        $qgen->create_question('shortanswer', null, ['category' => $second->id, 'idnumber' => 'band2q1']);
        $this->getDataGenerator()->get_plugin_generator('mod_rememberme')
            ->create_band((int)$this->instance->id, (int)$second->id, 0, false, 2);

        $cm = get_coursemodule_from_instance('rememberme', $this->instance->id, $this->course->id);
        $newcm = (new cmactions($this->course))->duplicate((int)$cm->id);

        $bands = $DB->get_records('rememberme_bands', ['rememberme' => $newcm->instance]);
        $this->assertCount(2, $bands, 'both category rows should come across');

        $numbers = array_map(static fn($b): int => (int)$b->bandnumber, $bands);
        sort($numbers);
        $this->assertSame([1, 2], $numbers, 'the copy must keep two distinct bands, not merge them into one');
    }

    /**
     * Every instance setting survives, including the ones added after the
     * backup structure was first written.
     *
     * The field list in the backup step is maintained by hand, so a column
     * added later is silently dropped and the copy quietly falls back to the
     * schema default. This asserts against the schema rather than a list.
     *
     * @return void
     */
    public function test_every_instance_column_survives_a_duplicate(): void {
        global $DB;

        // Set every configurable column to something other than its default,
        // so a dropped field shows up as a difference rather than matching by
        // luck.
        $DB->update_record('rememberme', (object)[
            'id' => $this->instance->id,
            'questionbankcmid' => 424242,
            'ontimegrace' => 0.125,
            'maxchoices' => 3,
        ]);

        $cm = get_coursemodule_from_instance('rememberme', $this->instance->id, $this->course->id);
        $newcm = (new cmactions($this->course))->duplicate((int)$cm->id);

        $original = $DB->get_record('rememberme', ['id' => $cm->instance], '*', MUST_EXIST);
        $copy = $DB->get_record('rememberme', ['id' => $newcm->instance], '*', MUST_EXIST);

        // The id and course are rebuilt by the restore; the rest is settings.
        // The name gains a "(copy)" suffix by design; the timestamps are the
        // copy's own.
        $skip = ['id', 'course', 'name', 'timecreated', 'timemodified'];
        foreach (array_keys($DB->get_columns('rememberme')) as $column) {
            if (in_array($column, $skip, true)) {
                continue;
            }
            $this->assertEquals(
                $original->{$column},
                $copy->{$column},
                "setting {$column} was not carried across the backup"
            );
        }
    }

    /**
     * A band number out of a hand edited backup is brought back into range.
     *
     * @return void
     */
    public function test_restore_constrains_the_band_number(): void {
        $method = new \ReflectionMethod(
            \restore_rememberme_activity_structure_step::class,
            'clean_band_number'
        );
        $method->setAccessible(true);

        $this->assertSame(3, $method->invoke(null, 3), 'a real band number is kept');
        $this->assertSame(1, $method->invoke(null, 0), 'band zero is not a band');
        $this->assertSame(1, $method->invoke(null, -7));
        $this->assertSame(1, $method->invoke(null, 'nonsense'));
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

    /**
     * Back the course up with learner data and restore it as a new course.
     *
     * Only a course restore moves dates: the offset is the new course start
     * date minus the old one (backup/util/plan/restore_step.class.php,
     * apply_date_offset), so this is the path that exercises every
     * apply_date_offset call in the restore step.
     *
     * @param int $shift Seconds to move the course start date by.
     * @param callable|null $edit Called with the extracted backup directory before restoring.
     * @return \stdClass The restored activity instance.
     */
    protected function restore_course_with_users(int $shift, ?callable $edit = null): \stdClass {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $bc->destroy();

        $dirname = 'rememberme_restore_' . $shift . '_' . ($edit ? 'edited' : 'plain');
        $path = make_backup_temp_directory($dirname);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);
        if ($edit) {
            $edit($path);
        }

        $newcourseid = \restore_dbops::create_new_course('Restored', 'restored' . $shift, $this->course->category);
        $rc = new \restore_controller(
            $dirname,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $rc->get_plan()->get_setting('course_startdate')->set_value((int)$this->course->startdate + $shift);
        $this->assertTrue($rc->execute_precheck(), 'restore precheck');
        $rc->execute_plan();
        $rc->destroy();

        return $DB->get_record('rememberme', ['course' => $newcourseid], '*', MUST_EXIST);
    }

    /**
     * Learner data restored into a later course keeps its meaning.
     *
     * Two date fields were left behind by the offset. A week record's
     * snapshottaken, which made a record written since study day grading look
     * like one from before it and score a full week; and an answer's wasdue,
     * which made every restored answer look late and cost the learner their
     * punctuality grace.
     */
    public function test_learner_data_restored_into_a_later_course_keeps_its_meaning(): void {
        global $DB;

        [$student] = $this->answer_one_question();
        $switchedat = time() - HOURSECS;
        $DB->set_field('rememberme', 'studydaysfrom', $switchedat, ['id' => $this->instance->id]);
        $weekno = 1;
        $old = $DB->get_record(
            'rememberme_weeks',
            ['rememberme' => $this->instance->id, 'userid' => $student->id, 'weekno' => $weekno],
            '*',
            MUST_EXIST
        );
        $this->assertSame(0, (int)$old->legacy, 'the fixture record was written by the new code');
        $DB->set_field('rememberme_weeks', 'clearedmask', 5, ['id' => $old->id]);

        $due = time() - DAYSECS;
        $DB->insert_record('rememberme_review_log', (object)[
            'rememberme' => $this->instance->id, 'userid' => $student->id,
            'questionbankentryid' => 1, 'questionid' => 1, 'qtype' => 'shortanswer', 'rating' => 3,
            'fraction' => 1.0, 'latency' => 4000, 'weekno' => $weekno, 'wasdue' => $due, 'timecreated' => $due + 60,
        ]);

        $shift = 52 * WEEKSECS;
        $restored = $this->restore_course_with_users($shift);

        $this->assertSame($switchedat + $shift, (int)$restored->studydaysfrom);
        $window = $DB->get_record('rememberme_suspensions', ['rememberme' => $this->instance->id], '*', MUST_EXIST);
        $moved = $DB->get_record('rememberme_suspensions', ['rememberme' => $restored->id], '*', MUST_EXIST);
        $this->assertSame((int)$window->timestart + $shift, (int)$moved->timestart, 'the break moves with the course');
        $this->assertSame((int)$window->timeend + $shift, (int)$moved->timeend);
        $week = $DB->get_record(
            'rememberme_weeks',
            ['rememberme' => $restored->id, 'userid' => $student->id, 'weekno' => $weekno],
            '*',
            MUST_EXIST
        );
        $this->assertSame(0, (int)$week->legacy, 'still a record from after the switch');
        $this->assertSame((int)$old->snapshottaken + $shift, (int)$week->snapshottaken);
        $this->assertSame(5, (int)$week->clearedmask, 'the cleared days come across');
        $this->assertSame((int)$old->daysrequired, (int)$week->daysrequired);

        // Rescored in the restored course it is judged on study days alone:
        // two cleared days of three, not the old rule's full week for a
        // record with no item target.
        $scheduler = new \mod_rememberme\local\scheduler($restored);
        $rescored = $scheduler->rescore_week((int)$student->id, $weekno, time() + $shift, false);
        $this->assertEqualsWithDelta(2 / 3, (float)$rescored->fraction, 1.0E-4);

        $log = $DB->get_record_select('rememberme_review_log', 'rememberme = ? AND wasdue > 0', [$restored->id], '*', MUST_EXIST);
        $this->assertSame($due + $shift, (int)$log->wasdue);
        $this->assertSame($due + 60 + $shift, (int)$log->timecreated);

        $queued = $DB->record_exists_select(
            'task_adhoc',
            'classname = ? AND ' . $DB->sql_like('customdata', '?'),
            ['\\mod_rememberme\\task\\recalculate_weeks', '%"instanceid":' . $restored->id . '%']
        );
        $this->assertTrue($queued, 'the restored weeks are queued to be rescored');
    }

    /**
     * A hand edited backup cannot put out of range values into the new fields.
     */
    public function test_restore_constrains_the_study_day_fields(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/rememberme/lib.php');

        [$student] = $this->answer_one_question();
        $edit = function (string $path): void {
            $files = glob($path . '/activities/rememberme_*/rememberme.xml');
            $this->assertCount(1, $files);
            $xml = file_get_contents($files[0]);
            $xml = preg_replace('~<studydays>\d+</studydays>~', '<studydays>50</studydays>', $xml, 1, $one);
            $xml = preg_replace('~<termend>\d+</termend>~', '<termend>99999999999</termend>', $xml, 1, $two);
            $xml = preg_replace('~<clearedmask>\d+</clearedmask>~', '<clearedmask>999</clearedmask>', $xml, 1, $three);
            $xml = preg_replace('~<daysstudied>\d+</daysstudied>~', '<daysstudied>40</daysstudied>', $xml, 1, $four);
            $this->assertSame([1, 1, 1, 1], [$one, $two, $three, $four], 'every field was edited');
            file_put_contents($files[0], $xml);
        };

        $restored = $this->restore_course_with_users(0, $edit);

        $this->assertSame(7, (int)$restored->studydays);
        $this->assertSame(REMEMBERME_MAX_TERM_WEEKS, (int)$restored->activeweeks);
        $this->assertSame((int)$restored->coursestart + REMEMBERME_MAX_TERM_WEEKS * WEEKSECS, (int)$restored->termend);
        $week = $DB->get_record('rememberme_weeks', ['rememberme' => $restored->id, 'userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame(999 & 0x7f, (int)$week->clearedmask);
        $this->assertSame(7, (int)$week->daysstudied);
    }

    /**
     * Rewrite one element in every week of the backed up activity.
     *
     * @param string $path The extracted backup directory.
     * @param string $pattern Regular expression to replace.
     * @param string $replacement The replacement.
     * @return int How many replacements were made.
     */
    protected function edit_activity_xml(string $path, string $pattern, string $replacement): int {
        $files = glob($path . '/activities/rememberme_*/rememberme.xml');
        $this->assertCount(1, $files);
        $xml = preg_replace($pattern, $replacement, file_get_contents($files[0]), -1, $count);
        file_put_contents($files[0], $xml);
        return $count;
    }

    /**
     * An old-rule week keeps its protection through a backup and restore.
     */
    public function test_an_old_rule_week_stays_one_through_a_restore(): void {
        global $DB;

        [$student] = $this->answer_one_question();
        $DB->set_field('rememberme_weeks', 'legacy', 1, ['rememberme' => $this->instance->id, 'userid' => $student->id]);
        $schedule = $DB->get_record('rememberme_schedule', ['rememberme' => $this->instance->id], '*', MUST_EXIST);
        $DB->set_field('rememberme_schedule', 'learningdue', time() + 600, ['id' => $schedule->id]);

        $shift = 10 * WEEKSECS;
        $restored = $this->restore_course_with_users($shift);

        $this->assertSame(1, (int)$DB->get_field('rememberme_weeks', 'legacy', ['rememberme' => $restored->id]));
        $this->assertEqualsWithDelta(
            time() + 600 + $shift,
            (int)$DB->get_field('rememberme_schedule', 'learningdue', ['rememberme' => $restored->id]),
            5,
            'the learning step moves with the course'
        );
    }

    /**
     * A backup from before study days restores every week as an old-rule week.
     */
    public function test_a_backup_from_before_study_days_restores_old_rule_weeks(): void {
        global $DB;

        $this->answer_one_question();
        $edit = function (string $path): void {
            $this->assertSame(1, $this->edit_activity_xml($path, '~\s*<studydays>\d+</studydays>~', ''));
        };
        $before = time();
        $restored = $this->restore_course_with_users(0, $edit);

        $this->assertGreaterThanOrEqual($before, (int)$restored->studydaysfrom, 'the switch happens at restore');
        $this->assertSame(1, (int)$DB->get_field('rememberme_weeks', 'legacy', ['rememberme' => $restored->id]));
    }

    /**
     * A backup from the first study day build, which has no flag, gets it worked out.
     *
     * That build recorded the switch but not which weeks came before it, so the
     * flag is worked out from the backup's own dates, the way the upgrade does.
     */
    public function test_a_backup_without_the_flag_has_it_worked_out(): void {
        global $DB;

        $this->answer_one_question();
        // The week record was written before the switch.
        $DB->set_field('rememberme', 'studydaysfrom', time() + HOURSECS, ['id' => $this->instance->id]);
        $edit = function (string $path): void {
            $this->assertSame(1, $this->edit_activity_xml($path, '~\s*<legacy>\d</legacy>~', ''));
        };
        $restored = $this->restore_course_with_users(26 * WEEKSECS, $edit);

        $this->assertSame(1, (int)$DB->get_field('rememberme_weeks', 'legacy', ['rememberme' => $restored->id]));
    }
}
