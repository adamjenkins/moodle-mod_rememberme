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

use mod_rememberme\local\bands;

/**
 * Tests for the settings form's validation.
 *
 * Every setting the form accepts without complaint is a setting a teacher will
 * eventually type, so the ones that would quietly change how the activity paces
 * itself have to be refused rather than absorbed.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_rememberme_mod_form
 */
final class mod_form_test extends \advanced_testcase {
    /** @var \mod_rememberme_mod_form The form under test. */
    protected \mod_rememberme_mod_form $form;

    /** @var int The activity instance the form is editing. */
    protected int $instanceid;

    /** @var int That activity's course module id. */
    protected int $cmid;

    /**
     * A real form against a real activity.
     *
     * validation() chains to moodleform_mod::validation(), which reaches into
     * the built form, so the class cannot be exercised without constructing it.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $CFG, $COURSE, $PAGE;
        require_once($CFG->dirroot . '/course/moodleform_mod.php');
        require_once($CFG->dirroot . '/mod/rememberme/mod_form.php');

        $course = $this->getDataGenerator()->create_course();

        // The form resolves its course module through the global $COURSE rather
        // than through the course it was handed
        // (course/moodleform_mod.php, standard_coursemodule_elements), so it
        // cannot be built until that points at the right course.
        $COURSE = $course;
        $PAGE->set_course($course);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $module = $this->getDataGenerator()->create_module('rememberme', ['course' => $course->id]);
        // The modinfo cache was built before these modules existed, and
        // moodleform_mod resolves the course module through it.
        rebuild_course_cache($course->id, true);
        $cm = get_fast_modinfo($course)->get_cm(
            get_coursemodule_from_instance('rememberme', $module->id, $course->id, false, MUST_EXIST)->id
        );

        $current = (object)[
            'course' => $course->id,
            'coursemodule' => $cm->id,
            'instance' => $module->id,
            'modulename' => 'rememberme',
            'update' => $cm->id,
        ];

        $this->instanceid = (int)$module->id;
        $this->cmid = (int)$cm->id;
        $this->form = new \mod_rememberme_mod_form($current, 0, $cm, $course);
        unset($qbank);
    }

    /**
     * Run the form's validation over a valid baseline with changes applied.
     *
     * @param array $overrides Settings to change from the valid baseline.
     * @return array The errors the form would report.
     */
    protected function validate(array $overrides = []): array {

        $data = [
            'targetretention' => 0.9,
            'passthreshold' => 0.5,
            'sessionsize' => 20,
            'newperday' => 10,
            'maxchoices' => 0,
            'activeweeks' => 15,
            'gracebalance' => 1.0,
            'graceearnrate' => 0.25,
            'ontimegrace' => 0.5,
            'pausecorrect' => 1200,
            'pauseincorrect' => 2500,
            'unlockmode' => bands::MODE_EXHAUSTED,
            'unlockinterval' => 7,
            'masteryproportion' => 0.7,
            'stabilityfloor' => 14.0,
            'backstopdays' => 21,
            'bandcategory' => [0 => [11]],

            // Standard course module fields. moodleform_mod::validation()
            // reads these before this plugin's own checks run, so a baseline
            // without them fails on core's terms rather than on the plugin's.
            'name' => 'Vocabulary',
            'modulename' => 'rememberme',
            'instance' => $this->instanceid,
            'coursemodule' => $this->cmid,
            'cmidnumber' => '',
            'availabilityconditionsjson' => '{"op":"&","showc":[],"c":[]}',
        ];

        // The union keeps every baseline key the override does not mention,
        // and lets the override win where it does.
        $data = $overrides + $data;

        return $this->form->validation($data, []);
    }

    /**
     * The baseline is a settings page a teacher could actually save.
     *
     * Without this the negative cases below would pass even if validation
     * rejected everything.
     *
     * @return void
     */
    public function test_the_baseline_settings_are_accepted(): void {
        $this->assertSame([], $this->validate());
    }

