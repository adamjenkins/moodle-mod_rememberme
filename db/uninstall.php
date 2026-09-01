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
 * Uninstall cleanup for mod_rememberme.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove the data this plugin wrote outside its own tables.
 *
 * uninstall_plugin() drops the tables named in db/install.xml, but it never
 * calls rememberme_delete_instance(), so anything this plugin wrote into a core
 * table would simply be orphaned. Two things qualify:
 *
 * - Question engine usages. Every study session owns a question_usages row and
 *   a tree of question_attempts, question_attempt_steps and step data beneath
 *   it. On a site that has been running a term, that is the bulk of the data
 *   this plugin created, and none of it is in our tables.
 * - Gradebook items. grade_items and grade_grades rows for this module survive
 *   the uninstall and would show up as orphaned columns in course gradebooks.
 *
 * This runs before the tables are dropped, so our own tables can still be read
 * to find what to clean.
 *
 * @return bool True on success.
 */
function xmldb_rememberme_uninstall() {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/question/engine/lib.php');
    require_once($CFG->libdir . '/gradelib.php');

    // Delete the question engine usages, in batches: a long running site can
    // have a great many, and each one cascades into several attempt tables.
    $usageids = $DB->get_fieldset_select('rememberme_session', 'uniqueid', 'uniqueid > 0');
    foreach (array_chunk($usageids, 100) as $chunk) {
        foreach ($chunk as $usageid) {
            question_engine::delete_questions_usage_by_activity((int)$usageid);
        }
    }

    // Remove the gradebook items for every instance, so no course is left with
    // a grade column belonging to a module that no longer exists.
    $instances = $DB->get_records('rememberme', null, '', 'id, course');
    foreach ($instances as $instance) {
        grade_update(
            'mod/rememberme',
            $instance->course,
            'mod',
            'rememberme',
            $instance->id,
            0,
            null,
            ['deleted' => 1]
        );
    }

    return true;
}
