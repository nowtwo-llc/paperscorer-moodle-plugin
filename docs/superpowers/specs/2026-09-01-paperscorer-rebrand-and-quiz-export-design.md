# local_paperscorer — Rebrand and Quiz Export

**Date:** 2026-09-01
**Status:** Approved for implementation
**Scope:** `moodle-plugin/` (stage 1), `main-app/` PS/Moodle importer (stage 2, not yet authorized)

## Summary

Two changes to the Moodle plugin, shipped together:

1. **Rebrand** `local_akindi` → `local_paperscorer`. Clean break, no migration.
2. **Quiz export.** New API actions that read a Moodle quiz's questions and answer
   key and return a normalized payload PaperScorer can import as an assessment.

Plus test infrastructure, which the repo currently has none of.

## Decisions taken

| Question | Decision |
|---|---|
| Source artifact | Quiz activity (`mod_quiz`), not question-bank categories |
| Moodle range | 2.7+ — the plugin's existing `$plugin->requires` floor |
| Installed base | None. Clean rebrand, no `upgrade.php` config migration, no dual-read fallbacks |
| Wire protocol | Renamed outright (`ps_key`/`ps_signature`/`ps_expires`), lockstep deploy with the calling service |
| Testing | Both layers — zero-dependency standalone runner plus Moodle PHPUnit |

## Part 1 — Rebrand

### Why it is not just sed

Four categories, in ascending order of consequence:

**Cosmetic** — `README.rst`, `CHANGELOG.txt`, comments, the "Launch Akindi" nav
label, `pix/akindi-icon.*`. No risk.

**Internal identifiers** — `ak_sign`, `ak_get`, `ak_load_action`,
`ak_action_*`, `ak_get_validate_course_id`, `$AK_USER_ID`, `$AK_POST_JSON`.
Self-contained; the wire carries the action *name* in JSON, not the function
name, so renaming the dispatch target is invisible externally.

**Component identity** — the directory name *is* the component. `local/akindi/`
must become `local/paperscorer/`, and with it `$plugin->component`, both
`lib.php` navigation hook names (they are matched by component name), the lang
file name, and `get_plugins_of_type('local')['akindi']`. Moodle treats the
result as a new plugin; the old one, if present, stays installed and orphaned.

**Persisted / external** — the seven `$CFG->akindi_*` settings, the `ak_key` /
`ak_signature` / `ak_expires` query parameters, the `X-Ak-Plugin-Release`
response header, the `'akindi'` grade source string written into Moodle's grade
history, and the `AK_MOODLE_TEST` environment variable.

The decision that there is **no installed base** is what makes this tractable.
Renaming `akindi_instance_secret` would otherwise destroy every per-user API key
ever issued.

### Naming

| Old | New |
|---|---|
| `local_akindi` | `local_paperscorer` |
| `local/akindi/` | `local/paperscorer/` |
| `ak_*` functions | `ps_*` |
| `$AK_USER_ID`, `$AK_POST_JSON` | `$PS_USER_ID`, `$PS_POST_JSON` |
| `ak_action_<name>` | `ps_action_<name>` |
| `$CFG->akindi_*` | `$CFG->paperscorer_*` |
| `ak_key`, `ak_signature`, `ak_expires` | `ps_key`, `ps_signature`, `ps_expires` |
| `X-Ak-Plugin-Release` | `X-PS-Plugin-Release` |
| `update_final_grade(..., 'akindi', ...)` | `..., 'paperscorer', ...` |
| `AK_MOODLE_TEST` | `PS_MOODLE_TEST` |
| `pix/akindi-icon.*` | `pix/paperscorer-icon.*` |

The bare-name settings convention is **kept** — `paperscorer_launch_url`, not
`local_paperscorer/launch_url`. Namespaced plugin config would be the more
correct Moodle idiom, but switching conventions is a separate decision and this
change is already large.

### Incidental fix

Every `moodle_exception(...)` in the plugin passes `'akindi'` as the component
argument. That is not a valid Moodle component, so none of these strings ever
resolve and errors surface as a generic fallback. The rebrand corrects the
component to `local_paperscorer` **and** adds the corresponding strings to
`lang/en/local_paperscorer.php`, so API errors become readable. This is in
scope because the lang file is being rewritten anyway.

