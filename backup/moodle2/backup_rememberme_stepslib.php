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
 * Defines the backup structure step used by backup_rememberme_activity_task.
 *
 * @package    mod_rememberme
 * @category   backup
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the complete rememberme structure for backup, with file and id annotations.
 *
 * The base class is backup_questions_activity_structure_step
 * (backup/moodle2/backup_stepslib.php:342) rather than the plain
 * backup_activity_structure_step, because this activity owns question engine
 * usages and needs add_question_usages() from backup_questions_attempt_data_trait
 * (backup/moodle2/backup_stepslib.php:159).
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_rememberme_activity_structure_step extends backup_questions_activity_structure_step {
    /**
     * Defines the XML structure written to rememberme.xml.
     *
     * @return backup_nested_element The activity wrapped root element.
     */
    protected function define_structure() {

        // Whether learner data is being included in this backup. Created for us by
        // backup_activity_task::add_activity_userinfo_setting()
        // (backup/moodle2/backup_activity_task.class.php:355) and read by name via
        // base_step::get_setting_value() (backup/util/plan/base_step.class.php:100).
        $userinfo = $this->get_setting_value('userinfo');

        // Instance settings. Field list taken from db/install.xml, minus 'id'
        // (an attribute) and 'course' (rebuilt on restore from the target course).
        //
        // This list is maintained by hand, and a column added to install.xml
        // without being added here is dropped in silence: the restore inserts
        // the row without it and the database supplies its default, so the copy
        // looks fine and is configured differently. That had already happened
        // to three settings here and to bandnumber below, which defaults to 1
        // and so merged every band of a restored activity into one.
        // tests/backup_restore_test.php compares a duplicate against
        // get_columns() rather than against a list, so the next omission fails
        // a test instead of shipping.
        $rememberme = new backup_nested_element('rememberme', ['id'], [
            'name', 'intro', 'introformat', 'targetretention', 'sessionsize', 'newperday',
            'unlockmode', 'unlockinterval', 'stabilityfloor', 'masteryproportion', 'backstopdays',
            'coursestart', 'activeweeks', 'gracebalance', 'graceearnrate', 'passthreshold',
            'uselatency', 'audiocue', 'pausecorrect', 'pauseincorrect', 'grade',
            'completionweeks', 'questionbankcmid', 'ontimegrace', 'maxchoices',
            'timecreated', 'timemodified',
        ]);

        // Teacher configuration: bands and suspension windows. Always backed up.
        $bands = new backup_nested_element('bands');
        $band = new backup_nested_element('band', ['id'], [
            'bandnumber', 'sortorder', 'questioncategoryid', 'includesubcategories',
        ]);

        $suspensions = new backup_nested_element('suspensions');
        $suspension = new backup_nested_element('suspension', ['id'], [
            'name', 'timestart', 'timeend',
        ]);

        // Learner data. Declared unconditionally, sourced only when $userinfo,
        // which is the documented shape (mod/choice backup stepslib :69-71):
        // an element with no source emits nothing, so never gate add_child().
        $schedules = new backup_nested_element('schedules');
        $schedule = new backup_nested_element('schedule', ['id'], [
            'userid', 'questionbankentryid', 'stability', 'difficulty', 'fuzzfactor',
            'reps', 'lapses', 'state', 'bandlevel', 'lastreviewed', 'duedate',
            'learningdue', 'timecreated', 'timemodified',
        ]);

        $reviewlogs = new backup_nested_element('reviewlogs');
        $reviewlog = new backup_nested_element('reviewlog', ['id'], [
            'userid', 'questionbankentryid', 'questionid', 'qtype', 'rating', 'fraction',
            'elapseddays', 'retrievability', 'stabilitybefore', 'difficultybefore',
            'stabilityafter', 'difficultyafter', 'latency', 'weekno', 'insuspension',
            'wasdue', 'timecreated',
        ]);

        $bandstates = new backup_nested_element('bandstates');
        $bandstate = new backup_nested_element('bandstate', ['id'], [
            'userid', 'bandlevel', 'reason', 'firstsession', 'bandsince',
            'lastunlockwindow', 'timemodified',
        ]);

        $weeks = new backup_nested_element('weeks');
        $week = new backup_nested_element('week', ['id'], [
            'userid', 'weekno', 'snapshottarget', 'snapshottaken', 'completed', 'fraction',
            'graceapplied', 'suspended', 'timemodified',
        ]);

        $sessions = new backup_nested_element('sessions');
        $session = new backup_nested_element('session', ['id'], [
            'userid', 'uniqueid', 'itemcount', 'answered', 'timecreated', 'timemodified',
            'timefinished',
        ]);

        // Attach the question engine usage (question_usages, question_attempts,
        // question_attempt_steps and their step data plus response files) below
        // $session, matched on the 'uniqueid' final element. Same call mod_quiz
        // makes at mod/quiz/backup/moodle2/backup_quiz_stepslib.php:91. This must
        // happen before the remaining children are added, so that <question_usage>
        // is written before <sessionslots>: the restore side relies on the parent
        // <session> data being published when the first child container opens
        // (backup/util/xml/parser/progressive_parser.class.php:224-235).
        $this->add_question_usages($session, 'uniqueid');

        // Named 'sessionslot' rather than 'slot' to keep it clearly distinct from
        // the question engine's own 'slot' value inside <question_attempt>.
        $sessionslots = new backup_nested_element('sessionslots');
        $sessionslot = new backup_nested_element('sessionslot', ['id'], [
            'slot', 'questionbankentryid', 'questionid', 'bandlevel', 'isnew', 'graded',
            'timeshown',
        ]);

        // Build the tree.
        $rememberme->add_child($bands);
        $bands->add_child($band);

        $rememberme->add_child($suspensions);
        $suspensions->add_child($suspension);

        $rememberme->add_child($schedules);
        $schedules->add_child($schedule);

        $rememberme->add_child($reviewlogs);
        $reviewlogs->add_child($reviewlog);

        $rememberme->add_child($bandstates);
        $bandstates->add_child($bandstate);

        $rememberme->add_child($weeks);
        $weeks->add_child($week);

        $rememberme->add_child($sessions);
        $sessions->add_child($session);

        $session->add_child($sessionslots);
        $sessionslots->add_child($sessionslot);

        // Define the sources that are always present.
        $rememberme->set_source_table('rememberme', ['id' => backup::VAR_ACTIVITYID]);

        $band->set_source_table(
            'rememberme_bands',
            ['rememberme' => backup::VAR_PARENTID],
            'sortorder ASC, id ASC'
        );

        $suspension->set_source_table(
            'rememberme_suspensions',
            ['rememberme' => backup::VAR_PARENTID],
            'timestart ASC, id ASC'
        );

        // Everything below here is learner data.
        if ($userinfo) {
            $schedule->set_source_table(
                'rememberme_schedule',
                ['rememberme' => backup::VAR_PARENTID],
                'id ASC'
            );

            $reviewlog->set_source_table(
                'rememberme_review_log',
                ['rememberme' => backup::VAR_PARENTID],
                'id ASC'
            );

            $bandstate->set_source_table(
                'rememberme_bandstate',
                ['rememberme' => backup::VAR_PARENTID],
                'id ASC'
            );

            $week->set_source_table(
                'rememberme_weeks',
                ['rememberme' => backup::VAR_PARENTID],
                'weekno ASC, id ASC'
            );

            // Deliberately a JOIN rather than a plain source table. On restore the
            // session row is inserted from inform_new_usage_id(), which only fires
            // when a <question_usage> child was present; a session whose
            // question_usages row had gone missing would therefore be silently
            // dropped. Requiring the usage at backup time makes that case impossible
            // instead of undetectable.
            $session->set_source_sql(
                '
                    SELECT s.*
                      FROM {rememberme_session} s
                      JOIN {question_usages} qu ON qu.id = s.uniqueid
                     WHERE s.rememberme = :rememberme
                  ORDER BY s.id ASC',
                ['rememberme' => backup::VAR_PARENTID]
            );

            $sessionslot->set_source_table(
                'rememberme_slot',
                ['sessionid' => backup::VAR_PARENTID],
                'slot ASC'
            );
        }

        // Define id annotations.
        //
        // Users: the easy, fully supported case. annotate_ids('user', 'userid') here
        // plus get_mappingid('user', ...) on restore is the whole contract
        // (mod/choice backup stepslib :74, restore stepslib :86).
        $schedule->annotate_ids('user', 'userid');
        $reviewlog->annotate_ids('user', 'userid');
        $bandstate->annotate_ids('user', 'userid');
        $week->annotate_ids('user', 'userid');
        $session->annotate_ids('user', 'userid');

        // Questions: an activity does NOT map raw question ids. Since Moodle 4.0 the
        // mappable identity is the question bank entry, and the annotation itemname
        // is 'question_bank_entry' — see core's own reference handling at
        // backup/moodle2/backup_stepslib.php:245
        // ($reference->annotate_ids('question_bank_entry', 'questionbankentryid')),
        // consumed on restore via get_mappingid('question_bank_entry', ...) at
        // backup/moodle2/restore_stepslib.php:6555. These three tables key on exactly
        // that column, so they annotate it directly; this plugin writes no
        // question_references rows, so add_question_references() is not used.
        $schedule->annotate_ids('question_bank_entry', 'questionbankentryid');
        $reviewlog->annotate_ids('question_bank_entry', 'questionbankentryid');
        $sessionslot->annotate_ids('question_bank_entry', 'questionbankentryid');

        // The 'questionid' columns on rememberme_review_log and rememberme_slot are
        // deliberately NOT annotated. In 5.2 the 'question' itemname no longer drives
        // what gets backed up (backup_question_dbops::calculate_question_categories
        // joins on itemname = 'question_bank_entry' only,
        // backup/util/dbops/backup_question_dbops.class.php:50-58) and any 'question'
        // annotations are deleted again by backup_delete_temp_questions (:108-111).
        // They are historical records of which version was served and are remapped on
        // restore through the 'question_created' mapping, the same test core applies
        // at backup/moodle2/restore_stepslib.php:6381-6384.

        // Band question categories. 'question_category' is a genuine annotation
        // itemname: it is one of the inforef itemnames
        // (backup/util/helper/backup_helper.class.php:371), it is moved to
        // 'question_categoryfinal' by move_inforef_annotations_to_final
        // (backup/moodle2/backup_stepslib.php:2110-2120), and questions.xml sources
        // its categories from exactly that (backup/moodle2/backup_stepslib.php:2727).
        // Core creates these annotations indirectly, from annotated bank entries
        // (backup/util/dbops/backup_question_dbops.class.php:70), which is not enough
        // here: a band's category may contain no annotated entry at all (no learner
        // has met it yet, or userinfo was excluded), and it usually lives outside this
        // activity's own context.
        //
        // UNVERIFIED: no core code annotates 'question_category' directly from an
        // activity structure step (`grep -rn "annotate_ids('question_category'"` over
        // /srv/lms/moodle/public returns nothing), and I did not run a live
        // backup/restore cycle to confirm the category is emitted and remapped. The
        // annotation pipeline above is verified by reading; the end-to-end effect is
        // not. The restore side degrades safely: an unmapped category keeps its old
        // id, which is what core does for an unmapped bank entry
        // (backup/moodle2/restore_stepslib.php:6555-6557).
        $band->annotate_ids('question_category', 'questioncategoryid');

        // Define file annotations. 'intro' is the only file area this plugin owns
        // (mod/rememberme/lib.php:460 rejects every other area); response files
        // belonging to question attempts are annotated by add_question_usages().
        $rememberme->annotate_files('mod_rememberme', 'intro', null);

        // Return the root element, wrapped into the standard activity structure.
        return $this->prepare_activity_structure($rememberme);
    }
}
