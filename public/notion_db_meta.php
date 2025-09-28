<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';

$token = getenv('NOTION_TOKEN');
$db    = getenv('NOTION_DATABASE_ID');
if (!$token || !$db) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "TOKEN/DB missing";
  exit;
}

$ch = curl_init("https://api.notion.com/v1/databases/$db");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    'Authorization: Bearer '.$token,
    'Notion-Version: 2022-06-28',
    'Content-Type: application/json',
  ],
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$outProps = [];
$j = json_decode($body, true);
if (isset($j['properties']) && is_array($j['properties'])) {
  foreach ($j['properties'] as $name => $info) {
    $outProps[] = [
      'name' => $name,
      'type' => $info['type'] ?? null,
    ];
  }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status'=>$code, 'properties'=>$outProps], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
