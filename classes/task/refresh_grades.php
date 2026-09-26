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

namespace mod_rememberme\task;

/**
 * Keep gradebook grades in step with the calendar.
 *
 * A learner's grade changes when they answer, and that is pushed at once. It
 * also changes when a week ends without them, because a missed week then joins
 * the average, and nothing the learner does triggers that. This pushes grades
 * once a day for every activity whose graded calendar is running.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_grades extends \core\task\scheduled_task {
    /**
     * Get the descriptive name shown to administrators.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskrefreshgrades', 'mod_rememberme');
    }

    /**
     * Push grades for every activity in or just after its graded weeks.
     *
     * @return void
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/rememberme/lib.php');

        $now = time();
        // One extra week after the end of term, so the final week's ending
        // still reaches the gradebook.
        $instances = $DB->get_records_select(
            'rememberme',
            'grade <> 0 AND coursestart <= :now AND termend + :week >= :now2',
            ['now' => $now, 'week' => WEEKSECS, 'now2' => $now]
        );
        foreach ($instances as $instance) {
            rememberme_update_grades($instance);
        }
        mtrace('  Refreshed grades for ' . count($instances) . ' rememberme activity(ies).');
    }
}
