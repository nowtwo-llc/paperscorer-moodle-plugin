<?php

namespace local_paperscorer;

defined('MOODLE_INTERNAL') || die();

/**
 * Covers the pure normalization logic from inside Moodle.
 *
 * The bulk of the cases live in tests/standalone/, which runs with no Moodle
 * at all; this class re-runs that suite so a single PHPUnit command covers
 * both layers, and adds a couple of contract checks on top.
 *
 * @group local_paperscorer
 */
final class quiz_normalizer_test extends \advanced_testcase {

  public function test_standalone_suite_passes() {
    if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))))) {
      $this->markTestSkipped('exec() is disabled; run "php tests/standalone/run.php" directly.');
    }

    $runner = realpath(dirname(__FILE__)) . '/standalone/run.php';
    $output = array();
    $status = 0;
    exec('php ' . escapeshellarg($runner) . ' 2>&1', $output, $status);

    $this->assertEquals(0, $status, "Standalone normalizer suite failed:\n" . implode("\n", $output));
  }

  public function test_type_map_covers_the_supported_qtypes() {
    $map = quiz_normalizer::type_map();

    $this->assertEquals('multiple_choice', $map['multichoice']);
    $this->assertEquals('true_false', $map['truefalse']);
    $this->assertEquals('association_response', $map['match']);
    $this->assertEquals('number_response', $map['numerical']);
    $this->assertEquals('writing_response', $map['essay']);
    $this->assertArrayNotHasKey('multianswer', $map);
    $this->assertArrayNotHasKey('calculated', $map);
  }

  public function test_unsupported_qtypes_are_not_normalizable() {
    $descriptor = array(
      'slot' => 1, 'question_id' => 1, 'qtype' => 'multianswer',
      'name' => 'Cloze', 'text_html' => 'x', 'maxmark' => 1.0,
    );

    $this->assertNull(quiz_normalizer::normalize($descriptor));
    $this->assertEquals('unsupported-qtype', quiz_normalizer::skip_reason('multianswer'));
    $this->assertEquals('random-question', quiz_normalizer::skip_reason('random'));
  }
}
