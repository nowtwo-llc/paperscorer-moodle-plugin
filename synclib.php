<?php

/**
 * Course, roster and gradebook access shared by both transports.
 *
 * api.php (the signed endpoint) and externallib.php (Moodle web services)
 * both call these functions so their payloads cannot drift. Nothing here
 * bootstraps Moodle, which is what lets the PHPUnit tests include it.
 *
 * Every entry point takes the acting user id explicitly. Under api.php's
 * NO_MOODLE_COOKIES bootstrap $USER is NOT the acting user, so nothing in this
 * file may fall back to it.
 *
 * PHP 5.4 compatible: the plugin supports Moodle 2.7+.
 */
defined('MOODLE_INTERNAL') || die();

require_once(realpath(dirname(__FILE__)).'/helpers.php');
require_once("$CFG->libdir/gradelib.php");
require_once("$CFG->libdir/enrollib.php");
require_once("$CFG->dirroot/user/profile/lib.php");

/**
 * Courses a user can sync with PaperScorer: those in which they hold
 * moodle/grade:edit.
 *
 * When the caller is not the target user (a service-account token looking up
 * a teacher's courses), the caller must also hold moodle/grade:edit in each
 * course. A service account therefore sees exactly the courses it could sync
 * itself, and nothing about courses it could not.
 */
function ps_course_list($target_user_id, $caller_user_id) {
  $courses = enrol_get_users_courses($target_user_id, true, 'idnumber, visible');
  $result = array();

  foreach ($courses as $course) {
    $context = context_course::instance($course->id, IGNORE_MISSING);
    if (!$context)
      continue;
    if (!has_capability('moodle/grade:edit', $context, $target_user_id))
      continue;
    if ($caller_user_id != $target_user_id && !has_capability('moodle/grade:edit', $context, $caller_user_id))
      continue;

    array_push($result, array(
      'id'       => (int) $course->id,
      'label'    => $course->fullname,
      'name'     => $course->shortname,
      'idnumber' => $course->idnumber,
      'visible'  => (bool) $course->visible,
    ));
  }

  return $result;
}

/**
 * The bubble-sheet student ID for a user, per the paperscorer_student_id_field
 * setting: the idnumber field, the Moodle user id, or a custom profile field
 * (reduced to its digits).
 */
function ps_roster_student_id($user) {
  global $CFG;

  $field = empty($CFG->paperscorer_student_id_field) ? 'idnumber' : $CFG->paperscorer_student_id_field;

  if ($field == 'idnumber')
    return $user->idnumber;
  if ($field == 'userid')
    return (string) $user->id;

  profile_load_custom_fields($user);
  $value = isset($user->profile[$field]) ? $user->profile[$field] : '';
  return preg_replace("/[^0-9]/", "", $value);
}

/**
 * Every active enrolment in a course, with the student ID PaperScorer prints
 * on bubble sheets and the user's roles so PaperScorer can tell students from
 * staff.
 */
function ps_roster($course_id) {
  $context = context_course::instance($course_id);

  $students = array();
  $enrolled_users = get_enrolled_users(
    $context,
    $withcapability='', $groupid=0, $userfields='u.*', $orderby=null, $limitfrom=0, $limitnum=0,
    $onlyactive=true
  );
  foreach ($enrolled_users as $id=>$student) {
    $roles = array();
    foreach (get_user_roles($context, $student->id, true) as $roleid=>$role) {
      array_push($roles, array(
        'name'=>$role->name,
        'shortname'=>$role->shortname,
      ));
    }

    array_push($students, array(
      'student_id'=>ps_roster_student_id($student),
      'name'=>$student->lastname . "; " . $student->firstname,
      'fields'=>array(
        'lms_user_id'=>(int) $student->id,
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

/**
 * The wire shape of a grade item. Shared by every function that returns one.
 */
function ps_grade_item_describe($item) {
  return array(
    'id'=>(int) $item->id,
    'name'=>$item->itemname,
    'min_mark'=>(float) $item->grademin,
    'max_mark'=>(float) $item->grademax,
    'hidden'=>(bool) $item->hidden,
    'itemtype'=>$item->itemtype,
    'itemmodule'=>$item->itemmodule,
  );
}

/**
 * The manual (PaperScorer-created) grade items in a course.
 */
function ps_grade_item_list($course_id) {
  $result = array();
  $items = grade_item::fetch_all(array('courseid'=>$course_id, 'itemtype'=>'manual'));
  if (!$items)
    $items = array();
  foreach ($items as $item)
    array_push($result, ps_grade_item_describe($item));
  return $result;
}

/**
 * Load a grade item that belongs to this course, or throw.
 *
 * grade_item's constructor silently returns an *empty* object when no row
 * matches its params, so anything that goes on to call update() must fetch
 * explicitly first. Otherwise an id from another course would be rewritten
 * into this one.
 */
function ps_grade_item_load($course_id, $item_id) {
  $item = grade_item::fetch(array('id'=>$item_id, 'courseid'=>$course_id));
  if (!$item)
    throw new moodle_exception("no-such-grade-item", "local_paperscorer");
  return $item;
}

/**
 * Create a manual grade item, or rename/rescale an existing manual one.
 *
 * Only manual items may be edited: an activity's own column belongs to the
 * activity, and course/category totals are computed.
 *
 * @param object $spec  With name, min_mark, max_mark and an optional id.
 */
function ps_grade_item_save($course_id, $spec) {
  $item_id = (int) ps_get($spec, 'id', 0);
  $name = ps_get($spec, 'name');
  $min = ps_get($spec, 'min_mark');
  $max = ps_get($spec, 'max_mark');

  if ($item_id) {
    $item = ps_grade_item_load($course_id, $item_id);
    if ($item->itemtype !== 'manual')
      throw new moodle_exception("grade-item-not-manual", "local_paperscorer");
    $item->itemname = $name;
    $item->grademin = $min;
    $item->grademax = $max;
    $item->update('paperscorer');
  } else {
    // An empty constructor applies the optional-field defaults; passing the
    // params to it instead would make it try to fetch a matching row.
    $item = new grade_item();
    grade_item::set_properties($item, array(
      'courseid'=>$course_id,
      'itemtype'=>'manual',
      'itemmodule'=>NULL,
      'itemnumber'=>0,
      'gradetype'=>GRADE_TYPE_VALUE,
      'itemname'=>$name,
      'grademin'=>$min,
      'grademax'=>$max,
    ));
    $item->insert('paperscorer');
  }

  return ps_grade_item_describe($item);
}

/**
 * Write final grades into one grade item.
 *
 * The target may be a manual item or an activity's own column (a quiz or
 * assignment, where the write becomes a gradebook override). Course and
 * category totals are computed from their children and are refused.
 *
 * @param array $updates  Objects with lms_user_id and mark.
 * @param int   $user_id  The acting user, recorded as the grade's modifier.
 */
function ps_grades_update($course_id, $item_id, $updates, $user_id) {
  $grade_item = ps_grade_item_load($course_id, $item_id);
  if ($grade_item->itemtype === 'course' || $grade_item->itemtype === 'category')
    throw new moodle_exception("grade-item-not-writable", "local_paperscorer");

  $res = array();
  foreach ($updates as $update) {
    $userid = ps_get($update, 'lms_user_id');
    $rawgrade = ps_get($update, 'mark');
    $did_update = $grade_item->update_final_grade(
      $userid, $rawgrade, 'paperscorer', '', FORMAT_PLAIN, $user_id
    );
    array_push($res, array('lms_user_id'=>$userid, 'success'=>(bool) $did_update));
  }
  return $res;
}

?>
