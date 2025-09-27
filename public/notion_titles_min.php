<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';

function notion_req($url, $token, $payload=null) {
  $ch = curl_init($url);
  $hdr = [
    'Authorization: Bearer '.$token,
    'Notion-Version: 2022-06-28',
    'Content-Type: application/json',
  ];
  $opt = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$hdr];
  if ($payload !== null) { $opt[CURLOPT_POST]=true; $opt[CURLOPT_POSTFIELDS]=$payload; }
  curl_setopt_array($ch, $opt);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return [$code, $body];
}

function page_title($id, $token) {
  static $cache = [];
  if (isset($cache[$id])) return $cache[$id];
  list($c, $b) = notion_req("https://api.notion.com/v1/pages/$id", $token);
  $title = '';
  if ($c === 200) {
    $j = json_decode($b, true);
    if (isset($j['properties'])) {
      foreach ($j['properties'] as $p) {
        if (($p['type'] ?? '') === 'title') {
          foreach ($p['title'] as $t) $title .= $t['plain_text'] ?? '';
          break;
        }
      }
    }
  }
  return $cache[$id] = ($title !== '' ? $title : $id);
}

$token = getenv('NOTION_TOKEN');
$db    = getenv('NOTION_DATABASE_ID');
if (!$token || !$db) { http_response_code(500); echo json_encode(['error'=>'TOKEN/DB missing']); exit; }

$limit   = max(1, min(100, intval($_GET['limit'] ?? 10)));
$payload = json_encode(['page_size' => $limit], JSON_UNESCAPED_UNICODE);
list($code, $body) = notion_req("https://api.notion.com/v1/databases/$db/query", $token, $payload);
$j = json_decode($body, true);

$out = ['status'=>$code, 'items'=>[]];
if ($code === 200 && ($j['object'] ?? '') === 'list') {
  foreach ($j['results'] as $pg) {
    $p = $pg['properties'] ?? [];

    // タスク（relationならページタイトル解決／text/titleならそのまま）
    $task = null;
    if (isset($p['タスク'])) {
      $pr = $p['タスク'];
      if (($pr['type'] ?? '') === 'relation') {
        $names = [];
        foreach ($pr['relation'] as $rel) { $rid = $rel['id'] ?? ''; if ($rid) $names[] = page_title($rid, $token); }
        $task = implode('、', $names);
      } else {
        $s = ''; $arr = $pr['title'] ?? ($pr['rich_text'] ?? []);
        foreach ($arr as $t) $s .= $t['plain_text'] ?? '';
        $task = $s;
      }
    }

    // 計算案件（存在する候補名の relation を1つ拾う）
    $calc = null;
    foreach (['計算案件','計算案件名','計算','案件'] as $cand) {
      if (isset($p[$cand]) && (($p[$cand]['type'] ?? '') === 'relation')) {
        $names = [];
        foreach ($p[$cand]['relation'] as $rel) { $rid = $rel['id'] ?? ''; if ($rid) $names[] = page_title($rid, $token); }
        $calc = implode('、', $names);
        break;
      }
    }

    $out['items'][] = [
      'id'       => $pg['id'],
      'タスク'     => $task,
      '計算案件'    => $calc,
    ];
  }
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
