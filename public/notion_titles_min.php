<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';

function notion_req($url, $token, $payload=null){
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

function page_title($id, $token){
  static $cache = [];
  if (isset($cache[$id])) return $cache[$id];
  list($code,$body) = notion_req("https://api.notion.com/v1/pages/$id", $token);
  $title = '';
  if ($code === 200) {
    $j = json_decode($body, true);
    if (isset($j['properties'])) {
      foreach ($j['properties'] as $p) {
        if (($p['type'] ?? '') === 'title') {
          foreach (($p['title'] ?? []) as $t) $title .= $t['plain_text'] ?? '';
          break;
        }
      }
    }
  }
  return $cache[$id] = ($title !== '' ? $title : $id);
}

function relation_titles($prop, $token){
  if (($prop['type'] ?? '') !== 'relation') return null;
  $names = [];
  foreach ($prop['relation'] as $rel) {
    $rid = $rel['id'] ?? '';
    if ($rid) $names[] = page_title($rid, $token);
  }
  return implode('', $names);
}

function text_from_prop($prop){
  if (!is_array($prop)) return null;
  $t = $prop['type'] ?? '';
  if ($t === 'title' || $t === 'rich_text') {
    $s = '';
    foreach (($prop[$t] ?? []) as $x) $s .= $x['plain_text'] ?? '';
    return $s;
  }
  return null;
}

function pick_key($props, $candidates){
  foreach ($candidates as $kw) {
    foreach ($props as $name => $_) {
      if ($kw !== '' && mb_strpos($name, $kw) !== false) return $name;
    }
  }
  return null;
}

/* -------- main -------- */
$token = getenv('NOTION_TOKEN');
$db    = getenv('NOTION_DATABASE_ID');
if (!$token || !$db) { http_response_code(500); echo json_encode(['error'=>'TOKEN/DB missing']); exit; }

$limit = max(1, min(100, intval($_GET['limit'] ?? 20)));

/* 追加パラメータ
   field = task | calc | both(既定)
   only  = filled を指定すると、選ばれた field が空の項目を除外
*/
$field = strtolower($_GET['field'] ?? 'both'); // task|calc|both
if (!in_array($field, ['task','calc','both'], true)) $field = 'both';
$only  = strtolower($_GET['only']  ?? '');     // filled or ''

$payload = json_encode(['page_size'=>$limit], JSON_UNESCAPED_UNICODE);
list($code,$body) = notion_req("https://api.notion.com/v1/databases/$db/query", $token, $payload);
$j = json_decode($body, true);

$out = ['status'=>$code, 'items'=>[]];

if ($code === 200 && ($j['object'] ?? '') === 'list') {
  foreach ($j['results'] as $pg) {
    $p = $pg['properties'] ?? [];

    // ←← ここで列候補（必要なら実際の列名を足してください）
    $kTask = pick_key($p, ['タスク','Task','案件','内容']);        // 例: 実列名を追加 OK
    $kCalc = pick_key($p, ['計算案件','計算','計算案件名','案件']); // 例: 実列名を追加 OK

    // 値を取り出す
    $task = null;
    if ($kTask && isset($p[$kTask])) {
      $pr = $p[$kTask];
      $task = ($pr['type'] ?? '') === 'relation' ? relation_titles($pr, $token) : (text_from_prop($pr) ?? null);
    }

    $calc = null;
    if ($kCalc && isset($p[$kCalc])) {
      $pr = $p[$kCalc];
      $calc = ($pr['type'] ?? '') === 'relation' ? relation_titles($pr, $token) : (text_from_prop($pr) ?? null);
    }

    // フィルタリング
    if ($only === 'filled') {
      if ($field === 'task' && ($task === null || $task === '')) continue;
      if ($field === 'calc' && ($calc === null || $calc === '')) continue;
      if ($field === 'both' && ($task === null || $task === '') && ($calc === null || $calc === '')) continue;
    }

    // 出力成形（指定 field のみ返すことも可能）
    $item = ['id' => $pg['id']];
    if ($field === 'task') {
      $item['タスク'] = $task;
    } elseif ($field === 'calc') {
      $item['計算案件'] = $calc;
    } else { // both
      $item['タスク']   = $task;
      $item['計算案件'] = $calc;
    }
    $out['items'][] = $item;
  }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
