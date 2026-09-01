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

use mod_rememberme\event\band_unlocked;
use mod_rememberme\event\question_answered;
use mod_rememberme\task\maintenance;

/**
 * Tests for the maintenance scheduled task and the two activity events.
 *
 * The activity has no data generator yet, so the fixtures build the course
 * module and every data row directly through $DB, using the column names from
 * db/install.xml.
 *
 * The most important assertion in this file is the negative one: the task must
 * not write anything resembling a due queue. Scheduling is lazy and query
 * driven, and a nightly precomputation would be stale the moment a learner did
 * an extra session, so test_leaves_healthy_rows_untouched exists to fail if
 * anybody ever adds that.
 *
 * @package    mod_rememberme
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\task\maintenance
 * @covers     \mod_rememberme\event\question_answered
 * @covers     \mod_rememberme\event\band_unlocked
 */
final class task_maintenance_test extends \advanced_testcase {
    /** @var \stdClass The course holding the activity. */
    protected $course;

    /** @var \stdClass The activity instance under test. */
    protected $instance;

    /** @var \context_module The context of that activity. */
    protected $context;

    /** @var \stdClass The learner the fixture rows belong to. */
    protected $user;

    /** @var int A question id that really exists. */
    protected $questionid;

    /** @var int The bank entry id of that question. */
    protected $questionbankentryid;

    /** @var int A bank entry id that does not exist, standing in for a deleted question. */
    protected $missingqbeid;

    /** @var int An activity instance id that does not exist. */
    protected $missinginstanceid;

    /**
     * Build a course, an activity, a learner and one real question.
     */
    protected function setUp(): void {
        global $CFG, $DB;

        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->dirroot . '/course/lib.php');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        [$this->instance, $this->context] = $this->create_rememberme($this->course);
        $this->user = $generator->create_and_enrol($this->course, 'student');

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

