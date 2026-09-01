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

- Moodle 5.2 (`$plugin->requires = 2026042000`, supported branch 502)
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
points. Two modes:

- **Time** — one band per interval, counted from the learner's *first session*
  rather than course start, so somebody who joins in week three is not handed
  four bands at once.
- **Coverage** — the next band unlocks once every question in the current band
  has been seen at least once, whatever the learner made of them.
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
learners keep up with their schedule.

Each week the learner must clear what is due. Two rules make that fair:

- **The target is frozen when the week begins.** It is what was due at that
  moment plus the new items they may draw. Items that come due again *during*
  the week roll into next week instead of enlarging this one. Under the
  alternative — a denominator that grows as answered items fall due again — a
  learner who answers everything asked of them can never reach 100%, because
  every answer breeds another review before the week is out. The finish line
  recedes as they approach it. The test suite asserts this directly.
- **Partial weeks earn partial credit**, capped at 1.0, so a learner who logs in
  late and does what they can is not scored as harshly as one who never came.

**Returning on time is rewarded.** Answering an item close to when it falls due
earns grace, up to a maximum the teacher sets. Punctuality is measured rather
than visits, because opening the activity is free and counting visits would
reward the appearance of the habit rather than the habit. A learner who saves
everything for one sitting a fortnight earns none of it, because their questions
sat overdue. It is paid in grace, so it can only repair a bad week.

**Getting a question wrong costs time, never marks.** A wrong answer brings the
question back within the same sitting rather than the next day. Weekly credit
counts distinct questions engaged with, so answering one question repeatedly
earns one point, and an answer submitted faster than the question could be read
earns nothing at all.

**Grace credit** is a pool of fractional credit, not a count of whole weeks. It
tops a week up toward 1.0 and costs exactly the gap it fills: rescuing a missed
week costs 1.0, patching a 0.9 week costs 0.1. It is allocated at the end of the
course, cheapest gaps first, so nothing is wasted early on a week the learner
would have absorbed anyway.

Progress is shown as a **personal streak**. There is deliberately no leaderboard:
because the queue is capped and driven by each learner's own memory state, the
learner with the most reviews is the one with the most lapses, so a leaderboard
would rank learners roughly inversely to how well they know the material.

### Suspension windows

A teacher can declare breaks during which **the scheduling clock stops**. This is
the part that is easy to get wrong. If scheduling ticked through a two-week
break, everything would fall due at once and learners would return to a wall of
overdue reviews created purely by a holiday somebody else declared — punishing
them for the break and corrupting the difficulty estimates for the whole cohort.

Rather than batch-shifting stored dates when a window ends (which breaks for
mid-window enrolments, cannot be undone if the teacher edits the window, and
corrupts the review log's elapsed times), suspended time simply **does not
exist**. Every scheduling calculation runs through an effective-time function, so
windows stay editable after the fact and are correct for learners who join during
one. Weeks more than half suspended drop out of the grade entirely.

Voluntary study during a break earns grace credit, capped so a break cannot be
farmed to buy back an absent term. At most one band unlocks per window, so a
motivated learner does not mortgage their first week back.

## The learner experience

Opening the activity **is** the session — no landing page, no start button. The
friction of an intermediate screen is disproportionate for something meant to be
visited briefly and often. Answer, get immediate feedback with an optional audio
cue, and the next question appears, all without a page reload. The pause is
longer after a wrong answer, because there is more to take in.

Scheduling state is written per question as it is answered, so a learner who
closes the tab after three questions keeps the effect of those three.

## Teacher reports

- **Question difficulty** across the cohort — surfaces defective items, since a
  question that is hard for nearly everyone is usually badly worded rather than
  conceptually difficult.
- **Coverage and retention** per learner, with a review-load forecast.
- **Band progression**, flagging learners advanced by the backstop.
- **Weekly completion** matrix, including grace consumed.

## Mobile

The Moodle app is supported through `db/mobile.php` and assumes a live
connection. **Offline study is deliberately out of scope** for this release: it
requires queueing attempts locally and replaying them, which raises questions
this design does not yet answer — what counts as due while disconnected, how to
reconcile a replayed attempt whose elapsed time is now stale, and what happens
when the same question is answered on two devices before either syncs.

## Privacy

The schedule and the review log record what an individual learner knew and when.
Both are declared, exported and deleted through the privacy API, along with
weekly progress, band progress and session records.

## Status

Version 0.1.1, alpha. The model constants are the published FSRS-5 defaults and
the Mode B thresholds are reasoned estimates rather than validated ones — the
review log exists precisely so they can be refitted against real cohort data.

## Licence

GNU GPL v3 or later.
