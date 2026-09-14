<?php

/**
 * Reads Moodle quizzes and their question banks.
 *
 * The only version-variant logic in the plugin lives in
 * ps_quiz_slot_resolution() / ps_quiz_get_slots(): Moodle 4.0 dropped
 * quiz_slots.questionid and moved the link to question_references.
 * Everything downstream goes through question_bank::load_question(), stable
 * since the Moodle 2.1 question engine, so answer-key extraction is one code
 * path across the whole supported range.
 *
 * Every entry point takes the acting user id explicitly. Under the API's
 * NO_MOODLE_COOKIES bootstrap $USER is NOT the acting user, so capability
 * checks must never fall back to it.
 *
 * PHP 5.4 compatible: the plugin supports Moodle 2.7+.
 */
defined('MOODLE_INTERNAL') || die();

require_once("$CFG->dirroot/question/engine/lib.php");
require_once("$CFG->libdir/gradelib.php");

/**
 * The installed plugin version.
 *
 * Lives here rather than in common.php: common.php's first act is to require
 * config.php, so a PHPUnit test — which is already inside a booted Moodle —
 * can never include it. quizlib.php is loaded by both api.php and the tests.
 */
function ps_plugin_version() {
  $cpm = core_plugin_manager::instance();
  $plugins = $cpm->get_plugins_of_type('local');
  if (!isset($plugins['paperscorer']))
    return 0;
  return (int) $plugins['paperscorer']->versiondisk;
}

/**
 * Which slot-resolution strategy this Moodle supports.
 *
 * @return string 'qbank_helper' (4.0+), 'quiz_slots' (2.7-3.11), or '' if neither.
 */
function ps_quiz_slot_resolution() {
  global $DB;

  if (class_exists('mod_quiz\question\bank\qbank_helper'))
    return 'qbank_helper';

  if ($DB->get_manager()->table_exists('quiz_slots'))
    return 'quiz_slots';

  return '';
}

/**
 * The capability handshake payload. Shared by both transports (api.php's
 * signed endpoint and the web service) so they can never drift apart.
 */
function ps_capabilities_payload($course_id) {
  global $CFG;

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
      'supported'        => $resolution !== '',
      'slot_resolution'  => $resolution,
      'reason'           => $resolution === '' ? 'no-quiz-slots-table' : '',
      'supported_qtypes' => array_keys(\local_paperscorer\quiz_normalizer::type_map()),
    ),
  );
}

/**
 * May this user read the answer key for the quiz in this module context?
 *
 * Exporting a quiz hands over every correct answer, which is a broader
 * privilege than editing a gradebook column. mod/quiz:manage is the
 * capability Moodle uses for "may edit this quiz's questions" — i.e. someone
 * who can already see the answers in Moodle's own UI.
 */
function ps_quiz_can_export($context, $user_id) {
  return has_capability('mod/quiz:manage', $context, $user_id);
}

/**
 * The quiz activity's own grade item, so PaperScorer can push scores into the
 * existing column instead of creating a second, unweighted manual one.
 *
 * @return array|null null when the quiz is ungraded.
 */
function ps_quiz_grade_item($quiz_id, $course_id) {
  $item = grade_item::fetch(array(
    'courseid'     => $course_id,
    'itemtype'     => 'mod',
    'itemmodule'   => 'quiz',
    'iteminstance' => $quiz_id,
    'itemnumber'   => 0,
  ));

  if (!$item)
    return null;

  return array(
    'id'       => (int) $item->id,
    'name'     => $item->itemname,
    'min_mark' => (float) $item->grademin,
    'max_mark' => (float) $item->grademax,
    'hidden'   => (bool) $item->hidden,
  );
}

/**
 * Every quiz in a course this user may export.
 *
 * Quizzes the user cannot export are omitted rather than returned and
 * rejected later, so a picker built from this never offers a dead end.
 */
function ps_quiz_list($course_id, $user_id) {
  global $DB;

  $quizzes = $DB->get_records('quiz', array('course'=>$course_id), 'name ASC');
  $result = array();

  foreach ($quizzes as $quiz) {
    $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course_id, false, IGNORE_MISSING);
    if (!$cm)
      continue;

    $context = context_module::instance($cm->id);
    if (!ps_quiz_can_export($context, $user_id))
      continue;

    array_push($result, array(
      'id'             => (int) $quiz->id,
      'cmid'           => (int) $cm->id,
      'name'           => $quiz->name,
      'sumgrades'      => $quiz->sumgrades === null ? 0 : (float) $quiz->sumgrades,
      'question_count' => $DB->count_records('quiz_slots', array('quizid'=>$quiz->id)),
      'grade_item'     => ps_quiz_grade_item($quiz->id, $course_id),
    ));
  }

  return $result;
}

/**
 * Resolve a quiz's slots to question ids.
 *
 * @return array of array('slot'=>int, 'questionid'=>int, 'maxmark'=>float).
 *         questionid is 0 when the slot cannot resolve to a fixed question
 *         (a random slot), which the caller reports as skipped.
 */
