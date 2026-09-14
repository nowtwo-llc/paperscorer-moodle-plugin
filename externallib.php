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

require_once(realpath(dirname(__FILE__)).'/quizlib.php');
require_once(realpath(dirname(__FILE__)).'/synclib.php');

// Where the external_* classes come from depends on the Moodle generation.
//
// Moodle 4.2 moved them into the core_external namespace (autoloaded) and
// turned lib/externallib.php into a shim that aliases the legacy global names.
// The web service server always includes that shim, so in production the
// legacy names exist before this file loads. Under PHPUnit the shim refuses to
// load outside an isolated process, so when the legacy names are missing but
// the namespaced classes exist, alias them here instead of including it.
//
// Before 4.2 the classes live only in lib/externallib.php, so include it.
if (!class_exists('external_api')) {
  if (class_exists('core_external\external_api')) {
    class_alias('core_external\external_api', 'external_api');
    class_alias('core_external\external_function_parameters', 'external_function_parameters');
    class_alias('core_external\external_value', 'external_value');
    class_alias('core_external\external_single_structure', 'external_single_structure');
    class_alias('core_external\external_multiple_structure', 'external_multiple_structure');
  } else if (file_exists("$CFG->libdir/externallib.php")) {
    require_once("$CFG->libdir/externallib.php");
  }
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

  protected static function ps_payload($desc) {
    return new external_single_structure(array(
      'payload' => new external_value(PARAM_RAW, $desc),
    ));
  }

  protected static function ps_course_params() {
    return new external_function_parameters(array(
      'courseid' => new external_value(PARAM_INT, 'Course id'),
    ));
  }

  // --- list_courses ---------------------------------------------------------

  public static function list_courses_parameters() {
    return new external_function_parameters(array(
      'userid' => new external_value(PARAM_INT, 'Moodle user whose courses to list; 0 for the token user', VALUE_DEFAULT, 0),
    ));
  }

  public static function list_courses($userid = 0) {
    global $USER, $DB;

    $params = self::validate_parameters(self::list_courses_parameters(), array('userid' => $userid));
    self::validate_context(context_system::instance());

    $target = $params['userid'] ? $params['userid'] : $USER->id;
    if (!$DB->record_exists('user', array('id' => $target, 'deleted' => 0)))
      throw new moodle_exception('no-such-user', 'local_paperscorer');

    return array('payload' => json_encode(ps_course_list($target, $USER->id)));
  }

  public static function list_courses_returns() {
    return self::ps_payload('JSON array of courses the user can sync');
  }

  // --- get_roster -----------------------------------------------------------

  public static function get_roster_parameters() {
    return self::ps_course_params();
  }

  public static function get_roster($courseid) {
    $params = self::validate_parameters(self::get_roster_parameters(), array('courseid' => $courseid));
    self::ps_validate_course($params['courseid']);
    return array('payload' => json_encode(ps_roster($params['courseid'])));
  }

  public static function get_roster_returns() {
    return self::ps_payload('JSON roster: sections and students');
  }

  // --- list_grade_items -----------------------------------------------------

  public static function list_grade_items_parameters() {
    return self::ps_course_params();
  }

  public static function list_grade_items($courseid) {
    $params = self::validate_parameters(self::list_grade_items_parameters(), array('courseid' => $courseid));
    self::ps_validate_course($params['courseid']);
    return array('payload' => json_encode(ps_grade_item_list($params['courseid'])));
  }

  public static function list_grade_items_returns() {
    return self::ps_payload('JSON array of manual grade items');
  }

  // --- create_update_grade_item ---------------------------------------------

  public static function create_update_grade_item_parameters() {
    return new external_function_parameters(array(
      'courseid' => new external_value(PARAM_INT, 'Course id'),
      'item' => new external_single_structure(array(
        'id'       => new external_value(PARAM_INT, 'Existing manual grade item id; 0 to create', VALUE_DEFAULT, 0),
        'name'     => new external_value(PARAM_TEXT, 'Grade item name'),
        'min_mark' => new external_value(PARAM_FLOAT, 'Minimum mark'),
        'max_mark' => new external_value(PARAM_FLOAT, 'Maximum mark'),
      )),
    ));
  }

  public static function create_update_grade_item($courseid, $item) {
    $params = self::validate_parameters(
      self::create_update_grade_item_parameters(),
      array('courseid' => $courseid, 'item' => $item)
    );
    self::ps_validate_course($params['courseid']);
    return array('payload' => json_encode(ps_grade_item_save($params['courseid'], (object) $params['item'])));
  }

  public static function create_update_grade_item_returns() {
    return self::ps_payload('JSON grade item');
  }

  // --- update_grades --------------------------------------------------------

  public static function update_grades_parameters() {
    return new external_function_parameters(array(
      'courseid' => new external_value(PARAM_INT, 'Course id'),
      'itemid'   => new external_value(PARAM_INT, 'Grade item id'),
      'updates'  => new external_multiple_structure(
        new external_single_structure(array(
          'lms_user_id' => new external_value(PARAM_INT, 'Moodle user id'),
          'mark'        => new external_value(PARAM_FLOAT, 'Final grade'),
        ))
      ),
    ));
  }

  public static function update_grades($courseid, $itemid, $updates) {
    global $USER;

    $params = self::validate_parameters(
      self::update_grades_parameters(),
      array('courseid' => $courseid, 'itemid' => $itemid, 'updates' => $updates)
    );
    self::ps_validate_course($params['courseid']);

    $objects = array();
    foreach ($params['updates'] as $update)
      array_push($objects, (object) $update);

    return array('payload' => json_encode(ps_grades_update($params['courseid'], $params['itemid'], $objects, $USER->id)));
  }

  public static function update_grades_returns() {
    return self::ps_payload('JSON array of per-user results');
  }

  // --- get_capabilities -----------------------------------------------------

  public static function get_capabilities_parameters() {
    return self::ps_course_params();
  }

  public static function get_capabilities($courseid) {
    $params = self::validate_parameters(self::get_capabilities_parameters(), array('courseid' => $courseid));
    self::ps_validate_course($params['courseid']);
    return array('payload' => json_encode(ps_capabilities_payload($params['courseid'])));
  }

  public static function get_capabilities_returns() {
    return self::ps_payload('JSON capability report');
  }

  // --- list_quizzes ---------------------------------------------------------

  public static function list_quizzes_parameters() {
    return self::ps_course_params();
  }

  public static function list_quizzes($courseid) {
    global $USER;
    $params = self::validate_parameters(self::list_quizzes_parameters(), array('courseid' => $courseid));
    self::ps_validate_course($params['courseid']);
    return array('payload' => json_encode(ps_quiz_list($params['courseid'], $USER->id)));
  }

  public static function list_quizzes_returns() {
    return self::ps_payload('JSON array of exportable quizzes');
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
    return self::ps_payload('JSON quiz structure with answer key');
  }
}

?>
