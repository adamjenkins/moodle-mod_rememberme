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

namespace mod_rememberme\local;

/**
 * Turns an objective grade into a review rating.
 *
 * Implementations are deliberately swappable. Different courses want different
 * thresholds, and researchers using this plugin will want to replace the mapping
 * outright, so no other part of the plugin may assume how a rating was derived.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface grade_mapper {
    /**
     * Derive a review rating from a graded response.
     *
     * @param float $fraction The fraction earned, normally 0 to 1.
     * @param float|null $latencyseconds Seconds taken to answer, or null if unusable.
     * @param float|null $medianlatency The learner's rolling median for this question type, or null.
     * @return int A rating constant from the rating class.
     */
    public function map(float $fraction, ?float $latencyseconds, ?float $medianlatency): int;
}
