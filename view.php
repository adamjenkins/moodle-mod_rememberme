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
 * The study session. Opening the activity is the session.
 *
 * There is deliberately no landing page and no start button: the friction of an
 * intermediate screen is disproportionate for an activity intended to be
 * visited briefly and often, and a weekly habit tool cannot afford a two click
 * warm up.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/rememberme/lib.php');

use mod_rememberme\local\scheduler;

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'rememberme');
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/rememberme:view', $context);

$instance = $DB->get_record('rememberme', ['id' => $cm->instance], '*', MUST_EXIST);

$PAGE->set_url('/mod/rememberme/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_activity_record($instance);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

echo $OUTPUT->header();

// The activity header already renders the description, so it is deliberately
// not repeated here.
$scheduler = new scheduler($instance);

if (has_capability('mod/rememberme:viewreports', $context)) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/mod/rememberme/report.php', ['id' => $cm->id]),
            get_string('viewreports', 'rememberme'),
            ['class' => 'btn btn-secondary']
        ),
        'mb-3'
    );
}

if (!has_capability('mod/rememberme:attempt', $context)) {
    // A teacher previewing the activity must not silently accrue schedule
    // records of their own, so answering is a separate capability from viewing.
    echo $OUTPUT->notification(get_string('nothingduedesc', 'rememberme'), 'info');
    echo $OUTPUT->footer();
    exit;
}

if ($scheduler->get_pool()->get_band_count() === 0) {
    echo $OUTPUT->notification(get_string('errornoquestions', 'rememberme'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

$now = time();
$weeks = $scheduler->get_weeks();
$weekno = $weeks->week_for($now);

// Freeze this week's target now if it has not been already, so the learner sees
// an honest figure the moment they arrive rather than "0 of 0" until they answer
// something. The target is fixed once and never grows during the week.
$weekrecord = $scheduler->ensure_week_snapshot((int)$USER->id, $weekno, $now);

$fractions = $DB->get_records_menu('rememberme_weeks', [
    'rememberme' => $instance->id,
    'userid' => $USER->id,
], 'weekno ASC', 'weekno, fraction');
$streak = \mod_rememberme\local\weeks::streak(array_map('floatval', $fractions), $weekno);

echo $OUTPUT->render_from_template('mod_rememberme/session', [
    'cmid' => $cm->id,
    'audio' => !empty($instance->audiocue),
    'weeklabel' => get_string('progressthisweek', 'rememberme', [
        'done' => $weekrecord ? (int)$weekrecord->completed : 0,
        'target' => $weekrecord ? (int)$weekrecord->snapshottarget : 0,
    ]),
    'hasstreak' => $streak > 0,
    'streaklabel' => get_string('streakweeks', 'rememberme', $streak),
    'loading' => get_string('loading', 'rememberme'),
]);

$PAGE->requires->js_call_amd('mod_rememberme/session', 'init');

echo $OUTPUT->footer();
