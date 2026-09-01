# Changes

## 0.2.0 — 2026-09-01

The Moodle app view has been run in a real Moodle app for the first time, which
found four things wrong with it and two ways a study session could trap a
learner. Multiple choice questions are now answered by tapping an option rather
than by choosing a radio button and pressing a submit button, and bands unlock on
coverage by default.

### The app view now works

0.1.1 shipped an app view that had never been opened in an app. It could not have
worked: a site with web services enabled by CLI is still missing the
`webservice/rest:use` capability, which the admin interface's mobile setting
grants and `admin/cli/cfg.php` does not, so every call failed. Beyond that, four
faults lived in the app itself, each of them silent — no error, a successful web
service response, and a broken screen:

- The app parses any `otherdata` value that looks like JSON before a plugin's
  script sees it, so parsing it a second time threw and left the session with no
  question in it.
- The answer form was found by searching the whole document, which matched a form
  belonging to another part of the app. Answers were collected from that form
  instead, so every answer was submitted empty and graded wrong.
- A compiled app component is not redrawn by assigning to its own properties
  after an awaited call, so grading never reached the screen.
- The app's question component reads its question once and ignores later changes,
  so handing it the graded question changed nothing.

The app's dark theme was also unusable: the stylesheet named colour variables the
app does not define, so every value fell back to a light theme constant and drew
near white borders and bright chips on dark cards. The web stylesheet had the
same fault against Bootstrap 5.3. Both now take their colours from variables that
exist, checked against the rendered page rather than assumed.

### Two ways a session could trap a learner

A response the behaviour would not grade was being forced finished in the
question engine and then rejected here, which left the question unanswerable
while it stayed queued: the same dead question every time the activity was
opened, and a session that could never end. Nothing is saved for such a response
now, and the message asks the learner to answer the question. Sessions already
stuck recover on their own, because a question the engine has finished is skipped
rather than offered again.

### Multiple choice is answered by tapping

A single response multiple choice question is presented as a set of options the
learner taps, on the web and in the app, with no submit button: the tap is the
answer. The letter on each option becomes a tick or a cross in place, so the
result is never carried by colour alone. There is no confirmation step, which is
reasonable here because being wrong costs a repetition and never a mark.

A teacher can cap how many options such a question presents. A question written
with eight options is a reading exercise on a phone. The right answers are always
kept and the wrong ones thinned at random, drawn again each time the question
comes round, so the shape of the answer cannot be memorised in place of the
answer. The default presents every option, so nothing changes unless it is set.

### Questions are shuffled

New items are drawn at random rather than in pool order. In pool order every
learner met the same questions in the same sequence, and a learner who never
cleared their daily allowance only ever saw the front of the list, so the tail of
a large band went unseen indefinitely while the unlock rules waited for it.

Answer options are shuffled every time, whatever the question was authored to do.
An answer that sits in the same position can be recalled by position rather than
by content, which is the opposite of what this activity measures.

### Bands unlock on coverage by default

New activities now unlock the next band once every question in the current band
has been seen at least once. A timer moves a learner on whether or not they met
the band, and mastery can hold them behind a handful of items they keep lapsing;
coverage asks only that the syllabus has actually been covered, which is what
ordering questions into bands was expressing in the first place. **Existing
activities keep the mode their teacher chose** — only the default for new ones
moves.

### Upgrading

Adds `maxchoices` to the instance table and changes the `unlockmode` column
default. No existing setting is rewritten.

### Verified

On Moodle 5.2.2+ (build 20260818), PHP 8.4.24, MariaDB 11.8.6. Every step of the
plugin's own CI workflow was run locally and exited 0:

- PHPUnit: 174 tests, 3357 assertions, no failures, under `--fail-on-warning`.
- phplint, phpcpd, phpmd, savepoints, validate: exit 0.
- phpcs and phpdoc with `--max-warnings 0`: exit 0.
- stylelint and eslint: exit 0, and each was proven to still report by feeding it
  a known bad input first.
- Mustache lint: no errors. The HTML validator it calls is unreachable from the
  build machine, so those checks ran on GitHub rather than here.
- A fresh install from `install.xml` produced no debugging output, and the
  upgrade path was run separately.
- The committed AMD bundle is byte identical to a fresh build.

The web session and the Moodle app session were both driven end to end in a
browser — question, answer, feedback, next question — and the resulting database
rows read back. The app was the real Moodle app (5.3.0) served against the test
site, in both its light and dark themes.

**The app view has still not been run on a physical device.** The app it was
tested in is browser hosted, which behaves differently in at least one known way:
a browser blocks the app's first call to a site because `/lib/ajax/service.php`
sends no cross origin header, and a packaged app has no such restriction.