## Part 2 — Quiz export

### Why the plugin is the only lever

Moodle core ships no web service that returns question text with correct
answers. `PS\Moodle` in main-app already uses every core endpoint that exists —
`core_user_get_users_by_field`, `core_enrol_get_users_courses`,
`core_enrol_get_enrolled_users`, `core_course_get_courses_by_field`,
`core_course_get_contents` — and stops there for exactly this reason. Code
running *inside* Moodle is the only way to read the question bank.

### Version strategy: one stable reader, one branching shim

The entire version-variant surface is slot resolution.

| Range | Quiz → question ids |
|---|---|
| 2.7 – 3.11 | `quiz_slots.questionid` (the table landed in 2.7, matching `$plugin->requires`) |
| 4.0+ | `mod_quiz\question\bank\qbank_helper::get_question_structure()` — the `questionid` column was dropped and links moved to `question_references` |

Everything downstream is shared. `question_bank::load_question()` has been
stable since the 2.1 question-engine rewrite and returns a typed
`question_definition`, so answer-key extraction is one code path across the
whole supported range. Branch on `class_exists('mod_quiz\question\bank\qbank_helper')`,
never on a version number.

### PHP language level

Supporting 2.7 means **PHP 5.4**. No `??`, no scalar type hints, no return
types, no `Foo::class`, no short closures. `array()` over `[]` to match the
surrounding code. The existing plugin already lives in this dialect.

Note this makes `class_exists('mod_quiz\question\bank\qbank_helper')` a string
literal, not a `::class` constant.

### New actions

All three go through `ps_get_validate_course_id()` first, per the existing rule.

**`ps_action_get_capabilities`** — plugin version, Moodle release, and a feature
map. The handshake that lets PaperScorer decide what to offer.

**`ps_action_list_quizzes`** — course → quizzes (`id`, `cmid`, `name`,
`question_count`, `sumgrades`).

**`ps_action_get_quiz_structure`** — quiz → normalized questions, answer key,
per-question warnings, and a list of what was skipped and why.

### Normalized payload

```json
{
  "quiz": { "id": 12, "cmid": 340, "name": "Unit 3 Test", "sumgrades": 20 },
  "source": {
    "moodle_release": "4.3.2",
    "plugin_version": 2026090100,
    "slot_resolution": "qbank_helper"
  },
  "questions": [
    {
      "slot": 1,
      "question_id": 881,
      "qtype": "multichoice",
      "type": "multiple_choice",
      "name": "Capital of France",
      "text": "What is the capital of France?",
      "points": 1.0,
      "scoring_type": "per_question",
      "responses": ["A", "B", "C", "D"],
      "response_text": { "A": "Lyon", "B": "Paris", "C": "Nice", "D": "Brest" },
      "correct": ["B"],
      "response_value": "B",
      "warnings": []
    }
  ],
  "skipped": [
    { "slot": 5, "qtype": "multianswer", "reason": "unsupported-qtype" }
  ]
}
```

`association_response` items carry `prompts`, `options`, `correct` (aligned to
`prompts`) and `grid_scoring_type` instead of `responses`/`response_text`,
matching the shape `PS\Assessment:Item` already expects.

The payload is normalized **on the Moodle side**. The importer never learns
which Moodle version produced it — that is the main argument for putting the
branching here rather than shipping raw qtype data.

### Type map

| Moodle qtype | PaperScorer item | Degradation |
|---|---|---|
| `multichoice` (single) | `multiple_choice` | clean |
| `multichoice` (multi) | `multiple_choice`, letters joined | partial fractions → warning |
| `truefalse` | `true_false` | clean |
| `match` | `association_response` | clean |
| `shortanswer` | `writing_response` | key becomes reference text, hand-graded |
| `numerical` | `number_response` | tolerance dropped → warning |
| `essay` | `writing_response` + `rubric_basic` | clean |
| `calculated`, `multianswer`, `ddwtos`, `ddimageortext`, `random`, all others | — | skipped with reason |

