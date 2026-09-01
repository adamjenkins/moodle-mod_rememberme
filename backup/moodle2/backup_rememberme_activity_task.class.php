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
 * Defines the backup task for mod_rememberme.
 *
 * @package    mod_rememberme
 * @category   backup
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The stepslib is not autoloaded: these are pre-PSR-4 global classes, so the
// task file must require it, exactly as mod/choice does
// (mod/choice/backup/moodle2/backup_choice_activity_task.class.php:29).
require_once($CFG->dirroot . '/mod/rememberme/backup/moodle2/backup_rememberme_stepslib.php');

/**
 * Provides the steps to perform one complete backup of a rememberme instance.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_rememberme_activity_task extends backup_activity_task {
    /**
     * No activity specific settings; the inherited userinfo setting is enough.
     *
     * The 'userinfo' setting the structure step reads is created for us by
     * backup_activity_task::add_activity_userinfo_setting(), verified at
     * backup/moodle2/backup_activity_task.class.php:355.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Defines the backup steps for this activity.
     *
     * The two question bank steps are mandatory for any activity that references
     * the question bank. Core says so verbatim in
     * mod/quiz/backup/moodle2/backup_quiz_activity_task.class.php:52-54
     * ("Following steps must be present in all the activities using question
     * banks (only quiz for now)"). backup_calculate_question_categories turns the
     * 'question_bank_entry' annotations our structure step makes into the
     * 'question_category' / 'question_category_complete' / 'question_category_partial'
     * annotations that questions.xml is built from
     * (backup/util/dbops/backup_question_dbops.class.php:40-85), and
     * backup_delete_temp_questions then clears the now spent 'question'
     * annotations (same file, :108-111).
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new backup_rememberme_activity_structure_step('rememberme_structure', 'rememberme.xml'));

        // Process all the annotated question bank entries to calculate the
        // question categories that need to be included in the backup.
        $this->add_step(new backup_calculate_question_categories('activity_question_categories'));

        // Clean backup_ids_temp of questions; they have already been used to
        // detect the question categories and are not needed any more.
        $this->add_step(new backup_delete_temp_questions('clean_temp_questions'));
    }

    /**
     * Encodes URLs to the index.php and view.php scripts.
     *
     * @param string $content some HTML text that eventually contains URLs to the activity instance scripts.
     * @return string the content with the URLs encoded.
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to the list of rememberme activities in a course.
        $search = '/(' . $base . '\/mod\/rememberme\/index\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@REMEMBERMEINDEX*$2@$', $content);

        // Link to a rememberme instance by course module id.
        $search = '/(' . $base . '\/mod\/rememberme\/view\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@REMEMBERMEVIEWBYID*$2@$', $content);

        return $content;
    }
}
