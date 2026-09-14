# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`local_paperscorer` — a Moodle *local* plugin that syncs course rosters and grades between Moodle and PaperScorer (paperscorer.com), and exports Moodle quizzes (questions plus answer key) so they can be imported as PaperScorer assessments.

Renamed from `local_akindi` in v2.0.0. That was a clean break with no compatibility fallback: the component, every setting, and the API wire parameters all changed at once, and the calling service deploys in lockstep. Do not add `akindi_*` or `ak_*` shims.

There is no build system, no dependency manager, and no CI. The repo *is* the deployed artifact: its contents are dropped into a Moodle tree.

## Commands

```bash
# Development Moodle (docker-compose.yml). Moodle source is NOT vendored and
# must live outside this repo — mounting the repo into a Moodle tree nested
# inside it would be a recursive bind mount.
git clone --depth 1 --branch MOODLE_405_STABLE \
  https://github.com/moodle/moodle.git ../moodle-instance
cp docs/moodle-config.php.example ../moodle-instance/config.php
docker compose up -d
docker compose exec ps-moodle-php php admin/cli/install_database.php \
  --agree-license --adminpass=Paperscorer1! --adminemail=dev@example.com \
  --fullname="PaperScorer Dev" --shortname="psdev"
# Site: http://localhost:8090  Containers: ps-moodle-php, ps-moodle-database

php -l api.php                    # syntax check; repeat per file

php tests/standalone/run.php      # pure logic tests: no Moodle, no PHPUnit, ~instant

docker compose exec ps-moodle-php php admin/tool/phpunit/cli/init.php
docker compose exec ps-moodle-php vendor/bin/phpunit --group local_paperscorer
```

**PHP 8.4 is the ceiling, and it is Moodle's limit, not ours.** Moodle 4.x blocks 8.4+ (`restrict_php_version_84`), so it runs on 8.3; Moodle 5.x runs on 8.5 but its locked deps (`ezyang/htmlpurifier`, `openspout/openspout`) cap at 8.4, so PHPUnit cannot be installed there. `.env` carries `PHP_VERSION` plus the Moodle-generation paths (5.x serves from `public/`, so the plugin mounts at `public/local/paperscorer` and tool CLIs live under `public/admin/tool/`). The plugin's pure logic is separately verified on PHP 8.5 by `tests/standalone/run.php`, which uses the host PHP.

**Test classes must be namespaced.** `tests/foo_test.php` declares `namespace local_paperscorer;` and `class foo_test extends \advanced_testcase`. Moodle 5.x does not discover the older global `local_paperscorer_foo_testcase` naming; the namespaced form works on both 4.x and 5.x.

`init.php` must be re-run after every `$plugin->version` bump; the test
environment records the version it was built against and refuses to run
otherwise. Since every code change requires a version bump, that is every time.

**`common.php` is unreachable from PHPUnit.** Its first act is
`require_once ../../config.php`, and a test is already inside a booted Moodle.
Anything a test needs must live in `quizlib.php` (or another file that does not
bootstrap), never in `common.php` — that is why `ps_plugin_version()` lives in
`quizlib.php` despite being general plugin metadata.

The plugin is bind-mounted at `local/paperscorer` inside the container. The
directory name MUST be `paperscorer` — the component name `local_paperscorer`
is derived from the path, and `version.php` fails validation otherwise. That
also means a release zip must have `paperscorer/` as its root directory, not
this repo's folder name; see the Packaging section of `README.rst`.

After **any** code change, bump `$plugin->version` in `version.php` (date-based `YYYYMMDDXX`) or Moodle will not re-install the plugin. For a user-visible release also bump `$plugin->release` and add a `CHANGELOG.txt` entry.

## Compatibility constraints

`$plugin->requires = 2014051200` (Moodle 2.7); the plugin is advertised as working on Moodle 2.x through 5.x. Two consequences:

