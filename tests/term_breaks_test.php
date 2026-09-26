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

use mod_rememberme\external\helper;
use mod_rememberme\local\scheduler;

/**
 * The term, and breaks within it.
 *
 * A week that is mostly suspended is not graded, so staying away costs nothing
 * and any study in it earns grace. A week shortened by a break or by the end of
 * term needs proportionally fewer study days, rounded up.
 *
 * The term starts two weeks and an hour ago, so "now" is early on day one of
 * week three, three study days make a full week, and three different questions
 * make a study day.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\scheduler
 * @covers     ::rememberme_apply_term
 * @covers     ::rememberme_reset_userdata
 * @covers     \mod_rememberme\task\refresh_grades
 */
final class term_breaks_test extends \advanced_testcase {
    /** @var \stdClass The activity module record from the generator. */
    protected \stdClass $module;

    /** @var \stdClass The learner. */
    protected \stdClass $student;

    /** @var array Question bank entry ids in the pool. */
    protected array $entries = [];

    /** @var int Start of term. */
    protected int $termstart;

    /**
     * Build a course with six questions, an activity and a learner.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/questionlib.php');
        require_once($CFG->dirroot . '/mod/rememberme/lib.php');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $category = question_get_default_category(\context_module::instance($qbank->cmid)->id);
        $qgen = $generator->get_plugin_generator('core_question');
        for ($i = 1; $i <= 6; $i++) {
            $question = $qgen->create_question('shortanswer', null, ['category' => $category->id]);
            $this->entries[] = (int)$DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id]);
        }

        $this->termstart = time() - 2 * WEEKSECS - HOURSECS;
        $this->module = $generator->create_module('rememberme', [
            'course' => $course->id,
            'coursestart' => $this->termstart,
            'sessionsize' => 3,
            'studydays' => 3,
            'gracebalance' => 0,
            'graceearnrate' => 0.25,
            'ontimegrace' => 0,
        ]);
        $generator->get_plugin_generator('mod_rememberme')->create_band((int)$this->module->id, (int)$category->id, 0);

        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $course->id, 'student');
    }

    /**
     * A scheduler over the activity as it is stored now, suspensions included.
     *
     * @return scheduler The scheduler.
     */
    protected function scheduler(): scheduler {
        global $DB;
        return new scheduler($DB->get_record('rememberme', ['id' => $this->module->id], '*', MUST_EXIST));
    }

    /**
     * Add a break, in days from the start of a week.
     *
     * @param int $weekno Week number, one based.
     * @param int $fromday First day of the break, zero based.
     * @param int $days How many days it lasts.
     */
    protected function suspend(int $weekno, int $fromday, int $days): void {
        $start = $this->termstart + ($weekno - 1) * WEEKSECS + $fromday * DAYSECS;
        $this->getDataGenerator()->get_plugin_generator('mod_rememberme')
            ->create_suspension((int)$this->module->id, $start, $start + $days * DAYSECS);
    }

    /**
     * A moment on a given day of a given week, an hour into that day.
     *
     * @param int $weekno Week number, one based.
     * @param int $day Day of the week, zero based.
     * @return int Unix timestamp.
     */
    protected function moment(int $weekno, int $day): int {
        return $this->termstart + ($weekno - 1) * WEEKSECS + $day * DAYSECS + HOURSECS;
    }

    /**
     * Answer some different questions properly at a moment, one second apart.
     *
     * @param scheduler $scheduler The scheduler.
     * @param int $time When the first answer is given.
     * @param int $count How many different questions.
     * @param int $latency Milliseconds taken over each answer.
     */
    protected function study(scheduler $scheduler, int $time, int $count = 3, int $latency = 5000): void {
        foreach (array_slice($this->entries, 0, $count) as $index => $entry) {
            $scheduler->record_attempt((int)$this->student->id, $entry, 1, 'shortanswer', 1.0, $latency, 1, $time + $index);
        }
    }

