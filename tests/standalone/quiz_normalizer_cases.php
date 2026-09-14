<?php

use local_paperscorer\quiz_normalizer;

// --- multichoice, single answer ---------------------------------------------
$descriptor = array(
  'slot' => 1,
  'question_id' => 881,
  'qtype' => 'multichoice',
  'name' => 'Capital of France',
  'text_html' => '<p>What is the capital of France?</p>',
  'maxmark' => 1.0,
  'single' => true,
  'answers' => array(
    array('text' => 'Lyon', 'fraction' => 0.0),
    array('text' => 'Paris', 'fraction' => 1.0),
    array('text' => 'Nice', 'fraction' => 0.0),
  ),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals('multiple_choice', $result['type'], 'multichoice maps to multiple_choice');
ps_test_assert_equals(array('A', 'B', 'C'), $result['responses'], 'multichoice responses are letters');
ps_test_assert_equals(array('B'), $result['correct'], 'multichoice correct is the fraction-1 letter');
ps_test_assert_equals('B', $result['response_value'], 'multichoice response_value is the joined letters');
ps_test_assert_equals('What is the capital of France?', $result['text'], 'stem html is stripped to text');
ps_test_assert_equals(array(), $result['warnings'], 'clean multichoice has no warnings');
ps_test_assert_equals('Paris', $result['response_text']['B'], 'response_text maps letter to option text');

// --- multichoice, multiple correct answers summing to full credit ------------
$descriptor = array(
  'slot' => 2, 'question_id' => 882, 'qtype' => 'multichoice', 'name' => 'Primes',
  'text_html' => 'Which are prime?', 'maxmark' => 2.0, 'single' => false,
  'answers' => array(
    array('text' => '2', 'fraction' => 0.5),
    array('text' => '4', 'fraction' => 0.0),
    array('text' => '7', 'fraction' => 0.5),
  ),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals(array('A', 'C'), $result['correct'], 'multi-answer collects every positive fraction');
ps_test_assert_equals('AC', $result['response_value'], 'multi-answer response_value joins letters in order');
ps_test_assert_equals(array(), $result['warnings'], 'fractions summing to full credit are not lossy');

// --- multichoice, multi-answer with an odd partial scheme --------------------
$descriptor = array(
  'slot' => 3, 'question_id' => 883, 'qtype' => 'multichoice', 'name' => 'Odd',
  'text_html' => 'Pick some', 'maxmark' => 1.0, 'single' => false,
  'answers' => array(
    array('text' => 'One', 'fraction' => 0.25),
    array('text' => 'Two', 'fraction' => 0.25),
  ),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals(array('A', 'B'), $result['correct'], 'odd scheme still collects the positives');
ps_test_assert_equals(array('partial-credit-collapsed'), $result['warnings'], 'fractions not summing to 1 warn');

// --- multichoice, single with partial credit --------------------------------
$descriptor = array(
  'slot' => 4, 'question_id' => 884, 'qtype' => 'multichoice', 'name' => 'Partial',
  'text_html' => 'Pick one', 'maxmark' => 1.0, 'single' => true,
  'answers' => array(
    array('text' => 'Best', 'fraction' => 1.0),
    array('text' => 'Nearly', 'fraction' => 0.5),
  ),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals(array('A'), $result['correct'], 'single-answer collapses to the highest fraction');
ps_test_assert_equals(array('partial-credit-collapsed'), $result['warnings'], 'collapsing partial credit warns');

// --- multichoice with no correct answer -------------------------------------
$descriptor = array(
  'slot' => 5, 'question_id' => 885, 'qtype' => 'multichoice', 'name' => 'Broken',
  'text_html' => 'No key', 'maxmark' => 1.0, 'single' => true,
  'answers' => array(array('text' => 'A thing', 'fraction' => 0.0)),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals(array(), $result['correct'], 'no positive fraction yields no correct answer');
ps_test_assert_equals(array('no-correct-answer'), $result['warnings'], 'missing key warns');

// --- truefalse ---------------------------------------------------------------
$descriptor = array(
  'slot' => 6, 'question_id' => 886, 'qtype' => 'truefalse', 'name' => 'TF',
  'text_html' => 'The sky is blue.', 'maxmark' => 1.0, 'rightanswer' => true,
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals('true_false', $result['type'], 'truefalse maps to true_false');
ps_test_assert_equals(array('A', 'B'), $result['responses'], 'truefalse has two responses');
ps_test_assert_equals(array('A'), $result['correct'], 'true maps to A');
ps_test_assert_equals('True', $result['response_text']['A'], 'A is labelled True');

$descriptor['rightanswer'] = false;
$result = quiz_normalizer::normalize($descriptor);
ps_test_assert_equals(array('B'), $result['correct'], 'false maps to B');

// --- match -------------------------------------------------------------------
$descriptor = array(
  'slot' => 7, 'question_id' => 887, 'qtype' => 'match', 'name' => 'Capitals',
  'text_html' => 'Match the capitals', 'maxmark' => 2.0,
  'stems' => array(1 => 'France', 2 => 'Japan'),
  'choices' => array(10 => 'Paris', 11 => 'Tokyo'),
  'right' => array(1 => 10, 2 => 11),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals('association_response', $result['type'], 'match maps to association_response');
ps_test_assert_equals(array('France', 'Japan'), $result['prompts'], 'match prompts come from stems');
ps_test_assert_equals(array('A', 'B'), $result['options'], 'match options are letters');
ps_test_assert_equals(array('A', 'B'), $result['correct'], 'match correct aligns to prompts');
ps_test_assert_equals('per_part', $result['grid_scoring_type'], 'match scores per part');
ps_test_assert_equals(1.0, $result['points'], 'match points are divided across rows');

// --- shortanswer -------------------------------------------------------------
$descriptor = array(
  'slot' => 8, 'question_id' => 888, 'qtype' => 'shortanswer', 'name' => 'SA',
  'text_html' => 'Name the capital of Japan.', 'maxmark' => 1.0,
  'answers' => array(
    array('text' => 'Tokyo', 'fraction' => 1.0),
    array('text' => 'tokyo', 'fraction' => 1.0),
  ),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals('writing_response', $result['type'], 'shortanswer maps to writing_response');
ps_test_assert_equals('Tokyo', $result['response_value'], 'shortanswer keeps the first full-credit answer');
ps_test_assert_equals('small', $result['size'], 'shortanswer gets a small writing area');
ps_test_assert_equals(array('hand-graded'), $result['warnings'], 'shortanswer warns it is hand-graded');

// --- numerical ---------------------------------------------------------------
$descriptor = array(
  'slot' => 9, 'question_id' => 889, 'qtype' => 'numerical', 'name' => 'Num',
  'text_html' => 'What is 2 + 2?', 'maxmark' => 1.0,
  'answers' => array(array('text' => '4', 'fraction' => 1.0, 'tolerance' => 0.5)),
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals('number_response', $result['type'], 'numerical maps to number_response');
ps_test_assert_equals('4', $result['response_value'], 'numerical keeps the full-credit value');
ps_test_assert_equals(array('tolerance-dropped'), $result['warnings'], 'a non-zero tolerance warns');

// --- essay -------------------------------------------------------------------
$descriptor = array(
  'slot' => 10, 'question_id' => 890, 'qtype' => 'essay', 'name' => 'Essay',
  'text_html' => 'Discuss.', 'maxmark' => 5.0,
);
$result = quiz_normalizer::normalize($descriptor);

ps_test_assert_equals('writing_response', $result['type'], 'essay maps to writing_response');
ps_test_assert_equals('rubric_basic', $result['scoring_type'], 'essay uses rubric scoring');
ps_test_assert_equals(5.0, $result['points'], 'essay keeps its maxmark');
ps_test_assert_equals('medium', $result['size'], 'essay gets a medium writing area');

// --- unsupported types --------------------------------------------------------
foreach (array('multianswer', 'calculated', 'ddwtos', 'random') as $qtype) {
  $result = quiz_normalizer::normalize(array(
    'slot' => 11, 'question_id' => 891, 'qtype' => $qtype, 'name' => 'X',
    'text_html' => 'X', 'maxmark' => 1.0,
  ));
  ps_test_assert_equals(null, $result, $qtype . ' is not normalizable');
}

ps_test_assert_equals('unsupported-qtype', quiz_normalizer::skip_reason('multianswer'), 'unsupported qtype reason');
ps_test_assert_equals('random-question', quiz_normalizer::skip_reason('random'), 'random gets its own reason');

// --- html stripping -----------------------------------------------------------
ps_test_assert_equals(
  'Plain text',
  quiz_normalizer::html_to_text('<p>Plain <strong>text</strong></p>'),
  'inline markup is stripped'
);
ps_test_assert_equals(
  'A B',
  quiz_normalizer::html_to_text("<p>A</p>\n\n  <p>B</p>"),
  'whitespace is collapsed across blocks'
);
ps_test_assert_equals(
  'Caf&eacute; ok',
  htmlentities(quiz_normalizer::html_to_text('<p>Caf&eacute; ok</p>'), ENT_COMPAT, 'UTF-8'),
  'entities are decoded'
);

$descriptor = array(
  'slot' => 12, 'question_id' => 892, 'qtype' => 'truefalse', 'name' => 'Img',
  'text_html' => '<p>See <img src="x.png" alt="diagram"> above.</p>',
  'maxmark' => 1.0, 'rightanswer' => true,
);
$result = quiz_normalizer::normalize($descriptor);
ps_test_assert_equals(array('html-stripped'), $result['warnings'], 'a dropped image warns');

?>
