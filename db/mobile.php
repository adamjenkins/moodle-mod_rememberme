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
 * Moodle app support for mod_rememberme.
 *
 * The app assumes a live connection. Offline study is deliberately out of scope
 * for this release: it would require queueing attempts locally and replaying
 * them on sync, which raises questions this design does not answer, such as what
 * counts as due while disconnected, how to reconcile a replayed attempt whose
 * elapsed time is now stale, and what happens when the same question is answered
 * on two devices before either syncs.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$addons = [
    'mod_rememberme' => [
        'handlers' => [
            'rememberme' => [
                'displaydata' => [
                    'icon' => $CFG->wwwroot . '/mod/rememberme/pix/monologo.svg',
                    'class' => '',
                ],
                'delegate' => 'CoreCourseModuleDelegate',
                'method' => 'mobile_course_view',
                'offlinefunctions' => [],
                'styles' => [
                    'url' => $CFG->wwwroot . '/mod/rememberme/mobile/styles.css',
                    'version' => 4,
                ],
            ],
        ],
        // Only strings the app resolves on its own behalf belong here. The view
        // template is rendered server side, so its {{#str}} tags are already
        // substituted before the app sees them; listing those here would ship
        // them twice and let the two copies drift.
        'lang' => [
            ['pluginname', 'rememberme'],
        ],
    ],
];
