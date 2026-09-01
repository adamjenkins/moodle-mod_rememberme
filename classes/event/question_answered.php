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

namespace mod_rememberme\event;

/**
 * Fired once for each question the learner answers and has graded.
 *
 * This is deliberately per question rather than per session. A learner who
 * closes the tab after three questions keeps the scheduling effect of those
 * three, so a session level event would report nothing at all for exactly the
 * interrupted sessions that are most worth seeing.
 *
 * The object is the review log row, not the schedule row: the log is immutable
 * and one row per answer, so the event points at something that will still
 * describe this answer after the learner's memory state has moved on.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int questionbankentryid: the bank entry answered, which is what the
 *        learner's memory state is keyed on.
 *      - int questionid: the question version actually served, so a report can
 *        show that an item was edited mid course.
 *      - int rating: the mapped review grade, 1 again, 2 hard, 3 good, 4 easy.
 * }
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_answered extends \core\event\base {
    /**
     * Set the fixed properties of this event.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'rememberme_review_log';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Get the localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventquestionanswered', 'mod_rememberme');
    }

    /**
     * Describe what happened, for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' answered the question with bank entry id " .
            "'{$this->other['questionbankentryid']}' in the rememberme activity with course module id " .
            "'$this->contextinstanceid', and it was graded '{$this->other['rating']}'.";
    }

    /**
     * Get the URL a reader of the log should be sent to.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/rememberme/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Check that the caller supplied everything the description relies on.
     *
     * @throws \coding_exception If a required value is missing.
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->objectid)) {
            throw new \coding_exception('The \'objectid\' must be set to the review log row id.');
        }

        foreach (['questionbankentryid', 'questionid', 'rating'] as $key) {
            if (!array_key_exists($key, $this->other)) {
                throw new \coding_exception('The \'' . $key . '\' value must be set in other.');
            }
        }
    }

    /**
     * Map the objectid when course logs are restored.
     *
     * The review log is backed up as an unmapped nested element, so there is no
     * restore mapping to point at; see backup/moodle2/restore_rememberme_stepslib.php,
     * which sets a mapping for sessions only.
     *
     * @return array The table and restore mapping for the objectid.
     */
    public static function get_objectid_mapping() {
        return ['db' => 'rememberme_review_log', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Map the values in other when course logs are restored.
     *
     * @return array Mapping for each value carried in other.
     */
    public static function get_other_mapping() {
        $othermapped = [];
        // A question bank entry has no restore mapping name in core, so it
        // cannot be resolved in the restored course.
        $othermapped['questionbankentryid'] = \core\event\base::NOT_MAPPED;
        $othermapped['questionid'] = ['db' => 'question', 'restore' => 'question'];

        return $othermapped;
    }
}
