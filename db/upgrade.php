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

    return true;
}
