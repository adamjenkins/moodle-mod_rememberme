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
use mod_rememberme\external\helper;
use mod_rememberme\local\scheduler;
use mod_rememberme\task\recalculate_weeks;

/**
 * Weekly grading on study days.
 *
 * A week is scored on how many different days the learner studied, against a
 * number the teacher sets. These tests pin the rule, the cases the item count
 * target it replaced got wrong, and the protection for weeks worked under that
 * old rule.
 *
 * The course starts two weeks and an hour ago, so "now" is early on day one of
 * week three and weeks one and two have ended.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\scheduler
 * @covers     \mod_rememberme\task\recalculate_weeks
 * @covers     \mod_rememberme\external\helper
 */
final class study_days_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var \stdClass The activity module record from the generator. */
    protected \stdClass $module;

    /** @var \stdClass The learner. */
    protected \stdClass $student;

    /** @var array Question bank entry ids in the pool. */
    protected array $entries = [];

    /** @var int Start of week one. */
    protected int $coursestart;

    /**
     * Build a course with six questions, an activity and a learner.
     *
     * Grace is switched off throughout, so a grade is exactly the average of
     * the week scores and nothing tops it up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/questionlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['enablecompletion' => 1]);

        $qbank = $generator->create_module('qbank', ['course' => $this->course->id]);
        $category = question_get_default_category(\context_module::instance($qbank->cmid)->id);
        $qgen = $generator->get_plugin_generator('core_question');
        for ($i = 1; $i <= 6; $i++) {
            $question = $qgen->create_question('shortanswer', null, ['category' => $category->id]);
            $this->entries[] = (int)$DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id]);
        }

        $this->coursestart = time() - 2 * WEEKSECS - HOURSECS;
        $this->module = $generator->create_module('rememberme', [
            'course' => $this->course->id,
            'coursestart' => $this->coursestart,
            'sessionsize' => 3,
            'studydays' => 3,
            'gracebalance' => 0,
            'ontimegrace' => 0,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionweeks' => 1,
        ]);
        $generator->get_plugin_generator('mod_rememberme')->create_band((int)$this->module->id, (int)$category->id, 0);

        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * The activity record as it is stored now.
     *
     * @return \stdClass The instance.
     */
    protected function instance(): \stdClass {
        global $DB;
        return $DB->get_record('rememberme', ['id' => $this->module->id], '*', MUST_EXIST);
    }

    /**
     * A moment on a given day of a given week, an hour into that day.
     *
     * @param int $weekno Week number, one based.
     * @param int $day Day of the week, zero based.
     * @return int Unix timestamp.
     */
    protected function moment(int $weekno, int $day): int {
        return $this->coursestart + ($weekno - 1) * WEEKSECS + $day * DAYSECS + HOURSECS;
    }

    /**
     * Answer some different questions at a moment, one second apart.
     *
     * @param scheduler $scheduler The scheduler.
     * @param int $time When the first answer is given.
     * @param int $count How many different questions.
     * @param int|null $userid The learner, or null for the default one.
     */
    protected function study(scheduler $scheduler, int $time, int $count = 3, ?int $userid = null): void {
        $userid = $userid ?? (int)$this->student->id;
        foreach (array_slice($this->entries, 0, $count) as $index => $entry) {
            $scheduler->record_attempt($userid, $entry, 1, 'shortanswer', 1.0, 5000, 1, $time + $index);
        }
    }

    /**
     * Read a week's stored row.
     *
     * @param int $weekno The week number.
     * @return \stdClass|false The row, or false if there is none.
     */
    protected function week(int $weekno) {
        global $DB;
        return $DB->get_record('rememberme_weeks', [
            'rememberme' => $this->module->id,
            'userid' => $this->student->id,
            'weekno' => $weekno,
        ]);
    }

    /**
     * Three study days make a full week; two make two thirds.
     */
    public function test_a_week_is_scored_on_study_days(): void {
        $scheduler = new scheduler($this->instance());

        foreach ([0, 2, 4] as $day) {
            $this->study($scheduler, $this->moment(1, $day));
        }
        foreach ([1, 5] as $day) {
            $this->study($scheduler, $this->moment(2, $day));
        }

        $this->assertSame(3, (int)$this->week(1)->daysstudied);
        $this->assertEqualsWithDelta(1.0, (float)$this->week(1)->fraction, 1.0E-4);
        $this->assertSame(2, (int)$this->week(2)->daysstudied);
        $this->assertEqualsWithDelta(2 / 3, (float)$this->week(2)->fraction, 1.0E-4);
    }

    /**
     * The same questions on different days each count.
     *
     * The rule this replaced counted distinct questions across the whole week,
     * so a learner who met the same three questions on three days was scored as
     * if they had come once. A review is supposed to come back.
     */
    public function test_the_same_questions_on_different_days_each_count(): void {
        $scheduler = new scheduler($this->instance());
        foreach ([0, 3, 6] as $day) {
            $this->study($scheduler, $this->moment(1, $day));
        }
        $this->assertSame(3, (int)$this->week(1)->daysstudied);
    }

    /**
     * Doing everything in one sitting is one study day, however much it was.
     */
    public function test_one_sitting_is_one_study_day(): void {
        $scheduler = new scheduler($this->instance());
        $this->study($scheduler, $this->moment(1, 0), 6);
        $this->study($scheduler, $this->moment(1, 0) + HOURSECS, 6);

        $this->assertSame(1, (int)$this->week(1)->daysstudied);
        $this->assertEqualsWithDelta(1 / 3, (float)$this->week(1)->fraction, 1.0E-4);
    }

    /**
     * A day on which nothing more could be offered counts, however short.
     */
    public function test_a_cleared_day_counts_with_few_answers(): void {
        $scheduler = new scheduler($this->instance());
        $time = $this->moment(3, 0);
        $this->study($scheduler, $time, 1);
        $this->assertSame(0, (int)$this->week(3)->daysstudied, 'one answer alone is short of a session');

        $scheduler->mark_day_cleared((int)$this->student->id, $time + MINSECS);
        $this->assertSame(1, (int)$this->week(3)->clearedmask, 'day zero is bit zero');
        $this->assertSame(1, (int)$this->week(3)->daysstudied);
        $this->assertTrue($scheduler->today_progress((int)$this->student->id, $time + HOURSECS)['counts']);

        // Clearing again the same day changes nothing.
        $scheduler->mark_day_cleared((int)$this->student->id, $time + HOURSECS);
        $this->assertSame(1, (int)$this->week(3)->clearedmask);
        $this->assertSame(1, (int)$this->week(3)->daysstudied);
    }

    /**
     * An empty queue in the page's own web service makes today count.
     *
     * The day has to be recorded where the learner is actually told there is
     * nothing more, which is this call, or the rule exists only in tests.
     */
    public function test_the_empty_queue_response_marks_the_day_cleared(): void {
        global $DB;

        // No new items may be drawn and nothing has been seen, so nothing can
        // be offered at all.
        $DB->set_field('rememberme', 'newperday', 0, ['id' => $this->module->id]);
        $this->setUser($this->student);

        $result = get_question::execute((int)$this->module->cmid);
        $result = \core_external\external_api::clean_returnvalue(get_question::execute_returns(), $result);

        $this->assertFalse($result['hasquestion']);
        $this->assertSame(get_string('progresstodaydone', 'rememberme'), $result['todaylabel']);
        $this->assertSame(1, $result['weekdone']);
        $week = $this->week(3);
        $this->assertNotFalse($week);
        $this->assertSame(1, (int)$week->daysstudied);
    }

    /**
     * A session that merely ended does not count as a cleared day.
     */
    public function test_an_ended_session_is_not_a_cleared_day(): void {
        $session = new \mod_rememberme\local\session($this->instance(), \context_module::instance($this->module->cmid));
        $this->setUser($this->student);
        $payload = helper::empty_payload($session, (int)$this->student->id, false);

        $this->assertFalse($payload['hasquestion']);
        $this->assertSame(0, $payload['weekdone']);
        $this->assertFalse($this->week(3));
    }

    /**
     * Nothing is counted, or shown as a count, outside the graded weeks.
     */
    public function test_nothing_is_counted_outside_the_graded_weeks(): void {
        global $DB;

        // Before week one.
        $DB->set_field('rememberme', 'coursestart', time() + 2 * DAYSECS, ['id' => $this->module->id]);
        $scheduler = new scheduler($this->instance());
        $this->study($scheduler, time());
        $scheduler->mark_day_cleared((int)$this->student->id);
        $this->assertSame(0, $DB->count_records('rememberme_weeks', ['rememberme' => $this->module->id]));

        $progress = helper::week_progress($scheduler, (int)$this->student->id);
        $this->assertFalse($progress['graded']);
        $this->assertStringStartsWith('Graded weeks begin', $progress['weeklabel']);
        $this->assertSame('', $progress['todaylabel']);

        // After the last one.
        $DB->set_field('rememberme', 'coursestart', time() - 3 * WEEKSECS, ['id' => $this->module->id]);
        $DB->set_field('rememberme', 'activeweeks', 2, ['id' => $this->module->id]);
        $scheduler = new scheduler($this->instance());
        $this->study($scheduler, time());
        $this->assertSame(0, $DB->count_records('rememberme_weeks', ['rememberme' => $this->module->id]));
        $progress = helper::week_progress($scheduler, (int)$this->student->id);
        $this->assertSame(get_string('gradingended', 'rememberme'), $progress['weeklabel']);
    }

    /**
     * The week in progress joins the grade only once it is earned in full.
     */
    public function test_the_unfinished_week_does_not_lower_the_grade(): void {
        $scheduler = new scheduler($this->instance());
        foreach ([0, 1, 2] as $day) {
            $this->study($scheduler, $this->moment(1, $day));
        }
        // Week two is missed entirely. Week three has begun with one day.
        $this->study($scheduler, $this->moment(3, 0));

        $now = $this->moment(3, 0) + 2 * HOURSECS;
        $grade = $scheduler->final_grade((int)$this->student->id, $now);
        $this->assertSame([1, 2], array_keys($grade['fractions']));
        $this->assertEqualsWithDelta(0.5, $grade['proportion'], 1.0E-4);

        // Earned in full, it counts at once.
        $this->study($scheduler, $this->moment(3, 1));
        $this->study($scheduler, $this->moment(3, 2));
        $grade = $scheduler->final_grade((int)$this->student->id, $this->moment(3, 2) + HOURSECS);
        $this->assertSame([1, 2, 3], array_keys($grade['fractions']));
        $this->assertEqualsWithDelta(2 / 3, $grade['proportion'], 1.0E-4);
    }

    /**
     * Answering pushes the grade to the gradebook straight away.
     */
    public function test_answering_updates_the_gradebook(): void {
        $scheduler = new scheduler($this->instance());
        foreach ([0, 1, 2] as $day) {
            $this->study($scheduler, $this->moment(1, $day));
        }
        $this->study($scheduler, $this->moment(2, 0));

        $grades = grade_get_grades($this->course->id, 'mod', 'rememberme', $this->module->id, $this->student->id);
        $grade = $grades->items[0]->grades[$this->student->id]->grade;
        // Week one full, week two ended with one day of three.
        $this->assertEqualsWithDelta(100 * (1 + 1 / 3) / 2, (float)$grade, 1.0E-2);
    }

    /**
     * Earning a week in full completes the weeks rule without a page visit.
     */
    public function test_earning_a_week_updates_completion(): void {
        $scheduler = new scheduler($this->instance());
        foreach ([0, 1, 2] as $day) {
            $this->study($scheduler, $this->moment(1, $day));
        }

        $cm = get_coursemodule_from_id('rememberme', $this->module->cmid);
        $completion = new \completion_info($this->course);
        $data = $completion->get_data($cm, false, (int)$this->student->id);
        $this->assertEquals(COMPLETION_COMPLETE, $data->completionstate);
    }

    /**
     * A week that began before the switch never scores lower than it did.
     *
     * Learners worked those weeks without being told days mattered.
     */
    public function test_a_week_begun_before_the_switch_keeps_its_old_score(): void {
        global $DB;

        // The switch happened during week two.
        $DB->set_field('rememberme', 'studydaysfrom', $this->moment(2, 3), ['id' => $this->module->id]);
        $scheduler = new scheduler($this->instance());

        foreach ([1, 2, 3] as $weekno) {
            $DB->insert_record('rememberme_weeks', (object)[
                'rememberme' => $this->module->id,
                'userid' => $this->student->id,
                'weekno' => $weekno,
                'snapshottarget' => 10,
                'completed' => 10,
                'fraction' => 1.0,
                'daysrequired' => 0,
            ]);
            // One sitting each week: one study day under the new rule.
            $this->study($scheduler, $this->moment($weekno, 0));
        }

        $this->assertEqualsWithDelta(1.0, (float)$this->week(1)->fraction, 1.0E-4, 'ended before the switch');
        $this->assertEqualsWithDelta(1.0, (float)$this->week(2)->fraction, 1.0E-4, 'under way at the switch');
        $this->assertEqualsWithDelta(1 / 3, (float)$this->week(3)->fraction, 1.0E-4, 'began after the switch');
        $this->assertSame(1, (int)$this->week(1)->daysstudied, 'the new figure is still recorded');
    }

    /**
     * A record created since the switch has no old score to protect.
     *
     * Its legacy target is zero, which the old rule reads as a week with
     * nothing due and scores in full, so treating it as an old record would
     * hand out full weeks for nothing.
     */
    public function test_a_record_created_after_the_switch_has_no_old_score(): void {
        global $DB;

        $DB->set_field('rememberme', 'studydaysfrom', time() - MINSECS, ['id' => $this->module->id]);
        $scheduler = new scheduler($this->instance());

        // Week two began before the switch, but nothing was recorded for it
        // until now, so its record is created by the new code.
        $scheduler->rescore_week((int)$this->student->id, 2, time());
        $this->assertSame(0, (int)$this->week(2)->snapshottarget);
        $this->assertEqualsWithDelta(0.0, (float)$this->week(2)->fraction, 1.0E-4);
    }

    /**
     * The recalculation task rescores from the log and keeps the old figures.
     */
    public function test_recalculation_rescores_from_the_log_and_keeps_legacy_columns(): void {
        global $DB;

        $scheduler = new scheduler($this->instance());
        foreach ([0, 2, 4] as $day) {
            $this->study($scheduler, $this->moment(2, $day));
        }
        $this->study($scheduler, $this->moment(1, 0));

        // Put the rows back the way the item count release left them, and take
        // week one's row away entirely, as a learner who answered before any
        // week row existed would have.
        $DB->set_field('rememberme_weeks', 'snapshottarget', 70, ['rememberme' => $this->module->id, 'weekno' => 2]);
        $DB->set_field('rememberme_weeks', 'completed', 3, ['rememberme' => $this->module->id, 'weekno' => 2]);
        $DB->set_field('rememberme_weeks', 'fraction', 0.0429, ['rememberme' => $this->module->id, 'weekno' => 2]);
        $DB->set_field('rememberme_weeks', 'daysstudied', 0, ['rememberme' => $this->module->id, 'weekno' => 2]);
        $DB->delete_records('rememberme_weeks', ['rememberme' => $this->module->id, 'weekno' => 1]);
        $DB->set_field('rememberme', 'studydaysfrom', time(), ['id' => $this->module->id]);

        $task = new recalculate_weeks();
        $task->set_custom_data((object)['instanceid' => (int)$this->module->id]);
        foreach ([1, 2] as $run) {
            ob_start();
            $task->execute();
            ob_end_clean();

            $week2 = $this->week(2);
            $this->assertSame(3, (int)$week2->daysstudied, "run $run");
            $this->assertEqualsWithDelta(1.0, (float)$week2->fraction, 1.0E-4, "run $run");
            $this->assertSame(70, (int)$week2->snapshottarget, 'the old target is kept');
            $this->assertSame(3, (int)$week2->completed, 'the old count is kept');
            $this->assertNotFalse($this->week(1), 'a week with answers gets a record');
            $this->assertSame(1, (int)$this->week(1)->daysstudied);
        }

        $grades = grade_get_grades($this->course->id, 'mod', 'rememberme', $this->module->id, $this->student->id);
        $this->assertEqualsWithDelta(100 * (1 / 3 + 1) / 2, (float)$grades->items[0]->grades[$this->student->id]->grade, 1.0E-2);
    }

    /**
     * The daily cap on new items resets on the same boundary as a study day.
     */
    public function test_the_new_item_cap_resets_on_the_study_day_boundary(): void {
        global $DB;

        $DB->set_field('rememberme', 'newperday', 2, ['id' => $this->module->id]);
        $scheduler = new scheduler($this->instance());
        [$daystart] = $scheduler->day_bounds(time());

        $scheduler->record_attempt((int)$this->student->id, $this->entries[0], 1, 'shortanswer', 1.0, 5000, 1, $daystart - 20);
        $scheduler->record_attempt((int)$this->student->id, $this->entries[1], 1, 'shortanswer', 1.0, 5000, 1, $daystart - 10);

        $new = array_filter(
            $scheduler->get_due_questions((int)$this->student->id, null, $daystart - 5),
            fn($entry) => $entry->isnew
        );
        $this->assertCount(0, $new, 'yesterday\'s allowance is spent');

        $new = array_filter(
            $scheduler->get_due_questions((int)$this->student->id, null, $daystart + 5),
            fn($entry) => $entry->isnew
        );
        $this->assertCount(2, $new, 'a new study day brings a new allowance');
    }
}
