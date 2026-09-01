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

/**
 * The main configuration form for mod_rememberme.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use core_question\local\bank\question_bank_helper;
use mod_rememberme\local\bands;

/**
 * Instance settings form.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_rememberme_mod_form extends moodleform_mod {
    /** @var int How many extra band rows to offer when the form is extended. */
    protected const BAND_REPEAT_CHUNK = 3;

    /** @var int How many extra suspension rows to offer when the form is extended. */
    protected const SUSPENSION_REPEAT_CHUNK = 2;

    /**
     * Define the form.
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $this->add_pool_settings($mform);
        $this->add_scheduling_settings($mform);
        $this->add_session_settings($mform);
        $this->add_grading_settings($mform);
        $this->add_suspension_settings($mform);

        $this->standard_grading_coursemodule_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * The ordered question category bands.
     *
     * @param MoodleQuickForm $mform The form.
     */
    protected function add_pool_settings(MoodleQuickForm $mform): void {
        $mform->addElement('header', 'poolheader', get_string('poolsettings', 'rememberme'));
        $mform->setExpanded('poolheader');

        $mform->addElement('static', 'bandsintro', '', get_string('bandsintro', 'rememberme'));

        // Which bank the categories come from. Offered first, because a course
        // with several banks produces a category list too long to pick through.
        $mform->addElement(
            'select',
            'questionbankcmid',
            get_string('questionbank', 'rememberme'),
            $this->get_bank_options()
        );
        $mform->addHelpButton('questionbankcmid', 'questionbank', 'rememberme');
        $mform->setDefault('questionbankcmid', 0);

        $options = $this->get_category_options();
        if (empty($options)) {
            $mform->addElement('static', 'nobanks', '', get_string('nobanks', 'rememberme'));
        }

        $existing = $this->get_existing_band_count();
        $repeats = max(self::BAND_REPEAT_CHUNK, $existing + 1);

        // One band, several categories. An autocomplete rather than a plain
        // multiple select, because a bank can hold a great many categories and
        // the teacher usually knows the name of the one they are after.
        $elements = [
            $mform->createElement(
                'autocomplete',
                'bandcategory',
                get_string('bandcategories', 'rememberme'),
                $options,
                ['multiple' => true, 'noselectionstring' => get_string('choosecategory', 'rememberme')]
            ),
            $mform->createElement(
                'advcheckbox',
                'bandsubcategories',
                '',
                get_string('includesubcategories', 'rememberme')
            ),
        ];

        $this->repeat_elements(
            $elements,
            $repeats,
            [
                'bandsubcategories' => ['type' => PARAM_INT],
            ],
            'bandrepeats',
            'bandadd',
            self::BAND_REPEAT_CHUNK,
            get_string('addbands', 'rememberme'),
            true
        );

        $mform->addElement('select', 'unlockmode', get_string('unlockmode', 'rememberme'), [
            bands::MODE_EXHAUSTED => get_string('unlockmode_exhausted', 'rememberme'),
            bands::MODE_TIME => get_string('unlockmode_time', 'rememberme'),
            bands::MODE_MASTERY => get_string('unlockmode_mastery', 'rememberme'),
        ]);
        $mform->addHelpButton('unlockmode', 'unlockmode', 'rememberme');
        // Coverage is the default. A band that unlocks on a timer moves the
        // learner on whether or not they have met what is in it, and a band that
        // unlocks on mastery can hold them behind a handful of items they keep
        // lapsing. Unlocking once nothing in the band is unseen asks only that
        // the syllabus has actually been covered, which is what a teacher
        // ordering questions into bands was expressing in the first place.
        $mform->setDefault('unlockmode', bands::MODE_EXHAUSTED);

        $mform->addElement('text', 'unlockinterval', get_string('unlockinterval', 'rememberme'), ['size' => 5]);
        $mform->setType('unlockinterval', PARAM_INT);
        $mform->setDefault('unlockinterval', 7);
        $mform->addHelpButton('unlockinterval', 'unlockinterval', 'rememberme');
        $mform->hideIf('unlockinterval', 'unlockmode', 'neq', bands::MODE_TIME);

        $mform->addElement('text', 'stabilityfloor', get_string('stabilityfloor', 'rememberme'), ['size' => 5]);
        $mform->setType('stabilityfloor', PARAM_FLOAT);
        $mform->setDefault('stabilityfloor', 14);
        $mform->addHelpButton('stabilityfloor', 'stabilityfloor', 'rememberme');
        $mform->hideIf('stabilityfloor', 'unlockmode', 'neq', bands::MODE_MASTERY);

        $mform->addElement('text', 'masteryproportion', get_string('masteryproportion', 'rememberme'), ['size' => 5]);
        $mform->setType('masteryproportion', PARAM_FLOAT);
        $mform->setDefault('masteryproportion', 0.7);
        $mform->addHelpButton('masteryproportion', 'masteryproportion', 'rememberme');
        $mform->hideIf('masteryproportion', 'unlockmode', 'neq', bands::MODE_MASTERY);

        $mform->addElement('text', 'backstopdays', get_string('backstopdays', 'rememberme'), ['size' => 5]);
        $mform->setType('backstopdays', PARAM_INT);
        $mform->setDefault('backstopdays', 21);
        $mform->addHelpButton('backstopdays', 'backstopdays', 'rememberme');
        $mform->hideIf('backstopdays', 'unlockmode', 'neq', bands::MODE_MASTERY);
    }

    /**
     * Memory model settings.
     *
     * @param MoodleQuickForm $mform The form.
     */
    protected function add_scheduling_settings(MoodleQuickForm $mform): void {
        $mform->addElement('header', 'schedulingheader', get_string('schedulingsettings', 'rememberme'));

        $mform->addElement('text', 'targetretention', get_string('targetretention', 'rememberme'), ['size' => 5]);
        $mform->setType('targetretention', PARAM_FLOAT);
        $mform->setDefault('targetretention', 0.9);
        $mform->addHelpButton('targetretention', 'targetretention', 'rememberme');

        $mform->addElement('text', 'passthreshold', get_string('passthreshold', 'rememberme'), ['size' => 5]);
        $mform->setType('passthreshold', PARAM_FLOAT);
        $mform->setDefault('passthreshold', 0.5);
        $mform->addHelpButton('passthreshold', 'passthreshold', 'rememberme');

        $mform->addElement('advcheckbox', 'uselatency', get_string('uselatency', 'rememberme'));
        $mform->setDefault('uselatency', 1);
        $mform->addHelpButton('uselatency', 'uselatency', 'rememberme');
    }

    /**
     * Delivery settings.
     *
     * @param MoodleQuickForm $mform The form.
     */
    protected function add_session_settings(MoodleQuickForm $mform): void {
        $mform->addElement('header', 'sessionheader', get_string('sessionsettings', 'rememberme'));

        $mform->addElement('text', 'sessionsize', get_string('sessionsize', 'rememberme'), ['size' => 5]);
        $mform->setType('sessionsize', PARAM_INT);
        $mform->setDefault('sessionsize', 20);
        $mform->addHelpButton('sessionsize', 'sessionsize', 'rememberme');

        $mform->addElement('text', 'newperday', get_string('newperday', 'rememberme'), ['size' => 5]);
        $mform->setType('newperday', PARAM_INT);
        $mform->setDefault('newperday', 10);
        $mform->addHelpButton('newperday', 'newperday', 'rememberme');

        $mform->addElement('text', 'maxchoices', get_string('maxchoices', 'rememberme'), ['size' => 5]);
        $mform->setType('maxchoices', PARAM_INT);
        $mform->setDefault('maxchoices', 0);
        $mform->addHelpButton('maxchoices', 'maxchoices', 'rememberme');

        $mform->addElement('advcheckbox', 'audiocue', get_string('audiocue', 'rememberme'));
        $mform->setDefault('audiocue', 1);
        $mform->addHelpButton('audiocue', 'audiocue', 'rememberme');

        $mform->addElement('text', 'pausecorrect', get_string('pausecorrect', 'rememberme'), ['size' => 6]);
        $mform->setType('pausecorrect', PARAM_INT);
        $mform->setDefault('pausecorrect', 1200);
        $mform->addHelpButton('pausecorrect', 'pausecorrect', 'rememberme');

        $mform->addElement('text', 'pauseincorrect', get_string('pauseincorrect', 'rememberme'), ['size' => 6]);
        $mform->setType('pauseincorrect', PARAM_INT);
        $mform->setDefault('pauseincorrect', 2500);
        $mform->addHelpButton('pauseincorrect', 'pauseincorrect', 'rememberme');
    }

    /**
     * Weekly completion and grace settings.
     *
     * @param MoodleQuickForm $mform The form.
     */
    protected function add_grading_settings(MoodleQuickForm $mform): void {
        $mform->addElement('header', 'gradingheader', get_string('gradingsettings', 'rememberme'));

        $mform->addElement('static', 'gradingintro', '', get_string('gradingintro', 'rememberme'));

        $mform->addElement('date_time_selector', 'coursestart', get_string('coursestart', 'rememberme'));
        $mform->addHelpButton('coursestart', 'coursestart', 'rememberme');

        $mform->addElement('text', 'activeweeks', get_string('activeweeks', 'rememberme'), ['size' => 5]);
        $mform->setType('activeweeks', PARAM_INT);
        $mform->setDefault('activeweeks', 15);
        $mform->addHelpButton('activeweeks', 'activeweeks', 'rememberme');

        $mform->addElement('text', 'gracebalance', get_string('gracebalance', 'rememberme'), ['size' => 5]);
        $mform->setType('gracebalance', PARAM_FLOAT);
        $mform->setDefault('gracebalance', 1.0);
        $mform->addHelpButton('gracebalance', 'gracebalance', 'rememberme');

        $mform->addElement('text', 'graceearnrate', get_string('graceearnrate', 'rememberme'), ['size' => 5]);
        $mform->setType('graceearnrate', PARAM_FLOAT);
        $mform->setDefault('graceearnrate', 0.25);
        $mform->addHelpButton('graceearnrate', 'graceearnrate', 'rememberme');

        $mform->addElement('text', 'ontimegrace', get_string('ontimegrace', 'rememberme'), ['size' => 5]);
        $mform->setType('ontimegrace', PARAM_FLOAT);
        $mform->setDefault('ontimegrace', 0.5);
        $mform->addHelpButton('ontimegrace', 'ontimegrace', 'rememberme');
    }

    /**
     * Suspension windows.
     *
     * @param MoodleQuickForm $mform The form.
     */
    protected function add_suspension_settings(MoodleQuickForm $mform): void {
        $mform->addElement('header', 'suspensionheader', get_string('suspensionsettings', 'rememberme'));

        $mform->addElement('static', 'suspensionintro', '', get_string('suspensionintro', 'rememberme'));

        $existing = $this->get_existing_suspension_count();
        $repeats = max(self::SUSPENSION_REPEAT_CHUNK, $existing + 1);

        $elements = [
            $mform->createElement(
                'text',
                'suspensionname',
                get_string('suspensionname', 'rememberme'),
                ['size' => 30]
            ),
            $mform->createElement(
                'date_time_selector',
                'suspensionstart',
                get_string('suspensionstart', 'rememberme'),
                ['optional' => true]
            ),
            $mform->createElement(
                'date_time_selector',
                'suspensionend',
                get_string('suspensionend', 'rememberme'),
                ['optional' => true]
            ),
        ];

        $this->repeat_elements(
            $elements,
            $repeats,
            ['suspensionname' => ['type' => PARAM_TEXT]],
            'suspensionrepeats',
            'suspensionadd',
            self::SUSPENSION_REPEAT_CHUNK,
            get_string('addsuspensions', 'rememberme'),
            true
        );
    }

    /**
     * The question banks this course can draw on.
     *
     * @return array Options keyed by course module id, zero meaning any bank.
     */
    protected function get_bank_options(): array {
        $options = [0 => get_string('anybank', 'rememberme')];

        foreach ($this->get_banks() as $bank) {
            $options[(int)$bank->cminfo->id] = $bank->cminfo->get_formatted_name();
        }

        return $options;
    }

    /**
     * The banks available to this course, shared and private alike.
     *
     * @return array Bank records.
     */
    protected function get_banks(): array {
        $courseid = $this->current->course ?? ($this->_course->id ?? 0);
        if (empty($courseid)) {
            return [];
        }

        return array_merge(
            question_bank_helper::get_activity_instances_with_shareable_questions(
                [$courseid],
                [],
                ['moodle/question:useall'],
                true
            ),
            question_bank_helper::get_activity_instances_with_private_questions(
                [$courseid],
                [],
                ['moodle/question:useall'],
                true
            )
        );
    }

    /**
     * Question category options for the band selectors.
     *
     * Categories live in the module context of a question bank in Moodle 5.x, so
     * they are grouped by the bank they belong to. When the teacher has narrowed
     * the activity to one bank, only that bank's categories are offered: a course
     * with several banks otherwise produces a list nobody can find anything in.
     *
     * @return array Options keyed by category id.
     */
    protected function get_category_options(): array {
        $options = [];
        $chosenbank = (int)($this->current->questionbankcmid ?? 0);

        foreach ($this->get_banks() as $bank) {
            $bankcmid = (int)$bank->cminfo->id;
            if ($chosenbank > 0 && $bankcmid !== $chosenbank) {
                continue;
            }

            $bankname = $bank->cminfo->get_formatted_name();
            foreach ($bank->questioncategories as $category) {
                $categoryid = (int)($category->id ?? 0);
                if ($categoryid <= 0) {
                    continue;
                }
                $options[$categoryid] = $bankname . ': ' . format_string($category->name ?? '');
            }
        }

        return $options;
    }

    /**
     * How many bands this instance already has.
     *
     * @return int The band count.
     */
    protected function get_existing_band_count(): int {
        global $DB;

        if (empty($this->current->id)) {
            return 0;
        }
        return $DB->count_records('rememberme_bands', ['rememberme' => $this->current->id]);
    }

    /**
     * How many suspension windows this instance already has.
     *
     * @return int The window count.
     */
    protected function get_existing_suspension_count(): int {
        global $DB;

        if (empty($this->current->id)) {
            return 0;
        }
        return $DB->count_records('rememberme_suspensions', ['rememberme' => $this->current->id]);
    }

    /**
     * Load the repeated band and suspension rows into the form.
     *
     * @param array $defaultvalues The values being loaded.
     */
    public function data_preprocessing(&$defaultvalues): void {
        global $DB;

        parent::data_preprocessing($defaultvalues);

        if (empty($this->current->id)) {
            return;
        }

        $bands = $DB->get_records(
            'rememberme_bands',
            ['rememberme' => $this->current->id],
            'bandnumber ASC, sortorder ASC'
        );

        $grouped = [];
        $subcategories = [];
        foreach ($bands as $band) {
            $grouped[(int)$band->bandnumber][] = (int)$band->questioncategoryid;
            // The subcategory choice belongs to the band, so the first row in a
            // band decides it for the whole band.
            $subcategories[(int)$band->bandnumber] ??= (int)$band->includesubcategories;
        }
        ksort($grouped);

        $index = 0;
        foreach ($grouped as $bandnumber => $categoryids) {
            $defaultvalues['bandcategory[' . $index . ']'] = $categoryids;
            $defaultvalues['bandsubcategories[' . $index . ']'] = $subcategories[$bandnumber];
            $index++;
        }

        $windows = $DB->get_records('rememberme_suspensions', ['rememberme' => $this->current->id], 'timestart ASC');
        $index = 0;
        foreach ($windows as $window) {
            $defaultvalues['suspensionname[' . $index . ']'] = $window->name;
            $defaultvalues['suspensionstart[' . $index . ']'] = $window->timestart;
            $defaultvalues['suspensionend[' . $index . ']'] = $window->timeend;
            $index++;
        }
    }

    /**
     * Validate the submitted settings.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        // The interval formula inverts a power of the retention, so it is only
        // defined strictly between 0 and 1.
        if ($data['targetretention'] <= 0 || $data['targetretention'] >= 1) {
            $errors['targetretention'] = get_string('errorretentionrange', 'rememberme');
        }
        if ($data['passthreshold'] < 0 || $data['passthreshold'] > 1) {
            $errors['passthreshold'] = get_string('errorthresholdrange', 'rememberme');
        }
        if ($data['sessionsize'] < 1) {
            $errors['sessionsize'] = get_string('errorpositive', 'rememberme');
        }
        if ($data['newperday'] < 0) {
            $errors['newperday'] = get_string('errornonnegative', 'rememberme');
        }
        // One option is not a question, and two is a coin toss dressed up as
        // recall. Zero is the way to switch the limit off.
        if ($data['maxchoices'] < 0 || $data['maxchoices'] === 1 || $data['maxchoices'] === 2) {
            $errors['maxchoices'] = get_string('errormaxchoices', 'rememberme');
        }
        if ($data['activeweeks'] < 1) {
            $errors['activeweeks'] = get_string('errorpositive', 'rememberme');
        }
        if ($data['gracebalance'] < 0) {
            $errors['gracebalance'] = get_string('errornonnegative', 'rememberme');
        }

        if ((int)$data['unlockmode'] === bands::MODE_MASTERY) {
            // A proportion at or above 1.0 lets a few persistently lapsing items
            // hold a learner on one band for the whole course.
            if ($data['masteryproportion'] <= 0 || $data['masteryproportion'] >= 1) {
                $errors['masteryproportion'] = get_string('errorproportionrange', 'rememberme');
            }
            if ($data['stabilityfloor'] <= 0) {
                $errors['stabilityfloor'] = get_string('errorpositive', 'rememberme');
            }
        }

        // Each band is now a set of categories, so the checks are across bands
        // as well as within them: a category used in two bands would be
        // introduced twice and unlock nothing the second time.
        $seen = [];
        $anychosen = false;
        foreach ((array)($data['bandcategory'] ?? []) as $index => $categoryids) {
            $categoryids = array_filter(array_map('intval', (array)$categoryids));
            if (empty($categoryids)) {
                continue;
            }
            $anychosen = true;

            if (count($categoryids) !== count(array_unique($categoryids))) {
                $errors['bandcategory[' . $index . ']'] = get_string('errorduplicateband', 'rememberme');
                continue;
            }
            foreach ($categoryids as $categoryid) {
                if (isset($seen[$categoryid])) {
                    $errors['bandcategory[' . $index . ']'] = get_string('errorduplicateband', 'rememberme');
                    break;
                }
                $seen[$categoryid] = true;
            }
        }

        if (!$anychosen) {
            $errors['bandcategory[0]'] = get_string('errornobands', 'rememberme');
        }

        foreach ((array)($data['suspensionstart'] ?? []) as $index => $start) {
            $end = (int)($data['suspensionend'][$index] ?? 0);
            if (!empty($start) && !empty($end) && $end <= $start) {
                $errors['suspensionend[' . $index . ']'] = get_string('errorwindowbackwards', 'rememberme');
            }
        }

        return $errors;
    }

    /**
     * Add the custom completion rule.
     *
     * @return array The element names added.
     */
    #[\Override]
    public function add_completion_rules(): array {
        $mform = $this->_form;
        $suffix = $this->get_suffix();

        $group = [];
        $group[] = $mform->createElement(
            'checkbox',
            'completionweeksenabled' . $suffix,
            '',
            get_string('completionweeks', 'rememberme')
        );
        $group[] = $mform->createElement('text', 'completionweeks' . $suffix, '', ['size' => 3]);
        $mform->setType('completionweeks' . $suffix, PARAM_INT);

        $mform->addGroup(
            $group,
            'completionweeksgroup' . $suffix,
            get_string('completionweeksgroup', 'rememberme'),
            [' '],
            false
        );
        $mform->hideIf('completionweeks' . $suffix, 'completionweeksenabled' . $suffix, 'notchecked');
        $mform->setDefault('completionweeks' . $suffix, 1);

        return ['completionweeksgroup' . $suffix];
    }

    /**
     * Whether any custom completion rule is enabled.
     *
     * @param array $data Submitted data.
     * @return bool True if a rule is enabled.
     */
    #[\Override]
    public function completion_rule_enabled($data): bool {
        $suffix = $this->get_suffix();
        return !empty($data['completionweeksenabled' . $suffix]) && $data['completionweeks' . $suffix] > 0;
    }

    /**
     * Normalise the completion rule before saving.
     *
     * @param stdClass $data Submitted data.
     */
    #[\Override]
    public function data_postprocessing($data): void {
        parent::data_postprocessing($data);

        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $enabled = !empty($data->{'completionweeksenabled' . $suffix});
            if (!$enabled || empty($data->{'completionweeks' . $suffix})) {
                $data->completionweeks = 0;
            } else {
                $data->completionweeks = (int)$data->{'completionweeks' . $suffix};
            }
        }
    }
}
