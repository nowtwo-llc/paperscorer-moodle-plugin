<?php

define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);

require_once(realpath(dirname(__FILE__)).'/common.php');

require_once("$CFG->libdir/gradelib.php");
require_once("$CFG->dirroot/grade/querylib.php");
require_once("$CFG->dirroot/user/profile/lib.php");
require_once(realpath(dirname(__FILE__)).'/quizlib.php');

function ps_get_validate_course_id($action) {
  global $PS_USER_ID;

  $course_id = ps_get($action, 'course_id');
  $context = context_course::instance($course_id);
  if (!has_capability('moodle/grade:edit', $context, $user=$PS_USER_ID))
    throw new moodle_exception("no-permission", 'local_paperscorer', '', array('userid'=>$PS_USER_ID, 'courseid'=>$course_id));
  return $course_id;
}

function ps_action_get_roster($action) {
  global $CFG;

  $course_id = ps_get_validate_course_id($action);
  $context = context_course::instance($course_id);

  $students = array();
  $enrolled_users = get_enrolled_users(
    $context,
    $withcapability='', $groupid=0, $userfields='u.*', $orderby=null, $limitfrom=0, $limitnum=0,
    $onlyactive=true
  );
  foreach ($enrolled_users as $id=>$student) {
    if ($CFG->paperscorer_student_id_field == "idnumber") {
        $idnumber = $student->idnumber;
    } else if ($CFG->paperscorer_student_id_field == "userid") {
        $idnumber = $id;
    } else {
        // Use a custom profile field for the idnumber
        profile_load_custom_fields($student);
        $idnumber = $student->profile[$CFG->paperscorer_student_id_field];

        // Remove any characters that are not 0-9.
        $idnumber = preg_replace("/[^0-9]/", "", $idnumber);
    }

    $roles = array();
    foreach (get_user_roles($context, $student->id, true) as $roleid=>$role) {
      array_push($roles, array(
        'name'=>$role->name,
        'shortname'=>$role->shortname,
      ));
    }

    array_push($students, array(
      'student_id'=>$idnumber,
      'name'=>$student->lastname . "; " . $student->firstname,
      'fields'=>array(
        'lms_user_id'=>$student->id,
        'lms_email'=>$student->email,
        'lms_username'=>$student->username,
        'lms_roles'=>$roles,
      ),
    ));
  }

  return array(
    'sections'=>array(),
    'students'=>$students,
  );
}

function ps_action_list_grade_items($action) {
  $course_id = ps_get_validate_course_id($action);
  $result = array();
  $items = grade_item::fetch_all(array('courseid'=>$course_id, 'itemtype'=>'manual'));
  if (!$items)
    $items = array();
  foreach ($items as $item) {
    array_push($result, array(
      'id'=>$item->id,
      'name'=>$item->itemname,
      'min_mark'=>$item->grademin,
      'max_mark'=>$item->grademax,
      'hidden'=>(bool)$item->hidden,
    ));
  }
  return $result;
}

function ps_action_create_update_grade_item($action) {
  global $DB;
  $course_id = ps_get_validate_course_id($action);
  $obj = ps_get($action, 'item');
  $item_id = ps_get($obj, 'id', 0);
  $item = new grade_item(array('id'=>$item_id, 'courseid'=>$course_id));
  grade_item::set_properties($item, array(
    'courseid'=>$course_id,
    'itemtype'=>'manual',
    'itemmodule'=>NULL,
    'gradetype'=>GRADE_TYPE_VALUE,
    'itemname'=>ps_get($obj, 'name'),
    'grademin'=>ps_get($obj, 'min_mark'),
    'grademax'=>ps_get($obj, 'max_mark'),
  ));
  if ($item_id) {
    $item->update();
  } else {
    $item->itemnumber = 0;
    $item->insert();
  }
  return array(
    'id'=>$item->id,
    'name'=>$item->itemname,
    'min_mark'=>$item->grademin,
    'max_mark'=>$item->grademax,
    'hidden'=>(bool)$item->hidden,
    'moodle_item'=>$item,
  );
}

function ps_action_update_grades($action) {
  global $USER;
  global $PS_POST_JSON;
  $course_id = ps_get_validate_course_id($action);
  $item_id = ps_get($action, 'item_id');

  if (!$grade_items = grade_item::fetch_all(array('courseid'=>$course_id, 'id'=>$item_id)))
    throw new moodle_exception("no-such-grade-item", "local_paperscorer");

  if (count($grade_items) != 1)
    throw new moodle_exception("multiple-grade-items", "local_paperscorer");

  $grade_item = reset($grade_items);
  unset($grade_items);

  $res = array();
  foreach (ps_get($PS_POST_JSON, 'updates') as $update) {
    $userid = ps_get($update, 'lms_user_id');
    $rawgrade = ps_get($update, 'mark');
    $did_update = $grade_item->update_final_grade(
      $userid, $rawgrade, 'paperscorer', '', FORMAT_PLAIN, $USER->id
    );
    array_push($res, array('lms_user_id'=>$userid, 'success'=>$did_update));
  }
  return $res;
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

  if ($expected_sig !== $sig)
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
