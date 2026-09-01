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

// Drives one study session inside the Moodle app.
//
// This runs as a site plugin script, so `this` is the compiled component
// instance rather than a module scope: the app attaches its providers to the
// instance by class name, and the plugin content component adds updateContent()
// for re-rendering with new arguments. There is no import mechanism here, which
// is why everything is reached through `this`.

this.question = null;
this.submitting = false;
this.feedbackShown = false;

// The question travels on CONTENT_OTHERDATA. It leaves the server as a JSON
// string, because every otherdata value crosses the wire as one, but it has
// usually been parsed again before it reaches here: the app parses any
// otherdata value whose first character is '{' or '[' as it reads the content
// (CoreSitePluginsProvider.getContent). Parsing a second time throws on the
// object and leaves the question null, and the template's *ngIf then renders a
// session with no question in it. So take whichever form actually arrived.
var rawquestion = this.CONTENT_OTHERDATA ? this.CONTENT_OTHERDATA.question : null;

if (typeof rawquestion === 'string') {
    try {
        this.question = JSON.parse(rawquestion);
    } catch (error) {
        this.question = null;
    }
} else {
    this.question = rawquestion || null;
}

// The tappable options, for a question presented as a hand rather than through
// the app's question component. Absent for every other question type. The app
// has already turned the JSON into an object, as it does for the question.
this.choices = this.CONTENT_OTHERDATA.choices || null;
if (typeof this.choices === 'string') {
    try {
        this.choices = JSON.parse(this.choices);
    } catch (error) {
        this.choices = null;
    }
}

this.picked = null;
this.answering = false;
this.verdict = null;
this.verdictLabel = '';
this.rightLabel = this.CONTENT_OTHERDATA.rightlabel || '';
this.wrongLabel = this.CONTENT_OTHERDATA.wronglabel || '';

this.slot = parseInt(this.CONTENT_OTHERDATA.slot, 10);
this.cmid = parseInt(this.CONTENT_OTHERDATA.cmid, 10);
this.pauseCorrect = parseInt(this.CONTENT_OTHERDATA.pausecorrect, 10) || 1200;
this.pauseIncorrect = parseInt(this.CONTENT_OTHERDATA.pauseincorrect, 10) || 2500;

/**
 * The mark shown on an option: its letter, or the verdict once one is in.
 *
 * One element does the labelling and then the reporting, so the result never
 * rests on colour alone, which matters both for colour blindness and for a
 * phone screen in daylight.
 *
 * @param {Object} choice The option.
 * @returns {String} A letter, a tick, or a cross.
 */
this.tokenFor = function(choice) {
    if (!this.verdict) {
        return choice.letter;
    }
    if (choice.value === this.choices.correctvalue) {
        return '\u2713';
    }
    if (choice.value === this.picked) {
        return '\u2715';
    }

    return choice.letter;
};

/**
 * Answer by tapping an option.
 *
 * There is no confirmation step. A tap is worth one repetition at most: being
 * wrong costs time and never marks, so the cost of a slip is that the question
 * comes round again, which is cheaper than making every learner confirm every
 * answer for the whole course.
 *
 * @param {Number} value The chosen option.
 * @returns {Promise} Resolves once the verdict is on screen.
 */
this.chooseAnswer = async function(value) {
    if (this.answering || this.verdict) {
        return;
    }

    this.answering = true;
    this.picked = value;
    this.refresh();

    try {
        var site = this.CoreSitesProvider.getCurrentSite();
        var result = await site.write('mod_rememberme_submit_answer', {
            cmid: this.cmid,
            slot: this.slot,
            response: [{name: this.choices.name, value: String(value)}],
        });

        this.verdict = result.correct ? 'right' : 'wrong';
        this.verdictLabel = result.correct ? this.rightLabel : this.wrongLabel;
        this.refresh();

        // Move on by itself. Asking for a second tap to continue would put the
        // button back that this presentation exists to remove.
        var component = this;
        setTimeout(function() {
            component.nextQuestion();
        }, result.correct ? this.pauseCorrect : this.pauseIncorrect);
    } catch (error) {
        this.picked = null;
        this.refresh();

        if (this.isAlreadyAnswered(error)) {
            this.nextQuestion();
        } else {
            this.showError(error);
        }
    } finally {
        this.answering = false;
    }
};

/**
 * Report a failed web service call.
 *
 * @param {Object} error The error the app should show.
 */
this.showError = function(error) {
    // CoreAlertsProvider is the current home of showError; older app versions
    // only have CoreDomUtilsProvider, and a site plugin has to run on whatever
    // the learner installed.
    if (this.CoreAlertsProvider) {
        this.CoreAlertsProvider.showError(error);
    } else {
        this.CoreDomUtilsProvider.showErrorModal(error);
    }
};

/**
 * Re draw after changing something the template binds to.
 *
 * A compiled site plugin component is not re rendered just because one of its
 * properties changed: everything after an awaited web service call happens
 * outside the change detection the tap started, so the screen keeps showing the
 * state from before the answer was sent. The app hands every compiled component
 * its own ChangeDetectorRef for exactly this.
 */
this.refresh = function() {
    if (this.ChangeDetectorRef) {
        this.ChangeDetectorRef.detectChanges();
    }
};

