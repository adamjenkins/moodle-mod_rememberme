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
        $this->assertSame('', $progress['todaylabel']);
    }
}
