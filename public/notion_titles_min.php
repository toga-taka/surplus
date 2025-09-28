<?php
mb_internal_encoding('UTF-8');
require __DIR__ . '/../bootstrap.php';

/** -----------------------
 *  Notion HTTP helpers
 *  ----------------------*/
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

function page_title($page_id, $token){
  static $cache = [];
  if (isset($cache[$page_id])) return $cache[$page_id];
  list($c,$b) = notion_req("https://api.notion.com/v1/pages/$page_id", $token);
  $t = '';
  if ($c===200) {
    $j = json_decode($b, true);
    foreach (($j['properties'] ?? []) as $p) {
      if (($p['type'] ?? '') === 'title') {
        foreach ($p['title'] as $x) $t .= $x['plain_text'] ?? '';
        break;
      }
    }
  }
  return $cache[$page_id] = ($t !== '' ? $t : $page_id);
}

/** rollup(array) 内の要素を文字列化 */
function rollup_item_to_text($it, $token){
  $t = $it['type'] ?? '';
  if ($t==='title' || $t==='rich_text'){
    $s=''; foreach($it[$t] as $x) $s .= $x['plain_text'] ?? ''; return $s;
  }
  if ($t==='people'){ return implode(', ', array_map(fn($u)=>($u['name']??($u['person']['email']??'')), $it['people'])); }
  if ($t==='relation'){ return relation_titles(['type'=>'relation','relation'=>$it['relation']], $token); }
  if ($t==='number'){ return (string)($it['number'] ?? ''); }
  if ($t==='date'){ $d=$it['date']??null; if(!$d) return ''; $s=$d['start']??''; $e=$d['end']??''; return $e ? "$s/$e" : $s; }
  return '';
}

/** relation をページタイトルに解決して結合 */
function relation_titles($prop, $token){
  if (($prop['type'] ?? '') !== 'relation') return '';
  $names=[];
  foreach ($prop['relation'] as $r) {
    $id = $r['id'] ?? '';
    if ($id) $names[] = page_title($id, $token);
  }
  // 読みやすいように読点で連結
  return implode('、', array_filter($names, fn($x)=>$x!==''));
}

/** あらゆる型を “表示用の文字列” に変換（formula/rollup 対応） */
function prop_to_text($prop, $token){
  if (!is_array($prop) || !isset($prop['type'])) return '';
  $t = $prop['type'];

  if ($t==='title' || $t==='rich_text'){
    $s=''; foreach($prop[$t] as $x) $s .= $x['plain_text'] ?? ''; return $s;
  }
  if ($t==='relation'){ return relation_titles($prop, $token); }
  if ($t==='people'){ return implode(', ', array_map(fn($u)=>($u['name']??($u['person']['email']??'')), $prop['people'])); }
  if ($t==='select'){ return $prop['select']['name'] ?? ''; }
  if ($t==='multi_select'){ return implode(', ', array_map(fn($m)=>$m['name']??'', $prop['multi_select'])); }
  if ($t==='status'){ return $prop['status']['name'] ?? ''; }
  if ($t==='number'){ return (string)($prop['number'] ?? ''); }
  if ($t==='checkbox'){ return !empty($prop['checkbox']) ? 'true' : ''; }
  if ($t==='url' || $t==='email' || $t==='phone_number'){ return $prop[$t] ?? ''; }
  if ($t==='date'){ $d=$prop['date']??null; if(!$d) return ''; $s=$d['start']??''; $e=$d['end']??''; return $e ? "$s/$e" : $s; }

  if ($t==='formula'){
    $ft = $prop['formula']['type'] ?? '';
    if ($ft==='string')  return (string)($prop['formula']['string']  ?? '');
    if ($ft==='number')  return (string)($prop['formula']['number']  ?? '');
    if ($ft==='boolean') return !empty($prop['formula']['boolean']) ? 'true' : '';
    if ($ft==='date'){ $d=$prop['formula']['date']??null; if(!$d) return ''; $s=$d['start']??''; $e=$d['end']??''; return $e ? "$s/$e" : $s; }
    return ''; // その他は空扱い
  }

  if ($t==='rollup'){
    $rt = $prop['rollup']['type'] ?? '';
    if ($rt==='array'){
      $vals=[]; foreach($prop['rollup']['array'] as $it){ $vals[] = rollup_item_to_text($it, $token); }
      $vals = array_filter($vals, fn($x)=>$x!=='');
      return implode('、', $vals);
    }
    if ($rt==='number') return (string)($prop['rollup']['number'] ?? '');
    if ($rt==='date'){ $d=$prop['rollup']['date']??null; if(!$d) return ''; $s=$d['start']??''; $e=$d['end']??''; return $e ? "$s/$e" : $s; }
    return '';
  }

  return '';
}

/** 名前でプロパティを取り出す（存在しなければ部分一致候補から探す） */
function pick_prop_key($props, $exact=null, $candidates=[]){
  if ($exact && isset($props[$exact])) return $exact;
  foreach ($candidates as $kw){
    foreach ($props as $name => $_) {
      if (mb_strpos($name, $kw) !== false) return $name;
    }
  }
  return null;
}

/* ====== main ====== */
$token = getenv('NOTION_TOKEN');
$db    = getenv('NOTION_DATABASE_ID');
if(!$token || !$db){ http_response_code(500); echo json_encode(['error'=>'TOKEN/DB missing']); exit; }

$limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
$field = $_GET['field'] ?? 'both'; // task | calc | both
$only  = $_GET['only']  ?? '';      // '' | 'filled'

// 明示指定できるように（任意）
$task_name = $_GET['task_name'] ?? null;
$calc_name = $_GET['calc_name'] ?? null;

$payload = json_encode(['page_size'=>$limit], JSON_UNESCAPED_UNICODE);
list($code, $body) = notion_req("https://api.notion.com/v1/databases/$db/query", $token, $payload);
$res = json_decode($body, true);

$out = ['status'=>$code, 'items'=>[]];

if ($code === 200 && ($res['object']??'') === 'list') {
  foreach ($res['results'] as $pg) {
    $p = $pg['properties'] ?? [];

    // プロパティ名の決定
    $kTask = pick_prop_key($p, $task_name, ['タスク','Task']);
    $kCalc = pick_prop_key($p, $calc_name, ['計算案件','案件','計算']);

    $task = $kTask && isset($p[$kTask]) ? prop_to_text($p[$kTask], $token) : '';
    $calc = $kCalc && isset($p[$kCalc]) ? prop_to_text($p[$kCalc], $token) : '';

    // only=filled のフィルタ
    if ($only === 'filled') {
      if ($field === 'task' && $task === '') continue;
      if ($field === 'calc' && $calc === '') continue;
      if ($field === 'both' && ($task==='' && $calc==='')) continue;
    }

    // 返却フィールド
    $row = ['id'=>$pg['id']];
    if ($field === 'task' || $field === 'both') $row['タスク'] = $task;
    if ($field === 'calc' || $field === 'both') $row['計算案件'] = $calc;

    $out['items'][] = $row;
  }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
