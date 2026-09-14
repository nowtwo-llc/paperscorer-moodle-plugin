<?php

if ( $hassiteconfig ){
  require_once(realpath(dirname(__FILE__)).'/common.php');
  require_once(realpath(dirname(__FILE__)).'/lib.php');

  $settings = new admin_settingpage('local_paperscorer', 'PaperScorer Settings');
  $ADMIN->add('localplugins', $settings);

  $settings->add(new admin_setting_configtext(
    'paperscorer_launch_url',
    'PaperScorer launch URL',
    'The URL used to launch PaperScorer.',
    'https://app.paperscorer.com/api/moodle/launch',
    PARAM_TEXT
  ));

  $settings->add(new admin_setting_configtext(
    'paperscorer_public_key',
    'PaperScorer public key',
    'The public key given to you by PaperScorer.',
    '',
    PARAM_TEXT
  ));

  $settings->add(new admin_setting_configtext(
    'paperscorer_secret_key',
    'PaperScorer secret key',
    'The secret key given to you by PaperScorer.',
    '',
    PARAM_TEXT
  ));

  $settings->add(new admin_setting_configtext(
    'paperscorer_instance_secret',
    'PaperScorer instance secret',
    'A secret key you have generated (the default value is suitable). DO NOT share this value with PaperScorer.',
    bin2hex(ps_random_bytes(16)),
    PARAM_TEXT
  ));

  $settings->add(new admin_setting_configselect(
    'paperscorer_student_id_field',
    'PaperScorer student ID field',
    'The user profile field PaperScorer should use as a numeric student ID on bubble sheets.',
    'idnumber',
    ps_settings_get_student_id_options()
  ));

  $settings->add(new admin_setting_configcheckbox(
    'paperscorer_open_in_new_window',
    'Open in new window',
    'Opens PaperScorer in a new window. Note: users will get a "popup blocked" warning which they will need to disable.',
    false,
    PARAM_BOOL
  ));

  $settings->add(new admin_setting_configcheckbox(
    'paperscorer_enable_student_launch',
    'Enable student launch',
    'Makes the "Launch PaperScorer" link available to students so they can access their online assessments via Moodle.',
    false,
    PARAM_BOOL
  ));
}

?>
