<?php
$days = max(1, min(60, intval($_GET['days'] ?? 14)));
$end = date('Y-m-d', strtotime($start." +".($days-1).' day'));


// ---- HTTP
function req($method,$url,$tok,$payload=null){
$ch=curl_init($url); $hdr=['Authorization: Bearer '.$tok,'Notion-Version: 2022-06-28','Content-Type: application/json'];
$opt=[CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$hdr, CURLOPT_CUSTOMREQUEST=>$method];
if($payload!==null){ $opt[CURLOPT_POSTFIELDS] = is_string($payload)? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE); }
curl_setopt_array($ch,$opt); $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch); return [$code,$body];
}
function jreq($method,$url,$tok,$payload=null){ list($c,$b)=req($method,$url,$tok,$payload); return [$c,json_decode($b,true)]; }


// ---- 値解釈
function pick_text($prop){ if(!$prop||!isset($prop['type'])) return null; $t=$prop['type'];
if($t==='title'||$t==='rich_text'){ $s=''; foreach($prop[$t] as $x){ $s.=$x['plain_text']??''; } return $s; }
if($t==='select') return $prop['select']['name']??null; if($t==='status') return $prop['status']['name']??null; if($t==='url') return $prop['url']??null; if($t==='email') return $prop['email']??null; if($t==='phone_number') return $prop['phone_number']??null; if($t==='number') return $prop['number'];
if($t==='date') return $prop['date']['start'] ?? null;
if($t==='formula'){ $ft=$prop['formula']['type']; return $prop['formula'][$ft]??null; }
if($t==='rollup'){ $rt=$prop['rollup']['type']; if($rt==='array'){ $arr=[]; foreach($prop['rollup']['array'] as $it){ $arr[]=pick_text($it);} return implode(', ',array_filter($arr)); } return $prop['rollup'][$rt]??null; }
return null; }


// Relation タイトルをページから解決
function page_title($id,$tok){ static $C=[]; if(isset($C[$id])) return $C[$id];
list($c,$j)=jreq('GET',"https://api.notion.com/v1/pages/$id",$tok); $title='';
if($c===200 && isset($j['properties'])) foreach($j['properties'] as $p){ if(($p['type']??'')==='title'){ foreach($p['title'] as $t){ $title.=$t['plain_text']??''; } break; }}
return $C[$id] = $title ?: $id; }


function relation_titles($prop,$tok){ if(($prop['type']??'')!=='relation') return null; $names=[]; foreach($prop['relation'] as $r){ $id=$r['id']??''; if($id) $names[]=page_title($id,$tok); } return implode('、',$names); }


// ---- DB クエリ（期間フィルタ）
$filter = [ 'and' => [
['property'=>P_DATE, 'date'=>['on_or_after'=>$start]],
['property'=>P_DATE, 'date'=>['on_or_before'=>$end]],
]];
$payload = ['page_size'=>100, 'filter'=>$filter];


$items = [];$cursor=null;
while(true){ if($cursor) $payload['start_cursor']=$cursor; list($code,$j)=jreq('POST',"https://api.notion.com/v1/databases/$db/query",$token,$payload);
if($code!==200){ http_response_code($code); echo json_encode(['status'=>$code,'error'=>'query failed','body'=>$j],JSON_UNESCAPED_UNICODE); exit; }
foreach(($j['results']??[]) as $pg){ $p=$pg['properties']??[];
$date = pick_text($p[P_DATE] ?? null);
// People
$assigneeName=null; $assigneeId=null;
if(isset($p[P_OWNER]) && ($p[P_OWNER]['type']??'')==='people'){
$u=$p[P_OWNER]['people'][0]??null; if($u){ $assigneeName=$u['name']??null; $assigneeId=$u['id']??null; }
}
$customer = pick_text($p[P_CUST] ?? null);
$task = isset($p[P_TASK]) && (($p[P_TASK]['type']??'')==='relation') ? relation_titles($p[P_TASK],$token) : pick_text($p[P_TASK] ?? null);


$items[] = [
'id' => $pg['id'],
'date' => $date,
'assignee' => $assigneeName,
'assignee_id' => $assigneeId,
'customer' => $customer,
'task' => $task,
'content' => pick_text($p[P_CONT] ?? null),
'expected_hours' => pick_text($p[P_EXP] ?? null),
'plan_total' => pick_text($p[P_PLAN_T] ?? null),
'contract' => pick_text($p[P_CONTR] ?? null),
'today_plan' => pick_text($p[P_TODAY] ?? null),
'actual' => pick_text($p[P_ACTUAL] ?? null),
'order' => pick_text($p[P_ORDER] ?? null),
];
}
if(!($j['has_more']??false)) break; $cursor=$j['next_cursor']??null;
}


header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status'=>200,'items'=>$items], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);