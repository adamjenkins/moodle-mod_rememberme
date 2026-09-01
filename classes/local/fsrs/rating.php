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
 * The four review ratings the memory model accepts.
 *
 * The learner never chooses one of these. They are synthesised from the
 * objective grade by a grade mapper, which is the whole point of this plugin:
 * the scheduling signal is what the answer was, not what the learner thought of
 * their own recall.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rating {
    /** @var int Failed recall. The only rating that counts as a lapse. */
    public const AGAIN = 1;

    /** @var int Recalled, but barely. */
    public const HARD = 2;

    /** @var int Recalled. */
    public const GOOD = 3;

    /** @var int Recalled easily. */
    public const EASY = 4;

    /**
     * All valid ratings.
     *
     * @return array List of rating constants.
     */
    public static function all(): array {
        return [self::AGAIN, self::HARD, self::GOOD, self::EASY];
    }

    /**
     * Whether a value is a valid rating.
     *
     * @param int $rating Candidate rating.
     * @return bool True if valid.
     */
    public static function is_valid(int $rating): bool {
        return in_array($rating, self::all(), true);
    }

    /**
     * Whether a rating represents successful recall.
     *
     * Everything except AGAIN lengthens the interval.
     *
     * @param int $rating The rating.
     * @return bool True if the item was recalled.
     */
    public static function is_success(int $rating): bool {
        return $rating !== self::AGAIN;
    }

    /**
     * Short identifier for a rating, for logging and language strings.
     *
     * @param int $rating The rating.
     * @return string One of again, hard, good, easy.
     */
    public static function name(int $rating): string {
        $names = [
            self::AGAIN => 'again',
            self::HARD => 'hard',
            self::GOOD => 'good',
            self::EASY => 'easy',
        ];
        return $names[$rating] ?? 'again';
    }
}
