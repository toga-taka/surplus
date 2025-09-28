<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$token=getenv('NOTION_TOKEN');
if(!$token){ http_response_code(500); echo json_encode(['error'=>'TOKEN missing']); exit; }

/* 列名（gridと合わせる） */
$kAssignee = '担当者';
$kDate     = '日付';
$kOrder    = '順番';
$kPlan     = '計画';       // ★追加
$kToday    = '当日予定';
$kActual   = '実績';

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true) ?: [];

/* id チェック（UUID） */
$id = $in['id'] ?? '';
if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)){
  echo json_encode(['status'=>400,'error'=>'invalid id','received'=>$in]); exit;
}

/* 更新組み立て */
$props = [];
if(array_key_exists('plan',$in))        $props[$kPlan]   = ['number'=> (is_null($in['plan'])? null : (float)$in['plan'])];
if(array_key_exists('today_plan',$in))  $props[$kToday]  = ['number'=> (is_null($in['today_plan'])? null : (float)$in['today_plan'])];
if(array_key_exists('actual',$in))      $props[$kActual] = ['number'=> (is_null($in['actual'])? null : (float)$in['actual'])];

if(isset($in['date']) && $in['date']){
  $props[$kDate] = ['date'=> ['start'=>$in['date']]];
}
if(isset($in['assignee_id']) && $in['assignee_id']){
  $props[$kAssignee] = ['people'=> [['id'=>$in['assignee_id']]]];
}
if(isset($in['order'])){
  $props[$kOrder] = ['number'=> (int)$in['order']];
}

$payload = json_encode(['properties'=>$props], JSON_UNESCAPED_UNICODE);

/* 送信 */
$ch=curl_init("https://api.notion.com/v1/pages/$id");
curl_setopt_array($ch,[
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_CUSTOMREQUEST =>'PATCH',
  CURLOPT_POSTFIELDS    =>$payload,
  CURLOPT_HTTPHEADER    =>[
    'Authorization: Bearer '.$token,
    'Notion-Version: 2022-06-28',
    'Content-Type: application/json',
  ],
]);
$body=curl_exec($ch);
$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
curl_close($ch);

$out = ['status'=>$code,'request'=>json_decode($payload,true)];
$nj = json_decode($body,true);
if($code!==200) $out['notion']=$nj;
echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