### Graceful degradation — four layers

**0. Capability handshake.** An older installed plugin hits the existing
`unknown-action:get_capabilities` throw, which PaperScorer reads as "no quiz
export here" and simply does not render the import button. No error reaches the
teacher. `X-PS-Plugin-Release` on every response means an old plugin is
detectable from any call.

**1. Moodle version guard.** `class_exists(...)` gates the 4.0+ path; the
pre-4.0 path is the fallback, not an error. If neither resolves, report
`quiz_export: false` with a reason — never a PHP fatal.

**2. Per-slot degradation.** A 20-question quiz with 3 cloze items exports 17
and returns 3 in `skipped` with reasons. Random-question slots land here too;
they cannot resolve to a fixed question on paper.

**3. Per-question fidelity warnings.** Where the trip is lossy, emit the item
*and* a warning: `partial-credit-collapsed`, `html-stripped`,
`tolerance-dropped`, `hand-graded`, `no-correct-answer`. (Shuffle metadata is
not yet carried; see the follow-up list.) This is the layer
that matters most — on paper, a silently wrong answer key is the worst possible
failure, so the importer shows a review screen rather than saving a key nobody
inspected.

## Part 3 — Testing

The repo has no build system, no dependency manager, and no test runner, and
"the repo *is* the deployed artifact." Both test layers must respect that: no
composer, no `vendor/`.

**Layer 1 — standalone, zero dependency.** The pure logic lives in
`classes/quiz_normalizer.php` with no Moodle calls: it takes plain objects
shaped like `question_definition` and returns normalized arrays. Run with
`php tests/standalone/run.php` — a small assert harness, no Moodle, no
PHPUnit, seconds. This covers the type map, letter mapping, answer extraction,
partial-credit collapse, and warning generation.

**Layer 2 — Moodle PHPUnit.** `tests/*_test.php` following Moodle's plugin
convention, run from a Moodle tree's own PHPUnit. Covers what genuinely needs a
Moodle: slot resolution on both branches, `question_bank::load_question()`
against real generated questions, the capability check, signature verification,
and the gradebook write path.

**`testing.php` is retired.** Its fixture-building (`ak_test_action_setup`)
becomes PHPUnit fixtures. This removes the HTTP-driven test endpoint that
Akindi's external suite depends on — acceptable given the lockstep-deploy
decision, but the external suite must be retired alongside it.

## Stage 2 — the PaperScorer importer (not yet authorized)

`PS\Moodle\Service\QuizImporter`, modeled directly on
`PS\GoogleForms\Service\FormImporter`: build `Assessment` + `Item` +
`AssessmentItem`, set `content_managed_flg = 1`, `external_source = 'moodle'`,
`external_id`, `external_data`. `FormImporter` already returns
`items_imported` / `items_skipped` / `skipped_questions`, so the contract above
needs no new shape on that side. Plus a quiz picker and a warning-review screen
before save.

Separate repo, separate conventions (XF addon rebuild, `scripts/db_updates/`),
and a separate go-ahead.

## Risks

**No Moodle to develop against.** There is no Moodle tree under
`~/Projects/PaperScorer`. Layer-2 tests and both slot-resolution branches need a
3.x and a 4.x/5.x instance. This is the dominant schedule risk.

**2.7 support is by construction, not by test.** Standing up a real Moodle 2.7
means PHP 5.4 and an old MySQL. The recommendation is to build and verify on
3.x as a faithful proxy for the pre-4.0 branch, and treat 2.7 as written to the
documented API surface but not exercised — the same basis on which the plugin
already advertises 2.x today.

**Lockstep deploy.** The wire rename means the calling service must ship with
the plugin. There is no compatibility window by design.

---

## Amendment 1 — 2026-09-01

Two additions after the first implementation pass, both agreed before building.

### Answer-key access control

`get_quiz_structure` originally inherited only the course-level
`moodle/grade:edit` check that every action performs. That is the permission to
edit a gradebook column; exporting a quiz hands over every correct answer,
which is a broader privilege. Quiz actions now also check **`mod/quiz:manage`
on the quiz's module context** — the capability Moodle uses for "may edit this
quiz's questions", i.e. someone who can already see those answers in Moodle's
own UI.