/**
 * Find the form the question was rendered into.
 *
 * The app builds native controls from the question and does not put them in a
 * form of its own, so the template supplies one; the app's answer helper reads
 * name and value pairs straight off form.elements, which reaches the native
 * inputs Ionic renders inside its components.
 *
 * The search is anchored on componentContainer, which is the host element the
 * app gives every compiled site plugin component. Falling back to a document
 * wide search would be worse than finding nothing: the first form on the page
 * belongs to some other part of the app, and reading answers out of it submits
 * an empty response that is graded as a wrong answer.
 *
 * @returns {HTMLFormElement|null} The form, or null if it is not on screen yet.
 */
this.findQuestionForm = function() {
    return this.componentContainer
        ? this.componentContainer.querySelector('form.mod_rememberme-question')
        : null;
};

/**
 * Grade the current answer.
 *
 * The answers are collected by the app's own helper rather than by walking the
 * DOM here, so that every question type the app understands is handled the way
 * it expects. Those name and value pairs are exactly what the plugin's
 * submit_answer web service already accepts, so nothing is reshaped.
 *
 * @returns {Promise} Resolves once feedback is on screen.
 */
this.submitAnswer = async function() {
    if (this.submitting || this.feedbackShown) {
        return;
    }

    var form = this.findQuestionForm();
    if (!form) {
        return;
    }

    this.submitting = true;

    try {
        var answers = this.CoreQuestionHelperProvider.getAnswersFromForm(form);
        var response = [];
        for (var name in answers) {
            if (Object.prototype.hasOwnProperty.call(answers, name)) {
                response.push({name: name, value: String(answers[name])});
            }
        }

        var site = this.CoreSitesProvider.getCurrentSite();
        var result = await site.write('mod_rememberme_submit_answer', {
            cmid: this.cmid,
            slot: this.slot,
            response: response,
        });

        // Show the graded question, then let the learner move on. The pause the
        // web view applies automatically is left as an explicit tap here,
        // because an auto advance that fires while a thumb is still travelling
        // is worse on a phone than on a desktop.
        //
        // core-question reads its question once, in ngOnInit, and implements no
        // ngOnChanges, so handing the same component a new object leaves the
        // ungraded question on screen and the answer looks to have been
        // swallowed. Clearing it first makes the template's *ngIf destroy the
        // component, and restoring it in a later task builds a fresh one that
        // reads the graded HTML. The behaviour buttons go with it: the graded
        // HTML has no Check button, and a carried over one would offer to
        // submit an answer that has already been marked.
        var graded = Object.assign({}, this.question, {html: result.html});
        delete graded.behaviourButtons;

        this.question = null;
        this.feedbackShown = true;
        this.refresh();

        var component = this;
        setTimeout(function() {
            component.question = graded;
            component.refresh();
        });
    } catch (error) {
        if (this.isAlreadyAnswered(error)) {
            this.nextQuestion();
        } else {
            this.showError(error);
        }
    } finally {
        this.submitting = false;
    }
};

/**
 * Fetch the next question by re-running the handler.
 *
 * The content must never be read from a cache. updateContent() goes through the
 * app's ordinary cached read of tool_mobile_get_content, and what this handler
 * returns is the next question, which is a different answer to the same call
 * every time it is made. The first call from here missed the cache, because the
 * app's own module page fetches with courseid as well as cmid, and it then
 * stored the reply: every later call was served that stored question, so the
 * session sat on one question for good and answering it again was refused as
 * already answered.
 *
 * The arguments match the ones the module page uses, so the two share a cache
 * entry rather than each holding a stale copy of the other's. Nothing is stored
 * either, which suits an activity that already declares no offline support: a
 * question kept on disk is a question that may have been answered since.
 * componentId is restated because supplying presets replaces them wholesale.
 *
 * @returns {Promise} Resolves once the next question is on screen.
 */
this.nextQuestion = function() {
    this.feedbackShown = false;
    this.picked = null;
    this.verdict = null;
    this.verdictLabel = '';
    this.refresh();

    var args = {cmid: this.cmid};
    if (this.courseId) {
        args.courseid = this.courseId;
    }

    return this.updateContent(args, 'mod_rememberme', 'mobile_course_view', undefined, {
        componentId: this.cmid,
        getFromCache: false,
        saveToCache: false,
        emergencyCache: false,
    });
};

/**
 * Whether a failed answer means the question had already been answered.
 *
 * The honest response to that is to move on rather than to report it. It means
 * the screen is showing a question the server has already dealt with, and the
 * learner did nothing wrong; telling them their answer was rejected would be
 * both alarming and useless, because the same tap would be rejected again.
 *
 * @param {Object} error The error from the web service.
 * @returns {Boolean} True if the question is already behind us.
 */
this.isAlreadyAnswered = function(error) {
    return !!error && error.errorcode === 'erroralreadyanswered';
};

/**
 * Handle a behaviour button rendered inside the question.
 *
 * @param {Object} button The button the learner pressed.
 */
this.questionButtonClicked = function(button) {
    if (button && button.name && button.name.indexOf('submit') !== -1) {
        this.submitAnswer();
    }
};

/**
 * Give up on a question the app could not render.
 *
 * Reloading is the honest response: the scheduler has recorded nothing, so the
 * item simply comes round again rather than being silently marked wrong.
 */
this.abortQuestion = function() {
    this.nextQuestion();
};
