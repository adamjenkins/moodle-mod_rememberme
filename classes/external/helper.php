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

use core_external\external_value;
use mod_rememberme\local\scheduler;
use mod_rememberme\local\session;

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
     * When the queue is empty because everything on offer has been done, that
     * is recorded first: it is what makes today count for a learner who had
     * little due, and the figures returned alongside then already show it.
     *
     * @param session $session The learner's session handler.
     * @param int $userid The learner.
     * @param bool $cleared Whether nothing more could be offered, as opposed to a session that ended.
     * @return array The payload.
     */
    public static function empty_payload(session $session, int $userid, bool $cleared): array {
        $scheduler = $session->get_scheduler();
        if ($cleared) {
            $scheduler->mark_day_cleared($userid);
        }

        return [
            'hasquestion' => false,
            'slot' => 0,
            'html' => '',
            'javascript' => '',
            'answered' => 0,
            'total' => 0,
            'message' => get_string('nothingduedesc', 'rememberme'),
        ] + self::progress_fields(self::week_progress($scheduler, $userid));
    }

    /**
     * The progress figures every response carries, from week_progress().
     *
     * @param array $progress The result of week_progress().
     * @return array Fields for a web service response.
     */
    public static function progress_fields(array $progress): array {
        return [
            'weekdone' => $progress['done'],
            'weektarget' => $progress['target'],
            'weeklabel' => $progress['weeklabel'],
            'todaylabel' => $progress['todaylabel'],
            'streak' => $progress['streak'],
        ];
    }

    /**
     * Describe the progress fields for a web service return structure.
     *
     * @return array Field name to external_value.
     */
    public static function progress_returns(): array {
        return [
            'weekdone' => new external_value(PARAM_INT, 'Study days that count so far this week'),
            'weektarget' => new external_value(PARAM_INT, 'Study days this week needs'),
            'weeklabel' => new external_value(PARAM_TEXT, 'This week\'s study days, worded for the learner'),
            'todaylabel' => new external_value(PARAM_TEXT, 'Progress toward today counting, empty outside the graded weeks'),
            'streak' => new external_value(PARAM_INT, 'Consecutive weeks earned in full'),
        ];
    }

    /**
     * The learner's standing this week, computed live from the review log.
     *
     * Computed rather than read from the stored week row, so the figure is
     * right even before the row has been rescored, and so what the learner
     * sees and what the gradebook gets can never drift apart.
     *
     * @param scheduler $scheduler The scheduler.
     * @param int $userid The learner.
     * @param int|null $now Current time, or null for now.
     * @return array Progress with done, target, graded, weekno, streak, weeklabel and todaylabel.
     */
    public static function week_progress(scheduler $scheduler, int $userid, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();
        $weeks = $scheduler->get_weeks();
        $weekno = $weeks->week_for($now);
        $instanceid = $scheduler->get_instance_id();

        $fractions = $DB->get_records_menu('rememberme_weeks', [
            'rememberme' => $instanceid,
            'userid' => $userid,
        ], 'weekno ASC', 'weekno, fraction');
        $streak = \mod_rememberme\local\weeks::streak(array_map('floatval', $fractions), $weekno);

        if (!$scheduler->is_graded_week($weekno)) {
            // Outside the graded weeks nothing is counted, so showing a
            // count would only ever show zero.
            if ($weekno < 1) {
                [$firststart] = $weeks->week_bounds(1);
                $label = get_string(
                    'gradingnotstarted',
                    'rememberme',
                    userdate($firststart, get_string('strftimedaydatetime', 'langconfig'))
                );
            } else {
                $label = get_string('gradingended', 'rememberme');
            }
            return [
                'done' => 0,
                'target' => $scheduler->required_study_days(),
                'graded' => false,
                'weekno' => $weekno,
                'streak' => $streak,
                'weeklabel' => $label,
                'todaylabel' => '',
            ];
        }

        if ($weeks->is_week_suspended($weekno)) {
            // A break week asks nothing, so there is no count to show. Saying
            // what studying now is worth is more use than a count of zero.
            return [
                'done' => 0,
                'target' => 0,
                'graded' => false,
                'weekno' => $weekno,
                'streak' => $streak,
                'weeklabel' => get_string('breakweek', 'rememberme'),
                'todaylabel' => '',
            ];
        }

        $record = $DB->get_record('rememberme_weeks', [
            'rememberme' => $instanceid,
            'userid' => $userid,
            'weekno' => $weekno,
        ]);
        $base = $record && (int)$record->daysrequired > 0
            ? (int)$record->daysrequired
            : $scheduler->required_study_days();
        $target = $scheduler->effective_required($base, $weekno);
        $done = $scheduler->count_study_days(
            $scheduler->day_counts($userid, $weekno),
            $record ? (int)$record->clearedmask : 0
        );

        $today = $scheduler->today_progress($userid, $now);
        $todaylabel = $today['counts']
            ? get_string('progresstodaydone', 'rememberme')
            : get_string('progresstoday', 'rememberme', ['done' => $today['done'], 'target' => $today['target']]);

        return [
            'done' => $done,
            'target' => $target,
            'graded' => true,
            'weekno' => $weekno,
            'streak' => $streak,
            'weeklabel' => get_string('progressthisweek', 'rememberme', ['done' => $done, 'target' => $target]),
            'todaylabel' => $todaylabel,
        ];
    }
}
