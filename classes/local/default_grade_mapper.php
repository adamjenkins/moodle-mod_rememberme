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

use mod_rememberme\local\fsrs\rating;

/**
 * The default objective grade to rating mapping.
 *
 * Written binary first. The correctness signal alone produces a complete,
 * correct rating; latency is a refinement layer applied afterwards and only
 * when it is trustworthy. That ordering is deliberate, so the degraded mode the
 * design requires is the normal code path rather than an untested branch.
 *
 * Latency can only ever separate good from easy. It must never turn a correct
 * answer into a lapse, because a slow correct answer is still recall.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class default_grade_mapper implements grade_mapper {
    /** @var float Fraction at or above which a partially correct answer counts as recall. */
    protected float $passthreshold;

    /** @var bool Whether latency may refine the rating at all. */
    protected bool $uselatency;

    /**
     * Constructor.
     *
     * @param float $passthreshold Partial credit at or above this counts as hard rather than again.
     * @param bool $uselatency Whether to use latency when it is available and trustworthy.
     */
    public function __construct(float $passthreshold = 0.5, bool $uselatency = true) {
        $this->passthreshold = min(1.0, max(0.0, $passthreshold));
        $this->uselatency = $uselatency;
    }

    /**
     * Derive a review rating from a graded response.
     *
     * @param float $fraction The fraction earned, normally 0 to 1.
     * @param float|null $latencyseconds Seconds taken to answer, or null if unusable.
     * @param float|null $medianlatency The learner's rolling median for this question type, or null.
     * @return int A rating constant from the rating class.
     */
    public function map(float $fraction, ?float $latencyseconds, ?float $medianlatency): int {
        if (!is_finite($fraction)) {
            $fraction = 0.0;
        }

        // Wrong, or partially right but below the threshold, is a lapse.
        if ($fraction <= 0.0) {
            return rating::AGAIN;
        }
        if ($fraction < 1.0) {
            return $fraction >= $this->passthreshold ? rating::HARD : rating::AGAIN;
        }

        // Fully correct. Everything from here can only choose between good and easy.
        if (!$this->uselatency || $latencyseconds === null || $medianlatency === null) {
            return rating::GOOD;
        }
        if (!is_finite($latencyseconds) || !is_finite($medianlatency) || $medianlatency <= 0.0) {
            return rating::GOOD;
        }

        return $latencyseconds <= $medianlatency ? rating::EASY : rating::GOOD;
    }
}
