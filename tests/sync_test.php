<?php

namespace local_paperscorer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/paperscorer/synclib.php');

/**
 * Covers the course, roster and gradebook functions shared by both transports.
 *
 * These run as real enrolled users rather than as admin, because the point of
 * the per-course capability filters is that they distinguish between them.
 *
 * @group local_paperscorer
 */
final class sync_test extends \advanced_testcase {

  /**
   * A course with an editing teacher, a student with an idnumber, and a
   * graded quiz (so the course has a mod grade item), plus a second course
   * with its own manual grade item for the cross-course checks.
   *
   * @return array keys: course, other, teacher, student, quiz, other_item
   */
  protected function make_fixture() {
    global $DB;

    $generator = $this->getDataGenerator();
    $course = $generator->create_course(array('fullname'=>'Biology 101', 'shortname'=>'BIO101'));
    $other = $generator->create_course(array('fullname'=>'Chemistry', 'shortname'=>'CHEM'));

    $teacher = $generator->create_user();
    $student = $generator->create_user(array('idnumber'=>'S-4471', 'firstname'=>'Ada', 'lastname'=>'Lovelace'));
    $editingteacherid = $DB->get_field('role', 'id', array('shortname'=>'editingteacher'), MUST_EXIST);
    $studentroleid = $DB->get_field('role', 'id', array('shortname'=>'student'), MUST_EXIST);
    $generator->enrol_user($teacher->id, $course->id, $editingteacherid);
    $generator->enrol_user($student->id, $course->id, $studentroleid);
    $generator->enrol_user($teacher->id, $other->id, $studentroleid);

    $quiz = $generator->create_module('quiz', array('course'=>$course->id, 'name'=>'Unit 3 Test', 'grade'=>10));

    $other_item = new \grade_item();
    \grade_item::set_properties($other_item, array(
      'courseid'=>$other->id, 'itemtype'=>'manual', 'itemname'=>'Chem Lab',
      'gradetype'=>GRADE_TYPE_VALUE, 'grademin'=>0, 'grademax'=>50,
    ));
    $other_item->insert();

    return array(
      'course'     => $course,
      'other'      => $other,
      'teacher'    => $teacher,
      'student'    => $student,
      'quiz'       => $quiz,
      'other_item' => $other_item,
    );
  }

  public function test_course_list_only_includes_gradable_courses() {
    $this->resetAfterTest();
    $f = $this->make_fixture();

    $courses = ps_course_list($f['teacher']->id, $f['teacher']->id);
    $ids = array_map(function($c) { return $c['id']; }, $courses);

    $this->assertEquals(array((int) $f['course']->id), $ids, 'Only the course where the teacher can edit grades is listed');
    $this->assertSame('Biology 101', $courses[0]['label']);
    $this->assertSame('BIO101', $courses[0]['name']);

    $this->assertSame(array(), ps_course_list($f['student']->id, $f['student']->id), 'A student has no gradable courses');
  }

  public function test_course_list_for_another_user_requires_caller_capability() {
    $this->resetAfterTest();
    $f = $this->make_fixture();

    $this->assertSame(array(), ps_course_list($f['teacher']->id, $f['student']->id),
      'A caller who cannot grade the course does not see it, even when the target can');

    $admin = get_admin();
    $courses = ps_course_list($f['teacher']->id, $admin->id);
    $this->assertCount(1, $courses, 'A site admin sees the teacher\'s gradable courses');
  }

  public function test_roster_carries_student_id_and_roles() {
    $this->resetAfterTest();
    $f = $this->make_fixture();
    set_config('paperscorer_student_id_field', 'idnumber');

    $roster = ps_roster($f['course']->id);
    $this->assertSame(array(), $roster['sections']);

    $by_user = array();
    foreach ($roster['students'] as $row)
      $by_user[$row['fields']['lms_user_id']] = $row;

    $this->assertArrayHasKey($f['student']->id, $by_user);
    $this->assertArrayHasKey($f['teacher']->id, $by_user);

    $s = $by_user[$f['student']->id];
    $this->assertSame('S-4471', $s['student_id']);
    $this->assertSame('Lovelace; Ada', $s['name']);
    $this->assertSame($f['student']->email, $s['fields']['lms_email']);
    $this->assertSame(array('student'), array_map(function($r) { return $r['shortname']; }, $s['fields']['lms_roles']));

    set_config('paperscorer_student_id_field', 'userid');
    $roster = ps_roster($f['course']->id);
    foreach ($roster['students'] as $row)
      if ($row['fields']['lms_user_id'] == $f['student']->id)
        $this->assertSame((string) $f['student']->id, $row['student_id']);
  }

