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
 * Rescore every learner's weeks in one activity from the review log, then push grades.
 *
 * Queued by the upgrade that introduced study day grading, and after a restore,
 * because in both cases the stored week scores were computed by other code.
 * Everything it writes is derived from the review log and the stored cleared
 * day flags, so it is safe to run any number of times.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recalculate_weeks extends \core\task\adhoc_task {
    /**
     * Queue a recalculation for one activity.
     *
     * @param int $instanceid The rememberme instance id.
     */
    public static function queue(int $instanceid): void {
        $task = new self();
        $task->set_custom_data((object)['instanceid' => $instanceid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Run the recalculation.
     *
     * @return void
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/rememberme/lib.php');

        $instanceid = (int)($this->get_custom_data()->instanceid ?? 0);
        $instance = $DB->get_record('rememberme', ['id' => $instanceid]);
        if (!$instance) {
            mtrace("  Activity {$instanceid} no longer exists; nothing to recalculate.");
            return;
        }

        $now = time();
        $scheduler = new \mod_rememberme\local\scheduler($instance);
        $weeks = $scheduler->get_weeks();
        $lastweek = min((int)$instance->activeweeks, $weeks->week_for($now));

        $userids = $DB->get_fieldset_sql(
            'SELECT userid FROM {rememberme_weeks} WHERE rememberme = :a
              UNION
             SELECT userid FROM {rememberme_review_log} WHERE rememberme = :b',
            ['a' => $instanceid, 'b' => $instanceid]
        );

        $rescored = 0;
        foreach ($userids as $userid) {
            $userid = (int)$userid;
            for ($weekno = 1; $weekno <= $lastweek; $weekno++) {
                // A week gets a record if the learner answered anything in it;
                // one that already has a record is rescored either way.
                $answered = array_sum($scheduler->day_counts($userid, $weekno)) > 0;
                if ($scheduler->rescore_week($userid, $weekno, $now, $answered) !== null) {
                    $rescored++;
                }
            }
        }

        rememberme_update_grades($instance);
        mtrace("  Rescored {$rescored} week(s) for " . count($userids) . " learner(s) in activity {$instanceid}.");
    }
}
