<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';

/* ---------- Notion HTTP ---------- */
function notion_req(string $url, string $token, ?string $payload = null): array {
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

/* ---------- helpers ---------- */
function page_title(string $id, string $token): string {
  static $cache = [];
  if (isset($cache[$id])) return $cache[$id];
  [$code, $body] = notion_req("https://api.notion.com/v1/pages/$id", $token);
  $title = '';
  if ($code === 200) {
    $j = json_decode($body, true);
    if (isset($j['properties'])) {
      foreach ($j['properties'] as $p) {
        if (($p['type'] ?? '') === 'title') {
          foreach ($p['title'] as $t) $title .= ($t['plain_text'] ?? '');
          break;
        }
      }
    }
  }
  // アクセス権が無い等で取れなければ ID を返す（null にはしない）
  return $cache[$id] = ($title !== '' ? $title : $id);
}

function extract_text(array $prop): ?string {
  $t = $prop['type'] ?? '';
  if ($t === 'title' || $t === 'rich_text') {
    $s = '';
    foreach ($prop[$t] as $x) $s .= ($x['plain_text'] ?? '');
    return $s;
  }
  return null;
}

function relation_titles(?array $prop, string $token): ?string {
  if (!is_array($prop) || ($prop['type'] ?? '') !== 'relation') return null;
  $names = [];
  foreach ($prop['relation'] as $r) {
    $id = $r['id'] ?? '';
    if ($id) $names[] = page_title($id, $token);
  }
  return implode('、', array_filter($names, fn($x)=>$x!==''));
}

function pick_key(array $props, array $needles): ?string {
  foreach ($needles as $needle) {
    foreach ($props as $name => $_) {
      if (mb_strpos($name, $needle) !== false) return $name;
    }
  }
  return null;
}

/* ---------- main ---------- */
$token = getenv('NOTION_TOKEN');
$db    = getenv('NOTION_DATABASE_ID');
if (!$token || !$db) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['status'=>500,'error'=>'TOKEN/DB missing'], JSON_UNESCAPED_UNICODE);
  exit;
}

$limit      = max(1, min(100, intval($_GET['limit'] ?? 10)));
$onlyFilled = (($_GET['only'] ?? '') === 'filled');

$payload = json_encode(['page_size'=>$limit], JSON_UNESCAPED_UNICODE);
[$code, $body] = notion_req("https://api.notion.com/v1/databases/$db/query", $token, $payload);
$raw = json_decode($body, true);

$out = ['status'=>$code, 'items'=>[]];

if ($code === 200 && ($raw['object'] ?? '') === 'list') {
  foreach ($raw['results'] as $page) {
    $props = $page['properties'] ?? [];

    // まず名前で探す
    $kTask = pick_key($props, ['タスク','タスク名','案件タスク']);
    $kCalc = pick_key($props, ['計算案件','計算 案件','計算案件名','計算']);

    // relation 一覧（自動判定用）
    $relationKeys = [];
    foreach ($props as $n => $p) {
      if (($p['type'] ?? '') === 'relation') $relationKeys[] = $n;
    }

    // タスクが見つからなければ、先頭の relation をタスクとみなす
    if ($kTask === null && !empty($relationKeys)) $kTask = $relationKeys[0];

    // 計算案件が見つからなければ、
    // 1) 「計算」を含む relation、2) それも無ければ 2つ目の relation を使う
    if ($kCalc === null) {
      foreach ($relationKeys as $n) { if (mb_strpos($n,'計算') !== false) { $kCalc = $n; break; } }
      if ($kCalc === null && count($relationKeys) >= 2) {
        $kCalc = ($relationKeys[0] === $kTask) ? $relationKeys[1] : $relationKeys[0];
      }
    }

    // 値の取り出し
    $task = null;
    if ($kTask !== null && isset($props[$kTask])) {
      $p = $props[$kTask];
      $task = (($p['type'] ?? '') === 'relation') ? relation_titles($p, $token) : extract_text($p);
    }

    $calc = null;
    if ($kCalc !== null && isset($props[$kCalc])) {
      $p = $props[$kCalc];
      $calc = (($p['type'] ?? '') === 'relation') ? relation_titles($p, $token) : extract_text($p);
    }

    if ($onlyFilled && (trim((string)$task)==='' && trim((string)$calc)==='')) continue;

    $out['items'][] = [
      'id'       => $page['id'],
      'タスク'   => ($task === '' ? null : $task),
      '計算案件' => ($calc === '' ? null : $calc),
    ];
  }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
