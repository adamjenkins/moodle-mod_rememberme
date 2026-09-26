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
use mod_rememberme\local\bands;
use mod_rememberme\local\scheduler;

/**
 * Grading by band establishment.
 *
 * Two bands, of two and four questions, so each is worth a third and two
 * thirds of the grade. A question is established at a stability of five days,
 * and a band counts in full once half of it is established.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme\local\scheduler
 * @covers     \mod_rememberme\local\bands
 */
final class band_grading_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected \stdClass $course;

    /** @var \stdClass The activity module record from the generator. */
    protected \stdClass $module;

    /** @var \stdClass The learner. */
    protected \stdClass $student;

    /** @var array Question bank entry ids by band number. */
    protected array $entries = [];

    /**
     * Build the two bands and a learner.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $CFG, $DB;
        require_once($CFG->dirroot . '/lib/questionlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $this->course->id]);
        $bankcontext = \context_module::instance($qbank->cmid);
        $qgen = $generator->get_plugin_generator('core_question');

        $this->module = $generator->create_module('rememberme', [
            'course' => $this->course->id,
            'unlockmode' => bands::MODE_MASTERY,
            'stabilityfloor' => 5.0,
            'masteryproportion' => 0.5,
            'gradingmethod' => scheduler::GRADING_BANDS,
        ]);

        foreach ([1 => 2, 2 => 4] as $band => $count) {
            $category = $qgen->create_question_category(['contextid' => $bankcontext->id]);
            for ($i = 0; $i < $count; $i++) {
                $question = $qgen->create_question('shortanswer', null, ['category' => $category->id]);
                $this->entries[$band][] = (int)$DB->get_field(
                    'question_versions',
                    'questionbankentryid',
                    ['questionid' => $question->id]
                );
            }
            $generator->get_plugin_generator('mod_rememberme')
                ->create_band((int)$this->module->id, (int)$category->id, $band - 1, false, $band);
        }

        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
    }

    /**
     * A scheduler over the activity as it is stored now.
     *
     * @return scheduler The scheduler.
     */
    protected function scheduler(): scheduler {
        global $DB;
        return new scheduler($DB->get_record('rememberme', ['id' => $this->module->id], '*', MUST_EXIST));
    }

    /**
     * Give the learner a question at a given stability.
     *
     * @param int $qbeid The question bank entry.
     * @param float $stability Stability in days.
     */
    protected function remember(int $qbeid, float $stability): void {
        global $DB;
        $params = ['rememberme' => $this->module->id, 'userid' => $this->student->id, 'questionbankentryid' => $qbeid];
        $id = $DB->get_field('rememberme_schedule', 'id', $params);
        if ($id) {
            $DB->set_field('rememberme_schedule', 'stability', $stability, ['id' => $id]);
            return;
        }
        $DB->insert_record('rememberme_schedule', (object)($params + [
            'stability' => $stability, 'difficulty' => 5.0, 'fuzzfactor' => 1.0, 'reps' => 1, 'lapses' => 0,
            'state' => 'review', 'bandlevel' => 1, 'lastreviewed' => time(), 'duedate' => time() + DAYSECS,
            'timecreated' => time(), 'timemodified' => time(),
        ]));
    }

    /**
     * Progress toward establishing a band follows the unlocking threshold.
     */
    public function test_establishment_progress(): void {
        // Half the band at the floor, with a threshold of a half: established.
        $this->assertEqualsWithDelta(1.0, bands::establishment_progress([6.0, 1.0], 2, 5.0, 0.5), 1.0E-9);
        // A quarter established against a half: half way.
        $this->assertEqualsWithDelta(0.5, bands::establishment_progress([6.0, 1.0, null, null], 4, 5.0, 0.5), 1.0E-9);
        // Unseen questions count against, and nothing established is nothing.
        $this->assertEqualsWithDelta(0.0, bands::establishment_progress([null, null], 2, 5.0, 0.5), 1.0E-9);
        // Beyond the threshold is still just the band.
        $this->assertEqualsWithDelta(1.0, bands::establishment_progress([9.0, 9.0], 2, 5.0, 0.5), 1.0E-9);
    }

    /**
     * Each band is weighted by its share of the questions.
     */
    public function test_bands_are_weighted_by_their_items(): void {
        $this->assertEqualsWithDelta(1 / 3, bands::establishment_grade([1 => 2, 2 => 4], [1 => 1.0]), 1.0E-9);
        $this->assertEqualsWithDelta(2 / 3, bands::establishment_grade([1 => 2, 2 => 4], [2 => 1.0]), 1.0E-9);
        $this->assertEqualsWithDelta(0.0, bands::establishment_grade([], []), 1.0E-9);
    }

    /**
     * The grade follows establishment, and credit once earned is never taken away.
     */
    public function test_the_grade_follows_establishment_and_keeps_credit(): void {
        $scheduler = $this->scheduler();
        $userid = (int)$this->student->id;
        $scheduler->ensure_band_state($userid, time());
        $this->assertTrue($scheduler->is_band_grading());

        // Band one fully established, band two a quarter: 2/6 * 1 + 4/6 * 0.5.
        $this->remember($this->entries[1][0], 10.0);
        $this->remember($this->entries[1][1], 10.0);
        $this->remember($this->entries[2][0], 10.0);
        $this->assertEqualsWithDelta(4 / 6, $scheduler->final_grade($userid)['proportion'], 1.0E-9);

        // Forgetting band one's questions takes nothing away.
        $this->remember($this->entries[1][0], 1.0);
        $this->remember($this->entries[1][1], 1.0);
        $this->assertEqualsWithDelta(4 / 6, $scheduler->final_grade($userid)['proportion'], 1.0E-9);

        // More of band two established raises it.
        $this->remember($this->entries[2][1], 10.0);
        $this->assertEqualsWithDelta(1.0, $scheduler->final_grade($userid)['proportion'], 1.0E-9);
    }

    /**
     * Answering pushes the band grade to the gradebook, and the learner sees it.
     */
    public function test_answering_pushes_the_band_grade(): void {
        $scheduler = $this->scheduler();
        $userid = (int)$this->student->id;
        $scheduler->ensure_band_state($userid, time());
        $this->remember($this->entries[1][0], 10.0);
        $this->remember($this->entries[1][1], 10.0);

        // An answer is what triggers the push.
        $scheduler->record_attempt($userid, $this->entries[2][3], 1, 'shortanswer', 1.0, 5000, 2, time());

        $grades = grade_get_grades($this->course->id, 'mod', 'rememberme', $this->module->id, $userid);
        $this->assertEqualsWithDelta(100 / 3, (float)$grades->items[0]->grades[$userid]->grade, 1.0E-2);

        $progress = helper::week_progress($scheduler, $userid);
        $this->assertSame(get_string('bandgradelabel', 'rememberme', '33'), $progress['weeklabel']);
        $this->assertSame('', $progress['todaylabel'], 'days do not count here, so no day counter');
        $this->assertStringContainsString('50%', helper::grading_explained($scheduler));
    }

    /**
     * Band grading applies only with bands that unlock on establishment.
     */
    public function test_band_grading_needs_mastery_unlocking(): void {
        global $DB;
        $DB->set_field('rememberme', 'unlockmode', bands::MODE_EXHAUSTED, ['id' => $this->module->id]);
        $this->assertFalse($this->scheduler()->is_band_grading(), 'falls back to study days');
    }

    /**
     * A restored best-progress record keeps only valid band progress.
     */
    public function test_restore_cleans_best_progress(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/mod/rememberme/backup/moodle2/restore_rememberme_stepslib.php');

        $method = new \ReflectionMethod(\restore_rememberme_activity_structure_step::class, 'clean_best_progress');
        $method->setAccessible(true);

        $this->assertSame('{"1":1,"2":0.5}', $method->invoke(null, '{"1":7,"2":0.5,"x":1,"0":1}'));
        $this->assertNull($method->invoke(null, 'not json'));
        $this->assertNull($method->invoke(null, null));
    }
}
