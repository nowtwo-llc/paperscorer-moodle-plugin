# local_paperscorer Rebrand and Quiz Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebrand `local_akindi` to `local_paperscorer` and add API actions that export a Moodle quiz's questions and answer key as a normalized payload PaperScorer can import as an assessment.

**Architecture:** Two new files split the quiz work along a testability seam. `quizlib.php` touches Moodle (slot resolution, `question_bank::load_question()`) and flattens each question into a plain array descriptor. `classes/quiz_normalizer.php` is pure PHP with zero Moodle dependencies and turns descriptors into the payload PaperScorer consumes — which is what lets the risky mapping logic be tested in seconds with no Moodle at all. Version differences are confined to one function in `quizlib.php`.

**Tech Stack:** PHP 5.4-compatible procedural PHP, Moodle 2.7–5.x plugin APIs (`question_bank`, `mod_quiz\question\bank\qbank_helper`), Moodle PHPUnit, plus a hand-rolled zero-dependency assert harness.

**Spec:** `docs/superpowers/specs/2026-09-01-paperscorer-rebrand-and-quiz-export-design.md`

## Global Constraints

- **PHP 5.4 only.** No `??`, no scalar type hints, no return types, no `Foo::class`, no short closures. Use `array()`, not `[]`.
- **Moodle 2.7+ (`$plugin->requires = 2014051200`).** Only long-stable APIs. Branch on `class_exists('mod_quiz\question\bank\qbank_helper')`, never on a version number.
- **No dependency manager, no build system.** No composer, no `vendor/`. The repo is the deployed artifact.
- **Style:** 2-space indent, `snake_case`, `ps_`-prefixed globals and functions. Match the surrounding file; do not reformat to the Moodle coding standard.
- **Naming, exact:** component `local_paperscorer`; functions `ps_*`; globals `$PS_USER_ID`, `$PS_POST_JSON`; settings `$CFG->paperscorer_*`; wire params `ps_key`, `ps_signature`, `ps_expires`; header `X-PS-Plugin-Release`; grade source `'paperscorer'`; env var `PS_MOODLE_TEST`.
- **No compatibility shims.** No installed base; do not add dual-read fallbacks for `akindi_*` settings or `ak_*` wire params.
- **Bump `$plugin->version` in `version.php` after any code change** or Moodle will not re-install the plugin.
- **Version control:** this repo is **not** a git repository. Commit steps below assume `git init` has been run; skip them (and note it) until it has.

---

### Task 1: Rebrand every Akindi reference to PaperScorer

**Files:**
- Modify: `version.php`, `common.php`, `api.php`, `launch.php`, `lib.php`, `settings.php`, `README.rst`, `CHANGELOG.txt`
- Rename: `lang/en/local_akindi.php` → `lang/en/local_paperscorer.php`
- Rename: `pix/akindi-icon.png` → `pix/paperscorer-icon.png`, `pix/akindi-icon.svg` → `pix/paperscorer-icon.svg`
- Delete: `testing.php` (retired into PHPUnit fixtures in Task 6)

**Interfaces:**
- Consumes: nothing.
- Produces: `ps_sign($key, $to_sign)`, `ps_get($obj, $attr, $default)`, `ps_load_action($action)`, `ps_random_bytes($n)` in `common.php`; `ps_get_validate_course_id($action)` and `ps_action_<name>($action)` dispatch in `api.php`; globals `$PS_USER_ID`, `$PS_POST_JSON`; settings read as `$CFG->paperscorer_*`.

- [ ] **Step 1: Rename the language file and add error strings**

Delete `lang/en/local_akindi.php` and create `lang/en/local_paperscorer.php`. The old file had only four strings; the exception strings are new — every `moodle_exception` in the plugin currently passes `'akindi'` as the component, which is not a valid component, so none of them ever resolved.

```php
<?php

$string['pluginname'] = 'PaperScorer';
$string['launch'] = 'Launch PaperScorer';
$string['launching'] = 'Launching PaperScorer';
$string['returntocourse'] = 'Return to course';

$string['empty-signing-key'] = 'A required signing key is not configured.';
$string['invalid-attr'] = 'The request is missing a required attribute: {$a}';
$string['invalid-action-json'] = 'The action parameter is not valid JSON.';
$string['invalid-post-json'] = 'The request body is not valid JSON.';
$string['bad-signature'] = 'The request signature is not valid.';
$string['signature-expired'] = 'The request signature expired {$a} seconds ago.';
$string['unknown-action'] = 'Unknown action: {$a}';
$string['no-permission'] = 'User {$a->userid} does not have permission to edit grades in course {$a->courseid}.';
$string['no-such-grade-item'] = 'No such grade item.';
$string['multiple-grade-items'] = 'Multiple grade items matched.';
$string['no-such-quiz'] = 'No such quiz in this course.';
$string['quiz-export-unsupported'] = 'Quiz export is not supported on this Moodle version.';
```

- [ ] **Step 2: Rename the pix icons**

```bash
cd ~/Projects/PaperScorer/moodle-plugin
mv pix/akindi-icon.png pix/paperscorer-icon.png
mv pix/akindi-icon.svg pix/paperscorer-icon.svg
```

- [ ] **Step 3: Rewrite `version.php`**

```php
<?php
defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026090100;
$plugin->requires  = 2014051200;
$plugin->cron      = 0;
$plugin->component = 'local_paperscorer';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = 'v2.0.0';
$plugin->dependencies = array();
```

- [ ] **Step 4: Rewrite `common.php`**

Rename all four functions and correct the exception component to `local_paperscorer`.

```php
<?php
require_once(realpath(dirname(__FILE__)).'/../../config.php');

function ps_sign($key, $to_sign) {
  if (!$key)
    throw new moodle_exception("empty-signing-key", "local_paperscorer");
  return base64_encode(hash_hmac("sha1", $to_sign, trim($key), $raw_output=TRUE));
}

function ps_get($obj, $attr, $default=null) {
  if (!property_exists($obj, $attr)) {
    if ($default !== null)
      return $default;
    throw new moodle_exception("invalid-attr", "local_paperscorer", '', $attr);
  }
  return $obj->{$attr};
}

function ps_load_action($action) {
  $res = json_decode($action);
  if (!$res)
    throw new moodle_exception("invalid-action-json", "local_paperscorer");
  return $res;
}

function ps_random_bytes($n) {
  $res = "";
  while ($n > 0) {
    $res .= chr(rand(0, 255));
    $n -= 1;
  }
  return $res;
}

?>
```

- [ ] **Step 5: Rewrite `settings.php`**

Rename the setting keys and the two helper calls. Keep the bare-name convention.

