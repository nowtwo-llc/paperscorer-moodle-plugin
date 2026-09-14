<?php

/**
 * Web service surface for local_paperscorer.
 *
 * This is the transport PaperScorer's servers use: a site administrator
 * creates a token on the pre-built "PaperScorer" service below and enters it
 * in PaperScorer. api.php's signed endpoint (ps_key / ps_signature, scoped to
 * one Moodle user by a derived key) remains available for launch-based use.
 *
 * Both transports call the same functions in synclib.php and quizlib.php and
 * return byte-identical payloads.
 */
defined('MOODLE_INTERNAL') || die();

$ps_function = function($method, $description, $type) {
  return array(
    'classname'   => 'local_paperscorer_external',
    'methodname'  => $method,
    'classpath'   => 'local/paperscorer/externallib.php',
    'description' => $description,
    'type'        => $type,
    'ajax'        => false,
  );
};

$functions = array(
  'local_paperscorer_list_courses' => $ps_function(
    'list_courses',
    'Lists the courses a Moodle user can sync with PaperScorer (those where they may edit grades).',
    'read'
  ),
  'local_paperscorer_get_roster' => $ps_function(
    'get_roster',
    'Returns a course\'s active enrolments with the configured bubble-sheet student ID and each user\'s roles.',
    'read'
  ),
  'local_paperscorer_list_grade_items' => $ps_function(
    'list_grade_items',
    'Lists the manual grade items in a course.',
    'read'
  ),
  'local_paperscorer_create_update_grade_item' => $ps_function(
    'create_update_grade_item',
    'Creates a manual grade item, or renames/rescales an existing manual one.',
    'write'
  ),
  'local_paperscorer_update_grades' => $ps_function(
    'update_grades',
    'Writes final grades into a manual grade item or an activity\'s own gradebook column.',
    'write'
  ),
  'local_paperscorer_get_capabilities' => $ps_function(
    'get_capabilities',
    'Reports the plugin version, Moodle release, and which PaperScorer features this site supports.',
    'read'
  ),
  'local_paperscorer_list_quizzes' => $ps_function(
    'list_quizzes',
    'Lists the quizzes in a course that the caller may export.',
    'read'
  ),
  'local_paperscorer_get_quiz_structure' => $ps_function(
    'get_quiz_structure',
    'Returns a quiz\'s questions and answer key as a normalized payload.',
    'read'
  ),
);

unset($ps_function);

/**
 * A pre-built service so an admin can attach a token in one step rather than
 * assembling the function list by hand.
 *
 * It bundles the core functions PaperScorer's connect and sync flow calls
 * alongside the plugin's own, so one token on this service is all a
 * self-hosted site needs. Moodle records service function names without
 * checking they exist, so a core function absent on an older release is
 * harmless here; it simply cannot be called there.
 */
$services = array(
  'PaperScorer' => array(
    'functions' => array(
      // Plugin functions.
      'local_paperscorer_list_courses',
      'local_paperscorer_get_roster',
      'local_paperscorer_list_grade_items',
      'local_paperscorer_create_update_grade_item',
      'local_paperscorer_update_grades',
      'local_paperscorer_get_capabilities',
      'local_paperscorer_list_quizzes',
      'local_paperscorer_get_quiz_structure',
      // Core functions PaperScorer calls during connect and sync.
      'core_webservice_get_site_info',
      'core_user_get_users_by_field',
      'core_enrol_get_users_courses',
      'core_enrol_get_enrolled_users',
      'core_course_get_courses_by_field',
      'core_course_get_contents',
      'mod_assign_save_grade',
    ),
    'restrictedusers' => 0,
    'enabled'         => 1,
    'shortname'       => 'local_paperscorer',
  ),
);

?>
