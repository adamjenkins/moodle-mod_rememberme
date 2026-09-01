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

namespace mod_rememberme\local\fsrs;

/**
 * Model constants and weights for the FSRS-style scheduler.
 *
 * Every tunable number used by {@see engine} lives here and nowhere else, so the
 * model can be re-tuned without touching any call site. Nothing in this class
 * depends on Moodle.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parameters {
    /** @var float Curvature constant of the forgetting curve. */
    public const FACTOR = 19.0 / 81.0;

    /** @var float Decay exponent of the forgetting curve. Negative by definition. */
    public const DECAY = -0.5;

    /** @var float Lowest difficulty the model will represent. */
    public const MIN_DIFFICULTY = 1.0;

    /** @var float Highest difficulty the model will represent. */
    public const MAX_DIFFICULTY = 10.0;

    /** @var float Lowest stability, in days. Stops a lapse collapsing to zero. */
    public const MIN_STABILITY = 0.01;

    /** @var float Highest stability, in days. Roughly 100 years. */
    public const MAX_STABILITY = 36500.0;

    /** @var float Default target retention when an instance does not set one. */
    public const DEFAULT_RETENTION = 0.9;

    /**
     * Default model weights.
     *
     * These are the published FSRS-5 defaults. They are a starting point for a
     * cohort, not a tuned result: the review log exists so they can be refitted
     * against real data later.
     *
     * @var array
     */
    public const DEFAULT_WEIGHTS = [
        0.40255, 1.18385, 3.173, 15.69105, 7.1949, 0.5345, 1.4604, 0.0046,
        1.54575, 0.1192, 1.01925, 1.9395, 0.11, 0.29605, 2.2698, 0.2315,
        2.9898, 0.51655, 0.6621,
    ];

    /** @var int How many weights the model expects. */
    public const WEIGHT_COUNT = 19;

    /** @var array The weights in use. */
    protected array $weights;

    /** @var float Target retention, between 0 and 1 exclusive. */
    protected float $retention;

    /**
     * Constructor.
     *
     * @param array|null $weights Model weights, or null for the defaults.
     * @param float|null $retention Target retention, or null for the default.
     */
    public function __construct(?array $weights = null, ?float $retention = null) {
        $this->weights = $this->validate_weights($weights ?? self::DEFAULT_WEIGHTS);
        $this->retention = $this->validate_retention($retention ?? self::DEFAULT_RETENTION);
    }

    /**
     * Check a weight set is usable, falling back to the defaults if it is not.
     *
     * A malformed weight set must never take the scheduler down mid-session, so
     * this repairs rather than throws.
     *
     * @param array $weights Candidate weights.
     * @return array Usable weights.
     */
    protected function validate_weights(array $weights): array {
        if (count($weights) !== self::WEIGHT_COUNT) {
            return self::DEFAULT_WEIGHTS;
        }
        $clean = [];
        foreach ($weights as $weight) {
            if (!is_numeric($weight) || !is_finite((float)$weight)) {
                return self::DEFAULT_WEIGHTS;
            }
            $clean[] = (float)$weight;
        }
        return $clean;
    }

    /**
     * Clamp a target retention into the open interval the model can invert.
     *
     * @param float $retention Candidate retention.
     * @return float Usable retention.
     */
    protected function validate_retention(float $retention): float {
        if (!is_finite($retention) || $retention <= 0.0 || $retention >= 1.0) {
            return self::DEFAULT_RETENTION;
        }
        return $retention;
    }

    /**
     * Get one weight by index.
     *
     * @param int $index Weight index, 0 based.
     * @return float The weight.
     */
    public function w(int $index): float {
        return $this->weights[$index];
    }

    /**
     * Get all weights.
     *
     * @return array The weights.
     */
    public function get_weights(): array {
        return $this->weights;
    }

    /**
     * Get the target retention.
     *
     * @return float Target retention.
     */
    public function get_retention(): float {
        return $this->retention;
    }
}
