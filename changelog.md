# Changelog

All notable changes to this plugin are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] - 2026-09-01

### Fixed

- The activity now actually works in the Moodle app. The previous release
  registered its app handler correctly and rendered, but only an informational
  card whose "Start studying" button re-invoked the same handler, so it redrew
  the identical card and no question was ever presented. The app view is now the
  study session, as the web view is: it renders the question through the app's
  own `core-question` component and grades the answer through the existing
  `mod_rememberme_submit_answer` web service.
- The app's due figure counted reviews only, so a learner whose whole queue was
  new questions was told nothing was due. It now reports the queue a session
  would actually offer.

## [0.1.0] - 2026-09-01

First release.

### Added

- FSRS-style spaced repetition scheduler for question bank items, driven by
  objective correctness rather than learner self-rating. Stability and
  difficulty are stored; the interval is derived at review time.
- Replaceable grade-to-rating strategy, with answer speed as an optional
  refinement that can never turn a correct answer into a lapse.
- Tiered question pools with per-learner unlocking, in time-based or
  mastery-based mode, the latter with a backstop for stalled learners.
- Weekly completion graded on schedule adherence, with the target frozen at the
  start of each week and fractional grace credit allocated across the course.
- Suspension windows that stop the scheduling clock, implemented as an
  effective-time function so they remain editable afterwards.
- Single-page study session: no landing page, immediate feedback, optional
  audio cue, auto-advance, no page reloads.
- Teacher reports: question difficulty, coverage and retention, band
  progression, weekly completion, review-load forecast.
- Privacy provider, backup and restore, course reset, `db/uninstall.php`,
  scheduled maintenance task, and Moodle app support (online only).
- `composer.json`, so the plugin can be installed through Composer and listed
  on Packagist.
- GitHub Actions workflow running the moodle-plugin-ci battery against Moodle
  5.2 on PHP 8.3 and 8.4, over PostgreSQL and MariaDB.

### Security

- The submitted response is scoped to the question being answered. The question
  engine processes every slot a request names, and the response arrives from the
  client, so an unscoped payload could have graded every question in a learner's
  session at once while the plugin recorded a single attempt.
- The question file callback declares the signature core actually calls, and
  checks that the usage belongs to the requesting learner (or that the caller
  may view reports) before serving anything.
- Restored data is cleaned as the settings form cleans it, and the lifecycle
  state and band unlock reason are constrained to their known sets. A backup
  file is untrusted input.
- `mod/rememberme:manage` was removed. It was declared but enforced nowhere, so
  it appeared in role definitions while granting nothing.

[0.1.1]: https://github.com/adamjenkins/moodle-mod_rememberme/releases/tag/v0.1.1
[0.1.0]: https://github.com/adamjenkins/moodle-mod_rememberme/releases/tag/v0.1.0
