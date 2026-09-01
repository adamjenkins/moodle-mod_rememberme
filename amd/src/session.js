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
 * Drives one study session: answer, feedback, auto advance, without a page reload.
 *
 * The friction of a page load between questions is disproportionate for an
 * activity meant to be visited briefly and often, so the whole cycle runs in
 * place.
 *
 * @module     mod_rememberme/session
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Fragment from 'core/fragment';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';

const SELECTORS = {
    root: '[data-region="rememberme-session"]',
    question: '[data-region="rememberme-question"]',
    status: '[data-region="rememberme-status"]',
    progress: '[data-region="rememberme-progress"]',
    progressbar: '[data-region="rememberme-progressbar"]',
    week: '[data-region="rememberme-week"]',
    streak: '[data-region="rememberme-streak"]',
    audiotoggle: '[data-action="toggle-audio"]',
};

const AUDIO_PREFERENCE_KEY = 'mod_rememberme_audio';

/**
 * A short audio cue, synthesised rather than downloaded.
 *
 * Building the tone in the browser avoids shipping audio assets and keeps the
 * cue instant, which matters when it fires on every answer.
 */
class Cue {
    /**
     * Create the cue player.
     *
     * @param {Boolean} defaultOn Whether audio is on by default for this activity.
     */
    constructor(defaultOn) {
        this.context = null;
        this.enabled = Cue.readPreference(defaultOn);
    }

    /**
     * Read the learner's own preference, falling back to the activity default.
     *
     * Some learners use this in class or on public transport, so the setting is
     * per person and persists between sessions.
     *
     * @param {Boolean} defaultOn The activity default.
     * @returns {Boolean} Whether audio should play.
     */
    static readPreference(defaultOn) {
        try {
            const stored = window.localStorage.getItem(AUDIO_PREFERENCE_KEY);
            if (stored === null) {
                return defaultOn;
            }
            return stored === '1';
        } catch (e) {
            // Private browsing and blocked site data both throw here.
            return defaultOn;
        }
    }

    /**
     * Persist the learner's preference.
     *
     * @param {Boolean} enabled Whether audio is on.
     */
    static writePreference(enabled) {
        try {
            window.localStorage.setItem(AUDIO_PREFERENCE_KEY, enabled ? '1' : '0');
        } catch (e) {
            // Nothing to do: the preference simply will not persist.
        }
    }

    /**
     * Turn the cue on or off.
     *
     * @param {Boolean} enabled Whether audio is on.
     */
    setEnabled(enabled) {
        this.enabled = enabled;
        Cue.writePreference(enabled);
    }

    /**
     * Play the cue for a result.
     *
     * @param {Boolean} correct Whether the answer was correct.
     */
    play(correct) {
        if (!this.enabled) {
            return;
        }
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) {
                return;
            }
            if (this.context === null) {
                this.context = new AudioContextClass();
            }
            const oscillator = this.context.createOscillator();
            const gain = this.context.createGain();
            oscillator.connect(gain);
            gain.connect(this.context.destination);

            // A rising pair for correct, a lower single tone for incorrect.
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(correct ? 660 : 220, this.context.currentTime);
            if (correct) {
                oscillator.frequency.setValueAtTime(880, this.context.currentTime + 0.08);
            }
            gain.gain.setValueAtTime(0.06, this.context.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.0001, this.context.currentTime + 0.25);

            oscillator.start();
            oscillator.stop(this.context.currentTime + 0.26);
        } catch (e) {
            // Audio is a nicety; never let it break the session.
        }
    }
}

/**
 * One study session.
 */
class Session {
    /**
     * Create the session controller.
     *
     * @param {HTMLElement} root The session container.
     */
    constructor(root) {
        this.root = root;
        this.cmid = parseInt(root.dataset.cmid, 10);
        this.slot = null;
        this.busy = false;
        this.cue = new Cue(root.dataset.audio === '1');
        this.pendingTimer = null;
    }

    /**
     * Start the session.
     */
    init() {
        this.renderAudioToggle();
        this.root.addEventListener('submit', (event) => this.onSubmit(event));
        this.root.addEventListener('click', (event) => this.onClick(event));
        this.loadQuestion();
    }

    /**
     * Reflect the current audio preference on the toggle control.
     */
    renderAudioToggle() {
        const toggle = this.root.querySelector(SELECTORS.audiotoggle);
        if (!toggle) {
            return;
        }
        toggle.setAttribute('aria-pressed', this.cue.enabled ? 'true' : 'false');
        getString(this.cue.enabled ? 'audiocueon' : 'audiocueoff', 'rememberme')
            .then((label) => {
                toggle.setAttribute('aria-label', label);
                toggle.title = label;
                return label;
            })
            .catch(() => {
                // A missing label must not stop the session.
            });
    }

    /**
     * Handle clicks on session controls.
     *
     * @param {Event} event The click event.
     */
    onClick(event) {
        const toggle = event.target.closest(SELECTORS.audiotoggle);
        if (toggle) {
            event.preventDefault();
            this.cue.setEnabled(!this.cue.enabled);
            this.renderAudioToggle();
        }
    }

    /**
     * Intercept the question form submission.
     *
     * @param {Event} event The submit event.
     */
    onSubmit(event) {
        event.preventDefault();
        if (this.busy || this.slot === null) {
            return;
        }
        this.submitAnswer(event.target);
    }