    /**
     * The number of graded weeks follows from the two term dates.
     */
    public function test_the_term_dates_set_the_number_of_weeks(): void {
        $data = (object)['coursestart' => 1000000, 'termend' => 1000000 + 10 * WEEKSECS + 3 * DAYSECS];
        rememberme_apply_term($data);
        $this->assertSame(11, $data->activeweeks, 'a partial last week is a week');

        // Data with a week count and no end, such as an old backup, ends where its weeks did.
        $data = (object)['coursestart' => 1000000, 'activeweeks' => 4];
        rememberme_apply_term($data);
        $this->assertSame(1000000 + 4 * WEEKSECS, $data->termend);
    }

    /**
     * A week partly lost to a break needs fewer days, in proportion, rounded up.
     */
    public function test_a_partly_suspended_week_needs_fewer_days(): void {
        global $DB;

        // Three of seven days suspended: under half, so the week is graded,
        // with four open days: three study days times four sevenths is 1.7, so two.
        $this->suspend(1, 0, 3);
        $scheduler = $this->scheduler();
        $this->assertFalse($scheduler->get_weeks()->is_week_suspended(1));
        $this->assertSame(2, $scheduler->effective_required(3, 1));

        $this->study($scheduler, $this->moment(1, 4));
        $this->study($scheduler, $this->moment(1, 5));

        $week = $DB->get_record('rememberme_weeks', ['rememberme' => $this->module->id, 'weekno' => 1]);
        $this->assertSame(2, (int)$week->daysstudied);
        $this->assertSame(3, (int)$week->daysrequired, 'the stored figure is the full-week requirement');
        $this->assertEqualsWithDelta(1.0, (float)$week->fraction, 1.0E-4);
    }

    /**
     * A mostly suspended week is not graded, so staying away costs nothing.
     */
    public function test_a_mostly_suspended_week_is_not_graded(): void {
        $this->suspend(2, 0, 5);
        $scheduler = $this->scheduler();
        foreach ([0, 1, 2] as $day) {
            $this->study($scheduler, $this->moment(1, $day));
        }

        // Nothing at all in week two.
        $grade = $scheduler->final_grade((int)$this->student->id, $this->moment(3, 0));
        $this->assertSame([1], array_keys($grade['fractions']));
        $this->assertEqualsWithDelta(1.0, $grade['proportion'], 1.0E-4);
    }

    /**
     * Study on the open days of a break week earns grace.
     *
     * The week is out of the grade, and these answers are outside the window,
     * so before this they were worth nothing at all.
     */
    public function test_study_on_the_open_days_of_a_break_week_earns_grace(): void {
        $this->suspend(2, 0, 5);
        $scheduler = $this->scheduler();

        $this->study($scheduler, $this->moment(2, 6));
        $this->assertFalse($scheduler->get_clock()->is_suspended_at($this->moment(2, 6)), 'outside the window');
        $this->assertSame(3, $scheduler->count_break_study((int)$this->student->id));
        // Three answers is one session's worth, at a quarter of a week each.
        $this->assertEqualsWithDelta(0.25, $scheduler->earned_grace((int)$this->student->id), 1.0E-9);
    }

    /**
     * Study inside a window in a graded week earns grace too.
     */
    public function test_study_inside_a_short_window_earns_grace(): void {
        $this->suspend(1, 0, 2);
        $scheduler = $this->scheduler();
        $this->study($scheduler, $this->moment(1, 1));
        $this->study($scheduler, $this->moment(1, 4));

        $this->assertSame(3, $scheduler->count_break_study((int)$this->student->id), 'only the answers inside the window');
    }

