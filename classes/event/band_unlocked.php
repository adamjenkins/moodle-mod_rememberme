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
 * Fired when a learner's band state advances to the next band.
 *
 * Unlocking is per learner, so this is an update to that learner's band state
 * row rather than a change to the activity: two students in one course can sit
 * on different bands at the same time.
 *
 * The reason carried in other is the part worth reporting on. An unlock for
 * reason 'backstop' means the learner did not meet the mastery condition and
 * was advanced anyway to stop them stalling on one band for the whole course.
 * That is the signal a teacher should act on, and it is indistinguishable from
 * an earned unlock unless the reason travels with the event.
 *
 * @property-read array $other {
 *      Extra information about the event.
 *
 *      - int bandlevel: the band the learner has just moved to.
 *      - string reason: how it was reached, as stored in rememberme_bandstate.
 * }
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class band_unlocked extends \core\event\base {
    /**
     * Set the fixed properties of this event.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'rememberme_bandstate';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Get the localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventbandunlocked', 'mod_rememberme');
    }

    /**
     * Describe what happened, for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' unlocked band '{$this->other['bandlevel']}' " .
            "in the rememberme activity with course module id '$this->contextinstanceid', " .
            "for the reason '{$this->other['reason']}'.";
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
            throw new \coding_exception('The \'objectid\' must be set to the band state row id.');
        }

        foreach (['bandlevel', 'reason'] as $key) {
            if (!array_key_exists($key, $this->other)) {
                throw new \coding_exception('The \'' . $key . '\' value must be set in other.');
            }
        }
    }

    /**
     * Map the objectid when course logs are restored.
     *
     * Band state is backed up as an unmapped nested element, so there is no
     * restore mapping to point at; see backup/moodle2/restore_rememberme_stepslib.php,
     * which sets a mapping for sessions only.
     *
     * @return array The table and restore mapping for the objectid.
     */
    public static function get_objectid_mapping() {
        return ['db' => 'rememberme_bandstate', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Map the values in other when course logs are restored.
     *
     * Neither value is an id, so neither needs remapping into a restored course.
     *
     * @return array Mapping for each value carried in other.
     */
    public static function get_other_mapping() {
        return [];
    }
}
