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
 * Defines the restore structure step used by restore_rememberme_activity_task.
 *
 * @package    mod_rememberme
 * @category   backup
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step to restore one rememberme activity.
 *
 * The base class is restore_questions_activity_structure_step
 * (backup/moodle2/restore_stepslib.php:6650) because this activity owns question
 * engine usages; that class supplies add_question_usages() and the
 * process_question_usage / process_question_attempt / process_question_attempt_step
 * handlers, and requires inform_new_usage_id() to be implemented
 * (backup/moodle2/restore_stepslib.php:6367).
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_rememberme_activity_structure_step extends restore_questions_activity_structure_step {
    /**
     * The session record currently being restored, held until its usage id is known.
     *
     * @var stdClass|null
     */
    protected $currentsession = null;

    /**
     * The id of the session row most recently written by inform_new_usage_id().
     *
     * get_new_parentid('rememberme_session') cannot be used for the child slot rows:
     * it returns the last id ever mapped under that name, so when a session is skipped
     * its slots would silently attach to the previous session instead. This is reset
     * for every session and only set when the row is actually written.
     *
     * @var int|null
     */
    protected $currentsessionid = null;

    /**
     * Defines the paths to be restored from rememberme.xml.
     *
     * Note the asymmetry with the backup side: there the learner elements are
     * always declared and only their source is gated, here the path element itself
     * is added conditionally. That is the documented shape
     * (mod/choice/backup/moodle2/restore_choice_stepslib.php:41-43).
     *
     * @return array The paths wrapped into the standard activity structure.
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('rememberme', '/activity/rememberme');
        $paths[] = new restore_path_element('rememberme_band', '/activity/rememberme/bands/band');
        $paths[] = new restore_path_element(
            'rememberme_suspension',
            '/activity/rememberme/suspensions/suspension'
        );

        if ($userinfo) {
            $paths[] = new restore_path_element(
                'rememberme_schedule',
                '/activity/rememberme/schedules/schedule'
            );
            $paths[] = new restore_path_element(
                'rememberme_reviewlog',
                '/activity/rememberme/reviewlogs/reviewlog'
            );
            $paths[] = new restore_path_element(
                'rememberme_bandstate',
                '/activity/rememberme/bandstates/bandstate'
            );
            $paths[] = new restore_path_element(
                'rememberme_week',
                '/activity/rememberme/weeks/week'
            );

            $session = new restore_path_element(
                'rememberme_session',
                '/activity/rememberme/sessions/session'
            );
            $paths[] = $session;

            // Adds the question_usage / question_attempt / question_attempt_step /
            // question_attempt_step_data paths below the session element. Same call
            // mod_quiz makes at mod/quiz/backup/moodle2/restore_quiz_stepslib.php:85.
            $this->add_question_usages($session, $paths);

            $paths[] = new restore_path_element(
                'rememberme_slot',
                '/activity/rememberme/sessions/session/sessionslots/sessionslot'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restores the activity instance.
     *
     * @param array|stdClass $data The raw element data.
     * @return void
     */
    protected function process_rememberme($data) {
        global $DB;

        $data = (object)$data;
        $data->course = $this->get_courseid();

        // A .mbz is attacker input: it may have been hand edited, or produced by
        // another site entirely. The interactive form cleans these fields, so the
        // restore path has to clean them identically or it becomes the one way to
        // get unclean values into the table.
        $data->name = clean_param($data->name ?? '', PARAM_TEXT);

        // The coursestart field anchors every week boundary in this activity, so it rolls
        // with the course start date. Any change to the list of rolled dates must be made
        // identically in course reset. See MDL-9367.
        $data->coursestart = $this->apply_date_offset($data->coursestart);

        $newitemid = $DB->insert_record('rememberme', $data);

        // Immediately after inserting the activity record, call this.
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restores one band (an ordered question category bound to the instance).
     *
     * @param array|stdClass $data The raw element data.
     * @return void
     */
    protected function process_rememberme_band($data) {
        global $DB;

        $data = (object)$data;
        $data->rememberme = $this->get_new_parentid('rememberme');

        // The 'question_category' mapping is created by the course level
        // restore_create_categories_and_questions step, which runs before this one
        // (backup/moodle2/restore_stepslib.php:5179 and :5232, added at
        // backup/moodle2/restore_root_task.class.php:94). When the category was not
        // part of the backup we keep the old id rather than guessing, which is what
        // core does for an unmapped bank entry
        // (backup/moodle2/restore_stepslib.php:6555-6557).
        $oldcategoryid = $data->questioncategoryid;
        $newcategoryid = $this->get_mappingid('question_category', $oldcategoryid);
        if ($newcategoryid === false) {
            $this->log(
                'No question category mapping for category ' . $oldcategoryid .
                '; the rememberme band keeps the original id and may point outside this site.',
                backup::LOG_WARNING
            );
        } else {
            $data->questioncategoryid = $newcategoryid;
        }

        $DB->insert_record('rememberme_bands', $data);
        // No mapping saved: nothing references a band by id. rememberme_bandstate
        // and rememberme_schedule both refer to bands by their integer bandlevel.
    }

    /**
     * Restores one suspension window.
     *
     * @param array|stdClass $data The raw element data.
     * @return void
     */
    protected function process_rememberme_suspension($data) {
        global $DB;

        $data = (object)$data;
        $data->rememberme = $this->get_new_parentid('rememberme');

        // Cleaned exactly as rememberme_save_suspensions() cleans the form value.
        $data->name = clean_param($data->name ?? '', PARAM_TEXT);

        // Teacher configured windows roll with the course start date, as coursestart does.
        $data->timestart = $this->apply_date_offset($data->timestart);
        $data->timeend = $this->apply_date_offset($data->timeend);

        $DB->insert_record('rememberme_suspensions', $data);
    }

    /**
     * Restores one learner's memory state for one question bank entry.
     *
     * @param array|stdClass $data The raw element data.
     * @return void
     */
    protected function process_rememberme_schedule($data) {
        global $DB;

        $data = (object)$data;
        $data->rememberme = $this->get_new_parentid('rememberme');

        if (!$userid = $this->get_mappingid('user', $data->userid)) {
            // Memory state without its learner is meaningless, and leaving userid at
            // 0 would collide on the unique (rememberme, userid, questionbankentryid)
            // index as soon as a second unmapped learner arrived, aborting the whole
            // restore. Skipping is the same call mod_quiz makes for an attempt whose
            // user did not restore (restore_quiz_stepslib.php:594-600).
            $this->log('No user mapping for user ' . $data->userid .
                '; skipping rememberme schedule row.', backup::LOG_INFO);
            return;
        }
        $data->userid = $userid;

        $data->questionbankentryid = $this->map_question_bank_entry_id($data->questionbankentryid);

        // Two distinct backed up bank entries can map onto one entry on this site
        // (core matches questions by a content identity hash, so duplicates collapse
        // — restore_dbops::prechek_precheck_qbanks_by_level, :725-781). That would
        // break the unique (rememberme, userid, questionbankentryid) index, so the
        // first row wins and later collisions are dropped.
        $existing = $DB->record_exists('rememberme_schedule', [
            'rememberme' => $data->rememberme,
            'userid' => $data->userid,
            'questionbankentryid' => $data->questionbankentryid,
        ]);
        if ($existing) {
            $this->log('Duplicate schedule row for question bank entry ' .
                $data->questionbankentryid . ' after mapping; keeping the first.', backup::LOG_INFO);
            return;
        }

        // The scheduling clock moves with the course. lastreviewed and duedate are two
        // halves of one statement (duedate is a cache derived from lastreviewed and
        // stability), so they must be offset together or not at all.
        $data->lastreviewed = $this->apply_date_offset($data->lastreviewed);
        $data->duedate = $this->apply_date_offset($data->duedate);

        // The lifecycle state is an enum in everything but the column type, and it
        // steers later scheduling decisions. A value from outside the set could only
        // come from a hand edited backup, so fall back rather than store it.
        $data->state = self::clean_schedule_state($data->state ?? '');

        $DB->insert_record('rememberme_schedule', $data);
        // No mapping saved: nothing references a schedule row by id.
    }

    /**
     * Restores one immutable review log row.
     *
     * @param array|stdClass $data The raw element data.
     * @return void
     */
    protected function process_rememberme_reviewlog($data) {
        global $DB;

        $data = (object)$data;
        $data->rememberme = $this->get_new_parentid('rememberme');

        if (!$userid = $this->get_mappingid('user', $data->userid)) {
            $this->log('No user mapping for user ' . $data->userid .
                '; skipping rememberme review log row.', backup::LOG_INFO);
            return;
        }
        $data->userid = $userid;

        $data->questionbankentryid = $this->map_question_bank_entry_id($data->questionbankentryid);
        $data->questionid = $this->map_question_id($data->questionid);

        // The weekno field is relative to coursestart, which was offset by the same delta,
        // so it stays correct. The timecreated field is offset for the same reason
        // lastreviewed is.
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        $DB->insert_record('rememberme_review_log', $data);
    }

    /**
     * Restores one learner's band progress.
     *
     * @param array|stdClass $data The raw element data.
     * @return void
     */
    protected function process_rememberme_bandstate($data) {
        global $DB;

        $data = (object)$data;
        $data->rememberme = $this->get_new_parentid('rememberme');

        if (!$userid = $this->get_mappingid('user', $data->userid)) {
            $this->log('No user mapping for user ' . $data->userid .
                '; skipping rememberme band state row.', backup::LOG_INFO);
            return;
        }
        $data->userid = $userid;

        $data->firstsession = $this->apply_date_offset($data->firstsession);
        $data->bandsince = $this->apply_date_offset($data->bandsince);
        $data->lastunlockwindow = $this->apply_date_offset($data->lastunlockwindow);

        // The unlock reason is turned into a language string key when the band
        // progression report renders it, so an arbitrary restored value would drive
        // a lookup for a string that does not exist.
        $data->reason = self::clean_band_reason($data->reason ?? '');

        $DB->insert_record('rememberme_bandstate', $data);
    }

    /**
     * Restores one learner's weekly completion record.
     *
     * @param array|stdClass $data The raw element data.
     * @return void
     */
    protected function process_rememberme_week($data) {
        global $DB;

        $data = (object)$data;
        $data->rememberme = $this->get_new_parentid('rememberme');

        if (!$userid = $this->get_mappingid('user', $data->userid)) {
            $this->log('No user mapping for user ' . $data->userid .
                '; skipping rememberme week row.', backup::LOG_INFO);
            return;
        }
        $data->userid = $userid;

        // The weekno field is an offset from coursestart and needs no date arithmetic.
        $DB->insert_record('rememberme_weeks', $data);
    }

    /**
     * Holds one study session until its question usage has been created.
     *
     * The row is not inserted here. The question engine usage is a child element, so
     * it is processed after this method returns, and only then is the new uniqueid
     * known; inform_new_usage_id() does the insert. This is the same two-stage shape
     * mod_quiz uses (restore_quiz_stepslib.php:623-624 and :637-652).
     *
     * @param array|stdClass $data The raw element data.
     * @return void
     */
    protected function process_rememberme_session($data) {
        $data = (object)$data;
        $data->rememberme = $this->get_new_parentid('rememberme');

        $this->currentsessionid = null;

        $olduserid = $data->userid;
        $data->userid = $this->get_mappingid('user', $olduserid, 0);
        if ($data->userid === 0) {
            $this->log('No user mapping for user ' . $olduserid .
                '; skipping rememberme session.', backup::LOG_INFO);
            $this->currentsession = null;
            return;
        }

        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->timefinished = $this->apply_date_offset($data->timefinished);

        $this->currentsession = $data;
    }

    /**
     * Writes the session row once the question engine has created its usage.
     *
     * Called from restore_question_usage_worker() immediately after the new
     * question_usages row is inserted (backup/moodle2/restore_stepslib.php:6356).
     *
     * @param int $newusageid The id of the newly created question usage.
     * @return void
     */
    protected function inform_new_usage_id($newusageid) {
        global $DB;

        $data = $this->currentsession;
        if ($data === null) {
            // The session was skipped above. Core has already inserted the usage row;
            // mod_quiz leaves the same orphan in this situation, so we do too rather
            // than deleting a usage whose attempts are still about to be restored.
            return;
        }
        $this->currentsession = null;

        $oldid = $data->id;
        $data->uniqueid = $newusageid;

        $newitemid = $DB->insert_record('rememberme_session', $data);
        $this->currentsessionid = $newitemid;

        // The mapping is saved under the path element name 'rememberme_session',
        // so that anything later needing the old-to-new session id can find it
        // (restore_structure_step.class.php:183-186).
        $this->set_mapping('rememberme_session', $oldid, $newitemid, false);
    }

    /**
     * Restores one slot of a study session.
     *
     * @param array|stdClass $data The raw element data.
     * @return void
     */
    protected function process_rememberme_slot($data) {
        global $DB;

        $data = (object)$data;

        if ($this->currentsessionid === null) {
            // Its session was skipped, so the slot has nothing to hang from.
            return;
        }
        $data->sessionid = $this->currentsessionid;

        $data->questionbankentryid = $this->map_question_bank_entry_id($data->questionbankentryid);
        $data->questionid = $this->map_question_id($data->questionid);
        $data->timeshown = $this->apply_date_offset($data->timeshown);

        $DB->insert_record('rememberme_slot', $data);
    }

    /**
     * Maps a backed up question bank entry id onto this site.
     *
     * 'question_bank_entry' is the itemname an activity maps against; the mapping is
     * created by the course level restore_create_categories_and_questions step
     * (backup/moodle2/restore_stepslib.php:5382 on the create branch, :5474 on the
     * reuse branch). When there is no mapping the original id is kept, matching core's
     * behaviour for question references (backup/moodle2/restore_stepslib.php:6555-6557).
     *
     * @param int $oldid The question bank entry id as recorded in the backup.
     * @return int The id to store on this site.
     */
    protected function map_question_bank_entry_id($oldid) {
        return $this->get_mappingid('question_bank_entry', $oldid, $oldid);
    }

    /**
     * Maps a backed up question id onto this site.
     *
     * Only a question that the restore actually created is rewritten. When the
     * question was matched to one already on this site the old id already points at
     * the right record and must be left alone. This is exactly the test core applies
     * in restore_question_attempt_worker (backup/moodle2/restore_stepslib.php:6381-6384).
     *
     * @param int $oldid The question id as recorded in the backup.
     * @return int The id to store on this site.
     */
    protected function map_question_id($oldid) {
        if (!$this->get_mappingid('question_created', $oldid)) {
            return $oldid;
        }
        $question = $this->get_mapping('question', $oldid);
        return $question ? $question->newitemid : $oldid;
    }

    /**
     * Restores the file areas belonging to this activity.
     *
     * The parent call is required: restore_questions_attempt_data_trait::after_execute()
     * restores the question response files
     * (backup/moodle2/restore_stepslib.php:6509-6516).
     *
     * @return void
     */
    protected function after_execute() {
        parent::after_execute();

        // The 'intro' area is the only file area this plugin owns; it has no itemid.
        $this->add_related_files('mod_rememberme', 'intro', null);
    }

    /**
     * Constrain a restored schedule lifecycle state to the known set.
     *
     * @param string $state The state from the backup file.
     * @return string A state this plugin recognises.
     */
    protected static function clean_schedule_state(string $state): string {
        $known = ['new', 'learning', 'review', 'relearning'];
        return in_array($state, $known, true) ? $state : 'new';
    }

    /**
     * Constrain a restored band unlock reason to the known set.
     *
     * @param string $reason The reason from the backup file.
     * @return string A reason this plugin recognises.
     */
    protected static function clean_band_reason(string $reason): string {
        $known = [
            \mod_rememberme\local\bands::REASON_NONE,
            \mod_rememberme\local\bands::REASON_TIME,
            \mod_rememberme\local\bands::REASON_MASTERY,
            \mod_rememberme\local\bands::REASON_BACKSTOP,
            \mod_rememberme\local\bands::REASON_SUSPENSION_LIMIT,
        ];
        return in_array($reason, $known, true) ? $reason : \mod_rememberme\local\bands::REASON_NONE;
    }
}
