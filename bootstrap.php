<?php
// app/  USER/HOME
$env = __DIR__ . '/../secrets/notion.env';
if (is_readable($env)) {
  foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line === '' || $line[0] === '#') continue;
    $pos = strpos($line, '=');
    if ($pos === false) continue;
    $k = trim(substr($line, 0, $pos));
    $v = trim(substr($line, $pos + 1));
    if ($k !== '') {
      putenv("$k=$v");       // getenv() 
      $_ENV[$k] = $v;        // v;        // 
      $_SERVER[$k] = $v;
    }
  }
}
