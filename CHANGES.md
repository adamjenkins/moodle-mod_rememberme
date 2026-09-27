# Changes

## Unreleased

Declare Moodle 5.3 support. The plugin now declares Moodle 5.2 to 5.3 as its
supported range. No code changes were needed for 5.3.

## 0.3.0 — 2026-09-26

Grading has been rebuilt around what a spaced repetition activity is for:
coming back. In 0.2.0 each week had an item count target that was often out of
reach, the weekly figure could sit at zero however much a learner did, and no
grade ever reached the gradebook. A week is now graded on the number of
different days a learner studied, the grade is a share of the whole term that
builds up week by week, and it is pushed to the gradebook as it changes. A
second way of grading, by band establishment, is available for activities whose
bands unlock on mastery. The term is set as two dates, breaks are handled
fairly, every report sorts, teachers can recalculate grades, and the activity is
available in Japanese.

### Graded on study days

The teacher sets how many different days a week a learner should study, three
by default. A day counts once the learner answers a session's worth of
different questions, or works through everything the activity can offer that
day, so a learner with little due is never marked down for a short queue, only
for not coming back. A week's score is the days studied against the days
required, and the page tells the learner, in one sentence, exactly what earns a
full grade. There is no "week complete" message any more: the point is to come
back several times a week.

The weekly target this replaces was frozen at the learner's first visit rather
than the start of the week, assumed seven days of new questions, and counted
questions in bands the learner could not yet reach, so many learners could not
meet it whatever they did. Outside the graded weeks nothing was counted at all,
which showed as a figure stuck at zero.

### The grade is a share of the whole term

Every graded week is an equal share of the grade from the first day: in a term
of fifteen graded weeks, week one is worth at most a fifteenth. The week in
progress counts for what has been earned in it already, which can only add.
Grace credit fills gaps only in weeks that have ended. At the end of the term
the grade is the same as an average of the weeks would be; before then it no
longer overstates anyone's standing.

Grades are pushed to the gradebook after every answer and by a new daily task,
and the "weeks earned in full" completion rule is re-evaluated as they change.
In 0.2.0 neither happened during normal use.

### Grading by band establishment

With "Grade by" set to band establishment, each band is worth its share of all
the questions, so a band of forty counts four times a band of ten. A band earns
its share in proportion to how far it is established, and counts in full at the
same threshold that unlocks the next band. The best progress each band reaches
is kept, so forgetting after a band was established never lowers the grade.
Learners see their grade so far in place of the study day count. It needs bands
that unlock when the current band is established; the settings form and restore
both refuse it otherwise.

### The term, and breaks within it

"Week one begins" and "Active weeks" are replaced by a start and an end of
term. Suspension windows must fall within the term. A week that is mostly
suspended is not graded, so staying away costs nothing, and any study in it
earns grace. A week partly lost to a break, or cut short by the end of term,
needs proportionally fewer study days. Resetting a course with a new start date
now moves the term, its breaks and the learners' records with it.

### Fairness and integrity

A pre-release security self-audit, and a second pass that tried to break its
fixes, closed several ways to be credited for study that did not happen: a queue
tapped through faster than it could be read, an activity with no questions
configured reached through the web service or the Moodle app, and answers to
questions that were never shown. Answers are now timed to the millisecond, so a
quick honest answer is no longer rounded down to nothing. Break grace counts
different questions answered properly each day, not every answer. Restoring a
course into later dates now moves every learner date with it, and a restored
term is capped at 520 weeks.

### Reports, recalculation and Japanese

Every column of every report sorts by clicking its heading, by value rather
than by its wording, and the heading tells a screen reader the order. Editing
teachers and managers get a "Recalculate grades" button, under the new
capability mod/rememberme:recalculategrades. The activity is available in
Japanese.

### Upgrading

Existing data is kept. Existing activities get an end of term exactly where
their active weeks ended, so no week moves, and keep grading on study days.
Every week is rescored from the review log by a task queued during the upgrade,
and gradebooks are refreshed. A week recorded before the upgrade is marked as
such and never scores lower than 0.2.0 scored it; its old target and count stay
on the record. A backup made before this release restores the same way. An
existing term longer than 520 weeks is shortened to 520.

### Verified

- PHPUnit: 241 tests, run on Moodle 5.2 with PHP 8.4 and MariaDB. The test for
  each fix was seen to fail with that fix removed.
- Every static step of the repository's CI workflow run locally, each exiting 0:
  phplint, phpcpd, phpcs and phpdoc with no warnings, validate, savepoints,
  Mustache (all 8 templates rendered) and the eslint and stylelint half of grunt.
- The upgrade from 0.2.0 was run on a test site over data written by 0.2.0, and
  every rescored week compared with a hand-worked value.
- In a browser: a learner's session end to end, every sortable report column in
  both directions, the recalculate button, the settings form's new validation,
  and the pages in Japanese.
