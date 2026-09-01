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

namespace mod_rememberme\task;

/**
 * Nightly housekeeping for mod_rememberme.
 *
 * This task is maintenance only, and that restriction is load bearing rather
 * than an accident of scope. Scheduling in this activity is lazy and query
 * driven: what is due is derived from stored stability and difficulty at the
 * moment a session is built. A cron job that precomputed a due queue would be
 * wrong the instant a learner did one extra session, and the learner would be
 * shown a stale queue for up to a day. So nothing here reads, writes or
 * derives a due queue, and nothing here decides what anybody studies.
 *
 * What it does do is remove records that can no longer refer to anything, and
 * close sessions that were opened and never finished:
 *
 * 1. Schedule rows whose question bank entry has been deleted. The schedule is
 *    keyed on questionbankentryid rather than a versioned question id, so
 *    editing a question keeps the learner's memory state; deleting the bank
 *    entry outright is the case that genuinely orphans it.
 * 2. Schedule rows whose activity instance is gone. Instance deletion clears
 *    these itself, but a failed deletion or a restore that half completed can
 *    leave them behind, and they would otherwise never be reachable again.
 * 3. Slot rows in the same two situations, plus slot rows whose session row
 *    has gone. A slot is only meaningful as part of a session.
 * 4. Sessions left open for more than a day, which are closed rather than
 *    deleted. The review log already holds every answer the learner gave, so
 *    deleting the session would destroy the only record of the delivery
 *    context while gaining nothing.
 *
 * Every step runs in bounded batches with a hard cap on the number of batches,
 * so a site with a large backlog spreads the work over several nightly runs
 * instead of running cron out of memory or holding locks for minutes.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class maintenance extends \core\task\scheduled_task {
    /** @var int Rows handled per database round trip. */
    public const BATCH_SIZE = 500;

    /** @var int Maximum batches per step per run, so one run cannot spin indefinitely. */
    public const MAX_BATCHES = 200;

    /** @var int A session untouched for this long is treated as abandoned. */
    public const ABANDON_AFTER = DAYSECS;

    /**
     * Get the descriptive name shown to administrators.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskmaintenance', 'mod_rememberme');
    }

    /**
     * Run the housekeeping.
     *
     * Progress is reported with mtrace() in plain English rather than through
     * get_string(), following the core convention for cron output; see
     * mod/quiz/classes/task/update_overdue_attempts.php, which does the same.
     *
     * @return void
     */
    public function execute() {
        $now = time();

        $count = $this->prune_schedules_for_deleted_questions();
        mtrace("  Pruned {$count} rememberme_schedule row(s) whose question bank entry no longer exists.");

        $count = $this->prune_schedules_for_deleted_instances();
        mtrace("  Pruned {$count} rememberme_schedule row(s) whose activity instance no longer exists.");

        $count = $this->prune_slots_for_deleted_questions();
        mtrace("  Pruned {$count} rememberme_slot row(s) whose question bank entry no longer exists.");

        $count = $this->prune_slots_for_deleted_parents();
        mtrace("  Pruned {$count} rememberme_slot row(s) whose session or activity instance no longer exists.");

        $count = $this->close_abandoned_sessions($now);
        mtrace("  Closed {$count} abandoned rememberme session(s).");
    }

    /**
     * Delete schedule rows pointing at a question bank entry that has been deleted.
     *
     * @return int Number of rows deleted.
     */
    protected function prune_schedules_for_deleted_questions(): int {
        $sql = "SELECT s.id
                  FROM {rememberme_schedule} s
             LEFT JOIN {question_bank_entries} qbe ON qbe.id = s.questionbankentryid
                 WHERE qbe.id IS NULL";

        return $this->delete_in_batches('rememberme_schedule', $sql, []);
    }

    /**
     * Delete schedule rows whose activity instance has been deleted.
     *
     * @return int Number of rows deleted.
     */
    protected function prune_schedules_for_deleted_instances(): int {
        $sql = "SELECT s.id
                  FROM {rememberme_schedule} s
             LEFT JOIN {rememberme} r ON r.id = s.rememberme
                 WHERE r.id IS NULL";

        return $this->delete_in_batches('rememberme_schedule', $sql, []);
    }

    /**
     * Delete slot rows pointing at a question bank entry that has been deleted.
     *
     * @return int Number of rows deleted.
     */
    protected function prune_slots_for_deleted_questions(): int {
        $sql = "SELECT sl.id
                  FROM {rememberme_slot} sl
             LEFT JOIN {question_bank_entries} qbe ON qbe.id = sl.questionbankentryid
                 WHERE qbe.id IS NULL";

        return $this->delete_in_batches('rememberme_slot', $sql, []);
    }

    /**
     * Delete slot rows whose session, or whose session's activity instance, has gone.
     *
     * @return int Number of rows deleted.
     */
    protected function prune_slots_for_deleted_parents(): int {
        $sql = "SELECT sl.id
                  FROM {rememberme_slot} sl
             LEFT JOIN {rememberme_session} sess ON sess.id = sl.sessionid
             LEFT JOIN {rememberme} r ON r.id = sess.rememberme
                 WHERE sess.id IS NULL OR r.id IS NULL";

        return $this->delete_in_batches('rememberme_slot', $sql, []);
    }

    /**
     * Close sessions that were started and never finished.
     *
     * Abandonment is judged on last activity, not on when the session was
     * opened: a learner who started a long session yesterday and answered a
     * question a minute ago is still working, and closing that session under
     * them would lose their place. Both timestamps must therefore be older
     * than the cutoff.
     *
     * The row is kept. timefinished is set to the last moment the session is
     * known to have been touched, not to the time cron happened to run, so a
     * later report does not claim a learner was studying all night. Where no
     * usable timestamp survives the row is closed at the current time so that
     * it cannot be picked up again on every subsequent run.
     *
     * @param int $now Current time, injectable so tests need not sleep.
     * @return int Number of sessions closed.
     */
    protected function close_abandoned_sessions(int $now): int {
        global $DB;

        $cutoff = $now - self::ABANDON_AFTER;
        $sql = "SELECT id, timecreated, timemodified
                  FROM {rememberme_session}
                 WHERE timefinished = 0 AND timecreated < :createdcutoff AND timemodified < :modifiedcutoff";

        $total = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            // Read one bounded page through a recordset, then close it before
            // writing. Updating the same table while its recordset is open is
            // not safe across every supported driver.
            $rows = [];
            $params = ['createdcutoff' => $cutoff, 'modifiedcutoff' => $cutoff];
            $rs = $DB->get_recordset_sql($sql, $params, 0, self::BATCH_SIZE);
            foreach ($rs as $record) {
                $rows[] = $record;
            }
            $rs->close();

            if (!$rows) {
                return $total;
            }

            foreach ($rows as $record) {
                $finished = max((int)$record->timemodified, (int)$record->timecreated);
                if ($finished <= 0) {
                    $finished = $now;
                }
                $DB->set_field('rememberme_session', 'timefinished', $finished, ['id' => $record->id]);
                $total++;
            }

            if (count($rows) < self::BATCH_SIZE) {
                return $total;
            }
        }

        mtrace('  Batch cap reached while closing abandoned sessions; the rest will be closed on the next run.');

        return $total;
    }

    /**
     * Delete the rows selected by a query, a bounded page at a time.
     *
     * The query must select exactly one column named id. Each deleted page
     * leaves the result set, so the loop always reads from offset zero.
     *
     * @param string $table Table the ids belong to, without braces.
     * @param string $sql Query selecting the ids to delete.
     * @param array $params Named parameters for the query.
     * @return int Number of rows deleted.
     */
    protected function delete_in_batches(string $table, string $sql, array $params): int {
        global $DB;

        $total = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $ids = [];
            $rs = $DB->get_recordset_sql($sql, $params, 0, self::BATCH_SIZE);
            foreach ($rs as $record) {
                $ids[] = (int)$record->id;
            }
            $rs->close();

            if (!$ids) {
                return $total;
            }

            $DB->delete_records_list($table, 'id', $ids);
            $total += count($ids);

            if (count($ids) < self::BATCH_SIZE) {
                return $total;
            }
        }

        mtrace("  Batch cap reached while pruning {$table}; the rest will be pruned on the next run.");

        return $total;
    }
}
