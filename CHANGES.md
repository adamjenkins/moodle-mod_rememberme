# Changes

## 0.1.1 — 2026-09-01

Fixes Moodle app support, which did not work in 0.1.0, and closes a hole in how
weekly credit was counted.

Weekly credit is now one point per distinct question engaged with rather than
one per attempt. Counting attempts meant a learner could clear a whole week by
answering a single question wrongly over and over, because a wrong answer brings
it straight back. An answer submitted too fast to have been read earns no credit
either, though it is still recorded. And a wrong answer now returns the question
within the same sitting instead of the next day, which is a scheduling
consequence rather than a grading one: being wrong costs time, never marks, so
there is still nothing to gain by looking an answer up.

The app handler was registered correctly and rendered without error, but what it
rendered was an informational card whose "Start studying" button re-invoked the
same handler: it redrew the identical card, and no question was ever presented.
The app view is now the study session, exactly as the web view is. Questions are
rendered by the app's own `core-question` component and graded through the
existing web service, so every question type the app supports works without this
plugin knowing anything about them.

Also fixed: the app reported how many items were due using reviews only, so a
learner whose queue was entirely new questions was told nothing was due.

Not verified on a device. The implementation is written against the Moodle app
source (v5.3.0) and covered by tests that exercise the handler the way the app
calls it, including validating the response against the web service contract,
but it has not been run in the app itself.

## 0.1.0 — 2026-09-01

First release. Alpha: the plugin is complete and verified against Moodle 5.2,
but the memory model's constants have not yet been tuned against real cohort
data.

### The activity

Remember Me schedules spaced repetition of question bank items. The schedule is
driven entirely by whether the submitted answer was correct, as judged by
Moodle's own question grading, rather than by learner self-rating.

- **FSRS-style memory model.** Stability and difficulty are stored per learner
  per question; the interval is derived at review time and never stored. A lapse
  reduces stability sharply without discarding it, so an item known for months
  that slips once returns sooner than a brand new one instead of resetting to
  day one.
- **Correctness becomes a rating** through a replaceable strategy class. Answer
  speed can refine a correct answer but can never turn one into a lapse; it is
  compared against the learner's own median for that question type and ignored
  until there are enough samples. With it disabled the mapping is wrong/right,
  and that simple path is the default rather than an untested branch.
- **Tiered question pools.** A teacher binds question categories in order; new
  items are drawn only from the learner's current band, and unlocking is per
  learner. Bands unlock either on a fixed interval or when the current band is
  established, the latter with a backstop so a struggling learner still covers
  the syllabus. Revision is never band restricted.
- **Grading measures schedule adherence, not accuracy**, because grading
  accuracy would contaminate the signal the scheduler depends on. Each week's
  target is frozen when the week begins, partial weeks earn partial credit, and
  a pool of fractional grace credit is allocated across the whole course.
- **Suspension windows stop the clock.** Implemented as an effective-time
  function rather than by shifting stored dates, so windows stay editable
  afterwards and are correct for learners who enrol during one.
- **The module page is the session** — no landing page, no start button.
  Immediate feedback, an optional audio cue, auto-advance, no page reloads.
- **Teacher reports**: question difficulty across the cohort (which surfaces
  badly worded items), coverage and retention, band progression flagging
  backstop advances, weekly completion, and a review-load forecast.
- Privacy provider, backup and restore, course reset, uninstall cleanup, a
  maintenance task, and Moodle app support (online only; offline is out of
  scope for this release).
- Installable through Composer, and continuously tested by GitHub Actions
  against Moodle 5.2 on PHP 8.3 and 8.4 over PostgreSQL and MariaDB.

### Verified

On Moodle 5.2.2+ (build 20260818), PHP 8.4.24, MariaDB 11.8.6:

- PHPUnit: 138 tests, 3177 assertions, no failures.
- `moodle-plugin-ci` phplint, phpcpd, phpmd, savepoints, validate: exit 0.
- `moodle-plugin-ci` phpcs and phpdoc with `--max-warnings 0`: exit 0.
  (phpdoc here is moodlecheck, which checks docblock/signature consistency
  only.)
- Mustache lint: no errors across all seven templates.
- The full answer cycle exercised in a real browser, with the resulting
  database rows read back.

### Known limitations

- No Behat acceptance tests.
- The Moodle app views follow the one in-tree example but have not been
  exercised on a device.
- Model constants are the published FSRS-5 defaults, and the mastery-mode
  thresholds are reasoned estimates. The review log exists so both can be
  refitted against real data.