```php
<?php

if ( $hassiteconfig ){
  require_once(realpath(dirname(__FILE__)).'/common.php');
  require_once(realpath(dirname(__FILE__)).'/lib.php');

  $settings = new admin_settingpage('local_paperscorer', 'PaperScorer Settings');
  $ADMIN->add('localplugins', $settings);

  $settings->add(new admin_setting_configtext(
    'paperscorer_launch_url',
    'PaperScorer launch URL',
    'The URL used to launch PaperScorer.',
    'https://app.paperscorer.com/api/moodle/launch',
    PARAM_TEXT
  ));

  $settings->add(new admin_setting_configtext(
    'paperscorer_public_key',
    'PaperScorer public key',
    'The public key given to you by PaperScorer.',
    '',
    PARAM_TEXT
  ));

  $settings->add(new admin_setting_configtext(
    'paperscorer_secret_key',
    'PaperScorer secret key',
    'The secret key given to you by PaperScorer.',
    '',
    PARAM_TEXT
  ));

  $settings->add(new admin_setting_configtext(
    'paperscorer_instance_secret',
    'PaperScorer instance secret',
    'A secret key you have generated (the default value is suitable). DO NOT share this value with PaperScorer.',
    bin2hex(ps_random_bytes(16)),
    PARAM_TEXT
  ));

  $settings->add(new admin_setting_configselect(
    'paperscorer_student_id_field',
    'PaperScorer student ID field',
    'The user profile field PaperScorer should use as a numeric student ID on bubble sheets.',
    'idnumber',
    ps_settings_get_student_id_options()
  ));

  $settings->add(new admin_setting_configcheckbox(
    'paperscorer_open_in_new_window',
    'Open in new window',
    'Opens PaperScorer in a new window. Note: users will get a "popup blocked" warning which they will need to disable.',
    false,
    PARAM_BOOL
  ));

  $settings->add(new admin_setting_configcheckbox(
    'paperscorer_enable_student_launch',
    'Enable student launch',
    'Makes the "Launch PaperScorer" link available to students so they can access their online assessments via Moodle.',
    false,
    PARAM_BOOL
  ));
}

?>
```

Confirm the launch URL default with the user before shipping — `https://app.paperscorer.com/api/moodle/launch` is a placeholder mirroring the old Akindi default.

- [ ] **Step 6: Rewrite `lib.php`**

Both hook function names must match the new component or navigation silently stops working.

```php
<?php

/**
 * @package    local_paperscorer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die;

require_once("$CFG->dirroot/user/profile/lib.php");

/**
 * Extends course navigation with the PaperScorer link (Moodle 3.X)
 */
function local_paperscorer_extend_navigation_course($navigation, $course, $context) {
  global $CFG;
  if (!$CFG->paperscorer_enable_student_launch && !has_capability('moodle/grade:edit', $context))
    return;

  $url = new moodle_url('/local/paperscorer/launch.php', array('id'=>$course->id));
  $navigation->add('Launch PaperScorer', $url, navigation_node::TYPE_SETTING, null, null, new pix_icon('paperscorer-icon', 'Launch PaperScorer', 'local_paperscorer'));
}

/**
 * Extends course navigation with the PaperScorer link (Moodle 2.X)
 */
function local_paperscorer_extends_settings_navigation($navigation, $context) {
  global $PAGE;
  global $CFG;
  if (!$CFG->paperscorer_enable_student_launch && !has_capability('moodle/grade:edit', $context))
    return;

  $settingnode = $navigation->find('courseadmin', navigation_node::TYPE_COURSE);
  if (!$settingnode)
    return;

  $settingnode->add_node(navigation_node::create(
    'Launch PaperScorer',
    new moodle_url('/local/paperscorer/launch.php', array('id'=>$PAGE->course->id)),
    navigation_node::NODETYPE_LEAF,
    'paperscorer',
    'null',
    new pix_icon('paperscorer-icon', 'Launch PaperScorer', 'local_paperscorer')
  ));
}

/**
 * Returns the possible fields used for student ID numbers.
 */
function ps_settings_get_student_id_options() {
  $options = array(
    'idnumber'=>"ID number (idnumber)",
    'userid'=>"Moodle user id (userid)",
  );
  $customfields = profile_get_custom_fields();
  foreach ($customfields as $field) {
    $options[$field->shortname] = "{$field->name} ({$field->shortname})";
  }
  return $options;
}
```

- [ ] **Step 7: Rewrite `launch.php`**

Rename every `$CFG->akindi_*` read, the `ak_sign` call, the form element id, and the JS function. The launch form's own field names (`public_key`, `signature`, `expires`, `data`) are already brand-neutral — leave them.

Apply these substitutions to the existing file:
- `$CFG->akindi_enable_student_launch` → `$CFG->paperscorer_enable_student_launch`
- the `$required_settings` array → `'paperscorer_launch_url'`, `'paperscorer_public_key'`, `'paperscorer_secret_key'`, `'paperscorer_instance_secret'`
- `ak_sign($CFG->akindi_instance_secret, $USER->id)` → `ps_sign($CFG->paperscorer_instance_secret, $USER->id)`
- `ak_sign($CFG->akindi_secret_key, $to_sign)` → `ps_sign($CFG->paperscorer_secret_key, $to_sign)`
- `$CFG->akindi_open_in_new_window` → `$CFG->paperscorer_open_in_new_window`
- `$CFG->akindi_launch_url` → `$CFG->paperscorer_launch_url`
- `$CFG->akindi_public_key` → `$CFG->paperscorer_public_key`
- `local_akindi` → `local_paperscorer` in every `get_string()` call
- `id="ak-launch-form"` → `id="ps-launch-form"`, `id="ak-submit-btn"` → `id="ps-submit-btn"`, `akDisableSubmit()` → `psDisableSubmit()`, and the two `getElementById` calls to match
- the "Only instructors ... can launch Akindi." copy → "... can launch PaperScorer."

- [ ] **Step 8: Rewrite `api.php`**

Apply these substitutions to the existing file:
- `ak_get_validate_course_id` → `ps_get_validate_course_id`; `ak_action_*` → `ps_action_*`; `ak_get` → `ps_get`; `ak_sign` → `ps_sign`; `ak_load_action` → `ps_load_action`; `ak_run` → `ps_run`
- `$AK_USER_ID` → `$PS_USER_ID`; `$AK_POST_JSON` → `$PS_POST_JSON`
- `$CFG->akindi_student_id_field` → `$CFG->paperscorer_student_id_field` (three occurrences)
- `$CFG->akindi_instance_secret` → `$CFG->paperscorer_instance_secret`
- `required_param('ak_key', ...)` → `'ps_key'`; `'ak_signature'` → `'ps_signature'`; `'ak_expires'` → `'ps_expires'`
- `"ak_action_" . ps_get($action, 'name')` → `"ps_action_" . ps_get($action, 'name')`
- `get_plugins_of_type('local')['akindi']` → `['paperscorer']`
- `header("X-Ak-Plugin-Release: " ...)` → `header("X-PS-Plugin-Release: " ...)`
- `update_final_grade($userid, $rawgrade, 'akindi', ...)` → `'paperscorer'`
- every `moodle_exception(..., 'akindi')` / `"akindi"` → `'local_paperscorer'`

Two exceptions take parameters now that the lang strings exist:

```php
    throw new moodle_exception("no-permission", 'local_paperscorer', '', array('userid'=>$PS_USER_ID, 'courseid'=>$course_id));
```

```php
    throw new moodle_exception("signature-expired", "local_paperscorer", '', $expired_ago);
```

```php
    throw new moodle_exception("unknown-action", "local_paperscorer", '', $action->name);
```

- [ ] **Step 9: Delete `testing.php`**

```bash
cd ~/Projects/PaperScorer/moodle-plugin && rm testing.php
```

Its fixture logic returns as PHPUnit fixtures in Task 6. This removes the HTTP endpoint Akindi's external suite drives; that suite must be retired alongside it.

