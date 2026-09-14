<?php

namespace local_paperscorer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/paperscorer/quizlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Covers the Moodle-touching half of quiz export: the answer-key capability
 * gate, slot resolution on whichever branch this Moodle needs, question
 * loading, and the assembled payload.
 *
 * These run as real enrolled users rather than as admin, because the point of
 * ps_quiz_can_export() is that it distinguishes between them.
 *
 * quiz_add_quiz_question() is the helper Moodle's own quiz tests use. If a
 * future Moodle removes it, this file is the first thing that will say so.
 *
 * @group local_paperscorer
 */
final class quiz_export_test extends \advanced_testcase {

  /**
   * A course with a graded quiz holding one multichoice and one truefalse
   * question, plus an editing teacher and a student enrolled on it.
   *
   * @return array keys: course, quiz, teacher, student
   */
  protected function make_fixture() {
    global $DB;

    $generator = $this->getDataGenerator();
    $course = $generator->create_course();
    $quiz = $generator->create_module('quiz', array(
      'course' => $course->id,
      'name'   => 'Unit 3 Test',
      'grade'  => 10,
    ));

    $teacher = $generator->create_user();
    $student = $generator->create_user();
    $editingteacherid = $DB->get_field('role', 'id', array('shortname'=>'editingteacher'), MUST_EXIST);
    $studentroleid = $DB->get_field('role', 'id', array('shortname'=>'student'), MUST_EXIST);
    $generator->enrol_user($teacher->id, $course->id, $editingteacherid);
    $generator->enrol_user($student->id, $course->id, $studentroleid);

    $questiongenerator = $generator->get_plugin_generator('core_question');
    $category = $questiongenerator->create_question_category();

    $mc = $questiongenerator->create_question('multichoice', 'one_of_four', array('category'=>$category->id));
    $tf = $questiongenerator->create_question('truefalse', null, array('category'=>$category->id));

    quiz_add_quiz_question($mc->id, $quiz);
    quiz_add_quiz_question($tf->id, $quiz);

    return array(
      'course'  => $course,
      'quiz'    => $quiz,
      'teacher' => $teacher,
      'student' => $student,
    );
  }

  public function test_slot_resolution_is_available() {
    $this->resetAfterTest(true);

    $resolution = ps_quiz_slot_resolution();

    $this->assertNotEquals('', $resolution, 'No slot resolution strategy on this Moodle');
    $this->assertContains($resolution, array('qbank_helper', 'quiz_slots'));
  }

  // --- the answer-key capability gate ---------------------------------------

  public function test_list_quizzes_returns_the_quiz_to_an_editing_teacher() {
    $this->resetAfterTest(true);
    $f = $this->make_fixture();

    $quizzes = ps_quiz_list($f['course']->id, $f['teacher']->id);

    $this->assertCount(1, $quizzes);
    $this->assertEquals('Unit 3 Test', $quizzes[0]['name']);
    $this->assertEquals(2, $quizzes[0]['question_count']);
    $this->assertNotEquals(0, $quizzes[0]['cmid']);
  }

  public function test_list_quizzes_hides_the_quiz_from_a_user_without_mod_quiz_manage() {
    $this->resetAfterTest(true);
    $f = $this->make_fixture();

    $quizzes = ps_quiz_list($f['course']->id, $f['student']->id);

    $this->assertEquals(array(), $quizzes, 'A student must not see an exportable quiz');
  }

  public function test_export_refuses_a_user_without_mod_quiz_manage() {
    $this->resetAfterTest(true);
    $f = $this->make_fixture();

    $this->expectException('moodle_exception');
    ps_quiz_export($f['quiz']->id, $f['course']->id, $f['student']->id);
  }

  public function test_export_is_allowed_for_an_editing_teacher() {
    $this->resetAfterTest(true);
    $f = $this->make_fixture();

    $payload = ps_quiz_export($f['quiz']->id, $f['course']->id, $f['teacher']->id);

    $this->assertEquals('Unit 3 Test', $payload['quiz']['name']);
  }

  // --- the payload -----------------------------------------------------------

  public function test_list_quizzes_is_empty_for_a_course_with_no_quizzes() {
    $this->resetAfterTest(true);
    $f = $this->make_fixture();
    $empty = $this->getDataGenerator()->create_course();

    $this->assertEquals(array(), ps_quiz_list($empty->id, $f['teacher']->id));
  }

  public function test_export_returns_normalized_questions_with_answer_keys() {
    $this->resetAfterTest(true);
    $f = $this->make_fixture();

    $payload = ps_quiz_export($f['quiz']->id, $f['course']->id, $f['teacher']->id);

    $this->assertCount(2, $payload['questions']);
    $this->assertEquals(array(), $payload['skipped']);

    $types = array();
    foreach ($payload['questions'] as $question) {
      $types[] = $question['type'];
      $this->assertNotEmpty(
        $question['correct'],
        'Question in slot ' . $question['slot'] . ' came back with no answer key'
      );
      $this->assertNotEmpty($question['text'], 'Question stem was lost');
    }

    sort($types);
    $this->assertEquals(array('multiple_choice', 'true_false'), $types);
  }

