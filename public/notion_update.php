<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function notion_req($url,$token,$method='GET',$payload=null){
  $ch=curl_init($url);
  $hdr=['Authorization: Bearer '.$token,'Notion-Version: 2022-06-28','Content-Type: application/json'];
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>$hdr,
    CURLOPT_CUSTOMREQUEST=>$method
  ]);
  if($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$payload);
  $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
  return [$code,$body];
}

/** 完全一致優先 + 型で絞る pick_key。型未指定なら従来通りの部分一致にも対応 */
function pick_key($props, $exacts = [], $fuzzies = [], $type = null){
  // 1) 完全一致（型も合うなら即採用）
  foreach($exacts as $name){
    if(isset($props[$name])){
      if($type===null || (($props[$name]['type'] ?? null) === $type)){
        return $name;
      }
    }
  }
  // 2) 型で絞って部分一致
  foreach($props as $name => $info){
    if($type!==null && (($info['type'] ?? null) !== $type)) continue;
    foreach($fuzzies as $w){
      if(mb_strpos($name, $w) !== false) return $name;
    }
  }
  // 3) 最後の保険: 同じ型の最初の列（あくまで任意）
  if($type!==null){
    foreach($props as $name=>$info){
      if(($info['type'] ?? null) === $type) return $name;
    }
  }
  return null;
}

function to_number_or_null($v){
  if($v===null || $v==='') return null;
  if(is_numeric($v)) return 0 + $v;
  return null;
}

/* ---- read payload ---- */
$input = file_get_contents('php://input');
$js = json_decode($input,true);
if(!$js){ echo json_encode(['status'=>400,'error'=>'invalid json']); exit; }

$token=getenv('NOTION_TOKEN');
if(!$token){ http_response_code(500); echo json_encode(['status'=>500,'error'=>'TOKEN missing']); exit; }

$id           = $js['id'] ?? null;
$assignee_id  = $js['assignee_id'] ?? null;
$date         = $js['date'] ?? null;
$order        = isset($js['order']) ? intval($js['order']) : null;
$plan         = isset($js['plan']) ? to_number_or_null($js['plan']) : null;
$today_plan   = isset($js['today_plan']) ? to_number_or_null($js['today_plan']) : null;
$actual       = isset($js['actual']) ? to_number_or_null($js['actual']) : null;
$remain       = isset($js['remain']) ? to_number_or_null($js['remain']) : null;
$promised_date = $js['promised_date'] ?? null;

if(!$id){ echo json_encode(['status'=>400,'error'=>'id required']); exit; }

/* ---- get page schema ---- */
list($c,$b)=notion_req("https://api.notion.com/v1/pages/$id",$token);
if($c!==200){ http_response_code(500); echo json_encode(['status'=>$c,'error'=>'get page failed','body'=>$b]); exit; }
$page=json_decode($b,true);
$props = $page['properties'] ?? [];

/* ---- resolve keys (型で厳密に) ---- */
$kAssignee = pick_key($props, ['担当者','担当'], ['社員','assignee'], 'people');
$kDate     = pick_key($props, ['日付'],         ['date','日'],        'date');   // ← ここが肝
$kOrder    = pick_key($props, ['順番'],         ['順','order'],       'number');
$kPlan     = pick_key($props, ['計画'],         [],                   'number');
$kToday    = pick_key($props, ['当日予定'],     ['今日予定','today'], 'number');
$kActual   = pick_key($props, ['実績'],         [],                   'number');
$kRemain   = pick_key($props, ['残時間','残'],  [],                   'number');
$kPromisedDate = pick_key($props, ['顧客と約束した納期'], ['約束納期','約束日','promised_date'], 'date');

/* ---- build properties ---- */
$update = ['properties'=>[]];

if($assignee_id!==null && $kAssignee){
  $update['properties'][$kAssignee] = ['people'=>[['id'=>$assignee_id]]];
}
if($date!==null && $kDate){
  $update['properties'][$kDate] = ['date'=>['start'=>$date]];
}
if($order!==null && $kOrder){
  $update['properties'][$kOrder] = ['number'=>$order];
}
if($plan!==null && $kPlan){
  $update['properties'][$kPlan] = ['number'=>$plan];
}
if($today_plan!==null && $kToday){
  $update['properties'][$kToday] = ['number'=>$today_plan];
}
if($actual!==null && $kActual){
  $update['properties'][$kActual] = ['number'=>$actual];
}
if($remain!==null && $kRemain){
  $update['properties'][$kRemain] = ['number'=>$remain];
}
if($promised_date!==null && $kPromisedDate){
  $update['properties'][$kPromisedDate] = ['date'=>['start'=>$promised_date]];
}

if(!$update['properties']){ echo json_encode(['status'=>200,'note'=>'no-op']); exit; }

/* ---- PATCH ---- */
$payload=json_encode($update, JSON_UNESCAPED_UNICODE);
list($pc,$pb)=notion_req("https://api.notion.com/v1/pages/$id",$token,'PATCH',$payload);
if($pc!==200){
  http_response_code(500);
  echo json_encode(['status'=>400,'request'=>$update,'notion'=>json_decode($pb,true)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
  exit;
}
echo json_encode(['status'=>200,'request'=>$update], JSON_UNESCAPED_UNICODE);