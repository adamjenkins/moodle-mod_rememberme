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

use mod_rememberme\local\session;

/**
 * Tests for presenting a question as a hand of options.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\session
 */
final class choices_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var \stdClass The question category holding the questions. */
    protected \stdClass $category;

    /** @var \stdClass The learner. */
    protected \stdClass $student;

    /**
     * A course with a bank to put questions in.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG;
        require_once($CFG->dirroot . '/lib/questionlib.php');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();

        $qbank = $generator->create_module('qbank', ['course' => $this->course->id]);
        $this->category = question_get_default_category(\context_module::instance($qbank->cmid)->id);

        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * Build an activity bound to the category, with the given settings.
     *
     * @param array $settings Instance overrides.
     * @return array Two element list of instance record and module context.
     */
    protected function make_activity(array $settings = []): array {
        global $DB;

        $module = $this->getDataGenerator()->create_module(
            'rememberme',
            ['course' => $this->course->id] + $settings
        );
        $this->getDataGenerator()->get_plugin_generator('mod_rememberme')
            ->create_band((int)$module->id, (int)$this->category->id, 0);

        return [
            $DB->get_record('rememberme', ['id' => $module->id], '*', MUST_EXIST),
            \context_module::instance($module->cmid),
        ];
    }

    /**
     * A four option multiple choice question, with the first option right.
     *
     * @return \stdClass The question.
     */
    protected function create_multichoice(): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('core_question')->create_question(
            'multichoice',
            'one_of_four',
            ['category' => $this->category->id, 'idnumber' => 'mc1']
        );
    }

    /**
     * A question is offered as a hand of options with a value to submit.
     *
     * @return void
     */
    public function test_a_multichoice_question_is_offered_as_choices(): void {
        $this->create_multichoice();
        [$instance, $context] = $this->make_activity();

        $session = new session($instance, $context);
        $this->assertTrue($session->start((int)$this->student->id));
        $slot = $session->next_slot();

        $choices = $session->get_choices($slot);
        $this->assertNotNull($choices);
        $this->assertCount(4, $choices['choices']);
        $this->assertMatchesRegularExpression('/_answer$/', $choices['name']);
        $this->assertGreaterThanOrEqual(0, $choices['correctvalue']);

        // The letters run in order and every option carries some text.
        $this->assertSame(['A', 'B', 'C', 'D'], array_column($choices['choices'], 'letter'));
        foreach ($choices['choices'] as $choice) {
            $this->assertNotSame('', trim(strip_tags($choice['text'])));
        }
    }

    /**
     * Answering with the value the hand gave back is graded as correct.
     *
     * @return void
     */
    public function test_the_choice_value_grades(): void {
        $this->create_multichoice();
        [$instance, $context] = $this->make_activity();

        $session = new session($instance, $context);
        $session->start((int)$this->student->id);
        $slot = $session->next_slot();
        $choices = $session->get_choices($slot);

        $result = $session->process_response($slot, [
            $choices['name'] => (string)$choices['correctvalue'],
        ]);

        $this->assertTrue($result['correct'], 'the value the hand reported as right was graded right');
        $this->assertEqualsWithDelta(1.0, $result['fraction'], 1.0E-9);
    }

    /**
     * Everything that is not single response multiple choice keeps the question
     * engine's own rendering.
     *
     * @return void
     */
    public function test_other_question_types_are_not_offered_as_choices(): void {
        $this->getDataGenerator()->get_plugin_generator('core_question')->create_question(
            'shortanswer',
            null,
            ['category' => $this->category->id, 'idnumber' => 'sa1']
        );
        [$instance, $context] = $this->make_activity();

        $session = new session($instance, $context);
        $session->start((int)$this->student->id);

        $this->assertNull($session->get_choices($session->next_slot()));
    }

    /**
     * The option limit thins the wrong answers and never the right one.
     *
     * @return void
     */
    public function test_the_option_limit_keeps_the_right_answer(): void {
        $this->create_multichoice();
        [$instance, $context] = $this->make_activity(['maxchoices' => 3]);

        // Twelve sittings, because the wrong answers are dropped at random and
        // one run keeping the right answer proves it only for that run. The
        // question is not answered in the loop: answering it schedules it into
        // the future, and it would stop being offered after the first pass.
        $rightanswers = [];
        for ($run = 0; $run < 12; $run++) {
            $session = new session($instance, $context);
            $this->assertTrue($session->start((int)$this->student->id));
            $choices = $session->get_choices($session->next_slot());

            $this->assertCount(3, $choices['choices'], 'four options were thinned to three');

            $bytext = array_column($choices['choices'], 'text', 'value');
            $this->assertArrayHasKey(
                $choices['correctvalue'],
                $bytext,
                'the option reported as right is one of the options offered, on run ' . $run
            );
            $rightanswers[trim(strip_tags($bytext[$choices['correctvalue']]))] = true;
        }

        $this->assertCount(1, $rightanswers, 'the same option was right in every sitting');
    }

    /**
     * A thinned question still grades on the value the hand reported.
     *
     * @return void
     */
    public function test_a_thinned_question_still_grades(): void {
        $this->create_multichoice();
        [$instance, $context] = $this->make_activity(['maxchoices' => 3]);

        $session = new session($instance, $context);
        $session->start((int)$this->student->id);
        $slot = $session->next_slot();
        $choices = $session->get_choices($slot);

        $result = $session->process_response($slot, [
            $choices['name'] => (string)$choices['correctvalue'],
        ]);

        $this->assertTrue($result['correct'], 'the right answer survived the thinning');
    }

    /**
     * A limit at or above the number of options changes nothing.
     *
     * @return void
     */
    public function test_a_limit_above_the_option_count_changes_nothing(): void {
        $this->create_multichoice();
        [$instance, $context] = $this->make_activity(['maxchoices' => 10]);

        $session = new session($instance, $context);
        $session->start((int)$this->student->id);

        $this->assertCount(4, $session->get_choices($session->next_slot())['choices']);
    }

    /**
     * The default presents everything, so existing activities are unaffected.
     *
     * @return void
     */
    public function test_no_limit_by_default(): void {
        $this->create_multichoice();
        [$instance, $context] = $this->make_activity();

        $this->assertSame(0, (int)$instance->maxchoices);

        $session = new session($instance, $context);
        $session->start((int)$this->student->id);

        $this->assertCount(4, $session->get_choices($session->next_slot())['choices']);
    }

    /**
     * The distractors are drawn again each sitting, not fixed once.
     *
     * @return void
     */
    public function test_the_thinned_options_vary_between_sittings(): void {
        $this->create_multichoice();
        [$instance, $context] = $this->make_activity(['maxchoices' => 3]);

        $sets = [];
        for ($run = 0; $run < 14; $run++) {
            $session = new session($instance, $context);
            $this->assertTrue($session->start((int)$this->student->id));
            $choices = $session->get_choices($session->next_slot());

            $texts = array_map(
                static fn(array $choice): string => trim(strip_tags($choice['text'])),
                $choices['choices']
            );
            sort($texts);
            $sets[implode('|', $texts)] = true;
        }

        $this->assertGreaterThan(
            1,
            count($sets),
            'fourteen sittings offered the same three options every time, so the wrong ones are not being redrawn'
        );
    }
}
