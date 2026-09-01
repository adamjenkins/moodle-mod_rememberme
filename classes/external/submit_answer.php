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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_rememberme\local\session;

/**
 * Grade one submitted answer and return the feedback view.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_answer extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters The parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'slot' => new external_value(PARAM_INT, 'The question engine slot being answered'),
            'response' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_RAW, 'Form field name'),
                    'value' => new external_value(PARAM_RAW, 'Form field value'),
                ]),
                'The submitted question form fields'
            ),
        ]);
    }

    /**
     * Grade the answer, reschedule the item, and return the feedback view.
     *
     * @param int $cmid Course module id.
     * @param int $slot The slot being answered.
     * @param array $response The submitted form fields.
     * @return array The feedback payload.
     */
    public static function execute(int $cmid, int $slot, array $response): array {
        global $USER, $PAGE;

        [
            'cmid' => $cmid,
            'slot' => $slot,
            'response' => $response,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'slot' => $slot,
            'response' => $response,
        ]);

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'rememberme');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/rememberme:attempt', $context);

        $PAGE->set_context($context);

        $instance = helper::get_instance($cm);
        $session = new session($instance, $context);

        // The session is resolved from the logged in user, never from anything
        // the client sent. The slot is then checked against that session, so a
        // learner cannot answer into somebody else's usage by guessing a number.
        if (!$session->load_or_start((int)$USER->id)) {
            throw new \moodle_exception('errorsessiongone', 'rememberme');
        }

        $postdata = [];
        foreach ($response as $field) {
            $postdata[$field['name']] = $field['value'];
        }

        $result = $session->process_response($slot, $postdata);

        [$html, $javascript] = $session->render_slot($slot, true);
        [$answered, $total] = $session->get_progress();

        $scheduler = $session->get_scheduler();
        $progress = helper::week_progress($scheduler, (int)$USER->id);

        return [
            'correct' => $result['correct'],
            'fraction' => $result['fraction'],
            'html' => $html,
            'javascript' => $javascript,
            'answered' => $answered,
            'total' => $total,
            'pause' => $result['correct']
                ? (int)$instance->pausecorrect
                : (int)$instance->pauseincorrect,
            'weekdone' => $progress['done'],
            'weektarget' => $progress['target'],
            'streak' => $progress['streak'],
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure The structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'correct' => new external_value(PARAM_BOOL, 'Whether the answer was fully correct'),
            'fraction' => new external_value(PARAM_FLOAT, 'The fraction earned'),
            'html' => new external_value(PARAM_RAW, 'Rendered feedback HTML'),
            'javascript' => new external_value(PARAM_RAW, 'JavaScript the feedback view requires'),
            'answered' => new external_value(PARAM_INT, 'Questions answered in this session'),
            'total' => new external_value(PARAM_INT, 'Questions in this session'),
            'pause' => new external_value(PARAM_INT, 'Milliseconds to show feedback before advancing'),
            'weekdone' => new external_value(PARAM_INT, 'Items completed this week'),
            'weektarget' => new external_value(PARAM_INT, 'This week\'s frozen target'),
            'streak' => new external_value(PARAM_INT, 'Consecutive weeks cleared'),
        ]);
    }
}
