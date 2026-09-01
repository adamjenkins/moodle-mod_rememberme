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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\request\approved_contextlist;
use mod_rememberme\privacy\provider;

/**
 * Tests for the privacy provider.
 *
 * The activity has no data generator yet, so the fixtures here build the
 * course module and every user data row directly through $DB, using the column
 * names from db/install.xml. The question engine usage is built for real
 * rather than faked: on MySQL and MariaDB core deletes usages with an inner
 * join to question_attempts, so a usage row with no attempt would survive a
 * delete and the test would pass while proving nothing.
 *
 * @package    mod_rememberme
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass The course holding both activities. */
    protected $course;

    /** @var \stdClass The activity instance under test. */
    protected $instance;

    /** @var \context_module The context of the activity under test. */
    protected $context;

    /** @var \stdClass A second activity instance, to prove deletion is scoped. */
    protected $otherinstance;

    /** @var \context_module The context of the second activity. */
    protected $othercontext;

    /** @var \stdClass The learner whose data is exported and deleted. */
    protected $student;

    /** @var \stdClass A second learner, whose data must survive. */
    protected $otherstudent;

    /** @var int The id of the question used by every fixture row. */
    protected $questionid;

    /** @var int The bank entry id of that question. */
    protected $questionbankentryid;

    /**
     * Build a course with two activities, two learners, and data for both.
     */
    protected function setUp(): void {
        global $CFG, $DB;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();

        [$this->instance, $this->context] = $this->create_rememberme($this->course);
        [$this->otherinstance, $this->othercontext] = $this->create_rememberme($this->course);

        $this->student = $generator->create_and_enrol($this->course, 'student');
        $this->otherstudent = $generator->create_and_enrol($this->course, 'student');

        $questiongenerator = $generator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
        $this->questionid = (int)$question->id;
        $this->questionbankentryid = (int)$DB->get_field(
            'question_versions',
            'questionbankentryid',
            ['questionid' => $question->id],
            MUST_EXIST
        );

        $this->create_user_data($this->instance, $this->context, $this->student);
        $this->create_user_data($this->instance, $this->context, $this->otherstudent);
        $this->create_user_data($this->otherinstance, $this->othercontext, $this->student);
    }

    /**
     * Create an activity instance and its course module by hand.
     *
     * @param \stdClass $course The course to add it to.
     * @return array A two element list: the instance record and its module context.
     */
    protected function create_rememberme(\stdClass $course): array {
        global $DB;

        $now = time();
        $instance = (object)[
            'course' => $course->id,
            'name' => 'Remember me ' . ($DB->count_records('rememberme') + 1),
            'intro' => 'Fixture intro',
            'introformat' => FORMAT_HTML,
            'coursestart' => $now - WEEKSECS,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $instance->id = $DB->insert_record('rememberme', $instance);

        $moduleid = $DB->get_field('modules', 'id', ['name' => 'rememberme'], MUST_EXIST);
        $cmid = add_course_module((object)[
            'course' => $course->id,
            'module' => $moduleid,
            'instance' => $instance->id,
            'section' => 0,
            'visible' => 1,
            'visibleold' => 1,
        ]);
        course_add_cm_to_section($course->id, $cmid, 0);

        return [$instance, \context_module::instance($cmid)];
    }

    /**
     * Insert one row in every user data table, plus a real question engine usage.
     *
     * @param \stdClass $instance The activity instance.
     * @param \context_module $context The context of that instance.
     * @param \stdClass $user The learner.
     */
    protected function create_user_data(\stdClass $instance, \context_module $context, \stdClass $user): void {
        global $DB;

        $now = time();

        $DB->insert_record('rememberme_schedule', (object)[
            'rememberme' => $instance->id,
            'userid' => $user->id,
            'questionbankentryid' => $this->questionbankentryid,
            'stability' => 4.5,
            'difficulty' => 5.25,
            'fuzzfactor' => 1.0,
            'reps' => 3,
            'lapses' => 1,
            'state' => 'review',
            'bandlevel' => 1,
            'lastreviewed' => $now - DAYSECS,
            'duedate' => $now + DAYSECS,
            'timecreated' => $now - WEEKSECS,
            'timemodified' => $now,
        ]);

        $DB->insert_record('rememberme_review_log', (object)[
            'rememberme' => $instance->id,
            'userid' => $user->id,
            'questionbankentryid' => $this->questionbankentryid,
            'questionid' => $this->questionid,
            'qtype' => 'truefalse',
            'rating' => 3,
            'fraction' => 1.0,
            'elapseddays' => 2.0,
            'retrievability' => 0.9,
            'stabilitybefore' => 2.0,
            'difficultybefore' => 5.0,
            'stabilityafter' => 4.5,
            'difficultyafter' => 5.25,
            'latency' => 4200,
            'weekno' => 1,
            'insuspension' => 0,
            'timecreated' => $now - DAYSECS,
        ]);

        $DB->insert_record('rememberme_weeks', (object)[
            'rememberme' => $instance->id,
            'userid' => $user->id,
            'weekno' => 1,
            'snapshottarget' => 12,
            'snapshottaken' => $now - WEEKSECS,
            'completed' => 9,
            'fraction' => 0.75,
            'graceapplied' => 0.0,
            'suspended' => 0,
            'timemodified' => $now,
        ]);

        $DB->insert_record('rememberme_bandstate', (object)[
            'rememberme' => $instance->id,
            'userid' => $user->id,
            'bandlevel' => 2,
            'reason' => 'mastery',
            'firstsession' => $now - WEEKSECS,
            'bandsince' => $now - DAYSECS,
            'lastunlockwindow' => 0,
            'timemodified' => $now,
        ]);

        $quba = \question_engine::make_questions_usage_by_activity('mod_rememberme', $context);
        $quba->set_preferred_behaviour('immediatefeedback');
        $slot = $quba->add_question(\question_bank::load_question($this->questionid), 1.0);
        $quba->start_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);

        $sessionid = $DB->insert_record('rememberme_session', (object)[
            'rememberme' => $instance->id,
            'userid' => $user->id,
            'uniqueid' => $quba->get_id(),
            'itemcount' => 1,
            'answered' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timefinished' => 0,
        ]);

        $DB->insert_record('rememberme_slot', (object)[
            'sessionid' => $sessionid,
            'slot' => $slot,
            'questionbankentryid' => $this->questionbankentryid,
            'questionid' => $this->questionid,
            'bandlevel' => 1,
            'isnew' => 1,
            'graded' => 0,
            'timeshown' => $now,
        ]);
    }

    /**
     * The session rows belonging to a learner in one instance.
     *
     * @param \stdClass $instance The activity instance.
     * @param \stdClass $user The learner.
     * @return array Session records keyed by id, each carrying id and uniqueid.
     */
    protected function get_sessions(\stdClass $instance, \stdClass $user): array {
        global $DB;

        return $DB->get_records(
            'rememberme_session',
            ['rememberme' => $instance->id, 'userid' => $user->id],
            'id ASC',
            'id, uniqueid'
        );
    }

    /**
     * Assert how many rows each user data table holds for a learner in one instance.
     *
     * @param \stdClass $instance The activity instance.
     * @param \stdClass $user The learner.
     * @param int $expected The expected row count in every table.
     */
    protected function assert_row_counts(\stdClass $instance, \stdClass $user, int $expected): void {
        global $DB;

        $params = ['rememberme' => $instance->id, 'userid' => $user->id];
        $tables = [
            'rememberme_schedule',
            'rememberme_review_log',
            'rememberme_weeks',
            'rememberme_bandstate',
            'rememberme_session',
        ];
        foreach ($tables as $table) {
            $this->assertEquals(
                $expected,
                $DB->count_records($table, $params),
                "Unexpected row count in {$table}"
            );
        }
    }

    /**
     * Assert that the slots and question usages of the given sessions are gone.
     *
     * @param array $sessions Session records keyed by id, each carrying id and uniqueid.
     */
    protected function assert_sessions_purged(array $sessions): void {
        global $DB;

        $this->assertNotEmpty($sessions, 'The fixture should have created sessions to purge.');
        foreach ($sessions as $session) {
            $this->assertFalse($DB->record_exists('rememberme_session', ['id' => $session->id]));
            $this->assertFalse($DB->record_exists('rememberme_slot', ['sessionid' => $session->id]));
            $this->assertFalse($DB->record_exists('question_usages', ['id' => $session->uniqueid]));
            $this->assertFalse($DB->record_exists('question_attempts', ['questionusageid' => $session->uniqueid]));
        }
    }

    /**
     * Assert that the slots and question usages of the given sessions are intact.
     *
     * @param array $sessions Session records keyed by id, each carrying id and uniqueid.
     */
    protected function assert_sessions_intact(array $sessions): void {
        global $DB;

        $this->assertNotEmpty($sessions, 'The fixture should have created sessions to keep.');
        foreach ($sessions as $session) {
            $this->assertTrue($DB->record_exists('rememberme_session', ['id' => $session->id]));
            $this->assertTrue($DB->record_exists('rememberme_slot', ['sessionid' => $session->id]));
            $this->assertTrue($DB->record_exists('question_usages', ['id' => $session->uniqueid]));
        }
    }

    /**
     * Every table that holds user data is declared in the metadata.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('mod_rememberme'));

        $names = [];
        foreach ($collection->get_collection() as $item) {
            $names[] = $item->get_name();
        }

        $this->assertEqualsCanonicalizing([
            'rememberme_schedule',
            'rememberme_review_log',
            'rememberme_weeks',
            'rememberme_bandstate',
            'rememberme_session',
            'rememberme_slot',
        ], $names);
    }

    /**
     * A learner's contexts are exactly the activities they have data in.
     */
    public function test_get_contexts_for_userid(): void {
        $contextlist = provider::get_contexts_for_userid($this->student->id);
        $this->assertEqualsCanonicalizing(
            [$this->context->id, $this->othercontext->id],
            $contextlist->get_contextids()
        );

        $contextlist = provider::get_contexts_for_userid($this->otherstudent->id);
        $this->assertEquals([$this->context->id], $contextlist->get_contextids());

        $stranger = $this->getDataGenerator()->create_user();
        $contextlist = provider::get_contexts_for_userid($stranger->id);
        $this->assertEmpty($contextlist->get_contextids());
    }

    /**
     * Both learners are found in the activity context, and only there.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist($this->context, 'mod_rememberme');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing(
            [$this->student->id, $this->otherstudent->id],
            $userlist->get_userids()
        );

        $userlist = new userlist($this->othercontext, 'mod_rememberme');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$this->student->id], $userlist->get_userids());

        $userlist = new userlist(\context_course::instance($this->course->id), 'mod_rememberme');
        provider::get_users_in_context($userlist);
        $this->assertEmpty($userlist->get_userids());
    }

    /**
     * Export produces the generic module data and every user data table.
     */
    public function test_export_user_data(): void {
        $this->export_context_data_for_user($this->student->id, $this->context, 'mod_rememberme');

        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());

        $data = $writer->get_data([]);
        $this->assertEquals($this->instance->name, $data->name);

        $schedule = $writer->get_related_data([], 'schedule');
        $this->assertCount(1, $schedule->schedule);
        $this->assertEquals($this->questionbankentryid, $schedule->schedule[0]->questionbankentryid);
        $this->assertEquals(3, $schedule->schedule[0]->reps);

        $reviews = $writer->get_related_data([], 'reviews');
        $this->assertCount(1, $reviews->reviews);
        $this->assertEquals(3, $reviews->reviews[0]->rating);
        $this->assertEquals(4200, $reviews->reviews[0]->latency);

        $weeks = $writer->get_related_data([], 'weeks');
        $this->assertCount(1, $weeks->weeks);
        $this->assertEquals(1, $weeks->weeks[0]->weekno);
        $this->assertEquals(9, $weeks->weeks[0]->completed);

        $bands = $writer->get_related_data([], 'bands');
        $this->assertCount(1, $bands->bands);
        $this->assertEquals(2, $bands->bands[0]->bandlevel);

        $sessions = $writer->get_related_data([], 'sessions');
        $this->assertCount(1, $sessions->sessions);
        $this->assertEquals(1, $sessions->sessions[0]->itemcount);
        $this->assertCount(1, $sessions->sessions[0]->slots);
        $this->assertEquals($this->questionbankentryid, $sessions->sessions[0]->slots[0]->questionbankentryid);
    }

    /**
     * A learner with nothing stored exports nothing at all.
     */
    public function test_export_user_data_with_no_data(): void {
        $stranger = $this->getDataGenerator()->create_and_enrol($this->course, 'student');

        $contextlist = new approved_contextlist($stranger, 'mod_rememberme', [$this->context->id]);
        provider::export_user_data($contextlist);

        $this->assertFalse(writer::with_context($this->context)->has_any_data());
    }

    /**
     * Deleting a context removes every learner's data there, and nowhere else.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        $studentsessions = $this->get_sessions($this->instance, $this->student);
        $othersessions = $this->get_sessions($this->instance, $this->otherstudent);
        $elsewhere = $this->get_sessions($this->otherinstance, $this->student);

        provider::delete_data_for_all_users_in_context($this->context);

        $this->assert_row_counts($this->instance, $this->student, 0);
        $this->assert_row_counts($this->instance, $this->otherstudent, 0);
        $this->assert_sessions_purged($studentsessions);
        $this->assert_sessions_purged($othersessions);

        // The second activity is untouched.
        $this->assert_row_counts($this->otherinstance, $this->student, 1);
        $this->assert_sessions_intact($elsewhere);
    }

    /**
     * Deleting one learner leaves the other learner and the other activity alone.
     */
    public function test_delete_data_for_user(): void {
        $studentsessions = $this->get_sessions($this->instance, $this->student);
        $othersessions = $this->get_sessions($this->instance, $this->otherstudent);
        $elsewhere = $this->get_sessions($this->otherinstance, $this->student);

        $contextlist = new approved_contextlist($this->student, 'mod_rememberme', [$this->context->id]);
        provider::delete_data_for_user($contextlist);

        $this->assert_row_counts($this->instance, $this->student, 0);
        $this->assert_sessions_purged($studentsessions);

        $this->assert_row_counts($this->instance, $this->otherstudent, 1);
        $this->assert_sessions_intact($othersessions);

        $this->assert_row_counts($this->otherinstance, $this->student, 1);
        $this->assert_sessions_intact($elsewhere);
    }

    /**
     * Deleting an approved userlist removes only the named learners.
     */
    public function test_delete_data_for_users(): void {
        $studentsessions = $this->get_sessions($this->instance, $this->student);
        $othersessions = $this->get_sessions($this->instance, $this->otherstudent);

        $userlist = new approved_userlist($this->context, 'mod_rememberme', [$this->otherstudent->id]);
        provider::delete_data_for_users($userlist);

        $this->assert_row_counts($this->instance, $this->otherstudent, 0);
        $this->assert_sessions_purged($othersessions);

        $this->assert_row_counts($this->instance, $this->student, 1);
        $this->assert_sessions_intact($studentsessions);
    }
}
