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
 * Database upgrade steps for mod_rememberme.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the database from an earlier version of this plugin.
 *
 * @param int $oldversion The version currently installed.
 * @return bool True on success.
 */
function xmldb_rememberme_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026090104) {
        // A wrong answer now puts the item into a short learning step, so it
        // comes back in the same sitting rather than at the interval its
        // stability implies. Zero means the item is on its normal schedule,
        // which is the right state for every row that already exists.
        $table = new xmldb_table('rememberme_schedule');
        $field = new xmldb_field('learningdue', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'duedate');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026090104, 'rememberme');
    }

    if ($oldversion < 2026090105) {
        // A band may now draw on several categories, so a band is identified by
        // its number rather than by being one row. Existing rows were one band
        // each, in sortorder, which is what this preserves.
        $table = new xmldb_table('rememberme_bands');
        $field = new xmldb_field('bandnumber', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '1', 'sortorder');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);

            $instances = $DB->get_fieldset_sql('SELECT DISTINCT rememberme FROM {rememberme_bands}');
            foreach ($instances as $instanceid) {
                $bands = $DB->get_records('rememberme_bands', ['rememberme' => $instanceid], 'sortorder ASC', 'id');
                $number = 1;
                foreach ($bands as $band) {
                    $DB->set_field('rememberme_bands', 'bandnumber', $number, ['id' => $band->id]);
                    $number++;
                }
            }
        }

        $oldindex = new xmldb_index('rememberme-sortorder', XMLDB_INDEX_NOTUNIQUE, ['rememberme', 'sortorder']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }
        $newindex = new xmldb_index(
            'rememberme-bandnumber',
            XMLDB_INDEX_NOTUNIQUE,
            ['rememberme', 'bandnumber', 'sortorder']
        );
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }

        // Punctuality is measured against the due date an item had when it was
        // answered, which was not previously recorded. Zero means "not known",
        // and history written before this point is simply not counted.
        $table = new xmldb_table('rememberme_review_log');
        $field = new xmldb_field('wasdue', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'insuspension');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('rememberme');
        $field = new xmldb_field(
            'questionbankcmid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'completionweeks'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field(
            'ontimegrace',
            XMLDB_TYPE_NUMBER,
            '10, 4',
            null,
            XMLDB_NOTNULL,
            null,
            '0.5000',
            'questionbankcmid'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026090105, 'rememberme');
    }

    if ($oldversion < 2026090106) {
        // How many options a multiple choice question may present. Existing
        // activities keep every option, which is what they have always done.
        $table = new xmldb_table('rememberme');
        $field = new xmldb_field(
            'maxchoices',
            XMLDB_TYPE_INTEGER,
            '4',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'ontimegrace'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026090106, 'rememberme');
    }

    if ($oldversion < 2026090107) {
        // Coverage becomes the default way the next band unlocks. Only the
        // column default moves: every activity that already exists keeps the
        // mode its teacher chose, because changing how an activity paces itself
        // underneath a running course would be a change nobody asked for.
        $table = new xmldb_table('rememberme');
        $field = new xmldb_field(
            'unlockmode',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            '2',
            'newperday'
        );
        $dbman->change_field_default($table, $field);

        upgrade_mod_savepoint(true, 2026090107, 'rememberme');
    }

    if ($oldversion < 2026090108) {
        // Weeks are graded on study days rather than on an item count target.
        // Nothing is dropped: the old target and count stay on every week row,
        // and the new figures go in new columns beside them.
        $table = new xmldb_table('rememberme');
        $fields = [
            new xmldb_field('studydays', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '3', 'maxchoices'),
            new xmldb_field('studydaysfrom', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'studydays'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $table = new xmldb_table('rememberme_weeks');
        $fields = [
            new xmldb_field('daysrequired', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'suspended'),
            new xmldb_field('daysstudied', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'daysrequired'),
            new xmldb_field('clearedmask', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'daysstudied'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Every activity that already exists switches now. A week that ended
        // before this moment was worked under the old rule, so it is never
        // scored lower than the old rule scored it.
        $DB->set_field('rememberme', 'studydaysfrom', time(), ['studydaysfrom' => 0]);

        // Rescoring reads the review log and pushes grades, which is too much
        // work for an upgrade step and needs the current code, so it is queued.
        foreach ($DB->get_fieldset_select('rememberme', 'id', '1 = 1') as $instanceid) {
            \mod_rememberme\task\recalculate_weeks::queue((int)$instanceid);
        }

        upgrade_mod_savepoint(true, 2026090108, 'rememberme');
    }

    if ($oldversion < 2026090109) {
        // The graded period is set as a start and an end of term rather than a
        // start and a number of weeks. Existing activities end exactly where
        // their weeks did, so no week moves and nothing is regraded by this.
        $table = new xmldb_table('rememberme');
        $field = new xmldb_field('termend', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'activeweeks');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $DB->execute(
            'UPDATE {rememberme} SET termend = coursestart + activeweeks * :week WHERE termend = 0',
            ['week' => WEEKSECS]
        );

        upgrade_mod_savepoint(true, 2026090109, 'rememberme');
    }

    if ($oldversion < 2026090110) {
        // Which week records were written under the item count rule is stated
        // on the record rather than inferred from timestamps: a restore into a
        // course with later dates moves one timestamp and not the other, and
        // the inference then treated new records as old ones.
        $table = new xmldb_table('rememberme_weeks');
        $field = new xmldb_field('legacy', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'clearedmask');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // At this point every record older than the switch was written by the
        // old release, whether the switch happened in this run or an earlier one.
        $DB->execute(
            'UPDATE {rememberme_weeks}
                SET legacy = 1
              WHERE snapshottaken < (SELECT r.studydaysfrom FROM {rememberme} r WHERE r.id = {rememberme_weeks}.rememberme)'
        );

        upgrade_mod_savepoint(true, 2026090110, 'rememberme');
    }

    if ($oldversion < 2026090111) {
        // Answers are timed to the millisecond. Existing slots keep their
        // whole-second stamp, which latency falls back to.
        $table = new xmldb_table('rememberme_slot');
        $field = new xmldb_field('timeshownms', XMLDB_TYPE_INTEGER, '15', null, XMLDB_NOTNULL, null, '0', 'timeshown');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Terms are capped at REMEMBERME_MAX_TERM_WEEKS from now on, and an
        // activity saved before the cap could hold any number of weeks.
        $DB->execute(
            'UPDATE {rememberme}
                SET activeweeks = :maxweeks, termend = coursestart + :maxseconds
              WHERE activeweeks > :maxweeks2',
            ['maxweeks' => 520, 'maxseconds' => 520 * WEEKSECS, 'maxweeks2' => 520]
        );

        upgrade_mod_savepoint(true, 2026090111, 'rememberme');
    }

    if ($oldversion < 2026090112) {
        // The recalculate grades capability arrives with this version; there is
        // nothing to migrate.
        upgrade_mod_savepoint(true, 2026090112, 'rememberme');
    }

    if ($oldversion < 2026090113) {
        // Grades are now a share of the whole term rather than an average of
        // the weeks so far, which had put every learner who had answered
        // anything in week one at 100%. Nothing stored changes; the gradebook
        // is brought up to date by rescoring every activity.
        foreach ($DB->get_fieldset_select('rememberme', 'id', '1 = 1') as $instanceid) {
            \mod_rememberme\task\recalculate_weeks::queue((int)$instanceid);
        }
        upgrade_mod_savepoint(true, 2026090113, 'rememberme');
    }

    if ($oldversion < 2026090114) {
        // A second way of grading, by band establishment. Every existing
        // activity keeps grading on study days, which is the default.
        $table = new xmldb_table('rememberme');
        $field = new xmldb_field('gradingmethod', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'studydaysfrom');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $table = new xmldb_table('rememberme_bandstate');
        $field = new xmldb_field('bestprogress', XMLDB_TYPE_TEXT, null, null, null, null, null, 'lastunlockwindow');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026090114, 'rememberme');
    }

    return true;
}
