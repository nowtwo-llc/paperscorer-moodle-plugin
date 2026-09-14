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
