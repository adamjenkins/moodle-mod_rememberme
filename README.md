# Remember Me (mod_rememberme)

A Moodle activity module that schedules **spaced repetition of question bank
items**, so learners revisit each question just as they are about to forget it.

Unlike Anki-style tools, learners never grade their own recall. The scheduling
signal is derived entirely from **whether the submitted answer was correct**, as
judged by Moodle's own question grading. That removes self-assessment bias,
which is unreliable in classroom populations and impossible to enforce in
assessed contexts, and it works with real question types — multiple choice,
short answer, matching, cloze — because grading and rendering are done by the
core question engine rather than reimplemented.

## Requirements

- Moodle 5.2 – 5.3 (`$plugin->requires = 2026042000`, supported branches 502–503)
- PHP 8.2+

The plugin depends on 5.x-shaped question bank APIs: question banks as
`mod_qbank` instances, and `question_bank_entries` as the stable identity of a
question. It will not run on 4.x.

## Installation

Copy the plugin into `mod/rememberme` in your Moodle tree (under `public/` on
5.0+), then visit Site administration → Notifications to install.

## How it works

### The scheduling model

The scheduler follows **FSRS-style memory modelling** rather than SM-2. It
stores two latent variables per learner per question — **stability** and
**difficulty** — and derives the interval from stability at review time.

The distinction matters in practice. SM-2 stores an interval and an ease factor,
and a lapse typically resets the interval to day one. Here a lapse reduces
stability sharply but does not discard it, so a long-known item that slips once
returns sooner than a brand new one instead of starting from scratch. Over a
course where learners see the same bank all term, that difference is the
difference between a manageable queue and a punishing one.

The interval is never stored, only derived, so re-tuning the model does not leave
stale intervals behind in the database.

### Correctness becomes a rating

The memory model expects a rating of `again`, `hard`, `good` or `easy`. That is
synthesised from the objective grade:

| Condition | Rating |
|---|---|
| Wrong, or partial credit below the threshold | `again` |
| Partial credit at or above the threshold | `hard` |
| Fully correct, slower than the learner's usual pace | `good` |
| Fully correct, at or faster than their usual pace | `easy` |

Answer speed is a **secondary signal only**. It can never turn a correct answer
into a lapse — it only separates `good` from `easy`, both of which lengthen the
interval. It is compared against the learner's own rolling median for that
question type, needs at least eight samples before it is trusted at all, and is
discarded when the attempt was left open too long. With it switched off the
mapping collapses to wrong/right, and the system works correctly in that mode:
the mapper is written binary-first, so the simple path is the tested one.

The mapping is a replaceable strategy class, because different courses want
different thresholds and researchers will want to swap it out entirely.

### Tiered question pools

The pool is not flat. A teacher binds **question categories in an ordered
sequence**, and new items are drawn from the learner's current band and every
band below it — so a category left unfinished before moving on is not stranded.
A band may draw on several categories, and the settings form offers a question
bank to pick them from.
Unlocking is per learner, so two students in one course can be at different
points. Three modes:

- **Coverage** *(default)* — the next band unlocks once every question in the
  current band has been seen at least once, whatever the learner made of them.
  It is the default because it asks only that the syllabus has been covered: a
  timer moves a learner on whether or not they met the band, and mastery can
  hold them behind a handful of items they keep lapsing.
- **Time** — one band per interval, counted from the learner's *first session*
  rather than course start, so somebody who joins in week three is not handed
  four bands at once.
- **Mastery** — the next band unlocks when a configurable proportion of the
  current band reaches a stability floor. Unseen items count against the
  threshold, so a band cannot qualify until most of it has been attempted. A
  **backstop** advances a stalled learner anyway after a maximum time, and flags
  that it did so: a learner who never leaves band one is the one who most needs
  the coverage.

Bands gate *introduction*, never revision. Once seen, an item competes for review
on memory strength alone, whichever band it came from.

### Grading is adherence, not accuracy

Grading accuracy would contaminate the very signal the scheduler depends on, by
rewarding guess avoidance and answer lookup. So the grade measures whether
learners come back to study, regularly.

**Each week is scored on study days.** The teacher sets how many different days
a week needs (3 by default). A day counts once the learner answers a session's
worth of different questions, or works through everything on offer that day,
whichever comes first. A week scores the days studied divided by the days
needed, capped at 1.0, and every graded week of the term is an equal share of the
grade from the first day, so the grade builds up week by week. So 100% means
studying on that many days every week of the term; in a 15-week term, week one
is worth at most 1/15. The learner sees this on the page as
"1 of 3 study days this week" and "Today: 2 of 20 questions", with one sentence
saying exactly what earns a full grade.

- **Coming back is what counts.** Doing a week's work in one sitting is one study
  day, however much it was. There is deliberately no "week complete" message:
  it would tell learners they are done when the point is to return.
- **A short queue is never a penalty.** The second way a day counts means a
  learner with little due is marked down only for not coming, never for having
  nothing to do.
- **The week in progress counts for what is earned in it already**, which can
  only add to the grade. Grace fills gaps only in weeks that have ended.
- **Outside the graded weeks nothing is counted**, and the page says when grading
  begins or that it has ended, rather than showing a count stuck at zero.
- Grades reach the gradebook as soon as they change, and a daily task pushes the
  effect of a week ending without the learner.

**Returning on time is rewarded.** Answering an item close to when it falls due
earns grace, up to a maximum the teacher sets. Punctuality is measured rather
than visits, because opening the activity is free and counting visits would
reward the appearance of the habit rather than the habit. A learner who saves
everything for one sitting a fortnight earns none of it, because their questions
sat overdue. It is paid in grace, so it can only repair a bad week.