- [ ] **Step 10: Update `README.rst` and `CHANGELOG.txt`**

In `README.rst`: retitle to "PaperScorer's Moodle Plugin", replace every Akindi mention, change the settings names in the step-8 table to `paperscorer_*`, update the install path to `local/paperscorer`, and update the GitHub download URL. The `doc-img/` screenshots still show Akindi branding — flag that as a follow-up rather than blocking on new screenshots.

Prepend to `CHANGELOG.txt`:

```
2.0.0 (2026-09-01):
    * Renamed the plugin from local_akindi to local_paperscorer. This is a
      breaking change: the component, all settings (paperscorer_*), and the
      API wire parameters (ps_key, ps_signature, ps_expires) are renamed with
      no compatibility fallback. The calling service must deploy in lockstep.
    * Added quiz export: get_capabilities, list_quizzes, and
      get_quiz_structure actions read a Moodle quiz's questions and answer key.
    * Removed testing.php in favour of PHPUnit tests under tests/.
```

- [ ] **Step 11: Syntax-check every file**

Run:
```bash
cd ~/Projects/PaperScorer/moodle-plugin && for f in *.php lang/en/*.php; do php -l "$f"; done
```
Expected: `No syntax errors detected` for each.

- [ ] **Step 12: Verify no Akindi references survive**

Run:
```bash
cd ~/Projects/PaperScorer/moodle-plugin && grep -rin "akindi\|\bak_\|AK_" --include="*.php" --include="*.rst" --include="*.txt" . | grep -v "^./docs/"
```
Expected: no output. (`docs/` is excluded — the spec and this plan discuss the old names deliberately.)

- [ ] **Step 13: Commit**

```bash
git add -A
git commit -m "refactor: rename local_akindi to local_paperscorer"
```

---

### Task 2: Standalone test harness and failing normalizer tests

**Files:**
- Create: `tests/standalone/run.php`
- Create: `tests/standalone/quiz_normalizer_cases.php`

**Interfaces:**
- Consumes: nothing yet — Task 3 implements what these tests call.
- Produces: `ps_test_assert_equals($expected, $actual, $label)`, `ps_test_report()` in `run.php`; the runner exits non-zero on failure.

- [ ] **Step 1: Write the harness**

Create `tests/standalone/run.php`. Zero dependencies, PHP 5.4, runnable with plain `php`.

```php
<?php
/*
 * Zero-dependency test runner for the pure normalization logic. No Moodle,
 * no composer, no PHPUnit. Run: php tests/standalone/run.php
 */

$GLOBALS['ps_test_passed'] = 0;
$GLOBALS['ps_test_failures'] = array();

function ps_test_assert_equals($expected, $actual, $label) {
  if ($expected === $actual) {
    $GLOBALS['ps_test_passed'] += 1;
    return;
  }
  $GLOBALS['ps_test_failures'][] = sprintf(
    "%s\n    expected: %s\n    actual:   %s",
    $label,
    str_replace("\n", "\n              ", var_export($expected, true)),
    str_replace("\n", "\n              ", var_export($actual, true))
  );
}

function ps_test_report() {
  $failures = $GLOBALS['ps_test_failures'];
  echo sprintf("\n%d passed, %d failed\n", $GLOBALS['ps_test_passed'], count($failures));
  foreach ($failures as $i => $failure) {
    echo sprintf("\n%d) %s\n", $i + 1, $failure);
  }
  return count($failures) === 0 ? 0 : 1;
}

require_once(realpath(dirname(__FILE__)) . '/../../classes/quiz_normalizer.php');
require_once(realpath(dirname(__FILE__)) . '/quiz_normalizer_cases.php');

exit(ps_test_report());

?>
```

- [ ] **Step 2: Write the failing test cases**

Create `tests/standalone/quiz_normalizer_cases.php`. These describe the full contract from the spec's type map.

