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
 * Defines the restore task for mod_rememberme.
 *
 * @package    mod_rememberme
 * @category   backup
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/rememberme/backup/moodle2/restore_rememberme_stepslib.php');

/**
 * Restore task providing the settings and steps to restore one rememberme activity.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_rememberme_activity_task extends restore_activity_task {
    /**
     * No particular settings for this activity.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Defines the restore steps for this activity.
     *
     * Only one structure step is needed. The question bank entries, question
     * versions and question categories this activity refers to are created and
     * mapped by the course level restore_create_categories_and_questions step,
     * which the root task adds before any activity task runs
     * (backup/moodle2/restore_root_task.class.php:94).
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_rememberme_activity_structure_step('rememberme_structure', 'rememberme.xml'));
    }

    /**
     * Defines the contents in the activity that must be processed by the link decoder.
     *
     * @return array Array of restore_decode_content objects.
     */
    public static function define_decode_contents() {
        $contents = [];

        $contents[] = new restore_decode_content('rememberme', ['intro'], 'rememberme');

        return $contents;
    }

    /**
     * Defines the decoding rules for links belonging to the activity.
     *
     * The token names must match the placeholders emitted by
     * backup_rememberme_activity_task::encode_content_links().
     *
     * @return array Array of restore_decode_rule objects.
     */
    public static function define_decode_rules() {
        $rules = [];

        $rules[] = new restore_decode_rule('REMEMBERMEVIEWBYID', '/mod/rememberme/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('REMEMBERMEINDEX', '/mod/rememberme/index.php?id=$1', 'course');

        return $rules;
    }

    /**
     * Defines the restore log rules applied when restoring rememberme logs.
     *
     * This plugin has always used the events API rather than the legacy log,
     * so there are no legacy actions to rewrite. The method is still declared
     * because restore_activity_task::define_restore_log_rules()
     * (backup/moodle2/restore_activity_task.class.php:290) expects an array.
     *
     * @return array Array of restore_log_rule objects.
     */
    public static function define_restore_log_rules() {
        return [];
    }

    /**
     * Defines the restore log rules applied when restoring course logs.
     *
     * Not declared on the base class; it is discovered reflectively by
     * restore_logs_processor (backup/util/helper/restore_logs_processor.class.php:122)
     * and must return an array. Empty for the same reason as above.
     *
     * @return array Array of restore_log_rule objects.
     */
    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
