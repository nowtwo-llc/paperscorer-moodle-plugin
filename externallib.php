<?php

/**
 * Web service entry points.
 *
 * Every function returns its result as a JSON string in a single `payload`
 * value rather than a declared external_single_structure. That is deliberate:
 * the quiz payload is polymorphic (a multiple-choice question carries
 * responses/correct, an association question carries prompts/options), and
 * restating that shape in a _returns() definition would duplicate a contract
 * that already lives in quizlib.php and quiz_normalizer, with nothing keeping
 * the two in sync. One definition, one shape, identical on both transports.
 *
 * PHP 5.4 compatible: the plugin supports Moodle 2.7+.
 */
defined('MOODLE_INTERNAL') || die();

if (file_exists("$CFG->libdir/externallib.php"))
  require_once("$CFG->libdir/externallib.php");

require_once(realpath(dirname(__FILE__)).'/quizlib.php');

// Moodle 4.2 moved these into the core_external namespace and kept the legacy
// global names. They are not deprecated yet, so the old names are correct for
// a plugin supporting 2.7+, but alias defensively so this keeps working if the
// legacy names are ever removed.
if (!class_exists('external_api') && class_exists('core_external\external_api')) {
  class_alias('core_external\external_api', 'external_api');
  class_alias('core_external\external_function_parameters', 'external_function_parameters');
  class_alias('core_external\external_value', 'external_value');
  class_alias('core_external\external_single_structure', 'external_single_structure');
  class_alias('core_external\external_multiple_structure', 'external_multiple_structure');
}

class local_paperscorer_external extends external_api {

  /**
   * Shared: resolve and validate a course context for the calling user.
   *
   * Unlike api.php, the web service runs with a real Moodle session derived
   * from the token, so $USER *is* the acting user here.
   */
  protected static function ps_validate_course($course_id) {
    $context = context_course::instance($course_id);
    self::validate_context($context);
    require_capability('moodle/grade:edit', $context);
    return $context;
  }

  // --- get_capabilities -----------------------------------------------------

  public static function get_capabilities_parameters() {
    return new external_function_parameters(array(
      'courseid' => new external_value(PARAM_INT, 'Course id'),
    ));
  }

  public static function get_capabilities($courseid) {
    $params = self::validate_parameters(
      self::get_capabilities_parameters(),
      array('courseid' => $courseid)
    );

    self::ps_validate_course($params['courseid']);

    return array('payload' => json_encode(ps_capabilities_payload($params['courseid'])));
  }

  public static function get_capabilities_returns() {
    return new external_single_structure(array(
      'payload' => new external_value(PARAM_RAW, 'JSON capability report'),
    ));
  }

  // --- list_quizzes ---------------------------------------------------------

  public static function list_quizzes_parameters() {
    return new external_function_parameters(array(
      'courseid' => new external_value(PARAM_INT, 'Course id'),
    ));
  }

  public static function list_quizzes($courseid) {
    global $USER;

    $params = self::validate_parameters(
      self::list_quizzes_parameters(),
      array('courseid' => $courseid)
    );

    self::ps_validate_course($params['courseid']);

    return array('payload' => json_encode(ps_quiz_list($params['courseid'], $USER->id)));
  }

  public static function list_quizzes_returns() {
    return new external_single_structure(array(
      'payload' => new external_value(PARAM_RAW, 'JSON array of exportable quizzes'),
    ));
  }

  // --- get_quiz_structure ---------------------------------------------------

  public static function get_quiz_structure_parameters() {
    return new external_function_parameters(array(
      'courseid' => new external_value(PARAM_INT, 'Course id'),
      'quizid'   => new external_value(PARAM_INT, 'Quiz id'),
    ));
  }

  public static function get_quiz_structure($courseid, $quizid) {
    global $USER;

    $params = self::validate_parameters(
      self::get_quiz_structure_parameters(),
      array('courseid' => $courseid, 'quizid' => $quizid)
    );

    self::ps_validate_course($params['courseid']);

    // ps_quiz_export() applies the mod/quiz:manage check on the quiz's own
    // module context; the course check above is not sufficient to read an
    // answer key.
    $payload = ps_quiz_export($params['quizid'], $params['courseid'], $USER->id);

    return array('payload' => json_encode($payload));
  }

  public static function get_quiz_structure_returns() {
    return new external_single_structure(array(
      'payload' => new external_value(PARAM_RAW, 'JSON quiz structure with answer key'),
    ));
  }
}

?>
