<?php

/**
 * Pure helpers with no Moodle bootstrap of their own.
 *
 * common.php requires config.php and then this file, so every entry point
 * gets these. PHPUnit tests, which are already inside a booted Moodle and can
 * never include common.php, require this file directly.
 *
 * PHP 5.4 compatible: the plugin supports Moodle 2.7+.
 */
defined('MOODLE_INTERNAL') || die();

function ps_sign($key, $to_sign) {
  if (!$key)
    throw new moodle_exception("empty-signing-key", "local_paperscorer");
  return base64_encode(hash_hmac("sha1", $to_sign, trim($key), $raw_output=TRUE));
}

/**
 * Constant-time string comparison. hash_equals() arrived in PHP 5.6; the
 * fallback covers 5.4 and 5.5.
 */
function ps_secure_compare($known, $given) {
  if (!is_string($known) || !is_string($given))
    return false;
  if (function_exists('hash_equals'))
    return hash_equals($known, $given);
  if (strlen($known) !== strlen($given))
    return false;
  $diff = 0;
  for ($i = 0; $i < strlen($known); $i++)
    $diff |= ord($known[$i]) ^ ord($given[$i]);
  return $diff === 0;
}

function ps_get($obj, $attr, $default=null) {
  if (!property_exists($obj, $attr)) {
    if ($default !== null)
      return $default;
    throw new moodle_exception("invalid-attr", "local_paperscorer", '', $attr);
  }
  return $obj->{$attr};
}

function ps_load_action($action) {
  $res = json_decode($action);
  if (!$res)
    throw new moodle_exception("invalid-action-json", "local_paperscorer");
  return $res;
}

/**
 * Cryptographically strong random bytes. The instance secret is derived from
 * this, so it must not come from rand().
 */
function ps_random_bytes($n) {
  if (function_exists('random_bytes')) {
    try {
      return random_bytes($n);
    } catch (Exception $e) {
      // Fall through to the next source.
    }
  }
  if (function_exists('openssl_random_pseudo_bytes')) {
    $strong = false;
    $bytes = openssl_random_pseudo_bytes($n, $strong);
    if ($bytes !== false && $strong)
      return $bytes;
  }
  throw new moodle_exception("no-secure-random", "local_paperscorer");
}

?>
