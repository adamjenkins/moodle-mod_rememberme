# Changes

## 0.1.1 — 2026-09-01

Fixes Moodle app support, closes a hole in how weekly credit was counted, and
adds a reward for returning while the queue is fresh.

### Moodle app support now works

0.1.0 registered its app handler correctly and rendered without error, but what
it rendered was an informational card whose "Start studying" button re-invoked
the same handler: it redrew the identical card, and no question was ever
presented. The app view is now the study session, as the web view is. Questions
are rendered by the app's own question component and graded through the existing
web service, so every question type the app supports works without this plugin
knowing anything about them. The app also reported how many items were due using
reviews only, so a learner whose queue was entirely new questions was told
nothing was due.

### Weekly credit counts questions, not attempts

Credit was one point per attempt. Because a wrong answer brings the question
straight back, that let a learner clear an entire week by answering a single
question wrongly over and over. Credit is now one point per distinct question
engaged with, and an answer submitted faster than the question could be read
earns nothing — though it is still recorded, because the review log is a
complete record of what happened.

A wrong answer now returns the question within the same sitting rather than the
next day. That is a scheduling consequence and not a grading one: being wrong
costs time and repetition, never marks, so there is still nothing to gain by
looking an answer up and the correctness signal the model depends on stays
honest. A question that has lapsed many times leaves the short step, so one
unanswerable question cannot crowd out the queue.

### Returning on time is rewarded

Answering an item close to when it falls due now earns grace, up to a maximum
the teacher sets. Punctuality is measured rather than visits, because opening
the activity is free and counting visits would reward the appearance of the
habit. It is paid in grace rather than marks, so it can only ever repair a bad
week and never lifts anybody above full marks. Set the maximum to zero to switch
it off.

Finishing a week is now celebrated in the session, announced to assistive
technology as well as shown, and the animation is suppressed for anyone who has
asked for reduced motion.

### Question pools are more flexible

- A band may draw on several question categories rather than exactly one.
- The settings form offers a question bank to choose categories from, instead of
  one long list across every bank the course can reach.
- New questions are introduced from the learner's current band **and every band
  below it**, so a category left unfinished before moving on is not stranded.
- Bands can unlock on a third condition: when every question in the current band
  has been seen at least once. Coverage rather than mastery.

### Upgrading

Adds columns to the schedule, review log, bands and instance tables, with
upgrade steps. Existing bands become one band each in their previous order.
Punctuality history begins at this release; earlier answers are not counted
rather than guessed at.

### Verified

On Moodle 5.2.2+ (build 20260818), PHP 8.4.24, MariaDB 11.8.6, every step of the
plugin's own CI workflow was run locally and exited 0:

- PHPUnit: 162 tests, 3265 assertions, no failures, under `--fail-on-warning`.
- phplint, phpcpd, phpmd, savepoints, validate: exit 0.
- phpcs and phpdoc with `--max-warnings 0`: exit 0. (phpdoc here is moodlecheck,
  which checks docblock and signature consistency only.)
- Mustache lint: no errors across every template.
- The grunt gate's eslint pass at `--max-warnings 0`: exit 0, and the committed
  AMD bundle is identical to a fresh build.
- A fresh install produces no debugging output, and the upgrade path was run.
- The web session was exercised in a real browser and the resulting database
  rows read back.

**The Moodle app view has not been exercised on a device.** It is written against
the app source and covered by tests that call the handler the way the app does,
including validating the response against the web service contract, but it has
not been run in the app itself.