Two behaviours, so a picker built from the API never offers a dead end:

- `ps_quiz_list()` **filters** to quizzes the caller may export.
- `ps_quiz_export()` **throws** `no-quiz-permission` when asked directly.

`get_capabilities` remains course-level and unchanged; per-quiz variation is
what the filtering is for.

Consequence, accepted: a non-editing teacher who can push grades loses quiz
export while keeping roster and grade sync.

### The quiz's own grade item

The payload previously described the quiz but not its gradebook column, so an
importer had no way to target it and would create a second, manual column
beside the real one — double-counting the assessment in the course total.

`list_quizzes` and the `quiz` block of `get_quiz_structure` now carry
`grade_item`: `id`, `name`, `min_mark`, `max_mark`, `hidden`, or `null` for an
ungraded quiz.

### Interface change

`quizlib.php` entry points take the acting user id as an explicit parameter —
`ps_quiz_list($course_id, $user_id)`, `ps_quiz_export($quiz_id, $course_id, $user_id)`.
Under `NO_MOODLE_COOKIES` the global `$USER` is not the actor, so a capability
check that fell back to it would silently test the wrong user. `api.php` passes
`$PS_USER_ID`; the PHPUnit tests pass a real enrolled teacher, which is what
makes the new gate testable at all.

### Correction

The degradation section previously listed a `shuffle-ignored` warning that was
never implemented. It has been removed from that list. Carrying Moodle's
`shuffleanswers` / `shufflequestions` through to PaperScorer's multi-version
support is a follow-up, not shipped.

---

## Amendment 2 — 2026-09-01

### The transport gap

Stage 2 could not proceed as designed. main-app has **no client for the
plugin's signed protocol**, and its existing Moodle integration is a different
architecture: `PS/Moodle/Pub/Controller/Moodle.php` implements an
admin-configured **web service token** flow (`actionRequestConnection` →
`actionInstanceCheck` → `actionConnect` → `actionImportCourses` →
`actionSyncData`) that pulls through core web services. There is no launch
receiver and no storage for the per-user key the plugin issues at launch. That
client half lives in Akindi's codebase, which is not on this machine.

**Decision: both transports.** The plugin now also publishes its three actions
as Moodle web service functions, so PaperScorer can reach them with the wstoken
it already stores. `api.php` is unchanged and remains the primary protocol.

Both transports call the same `quizlib.php` functions, and the capability
payload was factored into `ps_capabilities_payload()` so the two surfaces
cannot drift.

### Verification carried out

Moodle 4.2 moved `external_api` and friends into the `core_external` namespace
but **kept the legacy global names, with no deprecation notice** — notices were
only planned from 4.6 onward. The legacy names are therefore correct for a 2.7+
plugin. `externallib.php` still guards with `class_alias` against their
eventual removal.

### Built on the PaperScorer side

- `PS\Moodle\Util\PluginBridge` — calls the three functions through the
  existing `MoodleUtil::callWebservice()` (SSRF guard, logging, error parsing
  included) and unwraps the JSON envelope. `getCapabilities()` never throws for
  a missing plugin; it returns the `unsupported()` shape, which is layer-0
  degradation on the client side.
- `PS\Moodle\Util\QuizItemMapper` — pure; reshapes an exported question into
  the `raw_data` layout each PaperScorer item type expects.
- `PS\Moodle\Service\QuizImporter` — mirrors `FormImporter`; builds
  Assessment + Item + AssessmentItem, carries the quiz's `grade_item` in
  `external_data`, and returns `flagged_questions` for a pre-print review.

No new database table: `external_source` / `external_id` / `external_data`
cover the mapping, so no `scripts/db_updates/` migration.

### Not built

No controller action, route, template, or picker UI — the importer has no
caller yet. No integration test (needs the Docker MySQL).

---

## Amendment 3 — 2026-09-01

Running `QuizImporter` for the first time exposed three defects inherited from
using `PS\GoogleForms\Service\FormImporter` as the template. `FormImporter` is
the wrong model for a **content-managed** assessment; the Canvas import
(`PS\Canvas\Pub\Controller\Canvas::createImportedItem`) is the right one.

