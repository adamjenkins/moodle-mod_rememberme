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
 * Test data generator for mod_rememberme.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Generator for mod_rememberme instances.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_rememberme_generator extends testing_module_generator {
    /**
     * Create a new instance, filling in the scheduling defaults.
     *
     * @param array|stdClass|null $record Instance settings.
     * @param array|null $options Generator options.
     * @return stdClass The created instance.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;

        $defaults = [
            'targetretention' => 0.9,
            'sessionsize' => 20,
            'newperday' => 10,
            'unlockmode' => 0,
            'unlockinterval' => 7,
            'stabilityfloor' => 14.0,
            'masteryproportion' => 0.7,
            'backstopdays' => 21,
            'coursestart' => time(),
            'activeweeks' => 15,
            'gracebalance' => 1.0,
            'graceearnrate' => 0.25,
            'passthreshold' => 0.5,
            'uselatency' => 1,
            'audiocue' => 1,
            'pausecorrect' => 1200,
            'pauseincorrect' => 2500,
            'grade' => 100,
            'completionweeks' => 0,
        ];

        foreach ($defaults as $name => $value) {
            if (!isset($record->{$name})) {
                $record->{$name} = $value;
            }
        }

        return parent::create_instance($record, (array)$options);
    }

    /**
     * Bind a question category to an instance as a band.
     *
     * @param int $remembermeid The instance id.
     * @param int $questioncategoryid The question category.
     * @param int $sortorder The band order.
     * @param bool $includesubcategories Whether to include subcategories.
     * @return int The new band id.
     */
    public function create_band(
        int $remembermeid,
        int $questioncategoryid,
        int $sortorder = 0,
        bool $includesubcategories = false
    ): int {
        global $DB;

        return $DB->insert_record('rememberme_bands', (object)[
            'rememberme' => $remembermeid,
            'sortorder' => $sortorder,
            'questioncategoryid' => $questioncategoryid,
            'includesubcategories' => $includesubcategories ? 1 : 0,
        ]);
    }

    /**
     * Add a suspension window to an instance.
     *
     * @param int $remembermeid The instance id.
     * @param int $timestart When the window opens.
     * @param int $timeend When the window closes.
     * @param string $name A label for the window.
     * @return int The new window id.
     */
    public function create_suspension(int $remembermeid, int $timestart, int $timeend, string $name = 'Break'): int {
        global $DB;

        return $DB->insert_record('rememberme_suspensions', (object)[
            'rememberme' => $remembermeid,
            'name' => $name,
            'timestart' => $timestart,
            'timeend' => $timeend,
        ]);
    }
}
