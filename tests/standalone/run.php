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
