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
 * One delivery session: the questions a learner is being asked right now.
 *
 * Rendering and grading are done by the core question engine rather than
 * reimplemented here. That is what makes every question type work, including
 * ones this plugin's author has never seen, and it is why a session is backed by
 * a question_usage_by_activity.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session {
    /** @var string The behaviour that grades a single submission and shows the outcome at once. */
    public const BEHAVIOUR = 'immediatefeedback';

    /** @var \stdClass The activity instance. */
    protected \stdClass $instance;

    /** @var \context The module context. */
    protected \context $context;

    /** @var scheduler The scheduler service. */
    protected scheduler $scheduler;

    /** @var \stdClass|null The session record. */
    protected ?\stdClass $record = null;

    /** @var \question_usage_by_activity|null The question engine usage. */
    protected ?\question_usage_by_activity $quba = null;

    /** @var bool Whether JavaScript requirement collection has started this request. */
    protected bool $collecting = false;

    /**
     * Constructor.
     *
     * @param \stdClass $instance The rememberme instance record.
     * @param \context $context The module context.
     * @param scheduler|null $scheduler The scheduler, or null to build one.
     */
    public function __construct(\stdClass $instance, \context $context, ?scheduler $scheduler = null) {
        global $CFG;

        // The question engine is not autoloaded: question_engine, question_bank
        // and question_display_options all live in files that must be required
        // explicitly. Unit tests do not catch this, because the test bootstrap
        // has already loaded half of core, so it only shows up on a real page.
        require_once($CFG->dirroot . '/question/engine/lib.php');
        require_once($CFG->libdir . '/questionlib.php');

        $this->instance = $instance;
        $this->context = $context;
        $this->scheduler = $scheduler ?? new scheduler($instance);
    }

    /**
     * Get the scheduler.
     *
     * @return scheduler The scheduler.
     */
    public function get_scheduler(): scheduler {
        return $this->scheduler;
    }

    /**
     * Get the session record, if one is loaded.
     *
     * @return \stdClass|null The record.
     */
    public function get_record(): ?\stdClass {
        return $this->record;
    }

    /**
     * Get the question engine usage.
     *
     * @return \question_usage_by_activity|null The usage.
     */
    public function get_quba(): ?\question_usage_by_activity {
        return $this->quba;
    }

    /**
     * Resume the learner's unfinished session, or start a new one.
     *
     * @param int $userid The learner.
     * @param int|null $now Current time, or null for now.
     * @return bool True if there is a session to work on, false if nothing is due.
     */
    public function load_or_start(int $userid, ?int $now = null): bool {
        global $DB;

        $now = $now ?? time();

        $existing = $DB->get_records_select(
            'rememberme_session',
            'rememberme = :instanceid AND userid = :userid AND timefinished = 0',
            ['instanceid' => $this->instance->id, 'userid' => $userid],
            'timecreated DESC',
            '*',
            0,
            1
        );

        if (!empty($existing)) {
            $this->record = reset($existing);
            $this->quba = \question_engine::load_questions_usage_by_activity((int)$this->record->uniqueid);
            if ($this->next_slot() !== null) {
                return true;
            }
            // Everything in the resumed session is answered, so close it and
            // consider starting a fresh one.
            $this->finish($now);
        }

        return $this->start($userid, $now);
    }

    /**
     * Start a new session for a learner.
     *
     * @param int $userid The learner.
     * @param int|null $now Current time, or null for now.
     * @return bool True if a session was started, false if nothing was due.
     */
    public function start(int $userid, ?int $now = null): bool {
        global $DB;

        $now = $now ?? time();
        $queue = $this->scheduler->get_due_questions($userid, null, $now);
        if (empty($queue)) {
            return false;
        }

        $quba = \question_engine::make_questions_usage_by_activity('mod_rememberme', $this->context);
        $quba->set_preferred_behaviour(self::BEHAVIOUR);

        $slots = [];
        foreach ($queue as $entry) {
            try {
                $question = \question_bank::load_question($entry->questionid);
            } catch (\Throwable $e) {
                // A question that will not load must not take the whole session
                // down; skip it and carry on with the rest of the queue.
                continue;
            }
            $slot = $quba->add_question($question, 1.0);
            $slots[$slot] = $entry;
        }

        if (empty($slots)) {
            return false;
        }

        $quba->start_all_questions();
        \question_engine::save_questions_usage_by_activity($quba);

        $record = (object)[
            'rememberme' => $this->instance->id,
            'userid' => $userid,
            'uniqueid' => $quba->get_id(),
            'itemcount' => count($slots),
            'answered' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timefinished' => 0,
        ];
        $record->id = $DB->insert_record('rememberme_session', $record);

        foreach ($slots as $slot => $entry) {
            $DB->insert_record('rememberme_slot', (object)[
                'sessionid' => $record->id,
                'slot' => $slot,
                'questionbankentryid' => $entry->questionbankentryid,
                'questionid' => $entry->questionid,
                'bandlevel' => $entry->bandlevel,
                'isnew' => $entry->isnew ? 1 : 0,
                'graded' => 0,
                'timeshown' => 0,
            ]);
        }

        $this->record = $record;
        $this->quba = $quba;
        return true;
    }

    /**
     * The slot rows for this session.
     *
     * @return array Slot records keyed by slot number.
     */
    public function get_slot_records(): array {
        global $DB;

        if ($this->record === null) {
            return [];
        }

        $records = $DB->get_records('rememberme_slot', ['sessionid' => $this->record->id], 'slot ASC');

        // Key by the question engine slot number rather than the row id: the
        // slot is what every question engine call expects, and confusing the two
        // fails deep inside the engine rather than here.
        $byslot = [];
        foreach ($records as $record) {
            $byslot[(int)$record->slot] = $record;
        }
        return $byslot;
    }

    /**
     * The next unanswered slot, or null when the session is done.
     *
     * @return int|null The slot number.
     */
    public function next_slot(): ?int {
        foreach ($this->get_slot_records() as $slot => $record) {
            if (empty($record->graded)) {
                return (int)$slot;
            }
        }
        return null;
    }

    /**
     * How many items in this session have been answered.
     *
     * @return array Two element list of answered count and total count.
     */
    public function get_progress(): array {
        $records = $this->get_slot_records();
        $answered = 0;
        foreach ($records as $record) {
            if (!empty($record->graded)) {
                $answered++;
            }
        }
        return [$answered, count($records)];
    }

    /**
     * Display options for rendering a question the learner is answering.
     *
     * @return \question_display_options The options.
     */
    protected function attempt_options(): \question_display_options {
        $options = new \question_display_options();
        $options->flags = \question_display_options::HIDDEN;
        $options->marks = \question_display_options::HIDDEN;
        $options->rightanswer = \question_display_options::HIDDEN;
        $options->generalfeedback = \question_display_options::HIDDEN;
        $options->feedback = \question_display_options::HIDDEN;
        $options->correctness = \question_display_options::HIDDEN;
        $options->manualcomment = \question_display_options::HIDDEN;
        $options->manualcommentlink = \question_display_options::HIDDEN;
        // Setting this explicitly avoids a question_edit_contexts lookup during
        // rendering, which needs a page context we may not have off-page.
        $options->versioninfo = false;
        return $options;
    }

    /**
     * Display options for rendering the feedback after an answer.
     *
     * The correct answer is shown on an incorrect response, which is the whole
     * point of the pause before auto advancing.
     *
     * @return \question_display_options The options.
     */
    protected function feedback_options(): \question_display_options {
        $options = $this->attempt_options();
        $options->readonly = true;
        $options->correctness = \question_display_options::VISIBLE;
        $options->feedback = \question_display_options::VISIBLE;
        $options->generalfeedback = \question_display_options::VISIBLE;
        $options->rightanswer = \question_display_options::VISIBLE;
        return $options;
    }

    /**
     * Render one question, together with the JavaScript it needs.
     *
     * Question rendering registers its JavaScript as page requirements rather
     * than returning it inline, so returning only the HTML over AJAX would drop
     * every question type's behaviour on the floor: multiple choice would not
     * clear a choice, drag and drop would not drag. The requirements are
     * collected here and handed to the client to run alongside the markup.
     *
     * @param int $slot The slot to render.
     * @param bool $feedback Whether to render the graded feedback view.
     * @return array Two element list of html and javascript.
     */
    public function render_slot(int $slot, bool $feedback = false): array {
        global $PAGE, $OUTPUT, $DB;

        $options = $feedback ? $this->feedback_options() : $this->attempt_options();

        // Collecting JavaScript requirements is only possible once the theme has
        // been initialised, which on a normal page happens inside header(). A web
        // service never calls header(), so it has to be done explicitly or the
        // requirements manager does not exist yet and rendering dies.
        $PAGE->initialise_theme_and_output();

        // Only one collection may be started per request. A second render in the
        // same request, such as returning feedback after grading, reuses the
        // collector and simply asks for whatever JavaScript has accumulated
        // since the last call.
        if (!$this->collecting) {
            $PAGE->start_collecting_javascript_requirements();
            $this->collecting = true;
        }

        $html = $this->quba->render_question($slot, $options);
        $javascript = $PAGE->requires->get_end_code();

        if (!$feedback) {
            // Latency is measured from the moment the server rendered the
            // question, not from a timestamp the client supplies, because a
            // client supplied duration is trivially forgeable and this one feeds
            // the scheduling signal.
            $DB->set_field(
                'rememberme_slot',
                'timeshown',
                time(),
                ['sessionid' => $this->record->id, 'slot' => $slot]
            );
        }

        return [$html, $javascript];
    }

    /**
     * Process a submitted response for one slot.
     *
     * State is written per question as it is answered rather than batched at the
     * end of the session, so a learner who closes the tab after three questions
     * keeps the scheduling effect of those three.
     *
     * @param int $slot The slot being answered.
     * @param array $postdata The submitted form data for that question.
     * @param int|null $now Current time, or null for now.
     * @return array Result carrying correct, fraction, rating and html.
     */
    public function process_response(int $slot, array $postdata, ?int $now = null): array {
        global $DB;

        $now = $now ?? time();

        $slotrecord = $DB->get_record('rememberme_slot', [
            'sessionid' => $this->record->id,
            'slot' => $slot,
        ], '*', MUST_EXIST);

        if (!empty($slotrecord->graded)) {
            throw new \moodle_exception('erroralreadyanswered', 'rememberme');
        }

        // The behaviour only grades when it sees its own submit action, so make
        // sure it is present however the client serialised the form.
        $prefix = $this->quba->get_field_prefix($slot);
        $postdata[$prefix . '-submit'] = 1;

        // The sequence check is mandatory: without it process_all_actions
        // silently skips the slot rather than failing. Silently skipping would
        // leave the question ungraded, and an ungraded question reads back as a
        // zero fraction, which would record a lapse the learner never made and
        // corrupt their memory state. So it is supplied and then the outcome is
        // asserted below rather than assumed.
        $sequencekey = $prefix . ':sequencecheck';
        if (!array_key_exists($sequencekey, $postdata)) {
            $postdata[$sequencekey] = $this->quba->get_question_attempt($slot)->get_sequence_check_count();
        }

        $this->quba->process_all_actions($now, $postdata);

        if (!$this->quba->get_question_state($slot)->is_finished()) {
            $this->quba->finish_question($slot, $now);
        }

        \question_engine::save_questions_usage_by_activity($this->quba);

        $state = $this->quba->get_question_state($slot);
        $fraction = $this->quba->get_question_fraction($slot);

        // A question that came back ungraded means the response never reached
        // the behaviour. Recording that as a wrong answer would be worse than
        // failing, because the learner would silently lose memory state.
        if (!$state->is_graded() || $fraction === null) {
            throw new \moodle_exception('errornotgraded', 'rememberme');
        }

        $latency = null;
        if (!empty($slotrecord->timeshown)) {
            $latency = max(0, ($now - (int)$slotrecord->timeshown)) * 1000;
        }

        $qtype = $this->quba->get_question($slot, false)->get_type_name();

        $this->scheduler->record_attempt(
            (int)$this->record->userid,
            (int)$slotrecord->questionbankentryid,
            (int)$slotrecord->questionid,
            $qtype,
            (float)$fraction,
            $latency,
            (int)$slotrecord->bandlevel,
            $now
        );

        $DB->set_field('rememberme_slot', 'graded', 1, ['id' => $slotrecord->id]);
        $DB->update_record('rememberme_session', (object)[
            'id' => $this->record->id,
            'answered' => (int)$this->record->answered + 1,
            'timemodified' => $now,
        ]);
        $this->record->answered = (int)$this->record->answered + 1;

        $state = $this->quba->get_question_state($slot);

        return [
            'fraction' => (float)$fraction,
            'correct' => $state->is_correct() === true,
            'state' => (string)$state,
        ];
    }

    /**
     * Close this session.
     *
     * @param int|null $now Current time, or null for now.
     */
    public function finish(?int $now = null): void {
        global $DB;

        if ($this->record === null) {
            return;
        }
        $now = $now ?? time();
        $DB->set_field('rememberme_session', 'timefinished', $now, ['id' => $this->record->id]);
        $this->record->timefinished = $now;
    }
}
