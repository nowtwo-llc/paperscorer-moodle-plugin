<?php

namespace local_paperscorer;

/**
 * Turns a plain question descriptor (built by quizlib.php from a Moodle
 * question_definition) into the normalized item PaperScorer imports.
 *
 * This file must not call any Moodle API. It is loaded both by Moodle's class
 * autoloader and directly by tests/standalone/run.php, which is what lets the
 * risky mapping logic be tested without a Moodle install.
 *
 * PHP 5.4 compatible: the plugin supports Moodle 2.7+.
 */
class quiz_normalizer {

  /**
   * Moodle qtype => PaperScorer item type. Anything absent is skipped.
   */
  public static function type_map() {
    return array(
      'multichoice' => 'multiple_choice',
      'truefalse'   => 'true_false',
      'match'       => 'association_response',
      'shortanswer' => 'writing_response',
      'numerical'   => 'number_response',
      'essay'       => 'writing_response',
    );
  }

  /**
   * Why a question could not be exported.
   */
  public static function skip_reason($qtype) {
    if ($qtype === 'random')
      return 'random-question';
    return 'unsupported-qtype';
  }

  /**
   * Collapse question HTML to plain text for a printed sheet.
   */
  public static function html_to_text($html) {
    $text = preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\s*\/?>/i', ' ', $html);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
  }

  /**
   * True when stripping the markup would lose something a reader needs. A
   * plain <p> wrapper is not worth warning about; an image is.
   */
  protected static function loses_content($html) {
    return (bool) preg_match('/<(img|table|video|audio|object|embed|iframe)\b/i', $html);
  }

  protected static function letter($index) {
    return chr(65 + $index);
  }

  /**
   * @return array|null The normalized item, or null when the qtype cannot be exported.
   */
  public static function normalize($descriptor) {
    $qtype = $descriptor['qtype'];
    $map = self::type_map();
    if (!isset($map[$qtype]))
      return null;

    $item = array(
      'slot'           => $descriptor['slot'],
      'question_id'    => $descriptor['question_id'],
      'qtype'          => $qtype,
      'type'           => $map[$qtype],
      'name'           => $descriptor['name'],
      'text'           => self::html_to_text($descriptor['text_html']),
      'points'         => (float) $descriptor['maxmark'],
      'scoring_type'   => 'per_question',
      'response_value' => '',
      'warnings'       => array(),
    );

    if (self::loses_content($descriptor['text_html']))
      $item['warnings'][] = 'html-stripped';

    switch ($qtype) {
      case 'multichoice':
        $item = self::apply_multichoice($item, $descriptor);
        break;
      case 'truefalse':
        $item = self::apply_truefalse($item, $descriptor);
        break;
      case 'match':
        $item = self::apply_match($item, $descriptor);
        break;
      case 'shortanswer':
        $item = self::apply_shortanswer($item, $descriptor);
        break;
      case 'numerical':
        $item = self::apply_numerical($item, $descriptor);
        break;
      case 'essay':
        $item = self::apply_essay($item, $descriptor);
        break;
    }

    return $item;
  }

  protected static function apply_multichoice($item, $descriptor) {
    $answers = $descriptor['answers'];
    $single = !empty($descriptor['single']);

    $responses = array();
    $response_text = array();
    $positive = array();

    foreach (array_values($answers) as $index => $answer) {
      $letter = self::letter($index);
      $responses[] = $letter;
      $response_text[$letter] = self::html_to_text($answer['text']);
      if ($answer['fraction'] > 0)
        $positive[$letter] = (float) $answer['fraction'];
    }

    $correct = array();

    if (count($positive) === 0) {
      $item['warnings'][] = 'no-correct-answer';
    } else if ($single) {
      // Only one answer can be marked on paper. Keep the best one and say so
      // if that discards a partially-credited alternative.
      $best = max($positive);
      foreach ($positive as $letter => $fraction) {
        if ($fraction == $best) {
          $correct[] = $letter;
          break;
        }
      }
      if (count($positive) > 1)
        $item['warnings'][] = 'partial-credit-collapsed';
    } else {
      // Fractions that add up to full credit are Moodle's normal way of
      // saying "all of these together"; nothing is lost. Anything else is.
      $sum = 0.0;
      foreach ($positive as $letter => $fraction) {
        $correct[] = $letter;
        $sum += $fraction;
      }
      if (abs($sum - 1.0) > 0.001)
        $item['warnings'][] = 'partial-credit-collapsed';
    }

    sort($correct);

    $item['responses'] = $responses;
    $item['response_text'] = $response_text;
    $item['correct'] = $correct;
    $item['response_value'] = implode('', $correct);

    return $item;
  }

  protected static function apply_truefalse($item, $descriptor) {
    $item['responses'] = array('A', 'B');
    $item['response_text'] = array('A' => 'True', 'B' => 'False');
    $item['correct'] = !empty($descriptor['rightanswer']) ? array('A') : array('B');
    $item['response_value'] = $item['correct'][0];
    return $item;
  }

  protected static function apply_match($item, $descriptor) {
    $stems = $descriptor['stems'];
    $choices = $descriptor['choices'];
    $right = $descriptor['right'];

    $choice_letters = array();
    $options = array();
    $index = 0;
    foreach ($choices as $choice_key => $choice_text) {
      $letter = self::letter($index);
      $choice_letters[$choice_key] = $letter;
      $options[] = $letter;
      $index += 1;
    }

    $prompts = array();
    $correct = array();
    foreach ($stems as $stem_key => $stem_text) {
      $prompts[] = self::html_to_text($stem_text);
      $choice_key = isset($right[$stem_key]) ? $right[$stem_key] : null;
      if ($choice_key !== null && isset($choice_letters[$choice_key])) {
        $correct[] = $choice_letters[$choice_key];
      } else {
        $correct[] = '';
        if (!in_array('no-correct-answer', $item['warnings']))
          $item['warnings'][] = 'no-correct-answer';
      }
    }

    $item['prompts'] = $prompts;
    $item['options'] = $options;
    $item['correct'] = $correct;
    $item['grid_scoring_type'] = 'per_part';
    $item['response_value'] = implode('', $correct);

    // maxmark covers the whole question; PaperScorer scores association rows
    // individually, so divide it across the rows.
    $rows = count($prompts);
    if ($rows > 0)
      $item['points'] = (float) $item['points'] / $rows;

    return $item;
  }

  protected static function apply_shortanswer($item, $descriptor) {
    $item['scoring_type'] = 'rubric_basic';
    $item['size'] = 'small';
    $item['response_value'] = self::first_full_credit_answer($descriptor['answers']);
    $item['warnings'][] = 'hand-graded';
    return $item;
  }

  protected static function apply_numerical($item, $descriptor) {
    $item['response_value'] = self::first_full_credit_answer($descriptor['answers']);
    foreach ($descriptor['answers'] as $answer) {
      if (!empty($answer['tolerance'])) {
        $item['warnings'][] = 'tolerance-dropped';
        break;
      }
    }
    return $item;
  }

  protected static function apply_essay($item, $descriptor) {
    $item['scoring_type'] = 'rubric_basic';
    $item['size'] = 'medium';
    return $item;
  }

  protected static function first_full_credit_answer($answers) {
    foreach ($answers as $answer) {
      if ($answer['fraction'] >= 1.0)
        return self::html_to_text($answer['text']);
    }
    return '';
  }
}
