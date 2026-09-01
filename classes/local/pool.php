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
 * Resolves the question pool bound to an activity instance.
 *
 * The pool is resolved at delivery time rather than snapshotted at
 * configuration time, so questions a teacher adds mid term are picked up
 * automatically without anybody having to re-save the activity.
 *
 * Identity is the question bank entry, not the versioned question. Since Moodle
 * 4.0 the bank separates a stable entry from its versions, and editing a
 * question creates a new version under the same entry. Keying the schedule on
 * the entry means a teacher fixing a typo does not orphan every learner's
 * memory state for that item. The version actually served is recorded on each
 * review log row, so a mid course edit is still visible to a teacher.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pool {
    /** @var \stdClass The activity instance. */
    protected \stdClass $instance;

    /** @var array|null Cached band definitions. */
    protected ?array $bands = null;

    /**
     * Constructor.
     *
     * @param \stdClass $instance The rememberme instance record.
     */
    public function __construct(\stdClass $instance) {
        $this->instance = $instance;
    }

    /**
     * The ordered bands bound to this instance.
     *
     * @return array Band records in teacher defined order, indexed from 1.
     */
    public function get_bands(): array {
        global $DB;

        if ($this->bands === null) {
            $records = $DB->get_records(
                'rememberme_bands',
                ['rememberme' => $this->instance->id],
                'bandnumber ASC, sortorder ASC'
            );

            // A band may draw on several categories, so rows are grouped by
            // band number rather than being one row each. The numbers are then
            // compacted to 1..n, so a teacher deleting the middle band does not
            // leave a hole that unlocking would have to step over.
            $grouped = [];
            foreach ($records as $record) {
                $grouped[(int)$record->bandnumber][] = $record;
            }
            ksort($grouped);

            $this->bands = [];
            $level = 1;
            foreach ($grouped as $rows) {
                $this->bands[$level] = $rows;
                $level++;
            }
        }
        return $this->bands;
    }

    /**
     * How many bands this instance has.
     *
     * @return int The band count.
     */
    public function get_band_count(): int {
        return count($this->get_bands());
    }

    /**
     * Every question category id covered by one band.
     *
     * @param int $level The band level, 1 based.
     * @return array List of category ids.
     */
    public function get_band_category_ids(int $level): array {
        $bands = $this->get_bands();
        if (!isset($bands[$level])) {
            return [];
        }

        $ids = [];
        foreach ($bands[$level] as $row) {
            $ids[] = (int)$row->questioncategoryid;
            if (!empty($row->includesubcategories)) {
                $ids = array_merge($ids, self::descendant_category_ids((int)$row->questioncategoryid));
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * All descendant category ids of a question category.
     *
     * @param int $categoryid The parent category.
     * @return array List of descendant category ids.
     */
    protected static function descendant_category_ids(int $categoryid): array {
        global $DB;

        $found = [];
        $frontier = [$categoryid];
        // Bounded walk: question category trees are shallow, but a corrupt
        // parent chain must not spin forever.
        $guard = 0;
        while (!empty($frontier) && $guard < 100) {
            $guard++;
            [$insql, $params] = $DB->get_in_or_equal($frontier, SQL_PARAMS_NAMED, 'p');
            $children = $DB->get_fieldset_select('question_categories', 'id', "parent {$insql}", $params);
            $children = array_map('intval', $children);
            $children = array_diff($children, $found, [$categoryid]);
            if (empty($children)) {
                break;
            }
            $found = array_merge($found, $children);
            $frontier = $children;
        }
        return $found;
    }

    /**
     * The usable questions in a set of categories, one per bank entry.
     *
     * Only the latest ready version of each entry is returned: draft and hidden
     * versions are not shown to learners. Subquestions and random placeholders
     * are excluded, because neither is a thing a learner can be asked on its own.
     *
     * @param array $categoryids Category ids to draw from.
     * @return array Records keyed by questionbankentryid, carrying questionid, qtype and name.
     */
    public function get_entries_in_categories(array $categoryids): array {
        global $DB;

        if (empty($categoryids)) {
            return [];
        }

        [$catsql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');
        $params['ready'] = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $params['readysub'] = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;

        $sql = "SELECT qbe.id AS questionbankentryid,
                       qv.questionid,
                       qv.version,
                       q.qtype,
                       q.name,
                       qbe.questioncategoryid
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qbe.questioncategoryid {$catsql}
                   AND qv.status = :ready
                   AND q.parent = 0
                   AND q.qtype <> 'random'
                   AND qv.version = (SELECT MAX(v.version)
                                       FROM {question_versions} v
                                      WHERE v.questionbankentryid = qbe.id
                                        AND v.status = :readysub)";

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * The usable questions in one band.
     *
     * @param int $level The band level, 1 based.
     * @return array Records keyed by questionbankentryid.
     */
    public function get_entries_in_band(int $level): array {
        return $this->get_entries_in_categories($this->get_band_category_ids($level));
    }

    /**
     * The usable questions across every band up to and including a level.
     *
     * @param int $maxlevel The highest band to include.
     * @return array Records keyed by questionbankentryid.
     */
    public function get_entries_up_to_band(int $maxlevel): array {
        $categoryids = [];
        for ($level = 1; $level <= $maxlevel; $level++) {
            $categoryids = array_merge($categoryids, $this->get_band_category_ids($level));
        }
        return $this->get_entries_in_categories(array_values(array_unique($categoryids)));
    }

    /**
     * Every question in the whole pool, regardless of band.
     *
     * Review is never band restricted: once an item has been seen it competes on
     * memory state alone, whichever band introduced it.
     *
     * @return array Records keyed by questionbankentryid.
     */
    public function get_all_entries(): array {
        return $this->get_entries_up_to_band($this->get_band_count());
    }

    /**
     * Resolve a bank entry to the question version that should be served now.
     *
     * @param int $questionbankentryid The bank entry.
     * @return \stdClass|null Record with questionid and qtype, or null if the entry is gone or unusable.
     */
    public function resolve_entry(int $questionbankentryid): ?\stdClass {
        global $DB;

        $sql = "SELECT qv.questionid, qv.version, q.qtype, q.name
                  FROM {question_versions} qv
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qv.questionbankentryid = :qbeid
                   AND qv.status = :ready
              ORDER BY qv.version DESC";

        $records = $DB->get_records_sql($sql, [
            'qbeid' => $questionbankentryid,
            'ready' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
        ], 0, 1);

        return $records ? reset($records) : null;
    }
}
