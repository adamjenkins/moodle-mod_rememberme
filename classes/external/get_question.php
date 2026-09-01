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
use core_external\external_single_structure;
use core_external\external_value;
use mod_rememberme\local\session;

/**
 * Fetch the next question in the learner's session.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_question extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters The parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Return the next question for the current user.
     *
     * The session is always derived from the logged in user server side. No
     * session or slot identifier is accepted from the client, because a client
     * supplied one would let a learner answer somebody else's session.
     *
     * @param int $cmid Course module id.
     * @return array The question payload.
     */
    public static function execute(int $cmid): array {
        global $USER, $PAGE;

        [
            'cmid' => $cmid,
        ] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'rememberme');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/rememberme:attempt', $context);

        $PAGE->set_context($context);

        $instance = helper::get_instance($cm);
        $session = new session($instance, $context);

        if (!$session->load_or_start((int)$USER->id)) {
            return helper::empty_payload($instance, $context, (int)$USER->id);
        }

        $slot = $session->next_slot();
        if ($slot === null) {
            $session->finish();
            return helper::empty_payload($instance, $context, (int)$USER->id);
        }

        [$html, $javascript] = $session->render_slot($slot);
        [$answered, $total] = $session->get_progress();

        return [
            'hasquestion' => true,
            'slot' => $slot,
            'html' => $html,
            'javascript' => $javascript,
            'answered' => $answered,
            'total' => $total,
            'message' => '',
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure The structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'hasquestion' => new external_value(PARAM_BOOL, 'Whether a question was returned'),
            'slot' => new external_value(PARAM_INT, 'The question engine slot'),
            'html' => new external_value(PARAM_RAW, 'Rendered question HTML'),
            'javascript' => new external_value(PARAM_RAW, 'JavaScript the rendered question requires'),
            'answered' => new external_value(PARAM_INT, 'Questions answered in this session'),
            'total' => new external_value(PARAM_INT, 'Questions in this session'),
            'message' => new external_value(PARAM_RAW, 'Message shown when there is no question'),
        ]);
    }
}
