<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

/* ========== Notion helpers ========== */
function notion_req($url,$token,$payload=null){
  $ch=curl_init($url);
  $hdr=['Authorization: Bearer '.$token,'Notion-Version: 2022-06-28','Content-Type: application/json'];
  $opt=[CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$hdr];
  if($payload!==null){ $opt[CURLOPT_POST]=true; $opt[CURLOPT_POSTFIELDS]=$payload; }
  curl_setopt_array($ch,$opt);
  $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
  return [$code,$body];
}
function page_title($id,$token){
  static $cache=[]; if(isset($cache[$id])) return $cache[$id];
  list($c,$b)=notion_req("https://api.notion.com/v1/pages/$id",$token);
  $title=''; if($c===200){ $j=json_decode($b,true);
    if(isset($j['properties'])) foreach($j['properties'] as $p){
      if(($p['type']??'')==='title'){ foreach($p['title'] as $t){ $title.=$t['plain_text']??''; } break; }
    }
  }
  return $cache[$id]=($title!==''?$title:$id);
}
function relation_titles($prop,$token){
  if(($prop['type']??'')!=='relation') return null;
  $names=[]; foreach($prop['relation'] as $r){ $rid=$r['id']??''; if($rid) $names[]=page_title($rid,$token); }
  return implode('', $names);
}
function val($prop){
  if(!is_array($prop) || !isset($prop['type'])) return null; $t=$prop['type'];
  if($t==='title'||$t==='rich_text'){ $s=''; foreach(($prop[$t]??[]) as $x){ $s.=$x['plain_text']??''; } return $s; }
  if($t==='number') return $prop['number'];
  if($t==='status') return $prop['status']['name']??null;
  if($t==='select') return $prop['select']['name']??null;
  if($t==='multi_select'){ return implode('', array_map(fn($m)=>$m['name']??'', $prop['multi_select'])); }
  if($t==='formula'){ $ft=$prop['formula']['type']??null; return $ft?($prop['formula'][$ft]??null):null; }
  if($t==='rollup'){ $rt=$prop['rollup']['type']??null;
    if($rt==='array'){ $arr=[]; foreach($prop['rollup']['array'] as $it){ $arr[]=val($it);} return implode('',array_filter($arr)); }
    return $prop['rollup'][$rt]??null;
  }
  if($t==='date'){ $d=$prop['date']; return $d['start']??null; }
  return null;
}
function pick_key($props, $exacts, $fuzzies=[]){
  // 1) 完全一致を優先
  foreach($exacts as $name){ if(array_key_exists($name,$props)) return $name; }
  // 2) あいまい一致（部分一致・先頭一致）
  foreach($fuzzies as $w){
    foreach($props as $name=>$_){
      if(mb_strpos($name,$w)!==false) return $name;
    }
  }
  return null;
}

/* ========== main ========== */
$token=getenv('NOTION_TOKEN'); $db=getenv('NOTION_DATABASE_ID');
if(!$token||!$db){ http_response_code(500); echo json_encode(['error'=>'TOKEN/DB missing']); exit; }

$start = $_GET['start'] ?? date('Y-m-d');
$days  = max(1, min(60, intval($_GET['days'] ?? 14)));

$dates=[]; $dt=new DateTime($start);
for($i=0;$i<$days;$i++){
  $d=$dt->format('Y-m-d');
  $dates[]=['date'=>$d,'weekday'=>intval($dt->format('w')),'is_holiday'=>false];
  $dt->modify('+1 day');
}

$payload=json_encode(['page_size'=>100], JSON_UNESCAPED_UNICODE);
list($code,$body)=notion_req("https://api.notion.com/v1/databases/$db/query",$token,$payload);
if($code!==200){ echo json_encode(['status'=>$code,'error'=>'notion query failed']); exit; }
$j=json_decode($body,true);

$items=[];            // カード
$users=[];            // {id,name}
$userMap=[];          // Notion user id => name（重複防止用）

foreach(($j['results']??[]) as $pg){
  $p=$pg['properties'] ?? [];

  // === 列名を DB 実列名で固定 → 無ければ類推 ===
  $kAssignee = pick_key($p, ['担当者'], ['担当','アサイン','assignee']);
  $kDate     = pick_key($p, ['日付'],   ['date','日']);
  $kOrder    = pick_key($p, ['順番'],   ['順','order']);
  $kCust     = pick_key($p, ['顧客名'], ['顧客','客','customer','顧']);
  $kTask     = pick_key($p, ['タスク'], ['task','関連']);
  $kContent  = pick_key($p, ['内容'],   ['件名','タイトル','title','内容']);
  $kPlan     = pick_key($p, ['計画'],   ['計画']);
  $kToday    = pick_key($p, ['当日予定'], ['当日','予定']);
  $kActual   = pick_key($p, ['実績'],   ['実績','actual']);
  $kRemain   = pick_key($p, ['残時間'], ['残','remain']);
  $kpromiseddate = pick_key($p, ['顧客と約束した納期'], ['約束納期','約束日','promised_date']);
  

  // 日付必須（範囲外は捨てる）
  $date = ($kDate && isset($p[$kDate])) ? val($p[$kDate]) : null;
  if(!$date) continue;
  $end=(new DateTime($start))->modify("+$days day")->format('Y-m-d');
  if($date < $start || $date >= $end) continue;

  // 担当者（people）を取り出し、ユーザ一覧を作る
  $assignee_id=null; $assignee_name=null;
  if($kAssignee && isset($p[$kAssignee]) && ($p[$kAssignee]['type']??'')==='people'){
    $u = ($p[$kAssignee]['people'][0] ?? null);
    if($u){ $assignee_id = $u['id'] ?? null; $assignee_name = $u['name'] ?? null; }
  }
  if($assignee_id && !isset($userMap[$assignee_id])){
    $userMap[$assignee_id] = $assignee_name ?: $assignee_id;
    $users[] = ['id'=>$assignee_id,'name'=>$userMap[$assignee_id]];
  }

  // タスクは relation の場合はページタイトルも解決
  $task = null;
  if($kTask && isset($p[$kTask])){
    if(($p[$kTask]['type']??'')==='relation') $task = relation_titles($p[$kTask],$token);
    else $task = val($p[$kTask]);
  }


  $items[] = [
    'id'          => $pg['id'],
    'assignee_id' => $assignee_id,                    // null でも返す（未割当用）
    'date'        => $date,
    'order'       => ($kOrder && isset($p[$kOrder])) ? intval(val($p[$kOrder])) : 0,
    'customer'    => ($kCust && isset($p[$kCust])) ? val($p[$kCust]) : null,
    'task'        => $task,
    'content'     => ($kContent && isset($p[$kContent])) ? val($p[$kContent]) : null,
    'plan'        => ($kPlan   && isset($p[$kPlan]))   ? floatval(val($p[$kPlan]))   : null,
    'today_plan'  => ($kToday  && isset($p[$kToday]))  ? floatval(val($p[$kToday]))  : null,
    'actual'      => ($kActual && isset($p[$kActual])) ? floatval(val($p[$kActual])) : null,
    'remain'      => ($kRemain && isset($p[$kRemain])) ? floatval(val($p[$kRemain])) : null,
    'promised_date' => ($kpromiseddate && isset($p[$kpromiseddate])) ? val($p[$kpromiseddate]) : null,
  ];
}

echo json_encode([
  'status'=>200,
  'users'=>$users,   // people で見つかったメンバーだけ（UI 左列）
  'dates'=>$dates,
  'items'=>$items,
], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
