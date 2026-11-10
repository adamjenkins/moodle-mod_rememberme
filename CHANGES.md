# Changes

## 0.3.4 — 2026-11-10

- Moodle app: a multiple choice question no longer gives away its right answer before the learner answers. The app learns which option was right only once the answer has been marked, and still shows it with the result as before.
- Reports: in separate groups mode, a teacher who belongs to no group and cannot access all groups now sees a "not in a group" notice instead of every learner's results.
- Privacy: a learner's data export now includes the answers they gave in each study session, with marks and feedback, and the privacy metadata declares that these answers are stored by Moodle's question subsystem.
- The "view reports" capability is now flagged as a personal-information risk, since the reports show learners' names and progress. No role changes are needed.
