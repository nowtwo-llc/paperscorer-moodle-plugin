# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`local_paperscorer` — a Moodle *local* plugin that syncs course rosters and grades between Moodle and PaperScorer (paperscorer.com), and exports Moodle quizzes (questions plus answer key) so they can be imported as PaperScorer assessments.

v2.0.0 renamed the plugin to `local_paperscorer` as a clean break with no compatibility fallback: the component, every setting, and the API wire parameters all changed at once, and the calling service deploys in lockstep. Do not add shims for the pre-2.0 names.

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

**Inbound — `api.php`** (the launch-based inbound endpoint): `AJAX_SCRIPT` + `NO_MOODLE_COOKIES`, so there is no Moodle session and **`$USER` is not the acting user**. Authentication is entirely signature-based:

- Request carries `ps_key` (the plain Moodle user id), `ps_signature`, `ps_expires`, `action`.
- The plugin recomputes `user_key = ps_sign($CFG->paperscorer_instance_secret, ps_key)` and verifies `ps_signature` over `"$expires\n$METHOD\n$action"`, plus `"\n" . <raw request body>` on POST.
- `paperscorer_instance_secret` is never shared with PaperScorer — PaperScorer only ever holds the *derived* per-user key handed to it during launch. That is what scopes an API caller to a single user, and why the setting must never change after setup (all previously issued user keys die).

The effective actor is the global `$PS_USER_ID`, not `$USER`. Every action therefore starts with `ps_get_validate_course_id($action)`, which re-checks `moodle/grade:edit` for `$PS_USER_ID` in the course context. **Any new API action must do the same** — skipping it makes the endpoint act as an unchecked super-user.

**Action dispatch**: the `action` query param is JSON containing `name`; `api.php` calls `ps_action_<name>`. Adding an endpoint = adding a function, nothing to register. Current actions: `list_courses`, `get_roster`, `list_grade_items`, `create_update_grade_item`, `update_grades`, `selftest`, `get_capabilities`, `list_quizzes`, `get_quiz_structure`. Each is a thin wrapper over `synclib.php` or `quizlib.php`; if an action grows logic of its own, the web service transport silently loses it.

**Grade policy** (enforced in `synclib.php`): `create_update_grade_item` creates manual items and edits only manual items that belong to the requested course. `update_grades` writes via `grade_item::update_final_grade(..., 'paperscorer', ...)` to a manual item or an activity's own column (a gradebook override, which is how a quiz imported from Moodle gets its scores back into the quiz's column); course and category totals are refused. `ps_grade_item_load()` exists because `grade_item`'s constructor returns an *empty* object when its params match nothing, so an unchecked `update()` would rewrite another course's item.

**`common.php`** bootstraps Moodle (`require_once ../../config.php`, i.e. it assumes the `local/paperscorer/` install path) and then includes **`helpers.php`**, which holds the pure helpers `ps_sign` / `ps_secure_compare` / `ps_get` / `ps_load_action` / `ps_random_bytes`. Every entry point includes `common.php` first; tests include `helpers.php` directly.

## Two transports

The plugin exposes the same capabilities twice, and both call the same functions in `synclib.php` and `quizlib.php` so their payloads cannot drift.

**`externallib.php` + `db/services.php`** — Moodle web service functions, one per action (`local_paperscorer_list_courses`, `_get_roster`, `_list_grade_items`, `_create_update_grade_item`, `_update_grades`, `_get_capabilities`, `_list_quizzes`, `_get_quiz_structure`). **This is the transport PaperScorer's servers use**: main-app (`PS\Moodle` in the main-app repo) connects with an admin-created wstoken and has no client for the signed protocol. The token *does* establish a session, so `$USER` **is** the acting user — which is why `synclib.php` and `quizlib.php` take the user id as a parameter rather than reading a global.

The pre-built `PaperScorer` service in `db/services.php` lists the plugin functions **plus the core functions main-app calls** (`core_webservice_get_site_info`, `core_user_get_users_by_field`, `core_enrol_get_users_courses`, `core_enrol_get_enrolled_users`, `core_course_get_courses_by_field`, `core_course_get_contents`, `mod_assign_save_grade`). That list must track main-app's `MoodleUtil::callWebservice` call sites; the point is that a self-hosted site needs only this plugin, with no help-doc function list. Moodle inserts service function names without checking they exist, so listing a function an old Moodle lacks is safe.

**`api.php`** — the signed endpoint (`ps_key` / `ps_signature`), scoped to one Moodle user by a derived key issued at launch. No Moodle session; `$USER` is not the actor, `$PS_USER_ID` is. Kept for launch-based use; it has no caller in main-app today.

Each web service function returns its result as a JSON string in a single `payload` value rather than a declared `external_single_structure`. The quiz payload is polymorphic per question type; restating it in a `_returns()` definition would duplicate a contract that lives in `quizlib.php` with nothing keeping the two in sync.

`externallib.php` uses the legacy global `external_api` class names, correct for a 2.7+ plugin — Moodle 4.2 moved them to `core_external\` but kept the old names undeprecated. The file must **not** unconditionally include `lib/externallib.php`: on 4.2+ that file is a shim that calls `require_phpunit_isolation()`, so any test including it dies unless run in a separate process. Instead, when the legacy names are missing and the namespaced classes exist, alias them; include the shim only on pre-4.2 Moodle where it is the sole source. In production the web service server has already included the shim before the plugin file loads.

Adding or renaming a web service function requires a `$plugin->version` bump; Moodle only re-reads `db/services.php` on upgrade.

## Roster, courses and grades

`synclib.php` holds `ps_course_list`, `ps_roster`, `ps_grade_item_list`, `ps_grade_item_save` and `ps_grades_update`. It does not bootstrap Moodle, takes the acting user id explicitly, and is what both transports call. `ps_course_list($target, $caller)` filters to courses where the target holds `moodle/grade:edit`, and when the caller is a different user (a service-account token looking up a teacher) requires the caller to hold it too, so a token never sees a course it could not sync.

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
- **`tests/*_test.php`** — Moodle PHPUnit, `@group local_paperscorer`. Covers what genuinely needs a Moodle: slot resolution on the live schema, `question_bank::load_question()` against generated questions, the assembled payload, the gradebook functions (including the cross-course refusal), and `external_test.php`, which drives the web service functions through `external_api` parameter/return validation as a session user.

For an end-to-end REST check on the dev site, enable web services and REST, mint a token on the `local_paperscorer` service, and call `http://localhost:8090/webservice/rest/server.php` from the **host** (inside the container Moodle redirects to its `wwwroot`). Pass `-g` to curl: the bracketed parameter names (`item[name]`) are otherwise treated as globs and the request silently fails. The dev dataroot was created by a root CLI install and needs `chown -R www-data` before Apache can serve requests.

`testing.php` (the old HTTP-driven fixture endpoint that the pre-2.0 external test suite called) was removed in v2.0.0. Its fixtures live in the PHPUnit tests now.

## Style

Existing code is 2-space indented, `snake_case`, `ps_`-prefixed globals and functions, and does not follow the Moodle coding standard. Match the surrounding file rather than reformatting.