    /**
     * Break grace is earned by answering different questions properly.
     *
     * The old count was every answer inside a window, so one question repeated,
     * or tapped through, earned as much as real study.
     */
    public function test_break_grace_counts_different_questions_answered_properly(): void {
        $this->suspend(2, 0, 5);
        $scheduler = $this->scheduler();
        $userid = (int)$this->student->id;

        for ($i = 0; $i < 5; $i++) {
            $time = $this->moment(2, 1) + $i * MINSECS;
            $scheduler->record_attempt($userid, $this->entries[0], 1, 'shortanswer', 0.0, 5000, 1, $time);
        }
        $this->assertSame(1, $scheduler->count_break_study($userid), 'one question repeated is one');

        $this->study($scheduler, $this->moment(2, 2), 3, 50);
        $this->assertSame(1, $scheduler->count_break_study($userid), 'answers too fast to read earn nothing');

        $scheduler->record_attempt($userid, $this->entries[0], 1, 'shortanswer', 1.0, 5000, 1, $this->moment(2, 3));
        $this->assertSame(2, $scheduler->count_break_study($userid), 'the same question on another day counts again');
    }

    /**
     * Answers from before the switch keep the grace they earned then.
     */
    public function test_answers_before_the_switch_keep_their_old_grace_count(): void {
        global $DB;

        $this->suspend(1, 0, 5);
        $scheduler = $this->scheduler();
        $userid = (int)$this->student->id;
        for ($i = 0; $i < 4; $i++) {
            $time = $this->moment(1, 1) + $i * MINSECS;
            $scheduler->record_attempt($userid, $this->entries[0], 1, 'shortanswer', 0.0, 5000, 1, $time);
        }

        $DB->set_field('rememberme', 'studydaysfrom', $this->moment(2, 0), ['id' => $this->module->id]);
        $this->assertSame(4, $this->scheduler()->count_break_study($userid), 'counted the way it was then: every answer');
    }

    /**
     * A last week cut short by the end of term needs fewer days.
     */
    public function test_a_last_week_cut_short_needs_fewer_days(): void {
        global $DB;

        $termend = $this->termstart + 2 * WEEKSECS + 3 * DAYSECS;
        $data = (object)['coursestart' => $this->termstart, 'termend' => $termend];
        rememberme_apply_term($data);
        $DB->update_record('rememberme', (object)[
            'id' => $this->module->id,
            'termend' => $data->termend,
            'activeweeks' => $data->activeweeks,
        ]);

        $scheduler = $this->scheduler();
        $this->assertSame(3, $data->activeweeks);
        // Three days of the week are in term: three times three sevenths is 1.3, so two.
        $this->assertSame(2, $scheduler->effective_required(3, 3));
        $this->assertSame(3, $scheduler->effective_required(3, 2), 'full weeks are untouched');

        $progress = helper::week_progress($scheduler, (int)$this->student->id, $this->moment(3, 0));
        $this->assertSame(2, $progress['target']);
        $this->assertSame(get_string('progressthisweek', 'rememberme', ['done' => 0, 'target' => 2]), $progress['weeklabel']);
    }

    /**
     * A break week tells the learner so, rather than showing a count.
     */
    public function test_a_break_week_says_so(): void {
        $this->suspend(3, 0, 7);
        $progress = helper::week_progress($this->scheduler(), (int)$this->student->id);

        $this->assertFalse($progress['graded']);
        $this->assertSame(get_string('breakweek', 'rememberme'), $progress['weeklabel']);

        // Doing everything on offer in a break week is welcome, but it is not a
        // study day, and the learner is not told it counts toward the week.
        $scheduler = $this->scheduler();
        $this->study($scheduler, time());
        $this->assertSame(scheduler::CLEARED_UNCOUNTED, $scheduler->mark_day_cleared((int)$this->student->id));
        $this->assertSame(
            get_string('nothingdueplaindesc', 'rememberme'),
            helper::empty_message(scheduler::CLEARED_UNCOUNTED)
        );
        $this->assertSame('', $progress['todaylabel']);
    }