```php
<?php

use local_paperscorer\quiz_normalizer;

// --- multichoice, single answer ---------------------------------------------
$descriptor = array(
  'slot' => 1,
  'question_id' => 881,
  'qtype' => 'multichoice',
  'name' => 'Capital of France',
  'text_html' => '<p>What is the capital of France?</p>',
  'maxmark' => 1.0,
  'single' => true,
  'answers' => array(
    array('text' => 'Lyon', 'fraction' => 0.0),
    array('text' => 'Paris', 'fraction' => 1.0),
    array('text' => 'Nice', 'fraction' => 0.0),
  ),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals('multiple_choice', $result['type'], 'multichoice maps to multiple_choice');
ps_test_assert_equals(array('A', 'B', 'C'), $result['responses'], 'multichoice responses are letters');
ps_test_assert_equals(array('B'), $result['correct'], 'multichoice correct is the fraction-1 letter');
ps_test_assert_equals('B', $result['response_value'], 'multichoice response_value is the joined letters');
ps_test_assert_equals('What is the capital of France?', $result['text'], 'stem html is stripped to text');
ps_test_assert_equals(array(), $result['warnings'], 'clean multichoice has no warnings');
ps_test_assert_equals('Paris', $result['response_text']['B'], 'response_text maps letter to option text');

// --- multichoice, multiple correct answers ----------------------------------
$descriptor = array(
  'slot' => 2, 'question_id' => 882, 'qtype' => 'multichoice', 'name' => 'Primes',
  'text_html' => 'Which are prime?', 'maxmark' => 2.0, 'single' => false,
  'answers' => array(
    array('text' => '2', 'fraction' => 0.5),
    array('text' => '4', 'fraction' => 0.0),
    array('text' => '7', 'fraction' => 0.5),
  ),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals(array('A', 'C'), $result['correct'], 'multi-answer collects every positive fraction');
ps_test_assert_equals('AC', $result['response_value'], 'multi-answer response_value joins letters in order');

// --- multichoice, single with partial credit --------------------------------
$descriptor = array(
  'slot' => 3, 'question_id' => 883, 'qtype' => 'multichoice', 'name' => 'Partial',
  'text_html' => 'Pick one', 'maxmark' => 1.0, 'single' => true,
  'answers' => array(
    array('text' => 'Best', 'fraction' => 1.0),
    array('text' => 'Nearly', 'fraction' => 0.5),
  ),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals(array('A'), $result['correct'], 'single-answer collapses to the highest fraction');
ps_test_assert_equals(array('partial-credit-collapsed'), $result['warnings'], 'collapsing partial credit warns');

// --- multichoice with no correct answer -------------------------------------
$descriptor = array(
  'slot' => 4, 'question_id' => 884, 'qtype' => 'multichoice', 'name' => 'Broken',
  'text_html' => 'No key', 'maxmark' => 1.0, 'single' => true,
  'answers' => array(array('text' => 'A thing', 'fraction' => 0.0)),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals(array(), $result['correct'], 'no positive fraction yields no correct answer');
ps_test_assert_equals(array('no-correct-answer'), $result['warnings'], 'missing key warns');

// --- truefalse ---------------------------------------------------------------
$descriptor = array(
  'slot' => 5, 'question_id' => 885, 'qtype' => 'truefalse', 'name' => 'TF',
  'text_html' => 'The sky is blue.', 'maxmark' => 1.0, 'rightanswer' => true,
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals('true_false', $result['type'], 'truefalse maps to true_false');
ps_test_assert_equals(array('A', 'B'), $result['responses'], 'truefalse has two responses');
ps_test_assert_equals(array('A'), $result['correct'], 'true maps to A');
ps_test_assert_equals('True', $result['response_text']['A'], 'A is labelled True');

$descriptor['rightanswer'] = false;
$result = quiz_normalizer::normalize($descriptor);
ps_test_assert_equals(array('B'), $result['correct'], 'false maps to B');

// --- match -------------------------------------------------------------------
$descriptor = array(
  'slot' => 6, 'question_id' => 886, 'qtype' => 'match', 'name' => 'Capitals',
  'text_html' => 'Match the capitals', 'maxmark' => 2.0,
  'stems' => array(1 => 'France', 2 => 'Japan'),
  'choices' => array(10 => 'Paris', 11 => 'Tokyo'),
  'right' => array(1 => 10, 2 => 11),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals('association_response', $result['type'], 'match maps to association_response');
ps_test_assert_equals(array('France', 'Japan'), $result['prompts'], 'match prompts come from stems');
ps_test_assert_equals(array('A', 'B'), $result['options'], 'match options are letters');
ps_test_assert_equals(array('A', 'B'), $result['correct'], 'match correct aligns to prompts');
ps_test_assert_equals('per_part', $result['grid_scoring_type'], 'match scores per part');
ps_test_assert_equals(1.0, $result['points'], 'match points are divided across rows');

// --- shortanswer -------------------------------------------------------------
$descriptor = array(
  'slot' => 7, 'question_id' => 887, 'qtype' => 'shortanswer', 'name' => 'SA',
  'text_html' => 'Name the capital of Japan.', 'maxmark' => 1.0,
  'answers' => array(
    array('text' => 'Tokyo', 'fraction' => 1.0),
    array('text' => 'tokyo', 'fraction' => 1.0),
  ),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals('writing_response', $result['type'], 'shortanswer maps to writing_response');
ps_test_assert_equals('Tokyo', $result['response_value'], 'shortanswer keeps the first full-credit answer');
ps_test_assert_equals('small', $result['size'], 'shortanswer gets a small writing area');
ps_test_assert_equals(array('hand-graded'), $result['warnings'], 'shortanswer warns it is hand-graded');

// --- numerical ---------------------------------------------------------------
$descriptor = array(
  'slot' => 8, 'question_id' => 888, 'qtype' => 'numerical', 'name' => 'Num',
  'text_html' => 'What is 2 + 2?', 'maxmark' => 1.0,
  'answers' => array(array('text' => '4', 'fraction' => 1.0, 'tolerance' => 0.5)),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals('number_response', $result['type'], 'numerical maps to number_response');
ps_test_assert_equals('4', $result['response_value'], 'numerical keeps the full-credit value');
ps_test_assert_equals(array('tolerance-dropped'), $result['warnings'], 'a non-zero tolerance warns');

// --- essay -------------------------------------------------------------------
$descriptor = array(
  'slot' => 9, 'question_id' => 889, 'qtype' => 'essay', 'name' => 'Essay',
  'text_html' => 'Discuss.', 'maxmark' => 5.0,
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals('writing_response', $result['type'], 'essay maps to writing_response');
ps_test_assert_equals('rubric_basic', $result['scoring_type'], 'essay uses rubric scoring');
ps_test_assert_equals(5.0, $result['points'], 'essay keeps its maxmark');
ps_test_assert_equals('medium', $result['size'], 'essay gets a medium writing area');

// --- unsupported types --------------------------------------------------------
foreach (array('multianswer', 'calculated', 'ddwtos', 'random') as $qtype) {
  $result = quiz_normalizer::normalize(array(
    'slot' => 10, 'question_id' => 890, 'qtype' => $qtype, 'name' => 'X',
    'text_html' => 'X', 'maxmark' => 1.0,
  ));
  ps_test_assert_equals(null, $result, $qtype . ' is not normalizable');
}

ps_test_assert_equals('unsupported-qtype', quiz_normalizer::skip_reason('multianswer'), 'unsupported qtype reason');
ps_test_assert_equals('random-question', quiz_normalizer::skip_reason('random'), 'random gets its own reason');

// --- html stripping -----------------------------------------------------------
ps_test_assert_equals(
  'Plain text',
  quiz_normalizer::html_to_text('<p>Plain <strong>text</strong></p>'),
  'inline markup is stripped'
);
ps_test_assert_equals(
  'A B',
  quiz_normalizer::html_to_text("<p>A</p>\n\n  <p>B</p>"),
  'whitespace is collapsed across blocks'
);
ps_test_assert_equals(
  'Caf&eacute; ok',
  htmlentities(quiz_normalizer::html_to_text('<p>Caf&eacute; ok</p>'), ENT_COMPAT, 'UTF-8'),
  'entities are decoded'
);

$descriptor = array(
  'slot' => 11, 'question_id' => 891, 'qtype' => 'truefalse', 'name' => 'Img',
  'text_html' => '<p>See <img src="x.png" alt="diagram"> above.</p>',
  'maxmark' => 1.0, 'rightanswer' => true,
);
$result = quiz_normalizer::normalize($descriptor);
ps_test_assert_equals(array('html-stripped'), $result['warnings'], 'a dropped image warns');

?>
```

- [ ] **Step 3: Run the tests to verify they fail**

Run:
```bash
cd ~/Projects/PaperScorer/moodle-plugin && php tests/standalone/run.php
```
Expected: a PHP fatal — `failed to open stream` for `classes/quiz_normalizer.php`, which does not exist yet.

- [ ] **Step 4: Commit**

```bash
git add tests/standalone
git commit -m "test: add standalone harness and failing quiz normalizer cases"
```

---

### Task 3: Implement the pure quiz normalizer

**Files:**
- Create: `classes/quiz_normalizer.php`
- Test: `tests/standalone/quiz_normalizer_cases.php` (from Task 2)

**Interfaces:**
- Consumes: the descriptor array shape asserted in Task 2.
- Produces: `\local_paperscorer\quiz_normalizer::normalize($descriptor)` returning an item array or `null`; `::skip_reason($qtype)` returning a string; `::html_to_text($html)` returning a string; `::type_map()` returning `array(qtype => ps_type)`.

Descriptor keys, produced by `quizlib.php` in Task 4: `slot`, `question_id`, `qtype`, `name`, `text_html`, `maxmark`, and per type — `single` + `answers` (multichoice), `rightanswer` (truefalse), `stems`/`choices`/`right` (match), `answers` (shortanswer, numerical). Each `answers` entry is `array('text' => string, 'fraction' => float)` plus an optional `tolerance` float for numerical.

- [ ] **Step 1: Write the implementation**

No Moodle calls anywhere in this file — that is what keeps it standalone-testable.

