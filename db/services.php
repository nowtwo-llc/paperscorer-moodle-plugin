<?php

/**
 * Web service surface for local_paperscorer.
 *
 * This is the second transport. api.php's signed endpoint (ps_key /
 * ps_signature, scoped to one Moodle user by a derived key) remains the
 * primary one; these functions expose the same capabilities through Moodle's
 * own web services layer so a caller that already holds a wstoken for the site
 * can use them without implementing the signing protocol.
 *
 * Both transports call the same functions in quizlib.php and return byte-identical
 * payloads.
 */
defined('MOODLE_INTERNAL') || die();

$functions = array(
  'local_paperscorer_get_capabilities' => array(
    'classname'   => 'local_paperscorer_external',
    'methodname'  => 'get_capabilities',
    'classpath'   => 'local/paperscorer/externallib.php',
    'description' => 'Reports the plugin version, Moodle release, and which PaperScorer features this site supports.',
    'type'        => 'read',
    'ajax'        => false,
  ),

  'local_paperscorer_list_quizzes' => array(
    'classname'   => 'local_paperscorer_external',
    'methodname'  => 'list_quizzes',
    'classpath'   => 'local/paperscorer/externallib.php',
    'description' => 'Lists the quizzes in a course that the caller may export.',
    'type'        => 'read',
    'ajax'        => false,
  ),

  'local_paperscorer_get_quiz_structure' => array(
    'classname'   => 'local_paperscorer_external',
    'methodname'  => 'get_quiz_structure',
    'classpath'   => 'local/paperscorer/externallib.php',
    'description' => 'Returns a quiz\'s questions and answer key as a normalized payload.',
    'type'        => 'read',
    'ajax'        => false,
  ),
);

/**
 * A pre-built service so an admin can attach a token in one step rather than
 * assembling the function list by hand.
 */
$services = array(
  'PaperScorer' => array(
    'functions' => array(
      'local_paperscorer_get_capabilities',
      'local_paperscorer_list_quizzes',
      'local_paperscorer_get_quiz_structure',
    ),
    'restrictedusers' => 0,
    'enabled'         => 1,
    'shortname'       => 'local_paperscorer',
  ),
);

?>