    /**
     * The term cannot be longer than the calendar code can walk.
     *
     * Everything that walks the weeks is linear in their number, and a hand
     * edited backup can ask for any end date at all.
     */
    public function test_the_term_is_capped(): void {
        $data = (object)['coursestart' => 1000000, 'termend' => 1000000 + 100000 * WEEKSECS];
        rememberme_apply_term($data);
        $this->assertSame(REMEMBERME_MAX_TERM_WEEKS, $data->activeweeks);
        $this->assertSame(1000000 + REMEMBERME_MAX_TERM_WEEKS * WEEKSECS, $data->termend);

        $data = (object)['coursestart' => 1000000, 'activeweeks' => 8000000];
        rememberme_apply_term($data);
        $this->assertSame(REMEMBERME_MAX_TERM_WEEKS, $data->activeweeks);
    }

    /**
     * Resetting a course with a new start date moves the term and its breaks.
     *
     * Otherwise every week of the new term falls after the old one ended, and
     * nothing is graded at all.
     */
    public function test_a_course_reset_moves_the_term(): void {
        global $DB;

        $this->suspend(2, 0, 5);
        $DB->set_field('rememberme', 'studydaysfrom', $this->termstart + DAYSECS, ['id' => $this->module->id]);
        $before = $DB->get_record('rememberme', ['id' => $this->module->id]);
        $window = $DB->get_record('rememberme_suspensions', ['rememberme' => $this->module->id]);
        $shift = 52 * WEEKSECS;

        $status = rememberme_reset_userdata((object)[
            'courseid' => $this->module->course,
            'timeshift' => $shift,
            'reset_rememberme_all' => 0,
        ]);

        $after = $DB->get_record('rememberme', ['id' => $this->module->id]);
        $this->assertSame((int)$before->coursestart + $shift, (int)$after->coursestart);
        $this->assertSame((int)$before->termend + $shift, (int)$after->termend);
        $this->assertSame((int)$before->studydaysfrom + $shift, (int)$after->studydaysfrom);
        $this->assertSame((int)$before->activeweeks, (int)$after->activeweeks, 'the length of term is unchanged');
        $moved = $DB->get_record('rememberme_suspensions', ['id' => $window->id]);
        $this->assertSame((int)$window->timestart + $shift, (int)$moved->timestart);
        $this->assertSame((int)$window->timeend + $shift, (int)$moved->timeend);
        $this->assertSame(
            get_string('termdatesupdated', 'rememberme'),
            $status[0]['item'],
            'the reset report says the dates moved'
        );
    }

    /**
     * Grades keep being pushed until the last graded week has ended.
     *
     * The window used to close a week after the end of term, which for a term
     * ending early in its last week was before that week was over.
     */
    public function test_grades_are_refreshed_until_the_last_week_has_ended(): void {
        global $DB;

        // Three weeks, the last cut short an hour in; now is two days after it ended.
        $start = time() - 3 * WEEKSECS - 2 * DAYSECS;
        $DB->update_record('rememberme', (object)[
            'id' => $this->module->id,
            'coursestart' => $start,
            'termend' => $start + 2 * WEEKSECS + HOURSECS,
            'activeweeks' => 3,
        ]);

        $this->expectOutputRegex('/Refreshed grades for 1 rememberme/');
        (new \mod_rememberme\task\refresh_grades())->execute();
    }