```php
<?php

namespace local_paperscorer;

/**
 * Turns a plain question descriptor (built by quizlib.php from a Moodle
 * question_definition) into the normalized item PaperScorer imports.
 *
 * This file must not call any Moodle API. It is loaded both by Moodle's
 * class autoloader and directly by tests/standalone/run.php.
 */
class quiz_normalizer {

  /**
   * Moodle qtype => PaperScorer item type. Anything absent is skipped.
   */
  public static function type_map() {
    return array(
      'multichoice' => 'multiple_choice',
      'truefalse'   => 'true_false',
      'match'       => 'association_response',
      'shortanswer' => 'writing_response',
      'numerical'   => 'number_response',
      'essay'       => 'writing_response',
    );
  }

  /**
   * Why a question could not be exported.
   */
  public static function skip_reason($qtype) {
    if ($qtype === 'random')
      return 'random-question';
    return 'unsupported-qtype';
  }

  /**
   * Collapse question HTML to plain text for a printed sheet.
   */
  public static function html_to_text($html) {
    $text = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\s*\/?>/i', ' ', $html);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
  }

  /**
   * True when stripping the markup would lose something a reader needs.
   * A plain <p> wrapper is not worth warning about; an image is.
   */
  protected static function loses_content($html) {
    return (bool) preg_match('/<(img|table|video|audio|object|embed|iframe)\b/i', $html);
  }

  protected static function letter($index) {
    return chr(65 + $index);
  }

  /**
   * @return array|null The normalized item, or null when the qtype cannot be exported.
   */
  public static function normalize($descriptor) {
    $qtype = $descriptor['qtype'];
    $map = self::type_map();
    if (!isset($map[$qtype]))
      return null;

    $item = array(
      'slot'           => $descriptor['slot'],
      'question_id'    => $descriptor['question_id'],
      'qtype'          => $qtype,
      'type'           => $map[$qtype],
      'name'           => $descriptor['name'],
      'text'           => self::html_to_text($descriptor['text_html']),
      'points'         => (float) $descriptor['maxmark'],
      'scoring_type'   => 'per_question',
      'response_value' => '',
      'warnings'       => array(),
    );

    if (self::loses_content($descriptor['text_html']))
      $item['warnings'][] = 'html-stripped';

    switch ($qtype) {
      case 'multichoice':
        $item = self::apply_multichoice($item, $descriptor);
        break;
      case 'truefalse':
        $item = self::apply_truefalse($item, $descriptor);
        break;
      case 'match':
        $item = self::apply_match($item, $descriptor);
        break;
      case 'shortanswer':
        $item = self::apply_shortanswer($item, $descriptor);
        break;
      case 'numerical':
        $item = self::apply_numerical($item, $descriptor);
        break;
      case 'essay':
        $item = self::apply_essay($item, $descriptor);
        break;
    }

    return $item;
  }

  protected static function apply_multichoice($item, $descriptor) {
    $answers = $descriptor['answers'];
    $single = !empty($descriptor['single']);

    $responses = array();
    $response_text = array();
    $positive = array();

    foreach (array_values($answers) as $index => $answer) {
      $letter = self::letter($index);
      $responses[] = $letter;
      $response_text[$letter] = self::html_to_text($answer['text']);
      if ($answer['fraction'] > 0)
        $positive[$letter] = (float) $answer['fraction'];
    }

    $correct = array();
    if ($single && count($positive) > 0) {
      $best = max($positive);
      foreach ($positive as $letter => $fraction) {
        if ($fraction == $best) {
          $correct[] = $letter;
          break;
        }
      }
      if (count($positive) > 1)
        $item['warnings'][] = 'partial-credit-collapsed';
    } else {
      foreach ($positive as $letter => $fraction) {
        $correct[] = $letter;
        if ($fraction < 1.0 && !in_array('partial-credit-collapsed', $item['warnings']))
          $item['warnings'][] = 'partial-credit-collapsed';
      }
    }

    if (count($positive) === 0)
      $item['warnings'][] = 'no-correct-answer';

    sort($correct);

    $item['responses'] = $responses;
    $item['response_text'] = $response_text;
    $item['correct'] = $correct;
    $item['response_value'] = implode('', $correct);

    return $item;
  }

  protected static function apply_truefalse($item, $descriptor) {
    $item['responses'] = array('A', 'B');
    $item['response_text'] = array('A' => 'True', 'B' => 'False');
    $item['correct'] = !empty($descriptor['rightanswer']) ? array('A') : array('B');
    $item['response_value'] = $item['correct'][0];
    return $item;
  }

  protected static function apply_match($item, $descriptor) {
    $stems = $descriptor['stems'];
    $choices = $descriptor['choices'];
    $right = $descriptor['right'];

    $choice_letters = array();
    $options = array();
    $index = 0;
    foreach ($choices as $choice_key => $choice_text) {
      $letter = self::letter($index);
      $choice_letters[$choice_key] = $letter;
      $options[] = $letter;
      $index += 1;
    }

    $prompts = array();
    $correct = array();
    foreach ($stems as $stem_key => $stem_text) {
      $prompts[] = self::html_to_text($stem_text);
      $choice_key = isset($right[$stem_key]) ? $right[$stem_key] : null;
      if ($choice_key !== null && isset($choice_letters[$choice_key])) {
        $correct[] = $choice_letters[$choice_key];
      } else {
        $correct[] = '';
        if (!in_array('no-correct-answer', $item['warnings']))
          $item['warnings'][] = 'no-correct-answer';
      }
    }

    $item['prompts'] = $prompts;
    $item['options'] = $options;
    $item['correct'] = $correct;
    $item['grid_scoring_type'] = 'per_part';
    $item['response_value'] = implode('', $correct);

    // maxmark covers the whole question; PaperScorer scores association rows
    // individually, so divide it across the rows.
    $rows = count($prompts);
    if ($rows > 0)
      $item['points'] = (float) $item['points'] / $rows;

    unset($item['response_text']);

    return $item;
  }

  protected static function apply_shortanswer($item, $descriptor) {
    $item['scoring_type'] = 'rubric_basic';
    $item['size'] = 'small';
    $item['response_value'] = self::first_full_credit_answer($descriptor['answers']);
    $item['warnings'][] = 'hand-graded';
    return $item;
  }

  protected static function apply_numerical($item, $descriptor) {
    $item['response_value'] = self::first_full_credit_answer($descriptor['answers']);
    foreach ($descriptor['answers'] as $answer) {
      if (!empty($answer['tolerance'])) {
        $item['warnings'][] = 'tolerance-dropped';
        break;
      }
    }
    return $item;
  }

  protected static function apply_essay($item, $descriptor) {
    $item['scoring_type'] = 'rubric_basic';
    $item['size'] = 'medium';
    return $item;
  }

  protected static function first_full_credit_answer($answers) {
    foreach ($answers as $answer) {
      if ($answer['fraction'] >= 1.0)
        return self::html_to_text($answer['text']);
    }
    return '';
  }
}
```

- [ ] **Step 2: Run the standalone tests to verify they pass**

Run:
```bash
cd ~/Projects/PaperScorer/moodle-plugin && php tests/standalone/run.php
```
Expected: `48 passed, 0 failed`, exit status 0.

- [ ] **Step 3: Syntax check**

Run:
```bash
cd ~/Projects/PaperScorer/moodle-plugin && php -l classes/quiz_normalizer.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add classes/quiz_normalizer.php
git commit -m "feat: add pure quiz normalizer with type map and degradation warnings"
```

---

### Task 4: Moodle-side quiz reading and version-branching slot resolution

**Files:**
- Create: `quizlib.php`

**Interfaces:**
- Consumes: `\local_paperscorer\quiz_normalizer::normalize()`, `::skip_reason()` from Task 3.
- Produces: `ps_quiz_list($course_id)`, `ps_quiz_slot_resolution()`, `ps_quiz_get_slots($quiz_id, $context)`, `ps_quiz_describe_question($question, $slot, $maxmark)`, `ps_quiz_export($quiz_id, $course_id)`.