        // Ids chosen so they cannot collide with anything the fixtures created.
        $this->missingqbeid = $this->questionbankentryid + 100000;
        $this->missinginstanceid = (int)$this->instance->id + 100000;
        $this->assertFalse($DB->record_exists('question_bank_entries', ['id' => $this->missingqbeid]));
        $this->assertFalse($DB->record_exists('rememberme', ['id' => $this->missinginstanceid]));
    }

    /**
     * Create an activity instance in a course.
     *
     * This uses the plugin's own generator rather than assembling a course
     * module by hand: the generator fills in every scheduling default, so a
     * column added to the instance table later cannot quietly leave these
     * fixtures building half a record.
     *
     * @param \stdClass $course The course to add it to.
     * @return array A two element list: the instance record and its module context.
     */
    protected function create_rememberme(\stdClass $course): array {
        global $DB;

        $module = $this->getDataGenerator()->create_module('rememberme', [
            'course' => $course->id,
            'coursestart' => time() - WEEKSECS,
        ]);

        $instance = $DB->get_record('rememberme', ['id' => $module->id], '*', MUST_EXIST);

        return [$instance, \context_module::instance($module->cmid)];
    }

    /**
     * Insert one schedule row.
     *
     * @param int $instanceid The owning activity instance id.
     * @param int $qbeid The question bank entry the row is keyed on.
     * @return int The new row id.
     */
    protected function insert_schedule(int $instanceid, int $qbeid): int {
        global $DB;

        $now = time();

        return (int)$DB->insert_record('rememberme_schedule', (object)[
            'rememberme' => $instanceid,
            'userid' => $this->user->id,
            'questionbankentryid' => $qbeid,
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
    }

    /**
     * Insert one session row.
     *
     * @param int $instanceid The owning activity instance id.
     * @param int $age Seconds in the past the session was created and last touched.
     * @param int $timefinished Zero for an open session.
     * @return int The new row id.
     */
    protected function insert_session(int $instanceid, int $age, int $timefinished = 0): int {
        global $DB;

        $now = time();

        return (int)$DB->insert_record('rememberme_session', (object)[
            'rememberme' => $instanceid,
            'userid' => $this->user->id,
            'uniqueid' => $DB->count_records('rememberme_session') + 1,
            'itemcount' => 5,
            'answered' => 2,
            'timecreated' => $now - $age,
            'timemodified' => $now - $age,
            'timefinished' => $timefinished,
        ]);
    }

    /**
     * Insert one slot row.
     *
     * @param int $sessionid The owning session id.
     * @param int $qbeid The question bank entry the slot served.
     * @param int $slot The slot number within the session.
     * @return int The new row id.
     */
    protected function insert_slot(int $sessionid, int $qbeid, int $slot = 1): int {
        global $DB;

        return (int)$DB->insert_record('rememberme_slot', (object)[
            'sessionid' => $sessionid,
            'slot' => $slot,
            'questionbankentryid' => $qbeid,
            'questionid' => $this->questionid,
            'bandlevel' => 1,
            'isnew' => 0,
            'graded' => 1,
            'timeshown' => time() - HOURSECS,
        ]);
    }

    /**
     * Run the task, capturing its mtrace output.
     *
     * Under PHPUnit mtrace() echoes rather than writing to STDOUT
     * (lib/moodlelib.php:8848), so output buffering captures it.
     *
     * @return string Everything the task traced.
     */
    protected function run_task(): string {
        $task = new maintenance();
        ob_start();
        $task->execute();

        return (string)ob_get_clean();
    }

    /**
     * The admin facing name comes from the existing lang string.
     */
    public function test_get_name(): void {
        $this->assertSame(get_string('taskmaintenance', 'mod_rememberme'), (new maintenance())->get_name());
    }

    /**
     * Rows pointing at a deleted question bank entry are pruned; live rows are not.
     */
    public function test_prunes_rows_for_deleted_question_bank_entries(): void {
        global $DB;

        $liveschedule = $this->insert_schedule((int)$this->instance->id, $this->questionbankentryid);
        $deadschedule = $this->insert_schedule((int)$this->instance->id, $this->missingqbeid);

        $sessionid = $this->insert_session((int)$this->instance->id, HOURSECS, time());
        $liveslot = $this->insert_slot($sessionid, $this->questionbankentryid, 1);
        $deadslot = $this->insert_slot($sessionid, $this->missingqbeid, 2);

        $output = $this->run_task();

        $this->assertTrue($DB->record_exists('rememberme_schedule', ['id' => $liveschedule]));
        $this->assertFalse($DB->record_exists('rememberme_schedule', ['id' => $deadschedule]));
        $this->assertTrue($DB->record_exists('rememberme_slot', ['id' => $liveslot]));
        $this->assertFalse($DB->record_exists('rememberme_slot', ['id' => $deadslot]));

        $this->assertStringContainsString('Pruned 1 rememberme_schedule row(s) whose question bank entry', $output);
        $this->assertStringContainsString('Pruned 1 rememberme_slot row(s) whose question bank entry', $output);
    }

    /**
     * Rows whose parent instance or session has gone are pruned.
     */
    public function test_prunes_rows_for_deleted_parents(): void {
        global $DB;

        $liveschedule = $this->insert_schedule((int)$this->instance->id, $this->questionbankentryid);
        $orphanschedule = $this->insert_schedule($this->missinginstanceid, $this->questionbankentryid);

        $livesession = $this->insert_session((int)$this->instance->id, HOURSECS, time());
        $liveslot = $this->insert_slot($livesession, $this->questionbankentryid, 1);

        // A slot whose session row does not exist at all.
        $missingsessionid = $livesession + 100000;
        $this->assertFalse($DB->record_exists('rememberme_session', ['id' => $missingsessionid]));
        $orphanslot = $this->insert_slot($missingsessionid, $this->questionbankentryid, 1);

        // A slot whose session exists but whose activity instance does not.
        $orphansession = $this->insert_session($this->missinginstanceid, HOURSECS, time());
        $orphanparentslot = $this->insert_slot($orphansession, $this->questionbankentryid, 1);

        $output = $this->run_task();

        $this->assertTrue($DB->record_exists('rememberme_schedule', ['id' => $liveschedule]));
        $this->assertFalse($DB->record_exists('rememberme_schedule', ['id' => $orphanschedule]));
        $this->assertTrue($DB->record_exists('rememberme_slot', ['id' => $liveslot]));
        $this->assertFalse($DB->record_exists('rememberme_slot', ['id' => $orphanslot]));
        $this->assertFalse($DB->record_exists('rememberme_slot', ['id' => $orphanparentslot]));

        $this->assertStringContainsString('Pruned 1 rememberme_schedule row(s) whose activity instance', $output);
        $this->assertStringContainsString('Pruned 2 rememberme_slot row(s) whose session or activity instance', $output);
    }

    /**
     * Open sessions untouched for more than a day are closed, and nothing else is.
     */
    public function test_closes_abandoned_sessions(): void {
        global $DB;

        $abandoned = $this->insert_session((int)$this->instance->id, 2 * DAYSECS);
        $recent = $this->insert_session((int)$this->instance->id, HOURSECS);
        $finishedat = time() - (3 * DAYSECS);
        $finished = $this->insert_session((int)$this->instance->id, 4 * DAYSECS, $finishedat);

        // Opened two days ago but answered a minute ago: still in use, so it
        // must survive. Judging on timecreated alone would close it under the
        // learner mid session.
        $stillactive = $this->insert_session((int)$this->instance->id, 2 * DAYSECS);
        $DB->set_field('rememberme_session', 'timemodified', time() - MINSECS, ['id' => $stillactive]);

        $before = $DB->get_record('rememberme_session', ['id' => $abandoned], '*', MUST_EXIST);

        $output = $this->run_task();

        // Closed, not deleted, and closed at the last known activity rather
        // than at the time the task happened to run.
        $after = $DB->get_record('rememberme_session', ['id' => $abandoned], '*', MUST_EXIST);
        $this->assertEquals((int)$before->timemodified, (int)$after->timefinished);
        $this->assertGreaterThan(0, (int)$after->timefinished);

        $this->assertEquals(0, (int)$DB->get_field('rememberme_session', 'timefinished', ['id' => $recent]));
        $this->assertEquals($finishedat, (int)$DB->get_field('rememberme_session', 'timefinished', ['id' => $finished]));
        $this->assertEquals(0, (int)$DB->get_field('rememberme_session', 'timefinished', ['id' => $stillactive]));

        $this->assertStringContainsString('Closed 1 abandoned rememberme session(s).', $output);
    }

    /**
     * An abandoned session with no usable timestamps is still closed, so it
     * cannot be picked up again on every subsequent run.
     */
    public function test_closes_abandoned_session_with_no_usable_timestamp(): void {
        global $DB;

        $sessionid = $this->insert_session((int)$this->instance->id, 2 * DAYSECS);
        $DB->set_field('rememberme_session', 'timemodified', 0, ['id' => $sessionid]);
        $DB->set_field('rememberme_session', 'timecreated', 0, ['id' => $sessionid]);

        $this->run_task();

        $this->assertGreaterThan(0, (int)$DB->get_field('rememberme_session', 'timefinished', ['id' => $sessionid]));
    }

    /**
     * The task is maintenance only: healthy rows are left exactly as they were,
     * and no due queue is precomputed anywhere.
     */
    public function test_leaves_healthy_rows_untouched(): void {
        global $DB;

        $scheduleid = $this->insert_schedule((int)$this->instance->id, $this->questionbankentryid);
        $sessionid = $this->insert_session((int)$this->instance->id, HOURSECS);
        $this->insert_slot($sessionid, $this->questionbankentryid, 1);

        $tables = [
            'rememberme',
            'rememberme_schedule',
            'rememberme_review_log',
            'rememberme_bands',
            'rememberme_bandstate',
            'rememberme_weeks',
            'rememberme_suspensions',
            'rememberme_session',
            'rememberme_slot',
        ];
        $countsbefore = [];
        foreach ($tables as $table) {
            $countsbefore[$table] = $DB->count_records($table);
        }
        $schedulebefore = $DB->get_record('rememberme_schedule', ['id' => $scheduleid], '*', MUST_EXIST);

        $this->run_task();

        foreach ($tables as $table) {
            $this->assertEquals(
                $countsbefore[$table],
                $DB->count_records($table),
                "The task changed the number of rows in {$table}."
            );
        }
        $this->assertEquals(
            $schedulebefore,
            $DB->get_record('rememberme_schedule', ['id' => $scheduleid], '*', MUST_EXIST)
        );
    }

    /**
     * The question answered event carries the answer and points at the log row.
     */
    public function test_question_answered_event(): void {
        global $DB;

        $logid = (int)$DB->insert_record('rememberme_review_log', (object)[
            'rememberme' => $this->instance->id,
            'userid' => $this->user->id,
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
            'timecreated' => time(),
        ]);

        $sink = $this->redirectEvents();
        question_answered::create([
            'objectid' => $logid,
            'context' => $this->context,
            'userid' => $this->user->id,
            'other' => [
                'questionbankentryid' => $this->questionbankentryid,
                'questionid' => $this->questionid,
                'rating' => 3,
            ],
        ])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(question_answered::class, $event);
        $this->assertEquals($this->context, $event->get_context());
        $this->assertEquals($this->course->id, $event->courseid);
        $this->assertEquals($logid, $event->objectid);
        $this->assertEquals('rememberme_review_log', $event->objecttable);
        $this->assertEquals('c', $event->crud);
        $this->assertEquals(question_answered::LEVEL_PARTICIPATING, $event->edulevel);
        $this->assertEquals($this->user->id, $event->userid);
        $this->assertEquals(3, $event->other['rating']);
        $this->assertEquals($this->questionbankentryid, $event->other['questionbankentryid']);
        $this->assertSame(get_string('eventquestionanswered', 'mod_rememberme'), question_answered::get_name());
        $this->assertStringContainsString("user with id '{$this->user->id}'", $event->get_description());
        $this->assertStringContainsString("bank entry id '{$this->questionbankentryid}'", $event->get_description());
        $this->assertEquals(
            new \moodle_url('/mod/rememberme/view.php', ['id' => $this->context->instanceid]),
            $event->get_url()
        );
        $this->assertEquals(
            ['db' => 'rememberme_review_log', 'restore' => \core\event\base::NOT_MAPPED],
            question_answered::get_objectid_mapping()
        );
    }

    /**
     * The question answered event refuses to fire without the values its
     * description quotes.
     */
    public function test_question_answered_event_requires_other_values(): void {
        $this->expectException(\coding_exception::class);
        question_answered::create([
            'objectid' => 1,
            'context' => $this->context,
            'userid' => $this->user->id,
            'other' => ['questionbankentryid' => $this->questionbankentryid],
        ]);
    }

    /**
     * The band unlocked event carries the band and, importantly, the reason.
     */
    public function test_band_unlocked_event(): void {
        global $DB;

        $now = time();
        $stateid = (int)$DB->insert_record('rememberme_bandstate', (object)[
            'rememberme' => $this->instance->id,
            'userid' => $this->user->id,
            'bandlevel' => 2,
            'reason' => 'backstop',
            'firstsession' => $now - (3 * WEEKSECS),
            'bandsince' => $now,
            'lastunlockwindow' => 0,
            'timemodified' => $now,
        ]);

        $sink = $this->redirectEvents();
        band_unlocked::create([
            'objectid' => $stateid,
            'context' => $this->context,
            'userid' => $this->user->id,
            'other' => [
                'bandlevel' => 2,
                'reason' => 'backstop',
            ],
        ])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(band_unlocked::class, $event);
        $this->assertEquals($this->context, $event->get_context());
        $this->assertEquals($this->course->id, $event->courseid);
        $this->assertEquals($stateid, $event->objectid);
        $this->assertEquals('rememberme_bandstate', $event->objecttable);
        $this->assertEquals('u', $event->crud);
        $this->assertEquals(band_unlocked::LEVEL_PARTICIPATING, $event->edulevel);
        $this->assertEquals(2, $event->other['bandlevel']);
        $this->assertEquals('backstop', $event->other['reason']);
        $this->assertSame(get_string('eventbandunlocked', 'mod_rememberme'), band_unlocked::get_name());
        $this->assertStringContainsString("unlocked band '2'", $event->get_description());
        $this->assertStringContainsString("reason 'backstop'", $event->get_description());
        $this->assertEquals(
            new \moodle_url('/mod/rememberme/view.php', ['id' => $this->context->instanceid]),
            $event->get_url()
        );
        $this->assertEquals(
            ['db' => 'rememberme_bandstate', 'restore' => \core\event\base::NOT_MAPPED],
            band_unlocked::get_objectid_mapping()
        );
    }

    /**
     * The band unlocked event refuses to fire without the reason, which is the
     * value that distinguishes an earned unlock from a backstop advance.
     */
    public function test_band_unlocked_event_requires_reason(): void {
        $this->expectException(\coding_exception::class);
        band_unlocked::create([
            'objectid' => 1,
            'context' => $this->context,
            'userid' => $this->user->id,
            'other' => ['bandlevel' => 2],
        ]);
    }
}
