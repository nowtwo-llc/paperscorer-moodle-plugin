<?php
require_once(realpath(dirname(__FILE__)).'/common.php');

/**
 * @package    local_paperscorer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_login();

$id = required_param('id', PARAM_INT);
$course = get_course($id);
$context = context_course::instance($course->id);

$PAGE->set_context($context);
$PAGE->set_url('/local/paperscorer/launch.php');
$PAGE->set_heading($SITE->fullname);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('launching', 'local_paperscorer'));
$PAGE->navbar->add(get_string('pluginname', 'local_paperscorer'));
echo $OUTPUT->header();

global $CFG;
global $USER;

if (!$CFG->paperscorer_enable_student_launch && !has_capability('moodle/grade:edit', $context)) {
  ?>

  <h2>Permission Denied</h2>
  <p>Only instructors (with the <tt>moodle/grade:edit</tt> capability) can launch PaperScorer.</p>

  <?php
  echo $OUTPUT->footer();
  die();
}

$required_settings = array(
  'paperscorer_launch_url',
  'paperscorer_public_key',
  'paperscorer_secret_key',
  'paperscorer_instance_secret'
);

foreach ($required_settings as $s) {
  if ($CFG->{$s})
    continue;
  ?>
  <h2>Configuration Error</h2>
  <p>
    PaperScorer has not been properly configured. Please ask your administrator to
    set the <tt><?=$s?></tt> setting.
  </p>
  <?php
  echo $OUTPUT->footer();
  die();
}

$data_str = json_encode(array(
  'user'=>array(
    'id'=>$USER->id,
    'email'=>$USER->email,
    'first'=>$USER->firstname,
    'last'=>$USER->lastname,
    'key'=>ps_sign($CFG->paperscorer_instance_secret, $USER->id),
    // We will use this to determine whether we should launch as an instructor or student.
    'has_edit_grade_capability'=>has_capability('moodle/grade:edit', $context, $user=$USER->id)
  ),
  'course'=>array(
    'id'=>$course->id,
    'label'=>$course->fullname,
    'name'=>$course->shortname,
  ),
));

$expires = time() + 60 * 60 * 4;
$to_sign = "$expires\n$data_str";

$signature = ps_sign($CFG->paperscorer_secret_key, $to_sign);

$newwindow = ($CFG->paperscorer_open_in_new_window)? 'target="_blank"' : '';

?>
<h2><?=get_string('launching', 'local_paperscorer')?>&hellip;</h2>

<form id="ps-launch-form" method="POST" action="<?=trim($CFG->paperscorer_launch_url)?>" onSubmit="psDisableSubmit()" <?=$newwindow?>>
  <input type="hidden" name="public_key" value="<?=htmlspecialchars($CFG->paperscorer_public_key)?>" />
  <input type="hidden" name="signature" value="<?=htmlspecialchars($signature)?>" />
  <input type="hidden" name="expires" value="<?=htmlspecialchars($expires)?>" />
  <input type="hidden" name="data" value="<?=htmlspecialchars($data_str)?>" />
  <input id="ps-submit-btn" type="submit" value="<?=get_string('launch', 'local_paperscorer')?>" />
</form>

<a href="<?=$CFG->wwwroot?>/course/view.php?id=<?=$id?>"><?=get_string('returntocourse', 'local_paperscorer')?></a>

<script>
  function psDisableSubmit() {
    var submitBtn = document.getElementById("ps-submit-btn");
    if (!submitBtn)
      return;
    submitBtn.disabled = true;
    submitBtn.value = "<?=get_string('launching', 'local_paperscorer')?>…";
  }

  setTimeout(function(){
    document.getElementById("ps-launch-form").submit();
  }, 1);
</script>

<?php

echo $OUTPUT->footer();

?>
