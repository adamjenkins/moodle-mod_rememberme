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
 * Lists all Remember Me activities in a course.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$course = get_course($id);
require_login($course);

$context = context_course::instance($course->id);

$PAGE->set_url('/mod/rememberme/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'rememberme'));

$instances = get_all_instances_in_course('rememberme', $course);

if (empty($instances)) {
    echo $OUTPUT->notification(get_string('noinstances', 'rememberme'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [get_string('name'), get_string('duetoday', 'rememberme')];
$table->align = ['left', 'left'];
$table->attributes['class'] = 'generaltable';

foreach ($instances as $instance) {
    $cm = get_coursemodule_from_instance('rememberme', $instance->id, $course->id);
    $modulecontext = context_module::instance($cm->id);

    $due = '';
    if (has_capability('mod/rememberme:attempt', $modulecontext)) {
        $record = $DB->get_record('rememberme', ['id' => $instance->id], '*', MUST_EXIST);
        $scheduler = new \mod_rememberme\local\scheduler($record);
        $due = count($scheduler->due_records((int)$USER->id, time()));
    }

    $link = html_writer::link(
        new moodle_url('/mod/rememberme/view.php', ['id' => $cm->id]),
        format_string($instance->name),
        $instance->visible ? [] : ['class' => 'dimmed']
    );

    $table->data[] = [$link, $due];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