- [ ] **Step 1: Write the implementation**

```php
<?php

/**
 * Reads Moodle quizzes and their question banks.
 *
 * The only version-variant logic in the plugin lives in
 * ps_quiz_get_slots(): Moodle 4.0 dropped quiz_slots.questionid and moved
 * the link to question_references. Everything downstream uses
 * question_bank::load_question(), stable since Moodle 2.1.
 */
defined('MOODLE_INTERNAL') || die();

require_once("$CFG->dirroot/question/engine/lib.php");
require_once("$CFG->dirroot/question/engine/bank.php");

/**
 * Which slot-resolution strategy this Moodle supports.
 *
 * @return string 'qbank_helper' (4.0+), 'quiz_slots' (2.7-3.11), or '' if neither.
 */
function ps_quiz_slot_resolution() {
  if (class_exists('mod_quiz\question\bank\qbank_helper'))
    return 'qbank_helper';

  global $DB;
  $manager = $DB->get_manager();
  if ($manager->table_exists('quiz_slots'))
    return 'quiz_slots';

  return '';
}

/**
 * Every quiz in a course.
 */
function ps_quiz_list($course_id) {
  global $DB;

  $quizzes = $DB->get_records('quiz', array('course'=>$course_id), 'name ASC');
  $result = array();

  foreach ($quizzes as $quiz) {
    $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course_id, false, IGNORE_MISSING);
    $result[] = array(
      'id'             => (int) $quiz->id,
      'cmid'           => $cm ? (int) $cm->id : 0,
      'name'           => $quiz->name,
      'sumgrades'      => $quiz->sumgrades === null ? 0 : (float) $quiz->sumgrades,
      'question_count' => $DB->count_records('quiz_slots', array('quizid'=>$quiz->id)),
    );
  }

  return $result;
}

/**
 * Resolve a quiz's slots to question ids.
 *
 * @return array of array('slot'=>int, 'questionid'=>int, 'maxmark'=>float)
 *         questionid is 0 when the slot cannot resolve to a fixed question
 *         (a random slot), which the caller reports as skipped.
 */
function ps_quiz_get_slots($quiz_id, $context) {
  global $DB;

  $resolution = ps_quiz_slot_resolution();
  $slots = array();

  if ($resolution === 'qbank_helper') {
    // Moodle 4.0+: quiz_slots.questionid is gone; links live in question_references.
    $structure = \mod_quiz\question\bank\qbank_helper::get_question_structure($quiz_id, $context);
    foreach ($structure as $slot) {
      $questionid = isset($slot->questionid) ? (int) $slot->questionid : 0;
      // Random slots carry no resolvable question.
      if (!empty($slot->qtype) && $slot->qtype === 'random')
        $questionid = 0;
      $slots[] = array(
        'slot'       => (int) $slot->slot,
        'questionid' => $questionid,
        'maxmark'    => isset($slot->maxmark) ? (float) $slot->maxmark : 0.0,
      );
    }
    return $slots;
  }

  if ($resolution === 'quiz_slots') {
    // Moodle 2.7 - 3.11.
    $records = $DB->get_records('quiz_slots', array('quizid'=>$quiz_id), 'slot ASC');
    foreach ($records as $record) {
      $slots[] = array(
        'slot'       => (int) $record->slot,
        'questionid' => isset($record->questionid) ? (int) $record->questionid : 0,
        'maxmark'    => isset($record->maxmark) ? (float) $record->maxmark : 0.0,
      );
    }
    return $slots;
  }

  return $slots;
}

/**
 * Flatten a Moodle question_definition into the plain descriptor the
 * normalizer consumes. Keeps every Moodle-specific property access here so
 * quiz_normalizer stays testable without Moodle.
 */
function ps_quiz_describe_question($question, $slot, $maxmark) {
  $descriptor = array(
    'slot'        => $slot,
    'question_id' => (int) $question->id,
    'qtype'       => $question->get_type_name(),
    'name'        => $question->name,
    'text_html'   => $question->questiontext,
    'maxmark'     => $maxmark > 0 ? $maxmark : (float) $question->defaultmark,
  );

  switch ($descriptor['qtype']) {
    case 'multichoice':
      // Single vs multi is the question class, not a property.
      $descriptor['single'] = ($question instanceof qtype_multichoice_single_question);
      $descriptor['answers'] = array();
      foreach ($question->answers as $answer) {
        $descriptor['answers'][] = array(
          'text'     => $answer->answer,
          'fraction' => (float) $answer->fraction,
        );
      }
      break;

    case 'truefalse':
      // qtype_truefalse_question exposes the key as a plain bool.
      $descriptor['rightanswer'] = !empty($question->rightanswer);
      break;

    case 'match':
      $descriptor['stems'] = $question->stems;
      $descriptor['choices'] = $question->choices;
      $descriptor['right'] = $question->right;
      break;

    case 'shortanswer':
      $descriptor['answers'] = array();
      foreach ($question->answers as $answer) {
        $descriptor['answers'][] = array(
          'text'     => $answer->answer,
          'fraction' => (float) $answer->fraction,
        );
      }
      break;

    case 'numerical':
      $descriptor['answers'] = array();
      foreach ($question->answers as $answer) {
        $descriptor['answers'][] = array(
          'text'      => $answer->answer,
          'fraction'  => (float) $answer->fraction,
          'tolerance' => isset($answer->tolerance) ? (float) $answer->tolerance : 0.0,
        );
      }
      break;
  }

  return $descriptor;
}

/**
 * Export one quiz as the normalized payload.
 */
function ps_quiz_export($quiz_id, $course_id) {
  global $DB, $CFG;

  $quiz = $DB->get_record('quiz', array('id'=>$quiz_id, 'course'=>$course_id));
  if (!$quiz)
    throw new moodle_exception('no-such-quiz', 'local_paperscorer');

  $resolution = ps_quiz_slot_resolution();
  if ($resolution === '')
    throw new moodle_exception('quiz-export-unsupported', 'local_paperscorer');

  $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course_id, false, MUST_EXIST);
  $context = context_module::instance($cm->id);

  $questions = array();
  $skipped = array();

  foreach (ps_quiz_get_slots($quiz_id, $context) as $slot) {
    if (!$slot['questionid']) {
      $skipped[] = array(
        'slot'   => $slot['slot'],
        'qtype'  => 'random',
        'reason' => \local_paperscorer\quiz_normalizer::skip_reason('random'),
      );
      continue;
    }

    try {
      $question = question_bank::load_question($slot['questionid']);
    } catch (Exception $e) {
      $skipped[] = array(
        'slot'   => $slot['slot'],
        'qtype'  => 'unknown',
        'reason' => 'load-failed',
      );
      continue;
    }

    $descriptor = ps_quiz_describe_question($question, $slot['slot'], $slot['maxmark']);
    $item = \local_paperscorer\quiz_normalizer::normalize($descriptor);

    if ($item === null) {
      $skipped[] = array(
        'slot'   => $slot['slot'],
        'qtype'  => $descriptor['qtype'],
        'reason' => \local_paperscorer\quiz_normalizer::skip_reason($descriptor['qtype']),
      );
      continue;
    }

    $questions[] = $item;
  }

  return array(
    'quiz' => array(
      'id'        => (int) $quiz->id,
      'cmid'      => (int) $cm->id,
      'name'      => $quiz->name,
      'sumgrades' => $quiz->sumgrades === null ? 0 : (float) $quiz->sumgrades,
    ),
    'source' => array(
      'moodle_release'  => $CFG->release,
      'plugin_version'  => ps_plugin_version(),
      'slot_resolution' => $resolution,
    ),
    'questions' => $questions,
    'skipped'   => $skipped,
  );
}

?>
```

