<?php
mb_internal_encoding('UTF-8');
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

/* ---------- Notion helpers ---------- */
function notion_req($url, $token, $payload = null) {
  $ch = curl_init($url);
  $hdr = [
    'Authorization: Bearer ' . $token,
    'Notion-Version: 2022-06-28',
    'Content-Type: application/json',
  ];
  $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdr];
  if ($payload !== null) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = $payload; }
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
function relation_titles($prop, $token) {
  if (($prop['type'] ?? '') !== 'relation') return null;
  $names = [];
  foreach ($prop['relation'] as $r) {
    $rid = $r['id'] ?? '';
    if ($rid) $names[] = page_title($rid, $token);
  }
  return implode('', $names);
}
function val($prop) {
  if (!is_array($prop) || !isset($prop['type'])) return null;
  $t = $prop['type'];
  if ($t === 'title' || $t === 'rich_text') {
    $s = ''; foreach (($prop[$t] ?? []) as $x) $s .= $x['plain_text'] ?? '';
    return $s;
  }
  if ($t === 'number') return $prop['number'];
  if ($t === 'status') return $prop['status']['name'] ?? null;
  if ($t === 'select') return $prop['select']['name'] ?? null;
  if ($t === 'multi_select') return implode('', array_map(fn($m)=>$m['name'] ?? '', $prop['multi_select']));
  if ($t === 'formula') { $ft = $prop['formula']['type'] ?? null; return $ft ? ($prop['formula'][$ft] ?? null) : null; }
  if ($t === 'rollup') {
    $rt = $prop['rollup']['type'] ?? null;
    if ($rt === 'array') {
      $arr = [];
      foreach ($prop['rollup']['array'] as $it) $arr[] = val($it);
      return implode('', array_filter($arr));
    }
    return $prop['rollup'][$rt] ?? null;
  }
  if ($t === 'date') {
    $d = $prop['date']; if (!$d || !($d['start'] ?? '')) return null;
    // 2025-09-05 or 2025-09-05T00:00:00.000+09:00 -> 2025-09-05
    return substr($d['start'], 0, 10);
  }
  if ($t === 'people') return null; // peopleは別処理
  return null;
}
function pick_key($props, $cands) {
  foreach ($cands as $w) {
    foreach ($props as $name => $_) {
      if (mb_strpos($name, $w) !== false) return $name;
    }
  }
  return null;
}

/* ---------- main ---------- */
$token = getenv('NOTION_TOKEN');
$db    = getenv('NOTION_DATABASE_ID');
if (!$token || !$db) { http_response_code(500); echo json_encode(['error'=>'TOKEN/DB missing']); exit; }

$start = $_GET['start'] ?? date('Y-m-d');
$days  = max(1, min(31, intval($_GET['days'] ?? 14)));

$dates = [];
$dt = new DateTime($start);
for ($i = 0; $i < $days; $i++) {
  $d = $dt->format('Y-m-d');
  $dates[] = ['date'=>$d, 'weekday'=>intval($dt->format('w')), 'is_holiday'=>false];
  $dt->modify('+1 day');
}

// Notion DB 取得
$payload = json_encode(['page_size'=>100], JSON_UNESCAPED_UNICODE);
list($code, $body) = notion_req("https://api.notion.com/v1/databases/$db/query", $token, $payload);
if ($code !== 200) { echo json_encode(['status'=>$code, 'error'=>'notion query failed']); exit; }
$j = json_decode($body, true);

$items = [];
$userMap = [];  // Notion user id => name
$users   = [];

// レコードを走査
foreach (($j['results'] ?? []) as $pg) {
  $p = $pg['properties'] ?? [];

  // ★ここが肝：日本語プロパティ名に合わせて候補を定義
$kAssignee = '担当者';   // people
$kDate     = '日付';     // date
$kOrder    = '順番';     // number（無ければ空でもOK）
$kCust     = '顧客名';   // text/title
$kTask     = 'タスク';   // relation（カードに表示するタイトル）
$kContent  = '内容';     // text/rich_text
$kPlan     = '当日予定'; // number/text（どちらでも text化して返します）
$kActual   = '実績';     // number/text

  // 日付必須
  $date = ($kDate && isset($p[$kDate])) ? val($p[$kDate]) : null;
  if (!$date) continue;

  // 期間外はスキップ
  $end = (new DateTime($start))->modify("+$days day")->format('Y-m-d');
  if ($date < $start || $date >= $end) continue;

  // 担当者（people）
  $assignee_id = null; $assignee_name = null;
  if ($kAssignee && isset($p[$kAssignee]) && ($p[$kAssignee]['type'] ?? '') === 'people') {
    $u = ($p[$kAssignee]['people'][0] ?? null);
    if ($u) { $assignee_id = $u['id'] ?? null; $assignee_name = $u['name'] ?? null; }
  }
  if ($assignee_id && !isset($userMap[$assignee_id])) {
    $userMap[$assignee_id] = $assignee_name ?: $assignee_id;
    $users[] = ['id'=>$assignee_id, 'name'=>$userMap[$assignee_id]];
  }

  // タスク（relation or text）
  $task = null;
  if ($kTask && isset($p[$kTask])) {
    $task = (($p[$kTask]['type'] ?? '') === 'relation') ? relation_titles($p[$kTask], $token) : val($p[$kTask]);
  }

  $items[] = [
    'id'          => $pg['id'],
    'assignee_id' => $assignee_id,
    'date'        => $date,
    'order'       => ($kOrder && isset($p[$kOrder])) ? intval(val($p[$kOrder])) : 0,
    'customer'    => ($kCust    && isset($p[$kCust]))    ? val($p[$kCust])    : null,
    'task'        => $task,
    'content'     => ($kContent && isset($p[$kContent])) ? val($p[$kContent]) : null,
    'today_plan'  => ($kPlan    && isset($p[$kPlan]))    ? val($p[$kPlan])    : null,
    'actual'      => ($kActual  && isset($p[$kActual]))  ? val($p[$kActual])  : null,
  ];
}

echo json_encode([
  'status' => 200,
  'users'  => $users,   // board.html は notion_users.php も見に行くので、空でも大丈夫
  'dates'  => $dates,
  'items'  => $items,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