function ps_quiz_get_slots($quiz_id, $context) {
  global $DB;

  $resolution = ps_quiz_slot_resolution();
  $slots = array();

  if ($resolution === 'qbank_helper') {
    // Moodle 4.0+: quiz_slots.questionid is gone; the link lives in
    // question_references, and random slots in question_set_references.
    $structure = \mod_quiz\question\bank\qbank_helper::get_question_structure($quiz_id, $context);
    foreach ($structure as $slot) {
      $questionid = isset($slot->questionid) ? (int) $slot->questionid : 0;
      if (!empty($slot->qtype) && $slot->qtype === 'random')
        $questionid = 0;
      array_push($slots, array(
        'slot'       => (int) $slot->slot,
        'questionid' => $questionid,
        'maxmark'    => isset($slot->maxmark) ? (float) $slot->maxmark : 0.0,
      ));
    }
    return $slots;
  }

  if ($resolution === 'quiz_slots') {
    // Moodle 2.7 - 3.11.
    $records = $DB->get_records('quiz_slots', array('quizid'=>$quiz_id), 'slot ASC');
    foreach ($records as $record) {
      array_push($slots, array(
        'slot'       => (int) $record->slot,
        'questionid' => isset($record->questionid) ? (int) $record->questionid : 0,
        'maxmark'    => isset($record->maxmark) ? (float) $record->maxmark : 0.0,
      ));
    }
    return $slots;
  }

  return $slots;
}

/**
 * Flatten a Moodle question_definition into the plain descriptor the
 * normalizer consumes. Every Moodle-specific property access lives here so
 * quiz_normalizer stays testable without Moodle.
 */
function ps_quiz_describe_question($question, $slot, $maxmark) {
  $qtype = $question->get_type_name();

  $descriptor = array(
    'slot'        => $slot,
    'question_id' => (int) $question->id,
    'qtype'       => $qtype,
    'name'        => $question->name,
    'text_html'   => $question->questiontext,
    'maxmark'     => $maxmark > 0 ? (float) $maxmark : (float) $question->defaultmark,
  );

  switch ($qtype) {
    case 'multichoice':
      // Single vs multi is the question class, not a property: Moodle builds
      // qtype_multichoice_single_question or qtype_multichoice_multi_question.
      $descriptor['single'] = ($question instanceof qtype_multichoice_single_question);
      $descriptor['answers'] = ps_quiz_describe_answers($question->answers);
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
      $descriptor['answers'] = ps_quiz_describe_answers($question->answers);
      break;

    case 'numerical':
      $descriptor['answers'] = ps_quiz_describe_answers($question->answers, true);
      break;
  }

  return $descriptor;
}

/**
 * Flatten a question's answer objects. Numerical answers carry a tolerance.
 */
function ps_quiz_describe_answers($answers, $with_tolerance=false) {
  $result = array();
  foreach ($answers as $answer) {
    $flat = array(
      'text'     => $answer->answer,
      'fraction' => (float) $answer->fraction,
    );
    if ($with_tolerance)
      $flat['tolerance'] = isset($answer->tolerance) ? (float) $answer->tolerance : 0.0;
    array_push($result, $flat);
  }
  return $result;
}

/**
 * Export one quiz as the normalized payload.
 */
function ps_quiz_export($quiz_id, $course_id, $user_id) {
  global $DB, $CFG;

  $quiz = $DB->get_record('quiz', array('id'=>$quiz_id, 'course'=>$course_id));
  if (!$quiz)
    throw new moodle_exception('no-such-quiz', 'local_paperscorer');

  $resolution = ps_quiz_slot_resolution();
  if ($resolution === '')
    throw new moodle_exception('quiz-export-unsupported', 'local_paperscorer');

  $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course_id, false, MUST_EXIST);
  $context = context_module::instance($cm->id);

  if (!ps_quiz_can_export($context, $user_id))
    throw new moodle_exception('no-quiz-permission', 'local_paperscorer', '', array('userid'=>$user_id, 'quizid'=>$quiz_id));

  $questions = array();
  $skipped = array();

  foreach (ps_quiz_get_slots($quiz_id, $context) as $slot) {
    if (!$slot['questionid']) {
      array_push($skipped, array(
        'slot'   => $slot['slot'],
        'qtype'  => 'random',
        'reason' => \local_paperscorer\quiz_normalizer::skip_reason('random'),
      ));
      continue;
    }

    try {
      $question = question_bank::load_question($slot['questionid']);
    } catch (Exception $e) {
      array_push($skipped, array(
        'slot'   => $slot['slot'],
        'qtype'  => 'unknown',
        'reason' => 'load-failed',
      ));
      continue;
    }

    $descriptor = ps_quiz_describe_question($question, $slot['slot'], $slot['maxmark']);
    $item = \local_paperscorer\quiz_normalizer::normalize($descriptor);

    if ($item === null) {
      array_push($skipped, array(
        'slot'   => $slot['slot'],
        'qtype'  => $descriptor['qtype'],
        'reason' => \local_paperscorer\quiz_normalizer::skip_reason($descriptor['qtype']),
      ));
      continue;
    }

    array_push($questions, $item);
  }

  return array(
    'quiz' => array(
      'id'         => (int) $quiz->id,
      'cmid'       => (int) $cm->id,
      'name'       => $quiz->name,
      'sumgrades'  => $quiz->sumgrades === null ? 0 : (float) $quiz->sumgrades,
      'grade_item' => ps_quiz_grade_item($quiz->id, $course_id),
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
