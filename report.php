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

/**
 * Teacher facing reports for a Remember Me activity.
 *
 * Four views, selected by the mode parameter. They answer four different
 * questions and are deliberately not merged into one table: which of my
 * questions are broken, who is actually retaining anything, who has been
 * carried forward by the backstop, and who has stopped turning up.
 *
 * There is no cohort leaderboard here, and there will not be one. Because the
 * due queue is capped and driven by the learner's own memory state, volume of
 * questions answered ranks learners roughly inversely to how well they know the
 * material: the learner with the most reviews is the one with the most lapses.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/rememberme/lib.php');

use core\output\single_button;
use core\output\tabobject;
use mod_rememberme\output\report_renderer_helper;

$id = required_param('id', PARAM_INT);
$mode = optional_param('mode', report_renderer_helper::MODE_DIFFICULTY, PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'rememberme');
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/rememberme:viewreports', $context);

if (!report_renderer_helper::is_valid_mode($mode)) {
    // An unknown mode is a stale bookmark or a typed URL, not an attack worth
    // an exception. Fall back to the default view.
    $mode = report_renderer_helper::MODE_DIFFICULTY;
}

$instance = $DB->get_record('rememberme', ['id' => $cm->instance], '*', MUST_EXIST);

$url = new moodle_url('/mod/rememberme/report.php', ['id' => $cm->id, 'mode' => $mode]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(format_string($instance->name) . ': ' . get_string('reports', 'rememberme'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_activity_record($instance);
$PAGE->activityheader->set_attrs(['hidecompletion' => true, 'description' => '']);

// Rescoring every learner's weeks and pushing their grades. Scores and grades
// already follow every answer, every change to the term and a daily task, so
// this is for a teacher who wants them brought up to date now: after changing
// study days, say, or when a grade looks wrong. A write, so it takes a POST with
// a valid sesskey and its own capability, checked here where it happens.
if ($action === 'recalculate' && data_submitted() && confirm_sesskey()) {
    require_capability('mod/rememberme:recalculategrades', $context);
    core_php_time_limit::raise();
    [$rescored, $learners] = \mod_rememberme\task\recalculate_weeks::recalculate($instance);
    redirect(
        $url,
        get_string('recalculategradesdone', 'rememberme', ['weeks' => $rescored, 'learners' => $learners]),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Group mode is honoured so that a teacher confined to separate groups does not
// see the retention and completion of learners they are not responsible for.
$groupid = 0;
$groupmode = groups_get_activity_groupmode($cm, $course);
if ($groupmode) {
    $groupid = (int)groups_get_activity_group($cm, true);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reports', 'rememberme'));

if (has_capability('mod/rememberme:recalculategrades', $context)) {
    $button = new single_button(
        new moodle_url($url, ['action' => 'recalculate']),
        get_string('recalculategrades', 'rememberme'),
        'post',
        single_button::BUTTON_SECONDARY
    );
    $button->add_confirm_action(get_string('recalculategradesconfirm', 'rememberme'));
    echo html_writer::div($OUTPUT->render($button), 'mb-3');
}

if ($groupmode) {
    echo html_writer::div(
        groups_print_activity_menu($cm, $url, true),
        'mb-2'
    );
}

$tabs = [];
foreach (report_renderer_helper::modes() as $tabmode) {
    $taburl = new moodle_url('/mod/rememberme/report.php', ['id' => $cm->id, 'mode' => $tabmode]);
    $tabs[] = new tabobject($tabmode, $taburl, get_string('report' . $tabmode, 'rememberme'));
}
echo $OUTPUT->tabtree($tabs, $mode);

$helper = new report_renderer_helper($instance, $context, $groupid);

switch ($mode) {
    case report_renderer_helper::MODE_COVERAGE:
        $templatecontext = $helper->coverage_context();
        break;
    case report_renderer_helper::MODE_BANDS:
        $templatecontext = $helper->bands_context();
        break;
    case report_renderer_helper::MODE_WEEKS:
        $templatecontext = $helper->weeks_context();
        break;
    default:
        $templatecontext = $helper->difficulty_context();
        break;
}

echo $OUTPUT->render_from_template(report_renderer_helper::template_for($mode), $templatecontext);
$PAGE->requires->js_call_amd('mod_rememberme/sortable_table', 'init');

echo $OUTPUT->footer();