- [ ] **Step 2: Syntax check**

Run:
```bash
cd ~/Projects/PaperScorer/moodle-plugin && php -l quizlib.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add quizlib.php
git commit -m "feat: read quiz slots and questions across Moodle 2.7-5.x"
```

---

### Task 5: Wire the three new API actions

**Files:**
- Modify: `api.php` (add `ps_plugin_version()`, three actions, and the `quizlib.php` include)

**Interfaces:**
- Consumes: `ps_quiz_list()`, `ps_quiz_export()`, `ps_quiz_slot_resolution()` from Task 4; `ps_get_validate_course_id()` and `ps_get()` from Task 1.
- Produces: `ps_action_get_capabilities($action)`, `ps_action_list_quizzes($action)`, `ps_action_get_quiz_structure($action)`, `ps_plugin_version()`.

- [ ] **Step 1: Add the include and extract the version helper**

In `api.php`, add below the existing `require_once` block:

```php
require_once(realpath(dirname(__FILE__)).'/quizlib.php');
```

Replace the trailing header-emitting lines with a reusable helper, so `quizlib.php` can report the version too:

```php
function ps_plugin_version() {
  $cpm = core_plugin_manager::instance();
  $plugins = $cpm->get_plugins_of_type('local');
  if (!isset($plugins['paperscorer']))
    return 0;
  return (int) $plugins['paperscorer']->versiondisk;
}
```

and change the header line to:

```php
header("X-PS-Plugin-Release: " . ps_plugin_version());
```

- [ ] **Step 2: Add the three actions**

Add alongside the existing `ps_action_*` functions. Every one calls `ps_get_validate_course_id()` first — skipping it makes the endpoint an unchecked super-user.

```php
function ps_action_get_capabilities($action) {
  global $CFG;

  $course_id = ps_get_validate_course_id($action);
  $resolution = ps_quiz_slot_resolution();

  return array(
    'plugin_version' => ps_plugin_version(),
    'moodle_release' => $CFG->release,
    'features' => array(
      'roster'      => true,
      'grades'      => true,
      'quiz_export' => $resolution !== '',
    ),
    'quiz_export_detail' => array(
      'supported'       => $resolution !== '',
      'slot_resolution' => $resolution,
      'reason'          => $resolution === '' ? 'no-quiz-slots-table' : '',
      'supported_qtypes' => array_keys(\local_paperscorer\quiz_normalizer::type_map()),
    ),
  );
}

function ps_action_list_quizzes($action) {
  $course_id = ps_get_validate_course_id($action);
  return ps_quiz_list($course_id);
}

function ps_action_get_quiz_structure($action) {
  $course_id = ps_get_validate_course_id($action);
  $quiz_id = ps_get($action, 'quiz_id');
  return ps_quiz_export($quiz_id, $course_id);
}
```

- [ ] **Step 3: Syntax check**

Run:
```bash
cd ~/Projects/PaperScorer/moodle-plugin && php -l api.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Verify every action still validates the course**

Run:
```bash
cd ~/Projects/PaperScorer/moodle-plugin && grep -c "ps_get_validate_course_id(\$action)" api.php
```
Expected: `8` — one definition plus seven call sites (`get_roster`, `list_grade_items`, `create_update_grade_item`, `update_grades`, `get_capabilities`, `list_quizzes`, `get_quiz_structure`). `selftest` does not take a course.

- [ ] **Step 5: Commit**

```bash
git add api.php
git commit -m "feat: add get_capabilities, list_quizzes and get_quiz_structure actions"
```

---

### Task 6: Moodle PHPUnit tests

**Files:**
- Create: `tests/quiz_normalizer_test.php`
- Create: `tests/quiz_export_test.php`

**Interfaces:**
- Consumes: everything from Tasks 3–5.
- Produces: nothing consumed by later tasks.

These require a Moodle tree with PHPUnit initialised. They are the layer that covers what the standalone runner cannot: real question generation, slot resolution on the live schema, and the capability check.

- [ ] **Step 1: Write the normalizer test under Moodle's runner**

Create `tests/quiz_normalizer_test.php`. This re-runs the standalone cases inside Moodle so a single command covers both layers in CI.

```php
<?php

defined('MOODLE_INTERNAL') || die();

/**
 * @group local_paperscorer
 */
class local_paperscorer_quiz_normalizer_testcase extends advanced_testcase {

  public function test_standalone_suite_passes() {
    $runner = realpath(dirname(__FILE__)) . '/standalone/run.php';
    $output = array();
    $status = 0;
    exec('php ' . escapeshellarg($runner) . ' 2>&1', $output, $status);
    $this->assertEquals(0, $status, "Standalone normalizer suite failed:\n" . implode("\n", $output));
  }

  public function test_type_map_covers_the_supported_qtypes() {
    $map = \local_paperscorer\quiz_normalizer::type_map();
    $this->assertEquals('multiple_choice', $map['multichoice']);
    $this->assertEquals('true_false', $map['truefalse']);
    $this->assertEquals('association_response', $map['match']);
    $this->assertEquals('number_response', $map['numerical']);
    $this->assertArrayNotHasKey('multianswer', $map);
  }
}
```

- [ ] **Step 2: Write the export test against real generated questions**

Create `tests/quiz_export_test.php`.

```php
<?php

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/paperscorer/quizlib.php');

/**
 * @group local_paperscorer
 */
class local_paperscorer_quiz_export_testcase extends advanced_testcase {

  /**
   * Build a course with a quiz holding one multichoice and one truefalse
   * question. Returns array(course, quiz).
   */
  protected function make_quiz_with_questions() {
    $generator = $this->getDataGenerator();
    $course = $generator->create_course();
    $quiz = $generator->create_module('quiz', array('course'=>$course->id, 'name'=>'Unit 3 Test'));

    $questiongenerator = $generator->get_plugin_generator('core_question');
    $category = $questiongenerator->create_question_category();

    $mc = $questiongenerator->create_question('multichoice', 'one_of_four', array('category'=>$category->id));
    $tf = $questiongenerator->create_question('truefalse', null, array('category'=>$category->id));

    quiz_add_quiz_question($mc->id, $quiz);
    quiz_add_quiz_question($tf->id, $quiz);

    return array($course, $quiz);
  }

  public function test_slot_resolution_is_available() {
    $this->resetAfterTest(true);
    $this->assertNotEquals('', ps_quiz_slot_resolution(), 'No slot resolution strategy on this Moodle');
  }

  public function test_list_quizzes_returns_the_course_quiz() {
    $this->resetAfterTest(true);
    list($course, $quiz) = $this->make_quiz_with_questions();

    $quizzes = ps_quiz_list($course->id);

    $this->assertCount(1, $quizzes);
    $this->assertEquals('Unit 3 Test', $quizzes[0]['name']);
    $this->assertEquals(2, $quizzes[0]['question_count']);
  }

