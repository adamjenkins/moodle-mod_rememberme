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

namespace mod_rememberme\output;

use mod_rememberme\local\scheduler;
use mod_rememberme\local\session;

/**
 * Moodle app views for mod_rememberme.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /**
     * The main activity view in the app.
     *
     * @param array $args Arguments from the app, carrying at least cmid.
     * @return array The app view definition.
     */
    public static function mobile_course_view(array $args): array {
        global $OUTPUT, $USER, $DB, $PAGE;

        $args = (object)$args;
        $cmid = (int)$args->cmid;

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'rememberme');
        require_login($course, false, $cm);

        $context = \context_module::instance($cm->id);
        require_capability('mod/rememberme:view', $context);
        $PAGE->set_context($context);

        $instance = $DB->get_record('rememberme', ['id' => $cm->instance], '*', MUST_EXIST);
        $scheduler = new scheduler($instance);

        $canattempt = has_capability('mod/rememberme:attempt', $context);
        $due = $canattempt ? count($scheduler->due_records((int)$USER->id, time())) : 0;

        $weeks = $scheduler->get_weeks();
        $weekno = $weeks->week_for(time());
        $weekrecord = $DB->get_record('rememberme_weeks', [
            'rememberme' => $instance->id,
            'userid' => $USER->id,
            'weekno' => $weekno,
        ]);

        $data = [
            'cmid' => $cmid,
            'name' => format_string($instance->name),
            'intro' => format_module_intro('rememberme', $instance, $cm->id),
            'due' => $due,
            'canattempt' => $canattempt,
            'weekdone' => $weekrecord ? (int)$weekrecord->completed : 0,
            'weektarget' => $weekrecord ? (int)$weekrecord->snapshottarget : 0,
        ];

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_rememberme/mobile_view', $data),
                ],
            ],
            'javascript' => '',
            'otherdata' => '',
        ];
    }
}