    /**
     * Grace rates cannot be negative.
     *
     * A negative rate is read as "switched off" everywhere it is consumed, so
     * accepting one withdraws the reward without saying so.
     *
     * @return void
     */
    public function test_negative_grace_rates_are_refused(): void {
        $this->assertArrayHasKey('ontimegrace', $this->validate(['ontimegrace' => -0.5]));
        $this->assertArrayHasKey('graceearnrate', $this->validate(['graceearnrate' => -1]));

        // Zero is a real setting for both: it turns the reward off on purpose.
        $errors = $this->validate(['ontimegrace' => 0, 'graceearnrate' => 0]);
        $this->assertArrayNotHasKey('ontimegrace', $errors);
        $this->assertArrayNotHasKey('graceearnrate', $errors);
    }

    /**
     * A time based activity needs a real interval between unlocks.
     *
     * An interval of zero or less unlocks every band at once, so the ordering
     * the teacher just built would never be applied.
     *
     * @return void
     */
    public function test_a_zero_unlock_interval_is_refused_in_time_mode(): void {
        $this->assertSame(
            $this->bands_at_once(0),
            $this->bands_at_once(-3),
            'zero and a negative interval mean the same thing to the scheduler'
        );

        $errors = $this->validate(['unlockmode' => bands::MODE_TIME, 'unlockinterval' => 0]);
        $this->assertArrayHasKey('unlockinterval', $errors);

        $errors = $this->validate(['unlockmode' => bands::MODE_TIME, 'unlockinterval' => 7]);
        $this->assertArrayNotHasKey('unlockinterval', $errors);
    }

    /**
     * What the scheduler does with an interval, so the test above is anchored
     * to real behaviour rather than to an assumption about it.
     *
     * @param float $interval Days between unlocks.
     * @return int The band a brand new learner would be on.
     */
    protected function bands_at_once(float $interval): int {
        return bands::level_for_time(0.0, $interval, 5);
    }

    /**
     * The interval only matters in time mode, so it is not policed elsewhere.
     *
     * @return void
     */
    public function test_the_unlock_interval_is_ignored_in_other_modes(): void {
        foreach ([bands::MODE_EXHAUSTED, bands::MODE_MASTERY] as $mode) {
            $errors = $this->validate(['unlockmode' => $mode, 'unlockinterval' => 0]);
            $this->assertArrayNotHasKey('unlockinterval', $errors, 'mode ' . $mode);
        }
    }

    /**
     * A negative backstop is refused; zero means no backstop and is allowed.
     *
     * @return void
     */
    public function test_the_backstop_cannot_be_negative(): void {
        $errors = $this->validate(['unlockmode' => bands::MODE_MASTERY, 'backstopdays' => -1]);
        $this->assertArrayHasKey('backstopdays', $errors);

        $errors = $this->validate(['unlockmode' => bands::MODE_MASTERY, 'backstopdays' => 0]);
        $this->assertArrayNotHasKey('backstopdays', $errors);
    }

    /**
     * A negative feedback pause would show the learner no feedback at all.
     *
     * @return void
     */
    public function test_negative_feedback_pauses_are_refused(): void {
        $this->assertArrayHasKey('pausecorrect', $this->validate(['pausecorrect' => -1]));
        $this->assertArrayHasKey('pauseincorrect', $this->validate(['pauseincorrect' => -1]));
    }

    /**
     * The checks that were already here still fire.
     *
     * @return void
     */
    public function test_the_existing_checks_still_fire(): void {
        $this->assertArrayHasKey('sessionsize', $this->validate(['sessionsize' => 0]));
        $this->assertArrayHasKey('maxchoices', $this->validate(['maxchoices' => 2]));
        $this->assertArrayHasKey('gracebalance', $this->validate(['gracebalance' => -1]));
        $this->assertArrayHasKey('bandcategory[0]', $this->validate(['bandcategory' => []]));
    }
}
