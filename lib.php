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
 * Library of interface functions and constants for mod_rememberme.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return whether the plugin supports a feature.
 *
 * @param string $feature Constant representing the feature.
 * @return mixed True if the feature is supported, null if unknown.
 */
function rememberme_supports(string $feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_BACKUP_MOODLE2 => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_COMPLETION_HAS_RULES => true,
        FEATURE_MODEDIT_DEFAULT_COMPLETION => true,
        FEATURE_GRADE_HAS_GRADE => true,
        FEATURE_GRADE_OUTCOMES => false,
        FEATURE_USES_QUESTIONS => true,
        FEATURE_GROUPS => false,
        FEATURE_GROUPINGS => false,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_ASSESSMENT,
        default => null,
    };
}

/**
 * Add a new instance.
 *
 * @param stdClass $data Data from the add form.
 * @param mod_rememberme_mod_form|null $mform The form itself.
 * @return int The id of the new instance.
 */
function rememberme_add_instance(stdClass $data, $mform = null): int {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    if (empty($data->coursestart)) {
        $data->coursestart = $data->timecreated;
    }

    $data->id = $DB->insert_record('rememberme', $data);

    rememberme_save_bands($data);
    rememberme_save_suspensions($data);
    rememberme_grade_item_update($data);

    return $data->id;
}

/**
 * Update an existing instance.
 *
 * @param stdClass $data Data from the edit form.
 * @param mod_rememberme_mod_form|null $mform The form itself.
 * @return bool True on success.
 */
function rememberme_update_instance(stdClass $data, $mform = null): bool {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    $DB->update_record('rememberme', $data);

    rememberme_save_bands($data);
    rememberme_save_suspensions($data);
    rememberme_grade_item_update($data);

    // Editing the suspension windows moves every future due date, so the cached
    // duedate column has to be rebuilt. The stored memory state is untouched:
    // only the denormalised column that exists to keep the hot query indexed is
    // refreshed, which is what makes windows safely editable after the fact.
    $instance = $DB->get_record('rememberme', ['id' => $data->id], '*', MUST_EXIST);
    $scheduler = new \mod_rememberme\local\scheduler($instance);
    $scheduler->refresh_cached_due_dates();

    return true;
}

/**
 * Delete an instance and all of its data.
 *
 * @param int $id The instance id.
 * @return bool True on success.
 */
function rememberme_delete_instance(int $id): bool {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/question/engine/lib.php');

    $instance = $DB->get_record('rememberme', ['id' => $id]);
    if (!$instance) {
        return false;
    }

    // Question engine usages are not ours to leave behind.
    $usageids = $DB->get_fieldset_select('rememberme_session', 'uniqueid', 'rememberme = ?', [$id]);
    foreach ($usageids as $usageid) {
        question_engine::delete_questions_usage_by_activity((int)$usageid);
    }

    $sessionids = $DB->get_fieldset_select('rememberme_session', 'id', 'rememberme = ?', [$id]);
    if (!empty($sessionids)) {
        [$insql, $params] = $DB->get_in_or_equal($sessionids);
        $DB->delete_records_select('rememberme_slot', "sessionid {$insql}", $params);
    }

    foreach (
        ['rememberme_session', 'rememberme_weeks', 'rememberme_bandstate', 'rememberme_bands',
              'rememberme_suspensions', 'rememberme_review_log', 'rememberme_schedule'] as $table
    ) {
        $DB->delete_records($table, ['rememberme' => $id]);
    }

    $DB->delete_records('rememberme', ['id' => $id]);

    grade_update('mod/rememberme', $instance->course, 'mod', 'rememberme', $id, 0, null, ['deleted' => 1]);

    return true;
}

/**
 * Persist the ordered bands submitted by the edit form.
 *
 * @param stdClass $data Form data.
 */
function rememberme_save_bands(stdClass $data): void {
    global $DB;

    if (!isset($data->bandcategory)) {
        return;
    }

    $DB->delete_records('rememberme_bands', ['rememberme' => $data->id]);

    // A band is a set of categories sharing a band number. Bands are numbered
    // as they are saved, skipping any the teacher left empty, so deleting the
    // middle band closes the gap rather than leaving a level with nothing in it.
    $bandnumber = 1;
    foreach ((array)$data->bandcategory as $index => $categoryids) {
        $categoryids = array_values(array_unique(array_filter(array_map('intval', (array)$categoryids))));
        if (empty($categoryids)) {
            continue;
        }

        $sortorder = 0;
        foreach ($categoryids as $categoryid) {
            $DB->insert_record('rememberme_bands', (object)[
                'rememberme' => $data->id,
                'bandnumber' => $bandnumber,
                'sortorder' => $sortorder,
                'questioncategoryid' => $categoryid,
                'includesubcategories' => empty($data->bandsubcategories[$index]) ? 0 : 1,
            ]);
            $sortorder++;
        }
        $bandnumber++;
    }
}