**1. Items had no stem.** `Item::question_text` holds the printed question text
on a content-managed assessment. `FormImporter` never sets it — it puts the
title in `external_data` only — so its imported items print blank. Every
imported item now carries the plugin's plain-text `text` on `question_text`.

**2. Multiple-choice answer text was lost.** On a content-managed item the
answer text is stored as `ps_item_choice` rows and is the *source of truth*;
`Repository\Item::rebuildChoiceData()` derives `responses`, `correct` and
`response_value` from them. Writing the key straight into `raw_data`, as
`FormImporter` does, produces bubbles with no option text beside them. The
importer now calls `syncChoices()` then `rebuildChoiceData()`.

**3. True/false used the wrong labels.** PaperScorer labels these bubbles `T`
and `F`, not `A` and `B`. The plugin emits generic `A`/`B` because it does not
know that convention, so `QuizItemMapper` translates. Getting this wrong would
have keyed every true/false question to a bubble that does not exist.

Also corrected: `number_response` needs `responses` (the supported digit
characters) and `correct` alongside `response_length`; items attach via
`Assessment::getNewItem()` so order and label are assigned by
`AssessmentItem::_preSave` rather than by hand.

### Interface change

`QuizImporter` now extends `\XF\Service\AbstractService`, resolving the item
repository with `$this->repository()` per CODING-STANDARDS §4 rule 1, and wraps
its writes in a transaction. Construct it with
`\XF::service('PS\Moodle:QuizImporter', $payload, $name)`. This also resolves
the standards deviation noted when it was first written.

### Verification

- Unit: 562 pass (24 across the two new Moodle files).
- Integration: 13 `QuizImporterTest` cases pass against the real database —
  the first actual execution of the importer.
- Four pre-existing integration failures (`LmsImportOptionsRender`,
  `CanvasReimportGating`, `StudentImportBulkProcess` ×2) were confirmed
  unrelated by re-running them with this work removed: identical results.

---

## Amendment 4 — 2026-09-02

The "no Moodle to develop against" risk is closed. `docker-compose.yml` stands
up `ps-moodle-php` (moodlehq/moodle-php-apache:8.2, port 8090) and
`ps-moodle-database` (mysql:8.0, port 3317) on `ps-moodle-network`, following
the `ps-<project>-<service>` convention used by main-app.

Moodle source is **not** vendored — it is cloned into the sibling
`../moodle-instance`. It must live outside this repo: the repo is bind-mounted
into the Moodle tree at `local/paperscorer`, so a Moodle tree nested inside the
repo would be a recursive bind mount.

### Install verified

The documented install procedure now has evidence behind it. On Moodle 4.5.13:
`local_paperscorer` registered at version 2026090200, all three web service
functions registered from `db/services.php`, and the pre-built **PaperScorer**
service created and enabled.

### Defect found by the first PHPUnit run

`ps_plugin_version()` was undefined under PHPUnit. It had been placed in
`common.php` on the reasoning that "every entry point includes common.php" —
but `common.php`'s first act is `require_once ../../config.php`, and a PHPUnit
test is *already* inside a booted Moodle, so it loads `quizlib.php` directly
and never sees `common.php`. Moved to `quizlib.php`, which both `api.php` and
the tests load.

**General rule, now recorded in CLAUDE.md:** anything the tests need must not
live in `common.php`.

### Verification

- Plugin PHPUnit suite: **17 tests, 45 assertions, passing** — its first
  execution. Confirms on real Moodle 4.5 what was previously only asserted:
  `qbank_helper::get_question_structure()`, `question_bank::load_question()`,
  `$question->rightanswer` for truefalse, `instanceof qtype_multichoice_single_question`,
  the `mod/quiz:manage` gate against actual editingteacher/student role
  defaults, and that `quiz_add_quiz_question()` still exists in 4.5.