**Only long-stable Moodle APIs.** Anything added after 2.7, or removed in 4.x/5.x, breaks part of the supported range. This is why `lib.php` defines *two* navigation hooks: `local_paperscorer_extend_navigation_course` (3.x+) and `local_paperscorer_extends_settings_navigation` (2.x).

**PHP 5.4.** Moodle 2.7 ran on PHP 5.4.4+. No `??`, no scalar type hints, no return types, no `Foo::class` (use the string form in `class_exists`), no short closures. Use `array()`, not `[]`. Note that `php -l` on a modern PHP will *not* catch a 5.4 violation — this is enforced by review.

## Architecture

Two independent halves, tied together by a two-tier HMAC scheme. Understanding the key derivation is required before touching either file.

**Outbound — `launch.php`** (browser, normal Moodle session, `require_login()`): renders an auto-submitting form POSTing to `$CFG->paperscorer_launch_url`. The signed payload carries the user, the course, `has_edit_grade_capability` (PaperScorer uses it to decide instructor vs. student UI), and `user.key = ps_sign(paperscorer_instance_secret, $USER->id)`. The form signature itself is over `"$expires\n$data_str"` using `paperscorer_secret_key` (the key PaperScorer gave the admin).

**Inbound — `api.php`** (called by PaperScorer's servers): `AJAX_SCRIPT` + `NO_MOODLE_COOKIES`, so there is no Moodle session and **`$USER` is not the acting user**. Authentication is entirely signature-based:

- Request carries `ps_key` (the plain Moodle user id), `ps_signature`, `ps_expires`, `action`.
- The plugin recomputes `user_key = ps_sign($CFG->paperscorer_instance_secret, ps_key)` and verifies `ps_signature` over `"$expires\n$METHOD\n$action"`, plus `"\n" . <raw request body>` on POST.
- `paperscorer_instance_secret` is never shared with PaperScorer — PaperScorer only ever holds the *derived* per-user key handed to it during launch. That is what scopes an API caller to a single user, and why the setting must never change after setup (all previously issued user keys die).

The effective actor is the global `$PS_USER_ID`, not `$USER`. Every action therefore starts with `ps_get_validate_course_id($action)`, which re-checks `moodle/grade:edit` for `$PS_USER_ID` in the course context. **Any new API action must do the same** — skipping it makes the endpoint act as an unchecked super-user.

**Action dispatch**: the `action` query param is JSON containing `name`; `api.php` calls `ps_action_<name>`. Adding an endpoint = adding a function, nothing to register. Current actions: `get_roster`, `list_grade_items`, `create_update_grade_item`, `update_grades`, `selftest`, `get_capabilities`, `list_quizzes`, `get_quiz_structure`.

Grade writes go only to `itemtype = 'manual'` grade items, via `grade_item::update_final_grade(..., 'paperscorer', ...)`.

**`common.php`** bootstraps Moodle (`require_once ../../config.php`, i.e. it assumes the `local/paperscorer/` install path) and holds `ps_sign` / `ps_get` / `ps_load_action` / `ps_plugin_version`. Every entry point includes it first.

## Two transports

The plugin exposes the same capabilities twice, and both call the same functions in `quizlib.php` so their payloads cannot drift.

**`api.php`** — the signed endpoint (`ps_key` / `ps_signature`), scoped to one Moodle user by a derived key. No Moodle session; `$USER` is not the actor, `$PS_USER_ID` is.

**`externallib.php` + `db/services.php`** — Moodle web service functions (`local_paperscorer_get_capabilities`, `_list_quizzes`, `_get_quiz_structure`), for callers that already hold a wstoken. Here the token *does* establish a session, so `$USER` **is** the acting user — which is why `quizlib.php` takes the user id as a parameter rather than reading a global.

Each web service function returns its result as a JSON string in a single `payload` value rather than a declared `external_single_structure`. The quiz payload is polymorphic per question type; restating it in a `_returns()` definition would duplicate a contract that lives in `quizlib.php` with nothing keeping the two in sync.

`externallib.php` uses the legacy global `external_api` class names, correct for a 2.7+ plugin — Moodle 4.2 moved them to `core_external\` but kept the old names undeprecated. A `class_alias` guard at the top of the file covers their eventual removal.

Adding or renaming a web service function requires a `$plugin->version` bump; Moodle only re-reads `db/services.php` on upgrade.

## Quiz export

Split across two files along a testability seam. Keep the seam:

**`quizlib.php`** touches Moodle. It resolves a quiz's slots to question ids, loads each with `question_bank::load_question()`, and flattens the resulting `question_definition` into a plain array descriptor. All Moodle-specific property access lives here.

**`classes/quiz_normalizer.php`** must not call any Moodle API. It turns descriptors into the payload PaperScorer imports — the type map, the A/B/C letter mapping, answer-key extraction, and the degradation warnings. That constraint is what lets `tests/standalone/run.php` exercise the risky mapping logic with no Moodle install.

**All version-variant logic lives in `ps_quiz_slot_resolution()` / `ps_quiz_get_slots()`.** Moodle 4.0 dropped `quiz_slots.questionid` and moved the link to `question_references`, so 4.0+ goes through `mod_quiz\question\bank\qbank_helper::get_question_structure()` and 2.7–3.11 reads `quiz_slots` directly. Branch on `class_exists('mod_quiz\question\bank\qbank_helper')`, **never** on a version number. Everything downstream is shared, because `question_bank::load_question()` has been stable since Moodle 2.1.

**Quiz actions check two capabilities, not one.** `ps_get_validate_course_id()` covers `moodle/grade:edit` in the course, as every action must. Quiz export then also requires `mod/quiz:manage` on the quiz's *module* context via `ps_quiz_can_export()`, because handing over answer keys is a broader privilege than editing a gradebook column. `list_quizzes` filters to what the caller may export; `get_quiz_structure` throws. Any future action that reads question content must do the same.

**`quizlib.php` takes the acting user id as an explicit parameter.** Under `NO_MOODLE_COOKIES` the global `$USER` is not the actor, so a capability check that falls back to it silently tests the wrong user. `api.php` passes `$PS_USER_ID` down; the tests pass a real enrolled teacher.

Export degrades rather than failing: unsupported qtypes and random slots land in `skipped` with a reason, lossy conversions add a `warnings` entry to the question, and a Moodle too old to resolve slots reports `quiz_export: false` from `get_capabilities` instead of erroring.

## Settings are global `$CFG` values, not plugin config

`settings.php` registers settings with bare names (`paperscorer_public_key`, not `local_paperscorer/paperscorer_public_key`), so Moodle stores them in the core config table and they are read as `$CFG->paperscorer_*` everywhere. Keep that convention.

`paperscorer_student_id_field` selects which profile field becomes the bubble-sheet student ID: `idnumber`, `userid`, or any custom profile field (options built by `ps_settings_get_student_id_options()`). For custom fields, `api.php` strips non-digits from the value.

`paperscorer_enable_student_launch` gates the nav link and `launch.php` for non-instructors; the `api.php` capability check is unaffected and always requires `moodle/grade:edit`.

## Testing

Two layers, both dependency-free — do not introduce composer or a `vendor/` directory.

- **`tests/standalone/`** — a hand-rolled assert harness for `classes/quiz_normalizer.php`. Runs with plain `php`, no Moodle. This is where question-mapping behaviour is specified; add cases here first.
- **`tests/*_test.php`** — Moodle PHPUnit, `@group local_paperscorer`. Covers what genuinely needs a Moodle: slot resolution on the live schema, `question_bank::load_question()` against generated questions, and the assembled payload.

`testing.php` (the old HTTP-driven fixture endpoint that Akindi's external suite called) was removed in v2.0.0. Its fixtures live in the PHPUnit tests now.

## Style

Existing code is 2-space indented, `snake_case`, `ps_`-prefixed globals and functions, and does not follow the Moodle coding standard. Match the surrounding file rather than reformatting.
