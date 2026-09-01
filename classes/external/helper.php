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

namespace mod_rememberme\external;

use mod_rememberme\local\scheduler;

/**
 * Shared helpers for the external functions.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Load the activity instance for a course module.
     *
     * @param \cm_info|\stdClass $cm The course module.
     * @return \stdClass The instance record.
     */
    public static function get_instance($cm): \stdClass {
        global $DB;

        return $DB->get_record('rememberme', ['id' => $cm->instance], '*', MUST_EXIST);
    }

    /**
     * The payload returned when there is nothing to answer.
     *
     * A genuinely empty queue is an end of course condition rather than a
     * routine weekly one, and it is never a penalty: a learner who is on top of
     * their reviews has already met the week's requirement.
     *
     * @param \stdClass $instance The activity instance.
     * @param \context $context The module context.
     * @param int $userid The learner.
     * @return array The payload.
     */
    public static function empty_payload(\stdClass $instance, \context $context, int $userid): array {
        return [
            'hasquestion' => false,
            'slot' => 0,
            'html' => '',
            'javascript' => '',
            'answered' => 0,
            'total' => 0,
            'message' => get_string('nothingduedesc', 'rememberme'),
        ];
    }

    /**
     * The learner's progress against this week's frozen target.
     *
     * @param scheduler $scheduler The scheduler.
     * @param int $userid The learner.
     * @param int|null $now Current time, or null for now.
     * @return array Progress with done, target and streak.
     */
    public static function week_progress(scheduler $scheduler, int $userid, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $weeks = $scheduler->get_weeks();
        $weekno = $weeks->week_for($now);

        $record = $DB->get_record('rememberme_weeks', [
            'rememberme' => $scheduler->get_instance_id(),
            'userid' => $userid,
            'weekno' => $weekno,
        ]);

        $fractions = $DB->get_records_menu('rememberme_weeks', [
            'rememberme' => $scheduler->get_instance_id(),
            'userid' => $userid,
        ], 'weekno ASC', 'weekno, fraction');

        return [
            'done' => $record ? (int)$record->completed : 0,
            'target' => $record ? (int)$record->snapshottarget : 0,
            'weekno' => $weekno,
            'streak' => \mod_rememberme\local\weeks::streak(array_map('floatval', $fractions), $weekno),
        ];
    }
}
