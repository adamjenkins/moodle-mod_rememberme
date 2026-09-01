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
use mod_rememberme\output\mobile;

/**
 * Tests for the Moodle app view.
 *
 * The first version of this file's subject shipped as an informational card
 * whose button re-rendered itself, and nothing caught it because nothing
 * exercised the handler. These tests exercise it the way the app does.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\output\mobile
 */
final class mobile_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var \stdClass The course module. */
    protected \stdClass $cm;

    /** @var \stdClass The activity instance. */
    protected \stdClass $instance;

    /** @var \stdClass The learner. */
    protected \stdClass $student;

    /**
     * Build a course with a bank, questions and a bound activity.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/questionlib.php');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();

        $qbank = $generator->create_module('qbank', ['course' => $this->course->id]);
        $category = question_get_default_category(\context_module::instance($qbank->cmid)->id);

        $qgen = $generator->get_plugin_generator('core_question');
        foreach (['m1', 'm2', 'm3'] as $idnumber) {
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
        $this->instance = $DB->get_record('rememberme', ['id' => $module->id], '*', MUST_EXIST);

        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * The app view presents an actual question, not a button to nowhere.
     *
     * @return void
     */
    public function test_app_view_presents_a_question(): void {
        $this->setUser($this->student);

        $result = mobile::mobile_course_view(['cmid' => (int)$this->cm->id]);

        $this->assertCount(1, $result['templates']);
        $html = $result['templates'][0]['html'];

        $this->assertStringContainsString(
            '<core-question',
            $html,
            'the app view must render the question component, not a placeholder card'
        );
        $this->assertStringContainsString('preferredBehaviour="immediatefeedback"', $html);

        // The button must not simply re-invoke the same handler, which is what
        // made the original version loop back to an identical card.
        $this->assertStringNotContainsString('core-site-plugins-new-content', $html);

        $this->assertNotEmpty($result['javascript'], 'the view needs its session script');
    }

    /**
     * The question payload carries every field the app's component reads.
     *
     * @return void
     */
    public function test_question_payload_matches_the_app_contract(): void {
        $this->setUser($this->student);

        $result = mobile::mobile_course_view(['cmid' => (int)$this->cm->id]);
        $this->assertArrayHasKey('question', $result['otherdata']);

        $question = json_decode($result['otherdata']['question'], true);
        $this->assertIsArray($question, 'the question must be valid JSON');

        // CoreQuestionQuestionWSData in the app source.
        foreach (['slot', 'type', 'page', 'html', 'sequencecheck', 'flagged'] as $field) {
            $this->assertArrayHasKey($field, $question, "the app reads {$field}");
        }

        $this->assertSame('shortanswer', $question['type']);
        $this->assertGreaterThan(0, $question['slot']);
        $this->assertStringContainsString(
            'name="q',
            $question['html'],
            'the rendered question must carry its form fields for the app to read back'
        );
    }

    /**
     * otherdata is a plain map, because get_content builds the pairs itself.
     *
     * Returning pre-built name and value pairs wraps every value in a second
     * one, which reaches the app as an unusable nested structure.
     *
     * @return void
     */
    public function test_otherdata_is_a_flat_map(): void {
        $this->setUser($this->student);

        $result = mobile::mobile_course_view(['cmid' => (int)$this->cm->id]);

        foreach ($result['otherdata'] as $name => $value) {
            $this->assertIsString($name, 'otherdata keys must be field names');
            $this->assertIsNotArray($value, 'otherdata values must be scalars, not nested pairs');
        }
    }

    /**
     * The whole response satisfies the web service the app actually calls.
     *
     * @return void
     */
    public function test_response_validates_against_the_web_service_contract(): void {
        $this->setUser($this->student);

        $raw = \tool_mobile\external::get_content(
            'mod_rememberme',
            'mobile_course_view',
            [['name' => 'cmid', 'value' => (int)$this->cm->id]]
        );

        $cleaned = \core_external\external_api::clean_returnvalue(
            \tool_mobile\external::get_content_returns(),
            $raw
        );

        $this->assertNotEmpty($cleaned['templates']);
        $this->assertNotEmpty($cleaned['otherdata']);
        foreach ($cleaned['otherdata'] as $pair) {
            $this->assertArrayHasKey('name', $pair);
            $this->assertArrayHasKey('value', $pair);
        }
    }

    /**
     * The due figure matches what a session would offer.
     *
     * It previously counted reviews only, so a learner with a full queue of new
     * questions was told nothing was due.
     *
     * @return void
     */
    public function test_due_count_matches_the_session_queue(): void {
        $this->setUser($this->student);

        $scheduler = new scheduler($this->instance);
        $expected = count($scheduler->get_due_questions((int)$this->student->id));
        $this->assertGreaterThan(0, $expected, 'the fixture must have something to offer');

        $result = mobile::mobile_course_view(['cmid' => (int)$this->cm->id]);
        $html = $result['templates'][0]['html'];

        // Reviews alone would be zero here, since every item is still new.
        $reviewsonly = count($scheduler->due_records((int)$this->student->id, time()));
        $this->assertSame(0, $reviewsonly, 'the fixture must distinguish the two counts');

        $this->assertStringContainsString(
            (string)$expected,
            $html,
            'the view must report the queue a session would actually offer'
        );
    }

    /**
     * A learner with nothing due is told so rather than shown a broken question.
     *
     * @return void
     */
    public function test_empty_queue_is_handled(): void {
        global $DB;

        // Remove the pool, so nothing can be offered.
        $DB->delete_records('rememberme_bands', ['rememberme' => $this->instance->id]);

        $this->setUser($this->student);
        $result = mobile::mobile_course_view(['cmid' => (int)$this->cm->id]);

        $html = $result['templates'][0]['html'];
        $this->assertStringNotContainsString('<core-question', $html);
        $this->assertSame('', $result['javascript']);
    }

    /**
     * A teacher may look without accruing memory state of their own.
     *
     * @return void
     */
    public function test_teacher_does_not_accrue_schedule_records(): void {
        global $DB;

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);

        $result = mobile::mobile_course_view(['cmid' => (int)$this->cm->id]);

        $this->assertStringNotContainsString('<core-question', $result['templates'][0]['html']);
        $this->assertSame(0, $DB->count_records('rememberme_schedule', ['userid' => $teacher->id]));
        $this->assertSame(0, $DB->count_records('rememberme_session', ['userid' => $teacher->id]));
    }
}
