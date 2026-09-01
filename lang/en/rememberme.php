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

/**
 * Language strings for mod_rememberme.
 *
 * @package    mod_rememberme
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['activeweeks'] = 'Active weeks';
$string['activeweeks_help'] = 'How many weeks this activity is graded over. Weeks that fall inside a suspension window are removed from the total automatically.';
$string['addbands'] = 'Add {no} more bands';
$string['addsuspensions'] = 'Add {no} more suspension windows';
$string['anybank'] = 'Any bank in this course';
$string['audiocue'] = 'Play an audio cue with feedback';
$string['audiocue_help'] = 'Plays a short sound when an answer is marked. Learners can switch it off for themselves, for use in class or on public transport.';
$string['audiocueoff'] = 'Sound is off. Turn feedback sound on';
$string['audiocueon'] = 'Sound is on. Turn feedback sound off';
$string['backstopdays'] = 'Backstop (days)';
$string['backstopdays_help'] = 'The longest a learner may stay on one band before the next unlocks regardless of their progress. Without a backstop, a struggling learner can sit on the first band for the whole course and never see most of the syllabus, which is the worst outcome for the learner who most needs the coverage. The backstop makes mastery mode a pacing preference rather than a hard gate: it accelerates nobody, but it prevents an indefinite stall.';
$string['backstopwarning'] = 'Advanced by backstop';
$string['band'] = 'Band';
$string['bandcategories'] = 'Categories in this band';
$string['bandcategories_help'] = 'A band may draw on several categories. New questions are introduced from the learner\'s current band and any band below it, so a category left unfinished earlier is not stranded.';
$string['bandprogress'] = 'Band progress';
$string['bandreason'] = 'Reached by';
$string['bandreason_backstop'] = 'Backstop — did not meet the threshold';
$string['bandreason_exhausted'] = 'Saw every question in the band';
$string['bandreason_mastery'] = 'Met the threshold';
$string['bandreason_none'] = 'Starting band';
$string['bandreason_suspensionlimit'] = 'Held back during a break';
$string['bandreason_time'] = 'Time elapsed';
$string['bandsince'] = 'On this band since';
$string['bandsintro'] = 'Bind question categories in the order you want them taught. New questions are introduced from the learner\'s current band only. Revision is never restricted by band: once a question has been seen it comes back on memory strength alone, whichever band it came from.';
$string['bandunlocked'] = 'You have unlocked a new set of questions.';
$string['cachedef_instancesettings'] = 'Activity settings used while building a session';
$string['checkanswer'] = 'Check';
$string['choosecategory'] = 'Choose a question category...';
$string['completiondetail:weeks'] = 'Clear the queue in {$a} weeks';
$string['completionweeks'] = 'Learner must clear their queue in this many weeks:';
$string['completionweeksgroup'] = 'Weeks cleared';
$string['configuredefaults'] = 'Default settings for new activities';
$string['correct'] = 'Correct';
$string['coursestart'] = 'Week one begins';
$string['coursestart_help'] = 'Week boundaries are the same for every learner in the course, regardless of when they enrolled. This differs deliberately from band unlocking in time mode, which runs from each learner\'s own first session: unlocking paces an individual, while grading follows a shared calendar.';
$string['day'] = 'Day';
$string['difficulty'] = 'Difficulty';
$string['difficultyflagged'] = 'A question that is difficult for nearly every learner is usually badly worded rather than conceptually hard. These are worth rereading.';
$string['duetoday'] = 'Due today';
$string['erroralreadyanswered'] = 'That question has already been answered.';
$string['errorduplicateband'] = 'Each band must use a different question category.';
$string['errorincompleteresponse'] = 'Please answer the question before checking it.';
$string['errormaxchoices'] = 'Enter 0 to present every option, or 3 or more.';
$string['errornobands'] = 'Choose at least one question category.';
$string['errornonnegative'] = 'This value cannot be negative.';
$string['errornoquestions'] = 'The question categories bound to this activity contain no usable questions.';
$string['errornotgraded'] = 'That answer could not be graded, so nothing has been recorded. Please try again.';
$string['errorpositive'] = 'This value must be greater than zero.';
$string['errorproportionrange'] = 'The proportion must be greater than 0 and less than 1. A proportion of 1 would let a few persistently difficult questions hold a learner on one band indefinitely.';
$string['errorretentionrange'] = 'Target retention must be greater than 0 and less than 1.';
$string['errorsessiongone'] = 'This session is no longer available. Reload the page to start a new one.';
$string['errorthresholdrange'] = 'The threshold must be between 0 and 1.';
$string['errorwindowbackwards'] = 'A suspension window must end after it starts.';
$string['establishedat'] = 'Established at {$a} days';
$string['eventbandunlocked'] = 'Band unlocked';
$string['eventquestionanswered'] = 'Question answered';
$string['firstsession'] = 'First session';
$string['flagreview'] = 'Worth reviewing';
$string['forecast'] = 'Upcoming review load';
$string['forecast_help'] = 'How many items fall due on each of the coming days, across the whole cohort.';
$string['forecastcaption'] = 'Questions falling due over the next fortnight, across the whole group.';
$string['gracebalance'] = 'Grace credit';
$string['gracebalance_help'] = 'Insurance against a bad week, granted up front for the whole course and measured in weeks. Grace fills the gap between what a learner achieved and a full week, and costs exactly the size of that gap: rescuing a completely missed week costs 1.0, while topping up a week scored 0.9 costs only 0.1. A balance of 1.0 therefore rescues one missed week, or patches several near misses. It is allocated at the end of the course, cheapest gaps first, so nothing is wasted early on a week the learner would have absorbed anyway.';
$string['graceearned'] = 'Grace earned';
$string['graceearnrate'] = 'Grace earned per session during a break';
$string['graceearnrate_help'] = 'Studying voluntarily during a suspension window tops up the grace balance, so a learner who has fallen behind can make up ground at a time when they are under no obligation. Earned grace is capped at the original grant, so a break cannot be farmed to buy back an absent term. This is redemption rather than reward: a learner with no shortfalls gains nothing from it, because grace only ever fills gaps.';
$string['graceremaining'] = 'Grace remaining: {$a}';
$string['graceused'] = 'Grace used';
$string['gradingintro'] = 'Grading measures whether learners keep up with their schedule, not how many answers they get right. Grading accuracy would contaminate the signal the scheduler depends on, because it rewards avoiding guesses and looking answers up.';
$string['gradingsettings'] = 'Weekly completion and grading';
$string['includesubcategories'] = 'Include subcategories';
$string['incorrect'] = 'Incorrect';
$string['itemsdue'] = 'Due now';
$string['itemsestablished'] = 'Established';
$string['itemsseen'] = 'Seen';
$string['lapses'] = 'Lapses';
$string['learner'] = 'Learner';
$string['learners'] = 'Learners';
$string['loading'] = 'Loading your next question...';
$string['masteryproportion'] = 'Proportion established';
$string['masteryproportion_help'] = 'The share of a band\'s questions that must reach the stability floor before the next band unlocks. Questions the learner has never seen count against this, so a band cannot qualify until most of it has actually been attempted. Keep this well below 1: at 100 per cent a handful of persistently difficult questions would hold a learner on one band forever.';
$string['maxchoices'] = 'Options per multiple choice question';
$string['maxchoices_help'] = 'The most answer options a multiple choice question will present, counting the right one. A question with more options than this shows the right answer and a random selection of the wrong ones, drawn again each time the question comes round, so the learner cannot memorise the shape of the answer. Questions with fewer options than the limit are unaffected. Enter 0 to present every option.';
$string['meandifficulty'] = 'Mean difficulty';
$string['meanlapses'] = 'Mean lapses';
$string['meanstability'] = 'Mean stability (days)';
$string['modulename'] = 'Remember Me';
$string['modulename_help'] = 'Remember Me schedules spaced repetition of question bank items, so learners revisit each question just as they are about to forget it.

Unlike self-rated flashcard tools, the schedule is driven entirely by whether the answer was actually correct, as judged by Moodle\'s existing question grading. That removes self-assessment bias and works with real question types.

Learners are graded on keeping up with their schedule rather than on accuracy, and each learner progresses through the question categories at their own pace.';
$string['modulename_link'] = 'mod/rememberme/view';
$string['modulenameplural'] = 'Remember Me activities';
$string['newperday'] = 'New questions per day';
$string['newperday_help'] = 'The most new questions a learner can be introduced to in one day. Without this cap an eager learner front-loads hundreds of new questions and is buried in reviews a week later.';
$string['nextquestion'] = 'Next question';
$string['noactivity'] = 'No activity yet';
$string['nobanks'] = 'No question banks with usable questions were found in this course. Add a question bank activity and create some questions first.';
$string['noinstances'] = 'There are no Remember Me activities in this course.';
$string['nothingdue'] = 'Nothing is due right now';
$string['nothingduedesc'] = 'You are up to date. Come back when more questions fall due; this week already counts as complete.';
$string['notquite'] = 'Not quite';
$string['notstarted'] = 'Not started';
$string['ontimegrace'] = 'Grace earned for answering on time';
$string['ontimegrace_help'] = 'Rewards returning while the queue is fresh. A learner who answers questions close to when they fall due earns up to this much grace; one who saves everything for a fortnightly sitting earns none of it, because their questions sat overdue. It is paid in grace rather than marks, so it can only ever repair a bad week and never lift anybody above full marks. Set to zero to switch it off.';
$string['overduenow'] = 'Overdue';
$string['passthreshold'] = 'Partial credit threshold';
$string['passthreshold_help'] = 'Partial credit at or above this fraction counts as recall and lengthens the interval. Below it, the question is treated as forgotten.';
$string['pausecorrect'] = 'Feedback pause after a correct answer (ms)';
$string['pausecorrect_help'] = 'How long the result is shown before the next question appears.';
$string['pauseincorrect'] = 'Feedback pause after an incorrect answer (ms)';
$string['pauseincorrect_help'] = 'Longer than the pause after a correct answer, because there is more to take in: the learner needs time to read the right answer.';
$string['pluginadministration'] = 'Remember Me administration';
$string['pluginname'] = 'Remember Me';
$string['poolsettings'] = 'Question pool';
$string['poolsize'] = 'Questions in pool';
$string['privacy:metadata:rememberme_bandstate'] = 'How far each learner has progressed through the ordered question categories.';
$string['privacy:metadata:rememberme_bandstate:bandlevel'] = 'The category the learner has reached.';
$string['privacy:metadata:rememberme_bandstate:firstsession'] = 'When the learner first studied.';
$string['privacy:metadata:rememberme_bandstate:userid'] = 'The learner this progress belongs to.';
$string['privacy:metadata:rememberme_review_log'] = 'A permanent record of every graded answer, kept so the scheduling model can be checked and improved.';
$string['privacy:metadata:rememberme_review_log:fraction'] = 'The proportion of the question answered correctly.';
$string['privacy:metadata:rememberme_review_log:latency'] = 'How long the learner took to answer, in milliseconds.';
$string['privacy:metadata:rememberme_review_log:questionbankentryid'] = 'The question that was answered.';
$string['privacy:metadata:rememberme_review_log:rating'] = 'How the answer was interpreted by the scheduling model.';
$string['privacy:metadata:rememberme_review_log:timecreated'] = 'When the question was answered.';
$string['privacy:metadata:rememberme_review_log:userid'] = 'The learner who answered.';
$string['privacy:metadata:rememberme_schedule'] = 'The current memory strength of each question for each learner, used to decide when to ask it again.';
$string['privacy:metadata:rememberme_schedule:difficulty'] = 'How hard this question is for this learner.';
$string['privacy:metadata:rememberme_schedule:duedate'] = 'When the question is next due.';
$string['privacy:metadata:rememberme_schedule:lapses'] = 'How many times the learner has forgotten this question.';
$string['privacy:metadata:rememberme_schedule:questionbankentryid'] = 'The question this state belongs to.';
$string['privacy:metadata:rememberme_schedule:reps'] = 'How many times the learner has recalled this question.';
$string['privacy:metadata:rememberme_schedule:stability'] = 'How well the learner currently knows this question.';
$string['privacy:metadata:rememberme_schedule:userid'] = 'The learner this state belongs to.';
$string['privacy:metadata:rememberme_session'] = 'The questions offered in each study session.';
$string['privacy:metadata:rememberme_session:timecreated'] = 'When the session began.';
$string['privacy:metadata:rememberme_session:userid'] = 'The learner who studied.';
$string['privacy:metadata:rememberme_slot'] = 'The individual questions offered to a learner within a study session.';
$string['privacy:metadata:rememberme_slot:isnew'] = 'Whether this question was new to the learner.';
$string['privacy:metadata:rememberme_slot:questionbankentryid'] = 'The question that was offered.';
$string['privacy:metadata:rememberme_slot:sessionid'] = 'The study session this question was offered in.';
$string['privacy:metadata:rememberme_slot:timeshown'] = 'When the question was shown to the learner.';
$string['privacy:metadata:rememberme_weeks'] = 'Weekly progress toward the schedule, used for grading.';
$string['privacy:metadata:rememberme_weeks:completed'] = 'How many questions the learner completed that week.';
$string['privacy:metadata:rememberme_weeks:fraction'] = 'The proportion of the week\'s target that was met.';
$string['privacy:metadata:rememberme_weeks:snapshottarget'] = 'The number of questions set for the learner that week.';
$string['privacy:metadata:rememberme_weeks:userid'] = 'The learner this progress belongs to.';
$string['privacy:metadata:rememberme_weeks:weekno'] = 'Which week of the course this is.';
$string['progressthisweek'] = '{$a->done} of {$a->target} this week';
$string['question'] = 'Question';
$string['questionbank'] = 'Question bank';
$string['questionbank_help'] = 'Which question bank the categories below are offered from. Leave this as any bank to choose from every bank this course can reach.';
$string['questiongone'] = 'Question no longer in the bank';
$string['questionsanswered'] = 'Questions answered';
$string['relativeload'] = 'Share of the fortnight\'s load';
$string['rememberme:addinstance'] = 'Add a new Remember Me activity';
$string['rememberme:attempt'] = 'Answer questions';
$string['rememberme:view'] = 'View Remember Me activity';
$string['rememberme:viewreports'] = 'View Remember Me reports';
$string['reportbands'] = 'Band progression';
$string['reportbandscaption'] = 'How far each learner has progressed through the ordered question categories. Learners marked as advanced by backstop did not meet the threshold; they were moved on so they would still see the syllabus, which is the signal worth acting on.';
$string['reportcoverage'] = 'Coverage and retention';
$string['reportcoveragecaption'] = 'How much of the question pool each learner has seen, and how much of it they now know well.';
$string['reportdifficulty'] = 'Question difficulty';
$string['reportdifficulty_help'] = 'A question whose difficulty is high for nearly every learner is usually a defective question rather than a hard concept.';
$string['reportdifficultycaption'] = 'How hard each question is proving across the whole group.';
$string['reportnodata'] = 'There is nothing to report yet. This fills in once learners start answering.';
$string['reports'] = 'Reports';
$string['reportweeks'] = 'Weekly completion';
$string['reportweekscaption'] = 'Each learner against each week, showing the share of that week\'s target they met and any grace credit applied.';
$string['reportweeksintro'] = 'Weeks are scored on keeping up with the schedule, not on accuracy. A week with nothing due counts automatically.';
$string['resetall'] = 'Delete all schedules, review history and weekly progress';
$string['retention'] = 'Retention';
$string['reviews'] = 'Reviews';
$string['schedulingsettings'] = 'Scheduling model';
$string['sessioncomplete'] = 'Session complete';
$string['sessioncompletedesc'] = 'You have cleared everything due right now. Well done.';
$string['sessionprogress'] = 'Progress through this session';
$string['sessionsettings'] = 'Session and feedback';
$string['sessionsize'] = 'Questions per session';
$string['sessionsize_help'] = 'The most questions offered in a single session.';
$string['showanswer'] = 'The correct answer';
$string['stability'] = 'Stability';
$string['stabilityfloor'] = 'Stability floor (days)';
$string['stabilityfloor_help'] = 'How well a question must be known before it counts as established. At the default of 14 days a question needs roughly three or four successful reviews, spread over at least a fortnight, because the intervals themselves separate those reviews. That means mastery mode cannot be rushed by cramming, which is the point of it.';
$string['startsession'] = 'Start studying';
$string['streak'] = 'Streak';
$string['streaklabel'] = 'week streak';
$string['streakweeks'] = '{$a} weeks in a row';
$string['studyagain'] = 'Study again';
$string['suspensionend'] = 'Ends';
$string['suspensionintro'] = 'During a suspension window the scheduling clock stops. Nothing falls due, so learners do not come back from a break to a wall of overdue reviews created by the break itself. Weeks that are mostly suspended are removed from the grade.';
$string['suspensionname'] = 'Name';
$string['suspensionsettings'] = 'Suspension windows';
$string['suspensionstart'] = 'Starts';
$string['targetretention'] = 'Target retention';
$string['targetretention_help'] = 'How likely a learner should be to remember a question at the moment it comes back. A lower value means fewer reviews but more forgetting. This is a teaching decision, so it is left to you; 0.9 is the usual starting point.';
$string['taskmaintenance'] = 'Remember Me maintenance';
$string['unlockinterval'] = 'Days between bands';
$string['unlockinterval_help'] = 'In time mode, how long a learner spends on each band. This is counted from the learner\'s own first session rather than from the start of the course, so somebody who joins in week three is not handed four bands at once.';
$string['unlockmode'] = 'How the next band unlocks';
$string['unlockmode_exhausted'] = 'When every question in the band has been seen';
$string['unlockmode_help'] = 'Time mode unlocks one band per interval, which is predictable and follows a syllabus, but takes no account of whether the learner has consolidated anything.

Mastery mode waits until the learner has actually established most of the current band. It cannot be rushed, because the intervals themselves take time to elapse. For a learner who is doing well the two modes pace almost identically; they differ for a learner who is struggling, which is where the choice matters.';
$string['unlockmode_mastery'] = 'When the current band is established';
$string['unlockmode_time'] = 'After a fixed time';
$string['uselatency'] = 'Use answer speed';
$string['uselatency_help'] = 'When enabled, answering faster than usual is treated as a stronger memory, which lengthens the interval a little more. Answer speed is compared against the learner\'s own typical speed for that question type, and it can never turn a correct answer into a wrong one. With it disabled, only correctness is used.';
$string['viewreports'] = 'View reports';
$string['weekcleared'] = 'Week complete. Well done.';
$string['weekclearedstreak'] = 'Week complete — that is {$a} weeks in a row.';
$string['weekno'] = 'Week {$a}';
$string['weekprogresslabel'] = 'this week';
$string['weeksuspended'] = 'Suspended';
