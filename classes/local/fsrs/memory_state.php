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
 * The two latent variables the scheduler stores for one learner and one item.
 *
 * Stability and difficulty are the authoritative state. The interval is
 * deliberately absent: it is derived from stability whenever an item is
 * scheduled, because storing a derived value invites drift when the model is
 * re-tuned. Nothing in this class depends on Moodle.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class memory_state {
    /** @var float Days at which retrievability decays to the target threshold. */
    protected float $stability;

    /** @var float Intrinsic hardness of this item for this learner, 1 to 10. */
    protected float $difficulty;

    /**
     * Constructor.
     *
     * Values are clamped on construction, so an invalid state cannot exist.
     *
     * @param float $stability Stability in days.
     * @param float $difficulty Difficulty between 1 and 10.
     */
    public function __construct(float $stability, float $difficulty) {
        $this->stability = self::clamp_stability($stability);
        $this->difficulty = self::clamp_difficulty($difficulty);
    }

    /**
     * Get stability in days.
     *
     * @return float Stability.
     */
    public function get_stability(): float {
        return $this->stability;
    }

    /**
     * Get difficulty.
     *
     * @return float Difficulty between 1 and 10.
     */
    public function get_difficulty(): float {
        return $this->difficulty;
    }

    /**
     * Clamp a stability into the representable range.
     *
     * Also repairs NAN and INF, which can otherwise be written to the database
     * and poison every later calculation for that item.
     *
     * @param float $stability Candidate stability.
     * @return float Clamped stability.
     */
    public static function clamp_stability(float $stability): float {
        if (!is_finite($stability)) {
            return parameters::MIN_STABILITY;
        }
        return min(parameters::MAX_STABILITY, max(parameters::MIN_STABILITY, $stability));
    }

    /**
     * Clamp a difficulty into the representable range.
     *
     * @param float $difficulty Candidate difficulty.
     * @return float Clamped difficulty.
     */
    public static function clamp_difficulty(float $difficulty): float {
        if (!is_finite($difficulty)) {
            return parameters::MIN_DIFFICULTY;
        }
        return min(parameters::MAX_DIFFICULTY, max(parameters::MIN_DIFFICULTY, $difficulty));
    }

    /**
     * Whether this state is materially the same as another.
     *
     * @param memory_state $other State to compare with.
     * @param float $epsilon Tolerance.
     * @return bool True if equal within tolerance.
     */
    public function equals(memory_state $other, float $epsilon = 1.0E-9): bool {
        return abs($this->stability - $other->get_stability()) < $epsilon
            && abs($this->difficulty - $other->get_difficulty()) < $epsilon;
    }
}
