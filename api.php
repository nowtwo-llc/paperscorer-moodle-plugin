<?php

define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);

require_once(realpath(dirname(__FILE__)).'/common.php');

require_once("$CFG->libdir/gradelib.php");
require_once("$CFG->dirroot/grade/querylib.php");
require_once(realpath(dirname(__FILE__)).'/synclib.php');
require_once(realpath(dirname(__FILE__)).'/quizlib.php');

function ps_get_validate_course_id($action) {
  global $PS_USER_ID;

  $course_id = ps_get($action, 'course_id');
  $context = context_course::instance($course_id);
  if (!has_capability('moodle/grade:edit', $context, $user=$PS_USER_ID))
    throw new moodle_exception("no-permission", 'local_paperscorer', '', array('userid'=>$PS_USER_ID, 'courseid'=>$course_id));
  return $course_id;
}

function ps_action_list_courses($action) {
  global $PS_USER_ID;
  return ps_course_list($PS_USER_ID, $PS_USER_ID);
}

function ps_action_get_roster($action) {
  $course_id = ps_get_validate_course_id($action);
  return ps_roster($course_id);
}

function ps_action_list_grade_items($action) {
  $course_id = ps_get_validate_course_id($action);
  return ps_grade_item_list($course_id);
}

function ps_action_create_update_grade_item($action) {
  $course_id = ps_get_validate_course_id($action);
  return ps_grade_item_save($course_id, ps_get($action, 'item'));
}

function ps_action_update_grades($action) {
  global $PS_USER_ID;
  global $PS_POST_JSON;
  $course_id = ps_get_validate_course_id($action);
  $item_id = ps_get($action, 'item_id');
  return ps_grades_update($course_id, $item_id, ps_get($PS_POST_JSON, 'updates'), $PS_USER_ID);
}

function ps_action_selftest($action) {
  global $PS_POST_JSON;
  return array('success'=>true, 'body'=>$PS_POST_JSON);
}

function ps_action_get_capabilities($action) {
  $course_id = ps_get_validate_course_id($action);
  return ps_capabilities_payload($course_id);
}

function ps_action_list_quizzes($action) {
  global $PS_USER_ID;
  $course_id = ps_get_validate_course_id($action);
  return ps_quiz_list($course_id, $PS_USER_ID);
}

function ps_action_get_quiz_structure($action) {
  global $PS_USER_ID;
  $course_id = ps_get_validate_course_id($action);
  $quiz_id = ps_get($action, 'quiz_id');
  return ps_quiz_export($quiz_id, $course_id, $PS_USER_ID);
}

function ps_run() {
  global $CFG;
  global $PS_USER_ID;
  global $PS_POST_JSON;

  $user_id = required_param('ps_key', PARAM_RAW);
  $sig = required_param('ps_signature', PARAM_RAW);
  $expires = required_param('ps_expires', PARAM_RAW);
  $action_str = required_param('action', PARAM_RAW);

  $is_post = $_SERVER['REQUEST_METHOD'] === 'POST';
  $post_data_str = $is_post? file_get_contents('php://input') : null;

  $user_key = ps_sign($CFG->paperscorer_instance_secret, $user_id);
  $str_to_sign = "$expires\n{$_SERVER['REQUEST_METHOD']}\n$action_str";
  if ($is_post)
    $str_to_sign = "$str_to_sign\n" . ($post_data_str? $post_data_str : "");
  $expected_sig = ps_sign($user_key, $str_to_sign);

  if (!ps_secure_compare($expected_sig, $sig))
    throw new moodle_exception('bad-signature', 'local_paperscorer');

  $expired_ago = time() - floatval($expires);
  if ($expired_ago > 0)
    throw new moodle_exception("signature-expired", "local_paperscorer", '', $expired_ago);

  $PS_USER_ID = $user_id;
  $PS_POST_JSON = $post_data_str? json_decode($post_data_str) : null;
  if ($is_post && $post_data_str && !$PS_POST_JSON)
    throw new moodle_exception("invalid-post-json", "local_paperscorer");
  $action = ps_load_action($action_str);
  $func_name = "ps_action_" . ps_get($action, 'name');
  if (!function_exists($func_name))
    throw new moodle_exception("unknown-action", "local_paperscorer", '', $action->name);
  return call_user_func($func_name, $action);
}

header("X-PS-Plugin-Release: " . ps_plugin_version());

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");

$result = json_encode(ps_run());
echo $result;

?>
