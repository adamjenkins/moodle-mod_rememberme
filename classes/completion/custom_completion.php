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

namespace mod_rememberme\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion rules for mod_rememberme.
 *
 * Completion is measured in weeks cleared, which matches what the activity is
 * for: returning regularly over a term. It is deliberately not measured in
 * questions answered, because volume is not an achievement here. The due queue
 * is capped and driven by the learner's own memory state, so the learner
 * answering the most questions is the one with the most lapses.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Get the completion state for a rule.
     *
     * @param string $rule The completion rule.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $required = (int)($this->cm->customdata['customcompletionrules']['completionweeks'] ?? 0);
        if ($required <= 0) {
            return COMPLETION_INCOMPLETE;
        }

        $cleared = $DB->count_records_select(
            'rememberme_weeks',
            'rememberme = :instanceid AND userid = :userid AND fraction >= 1',
            ['instanceid' => $this->cm->instance, 'userid' => $this->userid]
        );

        return $cleared >= $required ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * The custom rules this activity defines.
     *
     * @return array List of rule names.
     */
    public static function get_defined_custom_rules(): array {
        return ['completionweeks'];
    }

    /**
     * Human readable descriptions of the custom rules.
     *
     * @return array Descriptions keyed by rule name.
     */
    public function get_custom_rule_descriptions(): array {
        $required = (int)($this->cm->customdata['customcompletionrules']['completionweeks'] ?? 0);
        return [
            'completionweeks' => get_string('completiondetail:weeks', 'rememberme', $required),
        ];
    }

    /**
     * The order rules are shown in.
     *
     * @return array Rule names in display order.
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionweeks',
        ];
    }
}