/**
 * Persist the suspension windows submitted by the edit form.
 *
 * @param stdClass $data Form data.
 */
function rememberme_save_suspensions(stdClass $data): void {
    global $DB;

    if (!isset($data->suspensionstart)) {
        return;
    }

    $DB->delete_records('rememberme_suspensions', ['rememberme' => $data->id]);

    foreach ((array)$data->suspensionstart as $index => $start) {
        $start = (int)$start;
        $end = (int)($data->suspensionend[$index] ?? 0);
        if ($start <= 0 || $end <= $start) {
            continue;
        }
        $DB->insert_record('rememberme_suspensions', (object)[
            'rememberme' => $data->id,
            'name' => clean_param((string)($data->suspensionname[$index] ?? ''), PARAM_TEXT),
            'timestart' => $start,
            'timeend' => $end,
        ]);
    }
}

/**
 * Create or update the grade item for an instance.
 *
 * @param stdClass $instance The instance record.
 * @param mixed $grades Grades to push, or null.
 * @return int A GRADE_UPDATE_* constant.
 */
function rememberme_grade_item_update(stdClass $instance, $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $item = [
        'itemname' => clean_param($instance->name, PARAM_NOTAGS),
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => $instance->grade,
        'grademin' => 0,
    ];

    if ($instance->grade == 0) {
        $item['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $item['reset'] = true;
        $grades = null;
    }

    return grade_update(
        'mod/rememberme',
        $instance->course,
        'mod',
        'rememberme',
        $instance->id,
        0,
        $grades,
        $item
    );
}

/**
 * Push grades for one or all users of an instance.
 *
 * The grade is schedule adherence, never accuracy: no fraction earned on an
 * individual question reaches the gradebook, because grading accuracy would
 * contaminate the correctness signal the scheduler depends on.
 *
 * @param stdClass $instance The instance record.
 * @param int $userid A single user, or 0 for all.
 * @param bool $nullifnone Whether to push a null grade for users with no data.
 */
function rememberme_update_grades(stdClass $instance, int $userid = 0, bool $nullifnone = true): void {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if ($instance->grade == 0) {
        rememberme_grade_item_update($instance);
        return;
    }

    $scheduler = new \mod_rememberme\local\scheduler($instance);

    if ($userid) {
        $userids = [$userid];
    } else {
        $userids = $DB->get_fieldset_sql(
            'SELECT DISTINCT userid FROM {rememberme_weeks} WHERE rememberme = ?',
            [$instance->id]
        );
    }

    $grades = [];
    foreach ($userids as $uid) {
        $uid = (int)$uid;
        $result = $scheduler->final_grade($uid);
        $grades[$uid] = (object)[
            'userid' => $uid,
            'rawgrade' => $result['proportion'] * $instance->grade,
        ];
    }

    if (empty($grades) && $userid && $nullifnone) {
        $grades[$userid] = (object)['userid' => $userid, 'rawgrade' => null];
    }

    if (!empty($grades)) {
        rememberme_grade_item_update($instance, $grades);
    } else {
        rememberme_grade_item_update($instance);
    }
}

/**
 * Whether any instance uses a given scale.
 *
 * This activity grades on a numeric value only, so no scale is ever in use.
 *
 * @param int $courseid The course, or 0 for any.
 * @param int $scaleid The scale.
 * @return bool Always false.
 */
function rememberme_scale_used_anywhere(int $courseid, int $scaleid): bool {
    return false;
}

/**
 * Add the reset options to the course reset form.
 *
 * @param MoodleQuickForm $mform The reset form.
 */
function rememberme_reset_course_form_definition($mform): void {
    $mform->addElement('header', 'remembermeheader', get_string('modulenameplural', 'rememberme'));
    $mform->addElement('advcheckbox', 'reset_rememberme_all', get_string('resetall', 'rememberme'));
}

/**
 * Default reset options.
 *
 * @param stdClass $course The course.
 * @return array Defaults.
 */
function rememberme_reset_course_form_defaults($course): array {
    return ['reset_rememberme_all' => 1];
}

/**
 * Reset user data for a course.
 *
 * @param stdClass $data Reset form data.
 * @return array Status report rows.
 */
function rememberme_reset_userdata($data): array {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/question/engine/lib.php');

    $status = [];
    $componentstr = get_string('modulenameplural', 'rememberme');

    if (empty($data->reset_rememberme_all)) {
        return $status;
    }

    $instances = $DB->get_records('rememberme', ['course' => $data->courseid]);
    foreach ($instances as $instance) {
        $usageids = $DB->get_fieldset_select('rememberme_session', 'uniqueid', 'rememberme = ?', [$instance->id]);
        foreach ($usageids as $usageid) {
            question_engine::delete_questions_usage_by_activity((int)$usageid);
        }

        $sessionids = $DB->get_fieldset_select('rememberme_session', 'id', 'rememberme = ?', [$instance->id]);
        if (!empty($sessionids)) {
            [$insql, $params] = $DB->get_in_or_equal($sessionids);
            $DB->delete_records_select('rememberme_slot', "sessionid {$insql}", $params);
        }

        foreach (
            ['rememberme_session', 'rememberme_weeks', 'rememberme_bandstate',
                  'rememberme_review_log', 'rememberme_schedule'] as $table
        ) {
            $DB->delete_records($table, ['rememberme' => $instance->id]);
        }

        // Reset the gradebook too, or learners keep a grade for data that is gone.
        if (empty($data->reset_gradebook_grades)) {
            rememberme_grade_item_update($instance, 'reset');
        }
    }

    $status[] = [
        'component' => $componentstr,
        'item' => get_string('resetall', 'rememberme'),
        'error' => false,
    ];

    return $status;
}

/**
 * Provide course module information, including the custom completion rules.
 *
 * Custom completion rules are inert unless their values are pushed into the
 * course module's cached custom data here, so this is not optional decoration.
 *
 * @param stdClass $coursemodule The course module.
 * @return cached_cm_info|false The info, or false if the instance is missing.
 */
function rememberme_get_coursemodule_info($coursemodule) {
    global $DB;

    $fields = 'id, name, intro, introformat, completionweeks';
    $instance = $DB->get_record('rememberme', ['id' => $coursemodule->instance], $fields);
    if (!$instance) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $instance->name;

    if ($coursemodule->showdescription) {
        $result->content = format_module_intro('rememberme', $instance, $coursemodule->id, false);
    }

    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionweeks'] = $instance->completionweeks;
    }

    return $result;
}

