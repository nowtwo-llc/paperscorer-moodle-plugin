<?php

namespace local_paperscorer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/paperscorer/externallib.php');

/**
 * Drives the web service functions through external_api's parameter and
 * return validation, as a token holder would. The payload contents are
 * covered by sync_test and quiz_export_test; this checks the declared
 * structures accept what PaperScorer sends and that the session user is the
 * one whose permissions apply.
 *
 * @group local_paperscorer
 */
final class external_test extends \advanced_testcase {

  protected function make_fixture() {
    global $DB;
    $generator = $this->getDataGenerator();
    $course = $generator->create_course();
    $teacher = $generator->create_user();
    $student = $generator->create_user(array('idnumber'=>'1001'));
    $editingteacherid = $DB->get_field('role', 'id', array('shortname'=>'editingteacher'), MUST_EXIST);
    $studentroleid = $DB->get_field('role', 'id', array('shortname'=>'student'), MUST_EXIST);
    $generator->enrol_user($teacher->id, $course->id, $editingteacherid);
    $generator->enrol_user($student->id, $course->id, $studentroleid);
    return array('course'=>$course, 'teacher'=>$teacher, 'student'=>$student);
  }

  protected function payload($method, $result) {
    $cleaned = \external_api::clean_returnvalue(\local_paperscorer_external::{$method . '_returns'}(), $result);
    return json_decode($cleaned['payload'], true);
  }

  public function test_full_sync_round_trip_as_teacher() {
    $this->resetAfterTest();
    $f = $this->make_fixture();
    $this->setUser($f['teacher']);

    $courses = $this->payload('list_courses', \local_paperscorer_external::list_courses(0));
    $this->assertSame(array((int) $f['course']->id), array_map(function($c) { return $c['id']; }, $courses));

    $roster = $this->payload('get_roster', \local_paperscorer_external::get_roster($f['course']->id));
    $ids = array_map(function($s) { return $s['fields']['lms_user_id']; }, $roster['students']);
    $this->assertContains((int) $f['student']->id, $ids);

    $item = $this->payload('create_update_grade_item', \local_paperscorer_external::create_update_grade_item(
      $f['course']->id, array('name'=>'Test 1', 'min_mark'=>0, 'max_mark'=>10)
    ));
    $this->assertSame('Test 1', $item['name']);

    $items = $this->payload('list_grade_items', \local_paperscorer_external::list_grade_items($f['course']->id));
    $this->assertSame($item['id'], $items[0]['id']);

    $res = $this->payload('update_grades', \local_paperscorer_external::update_grades(
      $f['course']->id, $item['id'], array(array('lms_user_id'=>$f['student']->id, 'mark'=>9))
    ));
    $this->assertTrue($res[0]['success']);

    $grade = \grade_grade::fetch(array('itemid'=>$item['id'], 'userid'=>$f['student']->id));
    $this->assertEquals(9, $grade->finalgrade);
    $this->assertEquals($f['teacher']->id, $grade->usermodified, 'The token user is recorded as the modifier');
  }

  public function test_service_account_lists_a_teachers_courses() {
    $this->resetAfterTest();
    $f = $this->make_fixture();
    $this->setAdminUser();

    $courses = $this->payload('list_courses', \local_paperscorer_external::list_courses($f['teacher']->id));
    $this->assertCount(1, $courses);

    $this->expectException(\moodle_exception::class);
    \local_paperscorer_external::list_courses(999999);
  }

  public function test_student_is_refused() {
    $this->resetAfterTest();
    $f = $this->make_fixture();
    $this->setUser($f['student']);

    $this->assertSame(array(), $this->payload('list_courses', \local_paperscorer_external::list_courses(0)));

    $this->expectException(\required_capability_exception::class);
    \local_paperscorer_external::get_roster($f['course']->id);
  }
}
