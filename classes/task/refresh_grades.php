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
        // Until a week after the last graded week has ended, not after the end
        // of term: a term that ends early in its last week would otherwise
        // stop being refreshed before that week ends, and a missed final week
        // would never reach the gradebook.
        //
        // The window is worked out here rather than in SQL: multiplying the
        // week count by a week's seconds overflows a 32 bit integer on
        // PostgreSQL for a large week count, and one such row would fail the
        // task for every activity on the site.
        $instances = $DB->get_recordset_select('rememberme', 'grade <> 0 AND coursestart <= :now', ['now' => $now]);
        $refreshed = 0;
        foreach ($instances as $instance) {
            if ((int)$instance->coursestart + ((int)$instance->activeweeks + 1) * WEEKSECS < $now) {
                continue;
            }
            rememberme_update_grades($instance);
            $refreshed++;
        }
        $instances->close();
        mtrace('  Refreshed grades for ' . $refreshed . ' rememberme activity(ies).');
    }
}
