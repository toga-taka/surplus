<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

/* ---------- Notion helpers ---------- */
function notion_req($url,$token,$payload=null){
  $ch=curl_init($url);
  $hdr=['Authorization: Bearer '.$token,'Notion-Version: 2022-06-28','Content-Type: application/json'];
  $opt=[CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$hdr];
  if($payload!==null){ $opt[CURLOPT_POST]=true; $opt[CURLOPT_POSTFIELDS]=$payload; }
  curl_setopt_array($ch,$opt);
  $body=curl_exec($ch);
  $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return [$code,$body];
}
function page_title($id,$token){
  static $cache=[]; if(isset($cache[$id])) return $cache[$id];
  list($c,$b)=notion_req("https://api.notion.com/v1/pages/$id",$token);
  $title='';
  if($c===200){
    $j=json_decode($b,true);
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
  if(!is_array($prop) || !isset($prop['type'])) return null;
  $t=$prop['type'];
  if($t==='title'||$t==='rich_text'){ $s=''; foreach(($prop[$t]??[]) as $x){ $s.=$x['plain_text']??''; } return $s; }
  if($t==='number') return $prop['number'];
  if($t==='status') return $prop['status']['name']??null;
  if($t==='select') return $prop['select']['name']??null;
  if($t==='multi_select'){ return implode('', array_map(fn($m)=>$m['name']??'', $prop['multi_select'])); }
  if($t==='formula'){ $ft=$prop['formula']['type']??null; return $ft?($prop['formula'][$ft]??null):null; }
  if($t==='rollup'){
    $rt=$prop['rollup']['type']??null;
    if($rt==='array'){ $arr=[]; foreach($prop['rollup']['array'] as $it){ $arr[]=val($it);} return implode('',array_filter($arr)); }
    return $prop['rollup'][$rt]??null;
  }
  if($t==='date'){ $d=$prop['date']; return $d['start']??null; }
  if($t==='people'){ $p=$prop['people'][0]??null; return $p?($p['id']??null):null; }
  return null;
}

/* ---------- main ---------- */
$token=getenv('NOTION_TOKEN'); $db=getenv('NOTION_DATABASE_ID');
if(!$token||!$db){ http_response_code(500); echo json_encode(['error'=>'TOKEN/DB missing']); exit; }

$start = $_GET['start'] ?? date('Y-m-d');
$days  = max(1, min(60, intval($_GET['days'] ?? 14)));
$end   = (new DateTime($start))->modify("+$days day")->format('Y-m-d'); // end は “未満”

// 画面用カレンダー（日・祝の着色はフロントで）
$dates=[];
$dt=new DateTime($start);
for($i=0;$i<$days;$i++){
  $d=$dt->format('Y-m-d');
  $dates[]=['date'=>$d,'weekday'=>intval($dt->format('w')),'is_holiday'=>false];
  $dt->modify('+1 day');
}

/* --- 固定の列名（あなたのDBに合わせてあります） --- */
$kAssignee = '担当者';   // people
$kDate     = '日付';     // date
$kOrder    = '順番';     // number（無ければ 0）
$kCust     = '顧客名';   // formula/text
$kTask     = 'タスク';   // relation
$kContent  = '内容';     // title
$kPlan     = '当日予定'; // number/text
$kActual   = '実績';     // number/text

/* --- Notion 側で日付レンジをフィルタ + ページネーション --- */
$results=[]; $cursor=null;
do{
  $query = [
    'page_size' => 100,
    'filter' => [
      'and' => [
        ['property'=>$kDate, 'date'=>['on_or_after'=>$start]],
        ['property'=>$kDate, 'date'=>['before'=>$end]],
      ]
    ],
    // 必要ならソート（例：日付昇順・担当者名など）
    'sorts' => [
      ['property'=>$kDate, 'direction'=>'ascending'],
      // ['timestamp'=>'last_edited_time','direction'=>'descending'] なども可
    ],
  ];
  if($cursor) $query['start_cursor']=$cursor;

  list($code,$body)=notion_req("https://api.notion.com/v1/databases/$db/query",$token,json_encode($query, JSON_UNESCAPED_UNICODE));
  if($code!==200){ echo json_encode(['status'=>$code,'error'=>'notion query failed']); exit; }
  $j=json_decode($body,true);
  $results = array_merge($results, $j['results'] ?? []);
  $cursor  = $j['has_more'] ? ($j['next_cursor'] ?? null) : null;
}while($cursor);

/* --- 組み立て --- */
$items=[];            // カード配列
$userMap=[]; $users=[]; // Notion user id => name

foreach($results as $pg){
  $p=$pg['properties'] ?? [];

  // 日付（null は除外）
  $date = isset($p[$kDate]) ? val($p[$kDate]) : null;
  if(!$date) continue;

  // 担当（people）
  $assignee_id = null; $assignee_name=null;
  if(isset($p[$kAssignee]) && ($p[$kAssignee]['type']??'')==='people'){
    $u = ($p[$kAssignee]['people'][0] ?? null);
    if($u){ $assignee_id = $u['id'] ?? null; $assignee_name = $u['name'] ?? null; }
  }
  if($assignee_id && !isset($userMap[$assignee_id])){
    $userMap[$assignee_id] = $assignee_name ?: $assignee_id;
    $users[] = ['id'=>$assignee_id,'name'=>$userMap[$assignee_id]];
  }

  // タスク（relation→ページタイトル解決）/ 顧客名（formula）など
  $task = null;
  if(isset($p[$kTask])){
    if(($p[$kTask]['type']??'')==='relation') $task = relation_titles($p[$kTask],$token);
    else $task = val($p[$kTask]);
  }

  $items[] = [
    'id'          => $pg['id'],
    'assignee_id' => $assignee_id,
    'date'        => $date,
    'order'       => isset($p[$kOrder])  ? intval(val($p[$kOrder]))  : 0,
    'customer'    => isset($p[$kCust])   ? val($p[$kCust])   : null,
    'task'        => $task,
    'content'     => isset($p[$kContent])? val($p[$kContent]): null,
    'today_plan'  => isset($p[$kPlan])   ? val($p[$kPlan])   : null,
    'actual'      => isset($p[$kActual]) ? val($p[$kActual]) : null,
  ];
}

/* --- 出力 --- */
echo json_encode([
  'status'=>200,
  'users'=>$users,
  'dates'=>$dates,
  'items'=>$items,
], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