/**
 * Serve files embedded in the questions this activity asks.
 *
 * The signature is fixed by core, which calls this with nine arguments from
 * question_pluginfile() in lib/questionlib.php. Declaring fewer silently
 * misaligns every argument after the first, so it must match exactly.
 *
 * @param stdClass $course The course.
 * @param context $context The context the file lives in.
 * @param string $component The component owning the file.
 * @param string $filearea The file area.
 * @param int $qubaid The question usage the file is being viewed through.
 * @param int $slot The slot within that usage.
 * @param array $args Remaining path arguments.
 * @param bool $forcedownload Whether to force download.
 * @param array $options Serving options.
 */
function rememberme_question_pluginfile(
    $course,
    $context,
    $component,
    $filearea,
    $qubaid,
    $slot,
    $args,
    $forcedownload,
    array $options = []
) {
    global $DB, $USER, $CFG;

    require_once($CFG->dirroot . '/question/engine/lib.php');

    // Resolve the usage back to the session that owns it. A usage id arrives
    // from the URL, so nothing about it may be taken on trust.
    $session = $DB->get_record('rememberme_session', ['uniqueid' => (int)$qubaid]);
    if (!$session) {
        send_file_not_found();
    }

    $cm = get_coursemodule_from_instance('rememberme', $session->rememberme, 0, false, MUST_EXIST);
    require_login($course, false, $cm);

    $modulecontext = context_module::instance($cm->id);

    // A learner may see the files in their own session; anybody else needs the
    // report capability. Without this check any participant could read another
    // learner's question media by guessing a usage id.
    if ((int)$session->userid === (int)$USER->id) {
        require_capability('mod/rememberme:attempt', $modulecontext);
    } else {
        require_capability('mod/rememberme:viewreports', $modulecontext);
    }

    // Let the question engine decide whether this file is reachable from that
    // slot at all, rather than trusting the path.
    $quba = question_engine::load_questions_usage_by_activity((int)$qubaid);
    if ($quba->get_owning_component() !== 'mod_rememberme') {
        send_file_not_found();
    }

    $displayoptions = new question_display_options();
    $displayoptions->feedback = question_display_options::VISIBLE;
    $displayoptions->generalfeedback = question_display_options::VISIBLE;
    $displayoptions->rightanswer = question_display_options::VISIBLE;

    if (!$quba->check_file_access((int)$slot, $displayoptions, $component, $filearea, $args, $forcedownload)) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/{$context->id}/{$component}/{$filearea}/{$relativepath}";
    $file = $fs->get_file_by_hash(sha1($fullpath));
    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Serve plugin files.
 *
 * @param stdClass $course The course.
 * @param stdClass $cm The course module.
 * @param context $context The context.
 * @param string $filearea The file area.
 * @param array $args Remaining path arguments.
 * @param bool $forcedownload Whether to force download.
 * @param array $options Serving options.
 * @return bool False if the file was not found.
 */
function rememberme_pluginfile($course, $cm, $context, $filearea, array $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, false, $cm);

    if ($filearea !== 'intro') {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/{$context->id}/mod_rememberme/{$filearea}/0/{$relativepath}";
    $file = $fs->get_file_by_hash(sha1($fullpath));
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, null, 0, $forcedownload, $options);
}
