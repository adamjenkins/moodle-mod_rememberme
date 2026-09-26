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
use mod_rememberme\output\report_renderer_helper;
use mod_rememberme\task\recalculate_weeks;

/**
 * The teacher reports: the values their columns sort by, and recalculating grades.
 *
 * Columns sort in the browser by each cell's raw value rather than its
 * formatted text, so the raw values are what these tests pin: a formatted
 * number can use a decimal comma, and a formatted date sorts by its wording.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\output\report_renderer_helper
 * @covers     \mod_rememberme\task\recalculate_weeks
 */
final class report_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var \stdClass The activity module record from the generator. */
    protected \stdClass $module;

    /** @var \stdClass A learner who studies. */
    protected \stdClass $student;

    /** @var \stdClass A learner who never comes. */
    protected \stdClass $absent;

    /** @var array Question bank entry ids in the pool. */
    protected array $entries = [];

    /** @var int Start of term. */
    protected int $termstart;

    /**
     * A term two weeks old with week two mostly a break, and two learners.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/questionlib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/rememberme/lib.php');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $this->course->id]);
        $category = question_get_default_category(\context_module::instance($qbank->cmid)->id);
        $qgen = $generator->get_plugin_generator('core_question');
        for ($i = 1; $i <= 3; $i++) {
            $question = $qgen->create_question('shortanswer', null, ['category' => $category->id]);
            $this->entries[] = (int)$DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id]);
        }

        $this->termstart = time() - 2 * WEEKSECS - HOURSECS;
        $this->module = $generator->create_module('rememberme', [
            'course' => $this->course->id,
            'coursestart' => $this->termstart,
            'sessionsize' => 3,
            'gracebalance' => 0,
            'ontimegrace' => 0,
        ]);
        $remembermegen = $generator->get_plugin_generator('mod_rememberme');
        $remembermegen->create_band((int)$this->module->id, (int)$category->id, 0);
        $remembermegen->create_suspension(
            (int)$this->module->id,
            $this->termstart + WEEKSECS,
            $this->termstart + WEEKSECS + 5 * DAYSECS
        );

        $this->student = $generator->create_user(['firstname' => 'Ada', 'lastname' => 'Study']);
        $this->absent = $generator->create_user(['firstname' => 'Ben', 'lastname' => 'Away']);
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
        $generator->enrol_user($this->absent->id, $this->course->id, 'student');
    }

    /**
     * The activity as stored now.
     *
     * @return \stdClass The instance.
     */
    protected function instance(): \stdClass {
        global $DB;
        return $DB->get_record('rememberme', ['id' => $this->module->id], '*', MUST_EXIST);
    }

    /**
     * The report helper for the activity.
     *
     * @return report_renderer_helper The helper.
     */
    protected function helper(): report_renderer_helper {
        return new report_renderer_helper($this->instance(), \context_module::instance($this->module->cmid));
    }

    /**
     * Have the studying learner answer every question on day one of week one.
     */
    protected function study(): void {
        $scheduler = new scheduler($this->instance());
        $time = $this->termstart + HOURSECS;
        // Building the queue is what a visit does first, and what records the
        // learner's first session and band.
        $scheduler->get_due_questions((int)$this->student->id, null, $time);
        foreach ($this->entries as $index => $entry) {
            $scheduler->record_attempt((int)$this->student->id, $entry, 1, 'shortanswer', 1.0, 5000, 1, $time + $index);
        }
    }

    /**
     * The row of a report for one learner.
     *
     * @param array $rows Report rows.
     * @param \stdClass $user The learner.
     * @return array The row.
     */
    protected function row_for(array $rows, \stdClass $user): array {
        foreach ($rows as $row) {
            if ($row['learner'] === s(fullname($user))) {
                return $row;
            }
        }
        $this->fail('no row for ' . fullname($user));
    }

    /**
     * The weekly matrix ranks a missed week as nothing and a break week last.
     */
    public function test_weeks_sort_values(): void {
        $this->study();
        $context = $this->helper()->weeks_context();

        $studied = $this->row_for($context['rows'], $this->student);
        $this->assertEqualsWithDelta(1 / 3, $studied['cells'][0]['fractionraw'], 1.0E-4, 'week one, one study day of three');
        $this->assertSame('', $studied['cells'][1]['fractionraw'], 'week two is a break, and sorts last');

        $away = $this->row_for($context['rows'], $this->absent);
        $this->assertSame(0.0, $away['cells'][0]['fractionraw'], 'a missed week ranks as nothing achieved');
        $this->assertSame(0.0, $away['gracetotalraw']);
    }

    /**
     * Numbers and dates sort by their raw values, not their formatted text.
     */
    public function test_other_reports_carry_raw_sort_values(): void {
        $this->study();
        $helper = $this->helper();

        foreach ($helper->difficulty_context()['rows'] as $row) {
            $this->assertIsFloat($row['meandifficultyraw']);
            $this->assertIsFloat($row['meanlapsesraw']);
            $this->assertIsFloat($row['meanstabilityraw']);
        }

        $coverage = $helper->coverage_context();
        $this->assertIsFloat($this->row_for($coverage['rows'], $this->student)['meanstabilityraw']);
        foreach ($coverage['forecast']['days'] as $offset => $day) {
            $this->assertSame($offset, $day['offset'], 'forecast days sort in date order');
        }

        $bands = $helper->bands_context()['rows'];
        $this->assertIsInt($this->row_for($bands, $this->student)['firstsessionraw']);
        $this->assertSame('', $this->row_for($bands, $this->absent)['firstsessionraw'], 'no first session sorts last');
    }

    /**
     * Recalculating rescores every learner's weeks and updates the gradebook.
     */
    public function test_recalculate_rescores_and_updates_the_gradebook(): void {
        global $DB;

        $this->study();
        // A stale score, as a teacher might see after changing the settings,
        // and the stale grade it pushed to the gradebook.
        $DB->set_field('rememberme_weeks', 'fraction', 0.9, ['rememberme' => $this->module->id, 'weekno' => 1]);
        rememberme_update_grades($this->instance());
        $stale = grade_get_grades($this->course->id, 'mod', 'rememberme', $this->module->id, $this->student->id);
        // Fourteen graded weeks: fifteen, less the break.
        $this->assertEqualsWithDelta(100 * 0.9 / 14, (float)$stale->items[0]->grades[$this->student->id]->grade, 1.0E-2);

        [$rescored, $learners] = recalculate_weeks::recalculate($this->instance());

        $this->assertSame(1, $learners, 'only learners with any record are rescored');
        $this->assertSame(1, $rescored, 'week one; week two has no record and nothing answered in it');
        $fraction = (float)$DB->get_field('rememberme_weeks', 'fraction', ['rememberme' => $this->module->id, 'weekno' => 1]);
        $this->assertEqualsWithDelta(1 / 3, $fraction, 1.0E-4);

        $grades = grade_get_grades($this->course->id, 'mod', 'rememberme', $this->module->id, $this->student->id);
        // Week one scored a third, of fourteen graded weeks.
        $this->assertEqualsWithDelta(100 * (1 / 3) / 14, (float)$grades->items[0]->grades[$this->student->id]->grade, 1.0E-2);
    }

    /**
     * Only those who may edit the activity may recalculate its grades.
     */
    public function test_recalculating_grades_is_for_editing_teachers(): void {
        $context = \context_module::instance($this->module->cmid);
        $generator = $this->getDataGenerator();

        $editing = $generator->create_user();
        $generator->enrol_user($editing->id, $this->course->id, 'editingteacher');
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $this->course->id, 'teacher');

        $this->assertTrue(has_capability('mod/rememberme:recalculategrades', $context, $editing));
        $this->assertFalse(
            has_capability('mod/rememberme:recalculategrades', $context, $teacher),
            'can read reports, not rewrite grades'
        );
        $this->assertTrue(has_capability('mod/rememberme:viewreports', $context, $teacher));
        $this->assertFalse(has_capability('mod/rememberme:recalculategrades', $context, $this->student));
    }
}
