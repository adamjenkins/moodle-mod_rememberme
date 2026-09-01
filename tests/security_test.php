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

use mod_rememberme\external\get_question;
use mod_rememberme\external\submit_answer;
use mod_rememberme\local\session;

/**
 * Access control and trust boundary tests.
 *
 * These cover the plugin's actual attack surface: the two external functions,
 * and the values a client supplies to them. An audit found a cross slot grading
 * defect precisely because nothing here existed, so each test below pins a
 * specific boundary rather than a happy path.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\external\get_question
 * @covers     \mod_rememberme\external\submit_answer
 * @covers     \mod_rememberme\local\session
 */
final class security_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var \stdClass The activity instance. */
    protected \stdClass $instance;

    /** @var \stdClass The course module. */
    protected \stdClass $cm;

    /** @var \context_module The module context. */
    protected \context_module $context;

    /** @var \stdClass A learner. */
    protected \stdClass $student;

    /**
     * Build a course, a bank with three questions, and a bound activity.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG;
        require_once($CFG->dirroot . '/lib/questionlib.php');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();

        $qbank = $generator->create_module('qbank', ['course' => $this->course->id]);
        $category = question_get_default_category(\context_module::instance($qbank->cmid)->id);

        $qgen = $generator->get_plugin_generator('core_question');
        foreach (['s1', 's2', 's3'] as $idnumber) {
            $qgen->create_question(
                'shortanswer',
                null,
                ['category' => $category->id, 'idnumber' => $idnumber]
            );
        }

        $module = $generator->create_module('rememberme', ['course' => $this->course->id]);
        $generator->get_plugin_generator('mod_rememberme')
            ->create_band((int)$module->id, (int)$category->id, 0);

        $this->cm = get_coursemodule_from_instance('rememberme', $module->id, $this->course->id);
        $this->context = \context_module::instance($this->cm->id);

        global $DB;
        $this->instance = $DB->get_record('rememberme', ['id' => $module->id], '*', MUST_EXIST);

        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * A learner may fetch their own next question.
     */
    public function test_get_question_allows_an_enrolled_learner(): void {
        $this->setUser($this->student);

        $result = get_question::execute((int)$this->cm->id);

        $this->assertTrue($result['hasquestion']);
        $this->assertNotEmpty($result['html']);
    }

    /**
     * Somebody with no role in the course cannot fetch a question.
     */
    public function test_get_question_refuses_a_stranger(): void {
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        $this->expectException(\require_login_exception::class);
        get_question::execute((int)$this->cm->id);
    }

    /**
     * A teacher, who may view but not attempt, is refused by the capability.
     *
     * This is not pedantry: a teacher accruing schedule records of their own
     * would pollute the cohort difficulty figures the reports exist to show.
     */
    public function test_get_question_refuses_a_user_without_the_attempt_capability(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);
        get_question::execute((int)$this->cm->id);
    }

    /**
     * Removing the attempt capability from a student is honoured.
     */
    public function test_get_question_honours_a_prohibited_capability(): void {
        global $DB;

        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        assign_capability('mod/rememberme:attempt', CAP_PROHIBIT, $studentrole->id, $this->context->id, true);

        $this->setUser($this->student);

        $this->expectException(\required_capability_exception::class);
        get_question::execute((int)$this->cm->id);
    }

    /**
     * Submitting an answer requires the attempt capability too.
     */
    public function test_submit_answer_refuses_a_user_without_the_attempt_capability(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);
        submit_answer::execute((int)$this->cm->id, 1, []);
    }

    /**
     * A learner cannot answer into another learner's session.
     *
     * The session is resolved from the logged in user, never from anything the
     * client sends, so a slot number belonging to somebody else's usage simply
     * does not exist in the attacker's own session.
     */
    public function test_a_learner_cannot_reach_another_learners_session(): void {
        global $DB;

        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id, 'student');

        // The victim starts a session.
        $victimsession = new session($this->instance, $this->context);
        $this->assertTrue($victimsession->start((int)$other->id));
        $victimslots = $victimsession->get_quba()->get_slots();
        $victimusage = (int)$victimsession->get_record()->uniqueid;

        // The attacker starts their own, then answers everything in it.
        $this->setUser($this->student);
        $attacker = new session($this->instance, $this->context);
        $this->assertTrue($attacker->start((int)$this->student->id));
        $attackerusage = (int)$attacker->get_record()->uniqueid;

        $this->assertNotSame($victimusage, $attackerusage);

        // Nothing the attacker does may touch the victim's rows.
        while (($slot = $attacker->next_slot()) !== null) {
            $prefix = $attacker->get_quba()->get_field_prefix($slot);
            $attacker->process_response($slot, [$prefix . 'answer' => 'frog']);
        }

        $this->assertSame(0, $DB->count_records('rememberme_schedule', ['userid' => $other->id]));
        $this->assertSame(0, $DB->count_records('rememberme_review_log', ['userid' => $other->id]));

        $victimquba = \question_engine::load_questions_usage_by_activity($victimusage);
        foreach ($victimslots as $victimslot) {
            $this->assertFalse(
                $victimquba->get_question_state($victimslot)->is_graded(),
                'another learner graded a question in this session'
            );
        }
    }

    /**
     * A crafted response may not grade any slot but the one being answered.
     *
     * This is the regression test for the audit finding. The response array
     * arrives from the client, and the question engine will happily process
     * every slot named in it, so the plugin must narrow the data to the slot it
     * was asked about. Without that, a learner could submit an answer for every
     * question in their session at once: the engine would grade them all while
     * this plugin recorded a single attempt, leaving questions finished in the
     * engine but still queued here, which then render read-only and unanswerable.
     */
    public function test_a_crafted_response_cannot_grade_other_slots(): void {
        global $DB;

        $session = new session($this->instance, $this->context);
        $this->assertTrue($session->start((int)$this->student->id));

        $quba = $session->get_quba();
        $slots = $quba->get_slots();
        $this->assertGreaterThanOrEqual(3, count($slots), 'the test needs several slots to be meaningful');

        // Craft a payload that submits every slot, not just the one being answered.
        $postdata = [];
        foreach ($slots as $slot) {
            $prefix = $quba->get_field_prefix($slot);
            $postdata[$prefix . 'answer'] = 'frog';
            $postdata[$prefix . ':sequencecheck'] = $quba->get_question_attempt($slot)->get_sequence_check_count();
            $postdata[$prefix . '-submit'] = 1;
        }

        $answeredslot = (int)$slots[0];
        $session->process_response($answeredslot, $postdata);

        // Exactly one slot may have been graded, in the engine and in our tables.
        $reloaded = \question_engine::load_questions_usage_by_activity((int)$session->get_record()->uniqueid);
        $graded = 0;
        foreach ($slots as $slot) {
            if ($reloaded->get_question_state($slot)->is_graded()) {
                $graded++;
            }
        }

        $this->assertSame(1, $graded, 'a crafted payload graded more than the slot it was answering');
        $this->assertSame(1, $DB->count_records('rememberme_review_log', ['rememberme' => $this->instance->id]));
        $this->assertSame(1, $DB->count_records('rememberme_schedule', ['rememberme' => $this->instance->id]));
        $this->assertSame(1, $DB->count_records('rememberme_slot', ['graded' => 1]));

        // And the engine and our records still agree about which slot that was.
        $this->assertTrue($reloaded->get_question_state($answeredslot)->is_graded());
    }

    /**
     * Another learner's response fields are ignored rather than processed.
     */
    public function test_foreign_slot_fields_are_dropped(): void {
        $session = new session($this->instance, $this->context);
        $session->start((int)$this->student->id);

        $quba = $session->get_quba();
        $slots = $quba->get_slots();
        $answeredslot = (int)$slots[0];
        $otherslot = (int)$slots[1];

        $ownprefix = $quba->get_field_prefix($answeredslot);
        $otherprefix = $quba->get_field_prefix($otherslot);

        // The foreign slot carries everything the engine would need to grade it,
        // including its sequence check. The only thing that may stop it is this
        // plugin narrowing the payload to the slot it was asked about.
        $result = $session->process_response($answeredslot, [
            $ownprefix . 'answer' => 'frog',
            $otherprefix . 'answer' => 'frog',
            $otherprefix . ':sequencecheck' =>
                $quba->get_question_attempt($otherslot)->get_sequence_check_count(),
            $otherprefix . '-submit' => 1,
        ]);

        $this->assertTrue($result['correct']);

        $reloaded = \question_engine::load_questions_usage_by_activity((int)$session->get_record()->uniqueid);
        $this->assertFalse(
            $reloaded->get_question_state($otherslot)->is_graded(),
            'a foreign slot response was processed'
        );
    }

    /**
     * The question file callback matches the signature core actually calls.
     *
     * Core invokes this from question_pluginfile() with nine arguments. PHP
     * accepts a shorter declaration without complaint and simply drops the
     * extras, which silently misaligns every parameter after the first: the
     * context receives a component string, the args receive an integer. The
     * result is that any question containing an image fails to serve. Nothing
     * else in the suite would notice, so the contract is pinned here.
     *
     * @return void
     */
    public function test_question_pluginfile_matches_the_core_contract(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/rememberme/lib.php');

        $reflection = new \ReflectionFunction('rememberme_question_pluginfile');
        $names = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            $reflection->getParameters()
        );

        $this->assertSame(
            ['course', 'context', 'component', 'filearea', 'qubaid', 'slot', 'args', 'forcedownload', 'options'],
            $names,
            'the callback signature no longer matches how core calls it'
        );
    }

    /**
     * One learner may not read the question files of another's session.
     *
     * @return void
     */
    public function test_question_pluginfile_refuses_another_learners_usage(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/rememberme/lib.php');

        $owner = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($owner->id, $this->course->id, 'student');

        $session = new session($this->instance, $this->context);
        $this->assertTrue($session->start((int)$owner->id));
        $qubaid = (int)$session->get_record()->uniqueid;
        $slot = (int)$session->get_quba()->get_slots()[0];

        // Starting a session renders question text, which initialises the page
        // theme; require_login() then refuses to change course on an already
        // set up page. That is a harness artefact, not the behaviour under test.
        global $PAGE;
        $PAGE->reset_theme_and_output();

        // A different learner, who has the attempt capability but no report
        // capability, asks for a file from somebody else's usage.
        $this->setUser($this->student);

        $this->expectException(\required_capability_exception::class);
        rememberme_question_pluginfile(
            $this->course,
            $this->context,
            'question',
            'questiontext',
            $qubaid,
            $slot,
            ['whatever.png'],
            false,
            []
        );
    }

    /**
     * The same slot cannot be banked twice.
     */
    public function test_a_slot_cannot_be_answered_twice(): void {
        $session = new session($this->instance, $this->context);
        $session->start((int)$this->student->id);
        $slot = $session->next_slot();
        $prefix = $session->get_quba()->get_field_prefix($slot);
        $session->process_response($slot, [$prefix . 'answer' => 'frog']);

        $this->expectException(\moodle_exception::class);
        $session->process_response($slot, [$prefix . 'answer' => 'frog']);
    }
}