  public function test_truefalse_answer_key_is_read_correctly() {
    $this->resetAfterTest(true);
    $f = $this->make_fixture();

    $payload = ps_quiz_export($f['quiz']->id, $f['course']->id, $f['teacher']->id);

    $truefalse = null;
    foreach ($payload['questions'] as $question) {
      if ($question['type'] === 'true_false')
        $truefalse = $question;
    }

    $this->assertNotNull($truefalse, 'No true_false question in the payload');
    // The core generator's default truefalse question has "true" as the key.
    $this->assertEquals(array('A'), $truefalse['correct']);
    $this->assertEquals('True', $truefalse['response_text']['A']);
  }

  // --- the quiz's own grade item ---------------------------------------------

  public function test_export_carries_the_quiz_grade_item() {
    $this->resetAfterTest(true);
    $f = $this->make_fixture();

    $payload = ps_quiz_export($f['quiz']->id, $f['course']->id, $f['teacher']->id);

    $this->assertNotNull($payload['quiz']['grade_item'], 'A graded quiz must report its grade item');
    $this->assertNotEquals(0, $payload['quiz']['grade_item']['id']);
    $this->assertEquals(10.0, $payload['quiz']['grade_item']['max_mark']);
    $this->assertFalse($payload['quiz']['grade_item']['hidden']);
  }

  public function test_list_quizzes_carries_the_quiz_grade_item() {
    $this->resetAfterTest(true);
    $f = $this->make_fixture();

    $quizzes = ps_quiz_list($f['course']->id, $f['teacher']->id);

    $this->assertNotNull($quizzes[0]['grade_item']);
    $this->assertEquals(10.0, $quizzes[0]['grade_item']['max_mark']);
  }

  public function test_grade_item_is_null_for_an_ungraded_quiz() {
    $this->resetAfterTest(true);
    global $DB;

    $generator = $this->getDataGenerator();
    $course = $generator->create_course();
    $quiz = $generator->create_module('quiz', array(
      'course' => $course->id,
      'name'   => 'Practice',
      'grade'  => 0,
    ));
    $teacher = $generator->create_user();
    $roleid = $DB->get_field('role', 'id', array('shortname'=>'editingteacher'), MUST_EXIST);
    $generator->enrol_user($teacher->id, $course->id, $roleid);

    $payload = ps_quiz_export($quiz->id, $course->id, $teacher->id);

    $this->assertNull($payload['quiz']['grade_item'], 'An ungraded quiz has no grade item to target');
  }

  // --- degradation ------------------------------------------------------------

  public function test_export_reports_the_slot_resolution_used() {
    $this->resetAfterTest(true);
    $f = $this->make_fixture();

    $payload = ps_quiz_export($f['quiz']->id, $f['course']->id, $f['teacher']->id);

    $this->assertContains($payload['source']['slot_resolution'], array('qbank_helper', 'quiz_slots'));
    $this->assertNotEmpty($payload['source']['moodle_release']);
  }

  public function test_export_rejects_a_quiz_from_another_course() {
    $this->resetAfterTest(true);
    $f = $this->make_fixture();
    $other = $this->getDataGenerator()->create_course();

    $this->expectException('moodle_exception');
    ps_quiz_export($f['quiz']->id, $other->id, $f['teacher']->id);
  }

  public function test_unsupported_question_is_skipped_not_fatal() {
    $this->resetAfterTest(true);
    global $DB;

    $generator = $this->getDataGenerator();
    $course = $generator->create_course();
    $quiz = $generator->create_module('quiz', array('course'=>$course->id, 'name'=>'Mixed', 'grade'=>10));
    $teacher = $generator->create_user();
    $roleid = $DB->get_field('role', 'id', array('shortname'=>'editingteacher'), MUST_EXIST);
    $generator->enrol_user($teacher->id, $course->id, $roleid);

    $questiongenerator = $generator->get_plugin_generator('core_question');
    $category = $questiongenerator->create_question_category();

    $mc = $questiongenerator->create_question('multichoice', 'one_of_four', array('category'=>$category->id));
    $cloze = $questiongenerator->create_question('multianswer', null, array('category'=>$category->id));

    quiz_add_quiz_question($mc->id, $quiz);
    quiz_add_quiz_question($cloze->id, $quiz);

    $payload = ps_quiz_export($quiz->id, $course->id, $teacher->id);

    $this->assertCount(1, $payload['questions'], 'The supported question should still export');
    $this->assertCount(1, $payload['skipped'], 'The cloze question should be skipped, not fatal');
    $this->assertEquals('unsupported-qtype', $payload['skipped'][0]['reason']);
    $this->assertEquals('multianswer', $payload['skipped'][0]['qtype']);
  }
}
