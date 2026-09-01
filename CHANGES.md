# Changes

## 0.1.0 (unreleased)

First build. Not yet released or tagged.

- FSRS-style scheduler with stability and difficulty as stored state and the
  interval derived at review time. Lapses reduce stability without discarding it.
- Ratings derived from objective correctness, with answer speed as an optional
  secondary signal that can never turn a correct answer into a lapse.
- Tiered question pools with per-learner unlocking, in time-based or
  mastery-based mode, with a backstop for stalled learners.
- Weekly completion graded on schedule adherence rather than accuracy, with a
  target frozen at the start of each week and fractional grace credit allocated
  across the whole course.
- Suspension windows that stop the scheduling clock, implemented as an
  effective-time function so windows stay editable after the fact.
- Single-page study session: no landing page, immediate feedback, auto-advance,
  optional audio cue, no page reloads.
- Teacher reports: question difficulty, coverage and retention, band
  progression, weekly completion, review-load forecast.
- Privacy provider, backup and restore, course reset, scheduled maintenance task,
  and Moodle app support (online only).
