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
 * External function declarations for mod_rememberme.
 *
 * Note that Moodle only re-reads this file when the plugin's numeric version
 * increases, so any change here must be accompanied by a version bump or the
 * new entry never reaches the external_functions table.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_rememberme_get_question' => [
        'classname' => 'mod_rememberme\external\get_question',
        'methodname' => 'execute',
        'description' => 'Get the next question due for the current user.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/rememberme:attempt',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_rememberme_submit_answer' => [
        'classname' => 'mod_rememberme\external\submit_answer',
        'methodname' => 'execute',
        'description' => 'Grade a submitted answer and reschedule the question.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/rememberme:attempt',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