**Getting a question wrong costs time, never marks.** A wrong answer brings the
question back within the same sitting rather than the next day. A study day
counts distinct questions engaged with, so answering one question repeatedly
counts once, and an answer submitted faster than the question could be read
counts not at all.

**Grace credit** is a pool of fractional credit, not a count of whole weeks. It
tops a week up toward 1.0 and costs exactly the gap it fills: rescuing a missed
week costs 1.0, patching a 0.9 week costs 0.1. It is allocated at the end of the
course, cheapest gaps first, so nothing is wasted early on a week the learner
would have absorbed anyway.

Progress is shown as a **personal streak**. There is deliberately no leaderboard:
because the queue is capped and driven by each learner's own memory state, the
learner with the most reviews is the one with the most lapses, so a leaderboard
would rank learners roughly inversely to how well they know the material.

### Grading by band establishment instead

With bands that unlock "when the current band is established", a teacher can
choose **Grade by: band establishment**. Each band is worth its share of all the
questions (a band of 40 counts four times one of 10) and earns it in proportion to
how far it is established: it counts in full once the mastery proportion of its
questions has reached the stability floor, the same rule that unlocks the next
band. The best progress each band reaches is kept, so forgetting after a band was
established never lowers the grade. Learners see their grade so far in place of
the study-day count.

### The term and its breaks

The teacher sets a **start of term** and an **end of term**. Weeks run from the
start of term, the same for every learner, and grading stops at the end. If the
term ends part way through a week, that last week needs proportionally fewer
study days, rounded up and never fewer than one.

Breaks (suspension windows) must fall within the term. A teacher can declare breaks during which **the scheduling clock stops**. This is
the part that is easy to get wrong. If scheduling ticked through a two-week
break, everything would fall due at once and learners would return to a wall of
overdue reviews created purely by a holiday somebody else declared — punishing
them for the break and corrupting the difficulty estimates for the whole cohort.

Rather than batch-shifting stored dates when a window ends (which breaks for
mid-window enrolments, cannot be undone if the teacher edits the window, and
corrupts the review log's elapsed times), suspended time simply **does not
exist**. Every scheduling calculation runs through an effective-time function, so
windows stay editable after the fact and are correct for learners who join during
one.

- **A week more than half suspended is not graded.** A learner who stays away
  loses nothing, and the page says it is a break week rather than showing a count.
- **A week partly suspended needs fewer study days**, in proportion to its open
  days, rounded up: with three a week, a week with four open days needs two.
- **Study during a break earns grace**, whether it is inside the window or on the
  open days of a week that is not graded. It is measured like a study day, in
  different questions answered properly each day, so repeating a question earns
  nothing, and it is capped so a break cannot be farmed to buy back an absent
  term. At most one band unlocks per window, so a
motivated learner does not mortgage their first week back.

## The learner experience

Opening the activity **is** the session — no landing page, no start button. The
friction of an intermediate screen is disproportionate for something meant to be
visited briefly and often. Answer, get immediate feedback with an optional audio
cue, and the next question appears, all without a page reload. The pause is
longer after a wrong answer, because there is more to take in.

Multiple choice questions are presented as options the learner taps, with no
submit button: the tap is the answer. The letter on each option turns into a
tick or a cross in place, so the result never rests on colour alone. There is no
confirmation step, which is reasonable here because being wrong costs a
repetition and never a mark.

Answer options are shuffled every time, whatever the question was authored to
do, and a teacher can cap how many options a question presents — a question
written with eight options is a reading exercise on a phone. The right answers
are always kept and the wrong ones thinned at random, drawn again each time the
question comes round, so the shape of the answer cannot be memorised in place of
the answer.

Scheduling state is written per question as it is answered, so a learner who
closes the tab after three questions keeps the effect of those three.

## Teacher reports

- **Question difficulty** across the cohort — surfaces defective items, since a
  question that is hard for nearly everyone is usually badly worded rather than
  conceptually difficult.
- **Coverage and retention** per learner, with a review-load forecast.
- **Band progression**, flagging learners advanced by the backstop.
- **Weekly completion** matrix, including grace consumed.

Every report column sorts when its heading is clicked. Editing teachers and
managers also get a **Recalculate grades** button, which rescores every
learner's weeks and updates the gradebook at once; grades otherwise follow each
answer, each change to the term or breaks, and a daily task.

## Mobile

The Moodle app shows the study session itself, not a summary of it. Questions
are rendered by the app's own question component, so every question type the app
understands works without this plugin knowing anything about them, and multiple
choice gets the same tappable options as the web view. The app view has been
exercised in a real Moodle app, though not yet on a physical device.

The site has to be set up for the app before any of this works — web services
on, the REST protocol enabled, and the `webservice/rest:use` capability granted,
which the admin UI's mobile setting does and the CLI equivalents do not.

The app assumes a live connection. **Offline study is deliberately out of
scope** for this release: it requires queueing attempts locally and replaying
them, which raises questions this design does not yet answer — what counts as
due while disconnected, how to reconcile a replayed attempt whose elapsed time
is now stale, and what happens when the same question is answered on two devices
before either syncs.

## Privacy

The schedule and the review log record what an individual learner knew and when.
Both are declared, exported and deleted through the privacy API, along with
weekly progress, band progress and session records.

## Languages

English and Japanese (日本語). The Japanese strings are in `lang/ja/`; they are
used when the site has the Japanese language pack installed and the user has
chosen Japanese.

## Status

Version 0.3.0, alpha. The model constants are the published FSRS-5 defaults and
the Mode B thresholds are reasoned estimates rather than validated ones — the
review log exists precisely so they can be refitted against real cohort data.

## Licence

GNU GPL v3 or later.