  public function test_grade_item_create_then_update() {
    $this->resetAfterTest();
    $f = $this->make_fixture();

    $created = ps_grade_item_save($f['course']->id, (object) array(
      'name'=>'Quiz 1', 'min_mark'=>0, 'max_mark'=>20,
    ));
    $this->assertGreaterThan(0, $created['id']);
    $this->assertSame('Quiz 1', $created['name']);
    $this->assertSame(20.0, $created['max_mark']);
    $this->assertSame('manual', $created['itemtype']);

    $listed = ps_grade_item_list($f['course']->id);
    $this->assertCount(1, $listed);
    $this->assertSame($created['id'], $listed[0]['id']);

    $updated = ps_grade_item_save($f['course']->id, (object) array(
      'id'=>$created['id'], 'name'=>'Quiz 1 (retake)', 'min_mark'=>0, 'max_mark'=>25,
    ));
    $this->assertSame($created['id'], $updated['id']);
    $this->assertSame('Quiz 1 (retake)', $updated['name']);
    $this->assertSame(25.0, $updated['max_mark']);

    $reloaded = \grade_item::fetch(array('id'=>$created['id']));
    $this->assertSame('Quiz 1 (retake)', $reloaded->itemname);
    $this->assertSame('manual', $reloaded->itemtype);
  }

  public function test_grade_item_update_refuses_other_course_item() {
    $this->resetAfterTest();
    $f = $this->make_fixture();

    try {
      ps_grade_item_save($f['course']->id, (object) array(
        'id'=>$f['other_item']->id, 'name'=>'Hijacked', 'min_mark'=>0, 'max_mark'=>1,
      ));
      $this->fail('Expected no-such-grade-item');
    } catch (\moodle_exception $e) {
      $this->assertSame('no-such-grade-item', $e->errorcode);
    }

    $untouched = \grade_item::fetch(array('id'=>$f['other_item']->id));
    $this->assertSame('Chem Lab', $untouched->itemname);
    $this->assertEquals($f['other']->id, $untouched->courseid);
  }

  public function test_grade_item_update_refuses_activity_item() {
    $this->resetAfterTest();
    $f = $this->make_fixture();

    $quiz_item = \grade_item::fetch(array(
      'courseid'=>$f['course']->id, 'itemtype'=>'mod', 'itemmodule'=>'quiz', 'iteminstance'=>$f['quiz']->id,
    ));
    $this->assertNotEmpty($quiz_item);

    try {
      ps_grade_item_save($f['course']->id, (object) array(
        'id'=>$quiz_item->id, 'name'=>'Converted', 'min_mark'=>0, 'max_mark'=>1,
      ));
      $this->fail('Expected grade-item-not-manual');
    } catch (\moodle_exception $e) {
      $this->assertSame('grade-item-not-manual', $e->errorcode);
    }

    $reloaded = \grade_item::fetch(array('id'=>$quiz_item->id));
    $this->assertSame('mod', $reloaded->itemtype);
    $this->assertSame('Unit 3 Test', $reloaded->itemname);
  }

  public function test_update_grades_records_acting_user() {
    $this->resetAfterTest();
    $f = $this->make_fixture();

    $item = ps_grade_item_save($f['course']->id, (object) array(
      'name'=>'Quiz 1', 'min_mark'=>0, 'max_mark'=>20,
    ));

    $res = ps_grades_update($f['course']->id, $item['id'], array(
      (object) array('lms_user_id'=>$f['student']->id, 'mark'=>17.5),
    ), $f['teacher']->id);

    $this->assertSame(array(array('lms_user_id'=>$f['student']->id, 'success'=>true)), $res);

    $grade = \grade_grade::fetch(array('itemid'=>$item['id'], 'userid'=>$f['student']->id));
    $this->assertEquals(17.5, $grade->finalgrade);
    $this->assertEquals($f['teacher']->id, $grade->usermodified);
  }

  public function test_update_grades_accepts_activity_item_but_not_totals() {
    $this->resetAfterTest();
    $f = $this->make_fixture();

    $quiz_item = \grade_item::fetch(array(
      'courseid'=>$f['course']->id, 'itemtype'=>'mod', 'itemmodule'=>'quiz', 'iteminstance'=>$f['quiz']->id,
    ));
    $res = ps_grades_update($f['course']->id, $quiz_item->id, array(
      (object) array('lms_user_id'=>$f['student']->id, 'mark'=>8),
    ), $f['teacher']->id);
    $this->assertTrue($res[0]['success']);

    $grade = \grade_grade::fetch(array('itemid'=>$quiz_item->id, 'userid'=>$f['student']->id));
    $this->assertEquals(8, $grade->finalgrade);
    $this->assertTrue($grade->is_overridden(), 'Writing to an activity column is a gradebook override');

    $total = \grade_item::fetch_course_item($f['course']->id);
    try {
      ps_grades_update($f['course']->id, $total->id, array(
        (object) array('lms_user_id'=>$f['student']->id, 'mark'=>1),
      ), $f['teacher']->id);
      $this->fail('Expected grade-item-not-writable');
    } catch (\moodle_exception $e) {
      $this->assertSame('grade-item-not-writable', $e->errorcode);
    }

    try {
      ps_grades_update($f['course']->id, $f['other_item']->id, array(), $f['teacher']->id);
      $this->fail('Expected no-such-grade-item');
    } catch (\moodle_exception $e) {
      $this->assertSame('no-such-grade-item', $e->errorcode);
    }
  }
}