    /**
     * Fetch and display the next question.
     *
     * @returns {Promise} Resolves when the question is on screen.
     */
    loadQuestion() {
        this.busy = true;
        // The core/ajax call returns a jQuery Deferred, which has no
        // finally(), so chaining one would throw as the chain is built rather
        // than when it runs. Wrapping it in a native promise keeps the chain
        // safe.
        return Promise.resolve(
            Ajax.call([{
                methodname: 'mod_rememberme_get_question',
                args: {cmid: this.cmid},
            }])[0]
        ).then((response) => {
            if (!response.hasquestion) {
                return this.showComplete(response.message);
            }
            this.slot = response.slot;
            this.updateProgress(response.answered, response.total);
            this.replaceQuestion(response.html, response.javascript);
            this.focusFirstControl();
            return null;
        }).catch((error) => {
            Notification.exception(error);
        }).finally(() => {
            this.busy = false;
        });
    }

    /**
     * Grade the current answer and show the feedback.
     *
     * @param {HTMLFormElement} form The question form.
     * @returns {Promise} Resolves once the next question has been requested.
     */
    submitAnswer(form) {
        this.busy = true;
        const response = Session.serialise(form);

        return Promise.resolve(
            Ajax.call([{
                methodname: 'mod_rememberme_submit_answer',
                args: {cmid: this.cmid, slot: this.slot, response},
            }])[0]
        ).then((result) => {
            this.cue.play(result.correct);
            this.updateProgress(result.answered, result.total);
            this.updateWeek(result.weekdone, result.weektarget, result.streak);
            this.announce(result.correct);

            this.replaceQuestion(result.html, result.javascript);

            // The pause is longer after an incorrect answer because there is
            // more to take in: the learner has to read the right answer.
            this.pendingTimer = window.setTimeout(() => {
                this.loadQuestion();
            }, result.pause);
            return null;
        }).catch((error) => {
            this.busy = false;
            Notification.exception(error);
        });
    }

    /**
     * Swap the question region's contents, running any JavaScript it needs.
     *
     * The rendered question registers its JavaScript as page requirements rather
     * than inline, so the server hands it back separately and it is run here.
     * Without this, question types that need scripting would silently stop
     * working: multiple choice would not clear a choice and drag and drop would
     * not drag.
     *
     * @param {String} html The markup.
     * @param {String} js The JavaScript.
     * @returns {Promise} Resolves once the content is in place.
     */
    replaceQuestion(html, js) {
        const region = this.root.querySelector(SELECTORS.question);
        if (!region) {
            return;
        }
        // The server hands back the JavaScript as full <script> tags, because
        // that is the form the page requirements manager emits. Templates only
        // accepts raw JavaScript, so it is unwrapped here using the same helper
        // core's fragment loader uses.
        Templates.replaceNodeContents(region, html, Fragment.processCollectedJavascript(js));
    }

    /**
     * Move focus to the first control of the new question.
     *
     * Without this a keyboard or screen reader user is left at the top of the
     * document after every auto advance.
     */
    focusFirstControl() {
        const region = this.root.querySelector(SELECTORS.question);
        if (!region) {
            return;
        }
        const control = region.querySelector('input:not([type="hidden"]), select, textarea, button');
        if (control) {
            control.focus();
        }
    }

    /**
     * Announce the result to assistive technology.
     *
     * @param {Boolean} correct Whether the answer was correct.
     */
    announce(correct) {
        const status = this.root.querySelector(SELECTORS.status);
        if (!status) {
            return;
        }
        getString(correct ? 'correct' : 'incorrect', 'rememberme')
            .then((text) => {
                status.textContent = text;
                return text;
            })
            .catch(() => {
                // A missing announcement must not stop the session.
            });
    }

    /**
     * Update the in session progress indicator.
     *
     * @param {Number} answered Questions answered.
     * @param {Number} total Questions in the session.
     */
    updateProgress(answered, total) {
        const bar = this.root.querySelector(SELECTORS.progressbar);
        if (bar && total > 0) {
            const percent = Math.round((answered / total) * 100);
            bar.style.width = percent + '%';
            bar.setAttribute('aria-valuenow', String(answered));
            bar.setAttribute('aria-valuemax', String(total));
        }
    }

    /**
     * Update the weekly progress figures.
     *
     * The weekly target is frozen at the start of the week, so this number never
     * creeps upward as the learner works. That is the whole point of the
     * snapshot rule: a progress indicator that grows as you approach it is worse
     * than none at all.
     *
     * @param {Number} done Items completed this week.
     * @param {Number} target The frozen weekly target.
     * @param {Number} streak Consecutive weeks cleared.
     */
    updateWeek(done, target, streak) {
        const week = this.root.querySelector(SELECTORS.week);
        if (week && target > 0) {
            getString('progressthisweek', 'rememberme', {done, target})
                .then((text) => {
                    week.textContent = text;
                    return text;
                })
                .catch(() => {
                    return null;
                });
        }

        const streakRegion = this.root.querySelector(SELECTORS.streak);
        if (streakRegion && streak > 0) {
            getString('streakweeks', 'rememberme', streak)
                .then((text) => {
                    streakRegion.textContent = text;
                    return text;
                })
                .catch(() => {
                    return null;
                });
        }
    }

    /**
     * Show the end of session state.
     *
     * @param {String} message The message to show.
     * @returns {Promise} Resolves once rendered.
     */
    showComplete(message) {
        this.slot = null;
        return Templates.render('mod_rememberme/session_complete', {message})
            .then((html) => {
                const region = this.root.querySelector(SELECTORS.question);
                region.innerHTML = html;
                return null;
            });
    }

    /**
     * Collect a form's fields as name and value pairs.
     *
     * @param {HTMLFormElement} form The form.
     * @returns {Array} The fields.
     */
    static serialise(form) {
        const fields = [];
        const data = new FormData(form);
        data.forEach((value, name) => {
            if (typeof value === 'string') {
                fields.push({name, value});
            }
        });
        return fields;
    }
}

/**
 * Initialise the session on a page.
 */
export const init = () => {
    const root = document.querySelector(SELECTORS.root);
    if (!root) {
        return;
    }
    new Session(root).init();
};
