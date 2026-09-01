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

    return true;
}