    /**
     * Learner records kept through a reset move with the calendar.
     *
     * Moving only the term read last term's week records as this term's, so a
     * learner kept last term's study days and cleared days for free.
     */
    public function test_learner_records_kept_through_a_reset_move_with_it(): void {
        global $DB;

        $scheduler = $this->scheduler();
        $this->study($scheduler, $this->moment(1, 2));
        $userid = (int)$this->student->id;
        $params = ['rememberme' => $this->module->id, 'userid' => $userid];
        $log = $DB->get_records('rememberme_review_log', $params, 'id');
        $schedule = $DB->get_records('rememberme_schedule', $params, 'id');
        $week = $DB->get_record('rememberme_weeks', $params + ['weekno' => 1], '*', MUST_EXIST);
        $shift = 52 * WEEKSECS;

        rememberme_reset_userdata((object)[
            'courseid' => $this->module->course,
            'timeshift' => $shift,
            'reset_rememberme_all' => 0,
        ]);

        foreach ($DB->get_records('rememberme_review_log', $params, 'id') as $id => $row) {
            $this->assertSame((int)$log[$id]->timecreated + $shift, (int)$row->timecreated);
            $this->assertSame((int)$log[$id]->weekno, (int)$row->weekno, 'still the same week of term');
        }
        foreach ($DB->get_records('rememberme_schedule', $params, 'id') as $id => $row) {
            $this->assertSame((int)$schedule[$id]->duedate + $shift, (int)$row->duedate);
            $this->assertSame((int)$schedule[$id]->lastreviewed + $shift, (int)$row->lastreviewed);
        }
        $moved = $DB->get_record('rememberme_weeks', ['id' => $week->id]);
        $this->assertSame((int)$week->snapshottaken + $shift, (int)$moved->snapshottaken);

        // Rescored against the moved calendar, the week is exactly what it was.
        $after = $this->scheduler()->rescore_week($userid, 1, time() + $shift, false);
        // The stored fraction has four decimal places.
        $this->assertEqualsWithDelta((float)$week->fraction, (float)$after->fraction, 1.0E-4);
        $this->assertTrue(
            $DB->record_exists('task_adhoc', ['classname' => '\\mod_rememberme\\task\\recalculate_weeks']),
            'the kept records are queued to be rescored and graded'
        );
    }

    /**
     * Clearing learner data clears the switch date, which then protects nothing.
     */
    public function test_a_reset_that_clears_learner_data_clears_the_switch_date(): void {
        global $DB;

        $DB->set_field('rememberme', 'studydaysfrom', $this->termstart + DAYSECS, ['id' => $this->module->id]);
        rememberme_reset_userdata((object)[
            'courseid' => $this->module->course,
            'timeshift' => 52 * WEEKSECS,
            'reset_rememberme_all' => 1,
        ]);
        $this->assertSame(0, (int)$DB->get_field('rememberme', 'studydaysfrom', ['id' => $this->module->id]));
    }

    /**
     * Break grace over awkward windows matches a plain count, window by window.
     *
     * The count reads only answers inside break periods. It must give exactly
     * what checking every answer would, for a window spanning several weeks,
     * overlapping windows, and a mostly suspended week with open days.
     */
    public function test_break_grace_matches_a_plain_count_over_awkward_windows(): void {
        // Week 2 mostly suspended (days 0-4); a window from week 3 day 5 to week 4
        // day 2, and an overlapping one inside it.
        $this->suspend(2, 0, 5);
        $this->suspend(3, 5, 4);
        $this->suspend(3, 6, 1);
        $scheduler = $this->scheduler();
        $userid = (int)$this->student->id;

        $moments = [];
        foreach ([[1, 3], [2, 1], [2, 5], [2, 6], [3, 2], [3, 5], [3, 6], [4, 1], [4, 3], [5, 0]] as [$weekno, $day]) {
            $moments[] = $this->moment($weekno, $day);
        }
        foreach ($moments as $time) {
            $this->study($scheduler, $time, 2);
        }

        // The plain count: every answer, checked one by one.
        $weeks = $scheduler->get_weeks();
        $clock = $scheduler->get_clock();
        $expected = 0;
        foreach ($moments as $time) {
            $weekno = $weeks->week_for($time);
            if ($clock->is_suspended_at($time) || ($scheduler->is_graded_week($weekno) && $weeks->is_week_suspended($weekno))) {
                $expected += 2;
            }
        }

        $this->assertGreaterThan(0, $expected, 'the fixture must hit some break periods');
        $this->assertLessThan(2 * count($moments), $expected, 'and miss others');
        $this->assertSame($expected, $scheduler->count_break_study($userid));
    }
}
