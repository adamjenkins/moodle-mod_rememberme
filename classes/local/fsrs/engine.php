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
 * The FSRS-style memory model.
 *
 * This class contains the entire scheduling mathematics and has no Moodle
 * dependency of any kind: no database, no globals, no configuration lookups. It
 * takes a memory state and a rating and returns a new memory state. That
 * isolation is deliberate, so the model can be unit tested on its own and
 * swapped out later.
 *
 * The distinction from SM-2 that drives the design: stability and difficulty are
 * separate latent variables, and a lapse reduces stability sharply without
 * discarding it. A long-known item that slips once returns sooner than a brand
 * new one, instead of being reset to day one.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class engine {
    /** @var parameters Model constants and weights. */
    protected parameters $params;

    /**
     * Constructor.
     *
     * @param parameters|null $params Model parameters, or null for the defaults.
     */
    public function __construct(?parameters $params = null) {
        $this->params = $params ?? new parameters();
    }

    /**
     * Get the parameters in use.
     *
     * @return parameters The parameters.
     */
    public function get_parameters(): parameters {
        return $this->params;
    }

    /**
     * Probability the item is still retrievable after a given elapsed time.
     *
     * R(t, S) = (1 + FACTOR * t / S) ^ DECAY
     *
     * @param float $stability Stability in days.
     * @param float $elapseddays Elapsed time in days. Must already be effective time.
     * @return float Retrievability between 0 and 1.
     */
    public function retrievability(float $stability, float $elapseddays): float {
        $stability = memory_state::clamp_stability($stability);
        $elapseddays = max(0.0, $elapseddays);
        $r = pow(1.0 + parameters::FACTOR * $elapseddays / $stability, parameters::DECAY);
        if (!is_finite($r)) {
            return 0.0;
        }
        return min(1.0, max(0.0, $r));
    }

    /**
     * The interval at which retrievability falls to the target retention.
     *
     * I = (S / FACTOR) * (R_target ^ (1 / DECAY) - 1)
     *
     * The interval is never stored. It is derived here every time an item is
     * scheduled, so that re-tuning the model does not leave stale intervals
     * behind in the database.
     *
     * @param float $stability Stability in days.
     * @return float Interval in days, at least a fraction of a day.
     */
    public function interval_for(float $stability): float {
        $stability = memory_state::clamp_stability($stability);
        $retention = $this->params->get_retention();
        $interval = ($stability / parameters::FACTOR) * (pow($retention, 1.0 / parameters::DECAY) - 1.0);
        if (!is_finite($interval) || $interval < 0.0) {
            return parameters::MIN_STABILITY;
        }
        return min(parameters::MAX_STABILITY, max(parameters::MIN_STABILITY, $interval));
    }

    /**
     * Seed a memory state for an item the learner has never seen.
     *
     * Records are created on first attempt rather than speculatively for every
     * question in the pool, so the table stays proportional to real activity.
     * A first attempt that fails seeds low stability and elevated difficulty; a
     * first attempt that succeeds seeds higher stability.
     *
     * @param int $rating The rating the first attempt produced.
     * @return memory_state The seeded state.
     */
    public function seed(int $rating): memory_state {
        if (!rating::is_valid($rating)) {
            $rating = rating::AGAIN;
        }
        $stability = $this->params->w($rating - 1);
        $difficulty = $this->params->w(4) - exp($this->params->w(5) * ($rating - 1)) + 1.0;
        return new memory_state($stability, $difficulty);
    }

    /**
     * Apply one graded review to a memory state.
     *
     * @param memory_state $state The state before this review.
     * @param int $rating The rating derived from the objective grade.
     * @param float $elapseddays Effective days since the last review (see effective_time).
     * @return memory_state The state after this review.
     */
    public function update(memory_state $state, int $rating, float $elapseddays): memory_state {
        if (!rating::is_valid($rating)) {
            $rating = rating::AGAIN;
        }
        $retrievability = $this->retrievability($state->get_stability(), $elapseddays);
        $difficulty = $this->next_difficulty($state->get_difficulty(), $rating);

        if (rating::is_success($rating)) {
            $stability = $this->stability_after_success(
                $state->get_stability(),
                $state->get_difficulty(),
                $retrievability,
                $rating
            );
        } else {
            $stability = $this->stability_after_lapse(
                $state->get_stability(),
                $state->get_difficulty(),
                $retrievability
            );
        }

        return new memory_state($stability, $difficulty);
    }

    /**
     * Update difficulty, with mean reversion toward the model default.
     *
     * Difficulty rises on a lapse and falls on an easy success. The reversion
     * term stops it drifting monotonically in either direction over a long
     * course, which would otherwise make every item eventually maximally hard or
     * maximally easy.
     *
     * @param float $difficulty Difficulty before the review.
     * @param int $rating The rating.
     * @return float Difficulty after the review.
     */
    public function next_difficulty(float $difficulty, int $rating): float {
        $delta = -$this->params->w(6) * ($rating - 3);
        // Linear damping: a difficulty already near the ceiling moves less.
        $damped = $difficulty + $delta * ((10.0 - $difficulty) / 9.0);
        // Mean reversion toward the difficulty an easy first attempt would seed.
        $target = $this->params->w(4) - exp($this->params->w(5) * (rating::EASY - 1)) + 1.0;
        $reverted = $this->params->w(7) * $target + (1.0 - $this->params->w(7)) * $damped;
        return memory_state::clamp_difficulty($reverted);
    }

    /**
     * Stability after a successful recall.
     *
     * Growth is larger when retrievability was low at review time, because
     * successfully recalling something nearly forgotten is far more informative
     * than recalling something just seen. Growth is smaller when difficulty is
     * high and when stability is already large.
     *
     * @param float $stability Stability before the review.
     * @param float $difficulty Difficulty before the review.
     * @param float $retrievability Retrievability at review time.
     * @param int $rating The rating.
     * @return float Stability after the review.
     */
    public function stability_after_success(
        float $stability,
        float $difficulty,
        float $retrievability,
        int $rating
    ): float {
        $hardpenalty = ($rating === rating::HARD) ? $this->params->w(15) : 1.0;
        $easybonus = ($rating === rating::EASY) ? $this->params->w(16) : 1.0;

        $growth = exp($this->params->w(8))
            * (11.0 - $difficulty)
            * pow($stability, -$this->params->w(9))
            * (exp($this->params->w(10) * (1.0 - $retrievability)) - 1.0)
            * $hardpenalty
            * $easybonus;

        $new = $stability * (1.0 + $growth);
        if (!is_finite($new)) {
            return parameters::MAX_STABILITY;
        }
        // Success must never shorten the interval.
        return memory_state::clamp_stability(max($stability, $new));
    }

    /**
     * Stability after a failed recall.
     *
     * This is the heart of the SM-2 departure. The result is a function of the
     * current stability and difficulty, not a constant and not zero, so
     * accumulated memory strength is partially retained. It is capped at the
     * pre-lapse stability, because a lapse must never lengthen the interval.
     *
     * @param float $stability Stability before the review.
     * @param float $difficulty Difficulty before the review.
     * @param float $retrievability Retrievability at review time.
     * @return float Stability after the lapse.
     */
    public function stability_after_lapse(
        float $stability,
        float $difficulty,
        float $retrievability
    ): float {
        $new = $this->params->w(11)
            * pow($difficulty, -$this->params->w(12))
            * (pow($stability + 1.0, $this->params->w(13)) - 1.0)
            * exp($this->params->w(14) * (1.0 - $retrievability));

        if (!is_finite($new)) {
            return parameters::MIN_STABILITY;
        }
        return memory_state::clamp_stability(min($stability, $new));
    }

    /**
     * Apply symmetric jitter to an interval.
     *
     * Without this, items introduced together stay clustered together forever,
     * producing workload spikes that recur for the whole course. Fuzz is applied
     * once at storage time rather than at query time, so a stored due date stays
     * stable and reproducible.
     *
     * @param float $intervaldays The computed interval in days.
     * @param float $proportion Maximum jitter either way, default 5 per cent.
     * @param int|null $seed Optional deterministic seed, for tests.
     * @return float The fuzzed interval in days.
     */
    public function fuzz_interval(float $intervaldays, float $proportion = 0.05, ?int $seed = null): float {
        if ($intervaldays <= 0.0 || $proportion <= 0.0) {
            return max(0.0, $intervaldays);
        }
        if ($seed !== null) {
            // Deterministic jitter, so a test can assert an exact due date.
            $unit = (($seed % 2001) / 2000.0) * 2.0 - 1.0;
        } else {
            $unit = (mt_rand(0, 2000) / 2000.0) * 2.0 - 1.0;
        }
        $fuzzed = $intervaldays * (1.0 + $proportion * $unit);
        return max(parameters::MIN_STABILITY, $fuzzed);
    }
}