- Web service transport exercised over real HTTP with a token.
  `get_capabilities` returns `slot_resolution: "qbank_helper"`, confirming the
  4.0+ branch is taken. `list_quizzes` returns `[]` for an empty course.
  `get_quiz_structure` on a missing quiz returns a structured
  `errorcode: no-such-quiz` **with a readable message** — which also confirms
  the rebrand's incidental fix, since those strings never resolved while the
  exception component was the invalid `'akindi'`.
- `externallib.php` previously had no coverage at all; this was its first
  execution.

### Operational note

`admin/tool/phpunit/cli/init.php` must be re-run after every
`$plugin->version` bump. Since every code change requires a bump, that is
every time.

### Still open

The plugin's PHPUnit suite has run only on Moodle 4.5. The pre-4.0
`quiz_slots.questionid` branch is still unexercised — it needs a 3.x instance,
which the compose file can produce by pointing `../moodle-instance` at a
`MOODLE_311_STABLE` checkout.

---

## Amendment 5 — 2026-09-02

The plugin is now verified at two points in its advertised range, on MySQL 8.4.

| Moodle | PHP | MySQL | PHPUnit | Result |
|---|---|---|---|---|
| 4.5.13 LTS | 8.3.33 | 8.4.11 | 9.6 | 17 tests, 45 assertions, pass |
| 5.2.2 | 8.4.25 | 8.4.11 | 11.5 | 17 tests, 45 assertions, pass |

### PHP 8.5 is not achievable, and the reason is Moodle's

Requested, attempted, and blocked at two independent layers:

- **Moodle 4.x refuses to install on PHP 8.4+.** `admin/environment.xml`
  declares `<RESTRICT function="restrict_php_version_84">`. Not a warning — the
  install aborted with zero tables created.
- **Moodle 5.x runs on PHP 8.5 but cannot be tested there.** Moodle 5.2
  installed successfully on 8.5.10 (490 tables, plugin registered), but its
  `composer.lock` pins `ezyang/htmlpurifier` v4.18.0 and `openspout/openspout`
  v4.28.5, both of which cap at PHP 8.4. Composer refuses, so `vendor/bin/phpunit`
  never appears. `--ignore-platform-req=php` installs PHPUnit but
  `admin/tool/phpunit/cli/init.php` runs Composer itself and fails the same way;
  a `config.platform.php` pin did not survive lock validation either.

`PHP_VERSION` is an `.env` variable so raising it is one line once Moodle's
dependencies allow it. The plugin's own pure logic is separately verified on
PHP 8.5.6 by `tests/standalone/run.php`, which runs on the host PHP.

### Moodle 5.x is structurally different

The web root moved into `public/`. The plugin mounts at
`public/local/paperscorer`, Apache's DocumentRoot is `public/`, and tool CLIs
live under `public/admin/tool/` — while `config.php` and the core `admin/cli/`
scripts stay at the tree root. `docker-compose.yml` is parameterised
(`MOODLE_SRC`, `MOODLE_PLUGIN_PATH`, `APACHE_DOCUMENT_ROOT`) so one image and
one compose file serve both generations; `.env` selects which.

### Defect found: test class naming

Moodle 5.x does not discover the global `local_paperscorer_quiz_export_testcase`
convention — it requires `namespace local_paperscorer; class quiz_export_test`.
The suite reported "No tests executed!" rather than failing, which is the
dangerous shape of that bug: a green-looking run that asserted nothing.

Both test classes were converted to the namespaced form and **re-verified on
4.5**, so the change did not trade LTS coverage for 5.x coverage.

### Image notes

Built from `php:8.4-apache` rather than `moodlehq/moodle-php-apache`, which
publishes no tag above 8.4. Two things the official image provides that a stock
one does not, both now in `docker/php.dockerfile`:

- **Locales.** Moodle's PHPUnit bootstrap refuses to run without `en_AU.UTF-8`.
- **opcache handling.** From PHP 8.5 opcache is compiled statically into the
  binary, so `docker-php-ext-install opcache` produces no shared module and
  fails the build; on 8.4 and earlier it is a normal shared extension.

### Still open

The pre-4.0 `quiz_slots.questionid` branch remains unexercised — both tested
instances take the `qbank_helper` path. That needs a 3.x checkout, which the
compose file can now serve by pointing `MOODLE_SRC` at it.
