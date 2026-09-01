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

// The question arrives as JSON on CONTENT_OTHERDATA, because otherdata values
// cross the wire as strings. The template guards its binding with *ngIf until
// this has run.
try {
    this.question = JSON.parse(this.CONTENT_OTHERDATA.question);
} catch (error) {
    this.question = null;
}

this.slot = parseInt(this.CONTENT_OTHERDATA.slot, 10);
this.cmid = parseInt(this.CONTENT_OTHERDATA.cmid, 10);
this.pauseCorrect = parseInt(this.CONTENT_OTHERDATA.pausecorrect, 10) || 1200;
this.pauseIncorrect = parseInt(this.CONTENT_OTHERDATA.pauseincorrect, 10) || 2500;

/**
 * Find the form the question was rendered into.
 *
 * The app wraps each rendered question in a form so that its own helpers can
 * read the answers back out of it, the same way the web view does.
 *
 * @returns {HTMLFormElement|null} The form, or null if it is not on screen yet.
 */
this.findQuestionForm = function() {
    var host = this.elementRef && this.elementRef.nativeElement
        ? this.elementRef.nativeElement
        : document;

    return host.querySelector('form') || document.querySelector('core-question form');
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
        this.question = Object.assign({}, this.question, {html: result.html});
        this.feedbackShown = true;
    } catch (error) {
        this.CoreAlertsProvider
            ? this.CoreAlertsProvider.showError(error)
            : this.CoreDomUtilsProvider.showErrorModal(error);
    } finally {
        this.submitting = false;
    }
};

/**
 * Fetch the next question by re-running the handler.
 *
 * @returns {Promise} Resolves once the next question is on screen.
 */
this.nextQuestion = function() {
    this.feedbackShown = false;

    return this.updateContent(
        {cmid: this.cmid},
        'mod_rememberme',
        'mobile_course_view'
    );
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
