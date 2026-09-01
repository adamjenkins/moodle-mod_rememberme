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

namespace mod_rememberme\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy subsystem implementation for mod_rememberme.
 *
 * The activity keeps a per learner memory model, so almost everything it
 * stores is personal data. Five tables carry a userid of their own
 * (rememberme_schedule, rememberme_review_log, rememberme_weeks,
 * rememberme_bandstate, rememberme_session) and one, rememberme_slot, carries
 * none but hangs off a session and is therefore just as personal; it is
 * reached through its sessionid.
 *
 * Deleting a learner also has to take the question engine usages their
 * sessions own. Core stores no owner on a question usage, so the only route
 * back to them is rememberme_session.uniqueid.
 *
 * @package    mod_rememberme
 * @category   privacy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    // This plugin stores personal data.
    \core_privacy\local\metadata\provider,
    // This plugin is capable of determining which users have data within it.
    \core_privacy\local\request\core_userlist_provider,
    // This plugin is a core_user_data_provider.
    \core_privacy\local\request\plugin\provider {
    /**
     * Return the fields which contain personal data.
     *
     * @param collection $collection A reference to the collection to use to store the metadata.
     * @return collection The updated collection of metadata items.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'rememberme_schedule',
            [
                'userid' => 'privacy:metadata:rememberme_schedule:userid',
                'questionbankentryid' => 'privacy:metadata:rememberme_schedule:questionbankentryid',
                'stability' => 'privacy:metadata:rememberme_schedule:stability',
                'difficulty' => 'privacy:metadata:rememberme_schedule:difficulty',
                'reps' => 'privacy:metadata:rememberme_schedule:reps',
                'lapses' => 'privacy:metadata:rememberme_schedule:lapses',
                'duedate' => 'privacy:metadata:rememberme_schedule:duedate',
            ],
            'privacy:metadata:rememberme_schedule'
        );

        $collection->add_database_table(
            'rememberme_review_log',
            [
                'userid' => 'privacy:metadata:rememberme_review_log:userid',
                'questionbankentryid' => 'privacy:metadata:rememberme_review_log:questionbankentryid',
                'rating' => 'privacy:metadata:rememberme_review_log:rating',
                'fraction' => 'privacy:metadata:rememberme_review_log:fraction',
                'latency' => 'privacy:metadata:rememberme_review_log:latency',
                'timecreated' => 'privacy:metadata:rememberme_review_log:timecreated',
            ],
            'privacy:metadata:rememberme_review_log'
        );

        $collection->add_database_table(
            'rememberme_weeks',
            [
                'userid' => 'privacy:metadata:rememberme_weeks:userid',
                'weekno' => 'privacy:metadata:rememberme_weeks:weekno',
                'snapshottarget' => 'privacy:metadata:rememberme_weeks:snapshottarget',
                'completed' => 'privacy:metadata:rememberme_weeks:completed',
                'fraction' => 'privacy:metadata:rememberme_weeks:fraction',
            ],
            'privacy:metadata:rememberme_weeks'
        );

        $collection->add_database_table(
            'rememberme_bandstate',
            [
                'userid' => 'privacy:metadata:rememberme_bandstate:userid',
                'bandlevel' => 'privacy:metadata:rememberme_bandstate:bandlevel',
                'firstsession' => 'privacy:metadata:rememberme_bandstate:firstsession',
            ],
            'privacy:metadata:rememberme_bandstate'
        );

        $collection->add_database_table(
            'rememberme_session',
            [
                'userid' => 'privacy:metadata:rememberme_session:userid',
                'timecreated' => 'privacy:metadata:rememberme_session:timecreated',
            ],
            'privacy:metadata:rememberme_session'
        );

        $collection->add_database_table(
            'rememberme_slot',
            [
                'sessionid' => 'privacy:metadata:rememberme_slot:sessionid',
                'questionbankentryid' => 'privacy:metadata:rememberme_slot:questionbankentryid',
                'isnew' => 'privacy:metadata:rememberme_slot:isnew',
                'timeshown' => 'privacy:metadata:rememberme_slot:timeshown',
            ],
            'privacy:metadata:rememberme_slot'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The list of contexts containing user info for the user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {rememberme} r ON r.id = cm.instance
                 WHERE r.id IN (
                           SELECT rememberme FROM {rememberme_schedule} WHERE userid = :userid1
                            UNION
                           SELECT rememberme FROM {rememberme_review_log} WHERE userid = :userid2
                            UNION
                           SELECT rememberme FROM {rememberme_weeks} WHERE userid = :userid3
                            UNION
                           SELECT rememberme FROM {rememberme_bandstate} WHERE userid = :userid4
                            UNION
                           SELECT rememberme FROM {rememberme_session} WHERE userid = :userid5
                       )";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'rememberme',
            'userid1' => $userid,
            'userid2' => $userid,
            'userid3' => $userid,
            'userid4' => $userid,
            'userid5' => $userid,
        ];

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('rememberme', $context->instanceid);
        if (!$cm) {
            return;
        }

        $sql = "SELECT userid FROM {rememberme_schedule} WHERE rememberme = :id1
                 UNION
                SELECT userid FROM {rememberme_review_log} WHERE rememberme = :id2
                 UNION
                SELECT userid FROM {rememberme_weeks} WHERE rememberme = :id3
                 UNION
                SELECT userid FROM {rememberme_bandstate} WHERE rememberme = :id4
                 UNION
                SELECT userid FROM {rememberme_session} WHERE rememberme = :id5";

        $params = [
            'id1' => $cm->instance,
            'id2' => $cm->instance,
            'id3' => $cm->instance,
            'id4' => $cm->instance,
            'id5' => $cm->instance,
        ];

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export personal data for the given approved_contextlist.
     *
     * @param approved_contextlist $contextlist A list of contexts approved for export.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('rememberme', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $data = [
                'schedule' => self::get_schedule_for_export($cm->instance, $user->id),
                'reviews' => self::get_reviews_for_export($cm->instance, $user->id),
                'weeks' => self::get_weeks_for_export($cm->instance, $user->id),
                'bands' => self::get_bandstate_for_export($cm->instance, $user->id),
                'sessions' => self::get_sessions_for_export($cm->instance, $user->id),
            ];

            $empty = true;
            foreach ($data as $rows) {
                if (!empty($rows)) {
                    $empty = false;
                }
            }
            if ($empty) {
                continue;
            }

            // Generic module data, so the export has a name and an intro to hang the rest off.
            $contextdata = helper::get_context_data($context, $user);
            writer::with_context($context)->export_data([], $contextdata);
            helper::export_context_files($context, $user);

            $writer = writer::with_context($context);
            foreach ($data as $name => $rows) {
                if (empty($rows)) {
                    continue;
                }
                $writer->export_related_data([], $name, (object)[$name => $rows]);
            }
        }
    }

    /**
     * The learner's memory state rows, shaped for export.
     *
     * @param int $instanceid The rememberme instance id.
     * @param int $userid The learner.
     * @return array A list of stdClass rows.
     */
    protected static function get_schedule_for_export(int $instanceid, int $userid): array {
        global $DB;

        $rows = [];
        $records = $DB->get_records(
            'rememberme_schedule',
            ['rememberme' => $instanceid, 'userid' => $userid],
            'id ASC'
        );
        foreach ($records as $record) {
            $rows[] = (object)[
                'questionbankentryid' => $record->questionbankentryid,
                'stability' => $record->stability,
                'difficulty' => $record->difficulty,
                'reps' => $record->reps,
                'lapses' => $record->lapses,
                'state' => $record->state,
                'bandlevel' => $record->bandlevel,
                'lastreviewed' => self::format_time($record->lastreviewed),
                'duedate' => self::format_time($record->duedate),
                'timecreated' => self::format_time($record->timecreated),
                'timemodified' => self::format_time($record->timemodified),
            ];
        }

        return $rows;
    }

    /**
     * The learner's review log rows, shaped for export.
     *
     * @param int $instanceid The rememberme instance id.
     * @param int $userid The learner.
     * @return array A list of stdClass rows.
     */
    protected static function get_reviews_for_export(int $instanceid, int $userid): array {
        global $DB;

        $rows = [];
        $records = $DB->get_records(
            'rememberme_review_log',
            ['rememberme' => $instanceid, 'userid' => $userid],
            'id ASC'
        );
        foreach ($records as $record) {
            $rows[] = (object)[
                'questionbankentryid' => $record->questionbankentryid,
                'questionid' => $record->questionid,
                'qtype' => $record->qtype,
                'rating' => $record->rating,
                'fraction' => $record->fraction,
                'elapseddays' => $record->elapseddays,
                'retrievability' => $record->retrievability,
                'stabilitybefore' => $record->stabilitybefore,
                'difficultybefore' => $record->difficultybefore,
                'stabilityafter' => $record->stabilityafter,
                'difficultyafter' => $record->difficultyafter,
                'latency' => $record->latency,
                'weekno' => $record->weekno,
                'insuspension' => transform::yesno($record->insuspension),
                'timecreated' => self::format_time($record->timecreated),
            ];
        }

        return $rows;
    }

    /**
     * The learner's weekly completion rows, shaped for export.
     *
     * @param int $instanceid The rememberme instance id.
     * @param int $userid The learner.
     * @return array A list of stdClass rows.
     */
    protected static function get_weeks_for_export(int $instanceid, int $userid): array {
        global $DB;

        $rows = [];
        $records = $DB->get_records(
            'rememberme_weeks',
            ['rememberme' => $instanceid, 'userid' => $userid],
            'weekno ASC'
        );
        foreach ($records as $record) {
            $rows[] = (object)[
                'weekno' => $record->weekno,
                'snapshottarget' => $record->snapshottarget,
                'snapshottaken' => self::format_time($record->snapshottaken),
                'completed' => $record->completed,
                'fraction' => $record->fraction,
                'graceapplied' => $record->graceapplied,
                'suspended' => transform::yesno($record->suspended),
                'timemodified' => self::format_time($record->timemodified),
            ];
        }

        return $rows;
    }

    /**
     * The learner's band progress rows, shaped for export.
     *
     * @param int $instanceid The rememberme instance id.
     * @param int $userid The learner.
     * @return array A list of stdClass rows.
     */
    protected static function get_bandstate_for_export(int $instanceid, int $userid): array {
        global $DB;

        $rows = [];
        $records = $DB->get_records(
            'rememberme_bandstate',
            ['rememberme' => $instanceid, 'userid' => $userid],
            'id ASC'
        );
        foreach ($records as $record) {
            $rows[] = (object)[
                'bandlevel' => $record->bandlevel,
                'reason' => $record->reason,
                'firstsession' => self::format_time($record->firstsession),
                'bandsince' => self::format_time($record->bandsince),
                'lastunlockwindow' => self::format_time($record->lastunlockwindow),
                'timemodified' => self::format_time($record->timemodified),
            ];
        }

        return $rows;
    }

    /**
     * The learner's study sessions, each with the slots it served, shaped for export.
     *
     * @param int $instanceid The rememberme instance id.
     * @param int $userid The learner.
     * @return array A list of stdClass rows.
     */
    protected static function get_sessions_for_export(int $instanceid, int $userid): array {
        global $DB;

        $rows = [];
        $records = $DB->get_records(
            'rememberme_session',
            ['rememberme' => $instanceid, 'userid' => $userid],
            'id ASC'
        );
        foreach ($records as $record) {
            $slots = [];
            $slotrecords = $DB->get_records('rememberme_slot', ['sessionid' => $record->id], 'slot ASC');
            foreach ($slotrecords as $slotrecord) {
                $slots[] = (object)[
                    'slot' => $slotrecord->slot,
                    'questionbankentryid' => $slotrecord->questionbankentryid,
                    'questionid' => $slotrecord->questionid,
                    'bandlevel' => $slotrecord->bandlevel,
                    'isnew' => transform::yesno($slotrecord->isnew),
                    'graded' => transform::yesno($slotrecord->graded),
                    'timeshown' => self::format_time($slotrecord->timeshown),
                ];
            }

            $rows[] = (object)[
                'itemcount' => $record->itemcount,
                'answered' => $record->answered,
                'timecreated' => self::format_time($record->timecreated),
                'timemodified' => self::format_time($record->timemodified),
                'timefinished' => self::format_time($record->timefinished),
                'slots' => $slots,
            ];
        }

        return $rows;
    }

    /**
     * Render a stored timestamp, treating the zero default as "never".
     *
     * @param int|null $time The stored timestamp.
     * @return string|null The formatted date, or null when there is no time to show.
     */
    protected static function format_time($time) {
        if (empty($time)) {
            return null;
        }

        return transform::datetime($time);
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The context to delete in.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('rememberme', $context->instanceid);
        if (!$cm) {
            return;
        }

        $sessions = $DB->get_records('rememberme_session', ['rememberme' => $cm->instance], '', 'id, uniqueid');
        self::delete_sessions($sessions);

        $DB->delete_records('rememberme_schedule', ['rememberme' => $cm->instance]);
        $DB->delete_records('rememberme_review_log', ['rememberme' => $cm->instance]);
        $DB->delete_records('rememberme_weeks', ['rememberme' => $cm->instance]);
        $DB->delete_records('rememberme_bandstate', ['rememberme' => $cm->instance]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist A list of contexts approved for deletion.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('rememberme', $context->instanceid);
            if (!$cm) {
                continue;
            }

            self::delete_data_for_userids($cm->instance, [$userid]);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('rememberme', $context->instanceid);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        self::delete_data_for_userids($cm->instance, $userids);
    }

    /**
     * Delete every trace of the given learners from one activity instance.
     *
     * @param int $instanceid The rememberme instance id.
     * @param array $userids The learners to remove.
     */
    protected static function delete_data_for_userids(int $instanceid, array $userids) {
        global $DB;

        if (empty($userids)) {
            return;
        }

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $params = ['rememberme' => $instanceid] + $userparams;
        $select = "rememberme = :rememberme AND userid {$usersql}";

        $sessions = $DB->get_records_select('rememberme_session', $select, $params, '', 'id, uniqueid');
        self::delete_sessions($sessions);

        $DB->delete_records_select('rememberme_schedule', $select, $params);
        $DB->delete_records_select('rememberme_review_log', $select, $params);
        $DB->delete_records_select('rememberme_weeks', $select, $params);
        $DB->delete_records_select('rememberme_bandstate', $select, $params);
    }

    /**
     * Delete session rows, their slots, and the question engine usages they own.
     *
     * A rememberme_slot row carries no userid of its own; it is reached only
     * through its sessionid, so it has to go with the session. The question
     * usage is the same story from the other direction: core records no owner
     * on a usage, so rememberme_session.uniqueid is the only way back to it.
     *
     * @param array $sessions Session records keyed by id, each carrying id and uniqueid.
     */
    protected static function delete_sessions(array $sessions) {
        global $CFG, $DB;

        if (empty($sessions)) {
            return;
        }

        require_once($CFG->libdir . '/questionlib.php');

        foreach ($sessions as $session) {
            if (!empty($session->uniqueid)) {
                \question_engine::delete_questions_usage_by_activity((int)$session->uniqueid);
            }
        }

        $sessionids = array_keys($sessions);
        [$insql, $inparams] = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sid');
        $DB->delete_records_select('rememberme_slot', "sessionid {$insql}", $inparams);
        $DB->delete_records_list('rememberme_session', 'id', $sessionids);
    }
}
