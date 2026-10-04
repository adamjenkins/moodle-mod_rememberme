# Changes

## Unreleased

- The Japanese language pack (lang/ja) is no longer included: releases ship the English strings
  only, as the Moodle Plugins directory expects. Japanese is provided through Moodle's language
  packs.
- The coverage report's summary shows the number of questions in the pool again.

## 0.3.1 — 2026-10-04

Declare Moodle 5.3 support. The plugin now declares Moodle 5.2 to 5.3 as its
supported range. No code changes were needed for 5.3. composer.json now
requires `moodle/composer-installer` `^1.0` instead of an unbounded `*`.
