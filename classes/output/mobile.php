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
use mod_rememberme\local\weeks;

/**
 * Moodle app views for mod_rememberme.
 *
 * The app view is the study session, exactly as the web view is: opening the
 * activity presents a question. It is not a landing card with a button, which is
 * what the first version of this file shipped and why the activity appeared to
 * do nothing in the app.
 *
 * The question itself is rendered by the app's own core-question component,
 * which is exported to site plugins by the app
 * (question.module.ts::getQuestionExportedDirectives). That component takes the
 * server rendered question HTML and turns it into native controls through the
 * app's question type handlers, so every question type the app supports works
 * here without this plugin knowing anything about them.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /**
     * The study session, as shown in the Moodle app.
     *
     * @param array $args Arguments from the app, carrying at least cmid.
     * @return array The app view definition.
     */
    public static function mobile_course_view(array $args): array {
        global $OUTPUT, $USER, $DB, $PAGE, $CFG;

        $args = (object)$args;
        $cmid = (int)$args->cmid;

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'rememberme');
        require_login($course, false, $cm);

        $context = \context_module::instance($cm->id);
        require_capability('mod/rememberme:view', $context);
        $PAGE->set_context($context);

        $instance = $DB->get_record('rememberme', ['id' => $cm->instance], '*', MUST_EXIST);
        $scheduler = new scheduler($instance);

        $data = [
            'cmid' => $cmid,
            'name' => format_string($instance->name),
        ] + self::progress_data($scheduler, (int)$USER->id);

        // A teacher may open the activity in the app but must not accrue
        // schedule records of their own, exactly as on the web.
        if (!has_capability('mod/rememberme:attempt', $context)) {
            $data['hasquestion'] = false;
            $data['message'] = get_string('nothingduedesc', 'rememberme');
            return self::view($data, []);
        }

        $session = new session($instance, $context);
        if (!$session->load_or_start((int)$USER->id)) {
            $data['hasquestion'] = false;
            $data['message'] = get_string('nothingduedesc', 'rememberme');
            return self::view($data, []);
        }

        $slot = $session->next_slot();
        if ($slot === null) {
            $session->finish();
            $data['hasquestion'] = false;
            $data['message'] = get_string('nothingduedesc', 'rememberme');
            return self::view($data, []);
        }

        [$html] = $session->render_slot($slot);
        $quba = $session->get_quba();
        [$answered, $total] = $session->get_progress();

        $data['hasquestion'] = true;
        $data['answered'] = $answered;
        $data['total'] = $total;
        $data['message'] = '';

        // The shape the app's core-question component expects, which is
        // CoreQuestionQuestionWSData in the app source. Its own comment notes
        // the specification came from the quiz web services only because they
        // were the first to return questions; it is not quiz specific.
        $question = [
            'slot' => (int)$slot,
            'type' => $quba->get_question($slot, false)->get_type_name(),
            'page' => 0,
            'html' => $html,
            'sequencecheck' => (int)$quba->get_question_attempt($slot)->get_sequence_check_count(),
            'flagged' => false,
        ];

        return self::view($data, [
            'question' => json_encode($question),
            'slot' => (string)$slot,
            'cmid' => (string)$cmid,
            'pausecorrect' => (string)$instance->pausecorrect,
            'pauseincorrect' => (string)$instance->pauseincorrect,
        ], file_get_contents($CFG->dirroot . '/mod/rememberme/mobile/session.js'));
    }

    /**
     * The learner's standing, shown above the question.
     *
     * @param scheduler $scheduler The scheduler.
     * @param int $userid The learner.
     * @return array Template data.
     */
    protected static function progress_data(scheduler $scheduler, int $userid): array {
        global $DB;

        $now = time();
        $weekcalc = $scheduler->get_weeks();
        $weekno = $weekcalc->week_for($now);

        $week = $DB->get_record('rememberme_weeks', [
            'rememberme' => $scheduler->get_instance_id(),
            'userid' => $userid,
            'weekno' => $weekno,
        ]);

        $fractions = $DB->get_records_menu('rememberme_weeks', [
            'rememberme' => $scheduler->get_instance_id(),
            'userid' => $userid,
        ], 'weekno ASC', 'weekno, fraction');
        $streak = weeks::streak(array_map('floatval', $fractions), $weekno);

        // The number of items a session would actually offer, which includes new
        // items under the per day cap. Counting only reviews here reported "0
        // due" to learners who had a full queue waiting.
        $due = count($scheduler->get_due_questions($userid, null, $now));

        return [
            'due' => $due,
            'weekdone' => $week ? (int)$week->completed : 0,
            'weektarget' => $week ? (int)$week->snapshottarget : 0,
            'streak' => $streak,
            'hasstreak' => $streak > 0,
        ];
    }

    /**
     * Assemble the app response.
     *
     * @param array $data Template data.
     * @param array $otherdata Values the template and JavaScript bind to.
     * @param string $javascript JavaScript for the app to run, if any.
     * @return array The app view definition.
     */
    protected static function view(array $data, array $otherdata, string $javascript = ''): array {
        global $OUTPUT;

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_rememberme/mobile_view', $data),
                ],
            ],
            'javascript' => $javascript,
            // A plain name to value map. tool_mobile_get_content turns this into
            // the name and value pairs the web service returns, so building
            // those pairs here would wrap every value in a second one.
            'otherdata' => $otherdata,
            'files' => [],
        ];
    }
}
