<?php
require __DIR__.'/../bootstrap.php';
$token = getenv('NOTION_TOKEN');
if (!$token) { http_response_code(500); header('Content-Type: text/plain'); echo "NOTION_TOKEN missing\n"; exit; }

$ch = curl_init('https://api.notion.com/v1/users/me');
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

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status'=>$code, 'body'=>json_decode($body, true)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