  public function test_export_returns_normalized_questions() {
    $this->resetAfterTest(true);
    list($course, $quiz) = $this->make_quiz_with_questions();

    $payload = ps_quiz_export($quiz->id, $course->id);

    $this->assertEquals('Unit 3 Test', $payload['quiz']['name']);
    $this->assertCount(2, $payload['questions']);
    $this->assertEquals(array(), $payload['skipped']);

    $types = array();
    foreach ($payload['questions'] as $question) {
      $types[] = $question['type'];
      $this->assertNotEmpty($question['correct'], 'Question ' . $question['slot'] . ' has no answer key');
    }
    sort($types);
    $this->assertEquals(array('multiple_choice', 'true_false'), $types);
  }

  public function test_export_reports_the_slot_resolution_used() {
    $this->resetAfterTest(true);
    list($course, $quiz) = $this->make_quiz_with_questions();

    $payload = ps_quiz_export($quiz->id, $course->id);

    $this->assertContains($payload['source']['slot_resolution'], array('qbank_helper', 'quiz_slots'));
    $this->assertNotEmpty($payload['source']['moodle_release']);
  }

  public function test_export_rejects_a_quiz_from_another_course() {
    $this->resetAfterTest(true);
    list($course, $quiz) = $this->make_quiz_with_questions();
    $other = $this->getDataGenerator()->create_course();

    $this->expectException('moodle_exception');
    ps_quiz_export($quiz->id, $other->id);
  }
}
```

- [ ] **Step 3: Run the standalone suite (no Moodle needed)**

Run:
```bash
cd ~/Projects/PaperScorer/moodle-plugin && php tests/standalone/run.php
```
Expected: `48 passed, 0 failed`.

- [ ] **Step 4: Run the Moodle suite**

Requires the plugin symlinked into a Moodle tree at `local/paperscorer` and PHPUnit initialised (`php admin/tool/phpunit/cli/init.php`).

Run, from the Moodle root:
```bash
vendor/bin/phpunit --group local_paperscorer
```
Expected: all tests pass. If no Moodle is available yet, record this step as blocked rather than marking it done — see the Risks section of the spec.

- [ ] **Step 5: Commit**

```bash
git add tests
git commit -m "test: add Moodle PHPUnit coverage for quiz export"
```

---

### Task 7: Document the new actions

**Files:**
- Modify: `README.rst`
- Modify: `CLAUDE.md`
- Modify: `version.php`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

- [ ] **Step 1: Add a Quiz Export section to `README.rst`**

Document the three actions, the payload shape, the supported qtype table from the spec, and the four degradation layers. Keep it to what an integrator needs: the action names, their parameters, and what `skipped` and `warnings` mean.

- [ ] **Step 2: Update `CLAUDE.md`**

The existing file documents the Akindi names throughout and will actively mislead after this change. Update: the component name and install path, the `ps_*` conventions, the settings prefix, the `ps_key`/`ps_signature`/`ps_expires` wire params, the current action list (now eight), the removal of `testing.php`, the new `quizlib.php` / `classes/quiz_normalizer.php` split and why the seam exists, and the two test commands.

- [ ] **Step 3: Bump the version**

```php
$plugin->version   = 2026090101;
```

- [ ] **Step 4: Final verification**

Run:
```bash
cd ~/Projects/PaperScorer/moodle-plugin && \
  for f in *.php lang/en/*.php classes/*.php tests/standalone/*.php; do php -l "$f"; done && \
  php tests/standalone/run.php && \
  grep -rin "akindi" --include="*.php" --include="*.rst" --include="*.txt" . | grep -v "^./docs/" | grep -v CHANGELOG
```
Expected: all files syntax-clean, `48 passed, 0 failed`, and no Akindi references outside `docs/` and the CHANGELOG entry that documents the rename.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "docs: document quiz export actions and update project guidance"
```

---

## Self-Review

**Spec coverage.** Rebrand → Task 1. Incidental exception-component fix → Task 1 Steps 1, 8. Version strategy and the two-branch shim → Task 4. PHP 5.4 constraint → Global Constraints, honoured throughout (no `??`, no `::class`, `array()` only). Three new actions → Task 5. Normalized payload → Tasks 3–4. Type map → Task 3. Degradation layers 0–3 → layer 0 and 1 in Task 5 (`get_capabilities`) and Task 4 (`ps_quiz_slot_resolution`), layer 2 in Task 4 (`skipped`), layer 3 in Task 3 (`warnings`). Testing both layers → Tasks 2, 3, 6. `testing.php` retirement → Task 1 Step 9, fixtures in Task 6. Stage 2 (main-app importer) is deliberately out of scope.

**Placeholder scan.** One deliberate open item: the `paperscorer_launch_url` default in Task 1 Step 5 is flagged for confirmation rather than guessed silently. Task 7 Steps 1–2 describe documentation content rather than quoting it verbatim, which is appropriate for prose. No TBDs elsewhere.

**Type consistency.** `quiz_normalizer::normalize()`, `::skip_reason()`, `::html_to_text()`, `::type_map()` are used identically in Tasks 3, 4, 5, and 6. `ps_plugin_version()` is defined in Task 5 Step 1 and called from `quizlib.php` (Task 4) — Task 5 must land before the Moodle tests in Task 6 run, which the task order guarantees. Descriptor keys asserted in Task 2 match those produced in Task 4 (`slot`, `question_id`, `qtype`, `name`, `text_html`, `maxmark`, `single`, `answers`, `rightanswer`, `stems`, `choices`, `right`, `tolerance`).

**Known risk carried from the spec.** `ps_quiz_describe_question()`'s `match` branch reads `$question->stems` / `->choices` / `->right`, and the `truefalse` branch reads `$question->rightanswer`. These property names are stable in the question engine but are the most likely place for a surprise on an untested Moodle version — Task 6's `test_export_returns_normalized_questions` is what catches it.

## Execution notes (2026-09-01)

Executed inline. Deviations from the plan as written, all applied:

1. **`ps_plugin_version()` lives in `common.php`, not `api.php`.** `quizlib.php`
   calls it, and the PHPUnit tests load `quizlib.php` without `api.php`, so
   defining it in `api.php` would have been a fatal at test time.
2. **`truefalse` reads `$question->rightanswer`** (a plain bool on
   `qtype_truefalse_question`). The plan's `$question->trueanswer->fraction` was
   wrong — `trueanswer` is a column on the `question_truefalse` table, not a
   property of the question definition.
3. **`multichoice` single-vs-multi is the class**
   (`$question instanceof qtype_multichoice_single_question`), not a `single`
   property. `single` is a column on `qtype_multichoice_options`.
4. **Multi-answer partial-credit warning is sum-based.** Fractions that add up
   to 1.0 are Moodle's normal way of saying "all of these together" and lose
   nothing, so they no longer warn; only a scheme that does not sum to full
   credit raises `partial-credit-collapsed`. Two extra standalone cases cover
   both sides.
5. **Assertion count is 48**, not the estimated 43.

Not done: `vendor/bin/phpunit --group local_paperscorer` (Task 6 Step 4) has
never been run — there is no Moodle instance available. Every commit step is
also outstanding: the repo is not a git repository.
