<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function notion_req($method,$url,$token,$payload=null){
  $ch=curl_init($url);
  $hdr=[
    'Authorization: Bearer '.$token,
    'Notion-Version: 2022-06-28',
    'Content-Type: application/json'
  ];
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_CUSTOMREQUEST=>$method,
    CURLOPT_HTTPHEADER=>$hdr,
  ]);
  if($payload!==null){ curl_setopt($ch,CURLOPT_POSTFIELDS,$payload); }
  $body=curl_exec($ch);
  $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return [$code,$body];
}

try{
  $token=getenv('NOTION_TOKEN');
  if(!$token){ http_response_code(500); echo json_encode(['error'=>'TOKEN missing']); exit; }

  // --- 入力（POST/PATCH 両対応。CLI からのテストでも STDIN を拾える） ---
  $raw = file_get_contents('php://input');
  $in  = json_decode($raw,true);
  if(!is_array($in)) $in=[];

  $pageId = $in['id'] ?? null;
  if(!$pageId){ http_response_code(400); echo json_encode(['error'=>'missing id']); exit; }

  // プロパティ名（あなたのDBに合わせる）
  $kDate   = '日付';      // date
  $kOrder  = '順番';      // number
  $kPlan   = '当日予定';  // number
  $kActual = '実績';      // number
  $kUser   = '担当者';    // people

  // --- 送信用プロパティを組み立て ---
  $props=[];

  if(array_key_exists('date',$in) && $in['date']!==''){
    $props[$kDate] = ['date'=>['start'=>$in['date']]];
  }
  if(array_key_exists('order',$in)){
    $props[$kOrder] = ['number'=> (is_null($in['order'])? null : (float)$in['order'])];
  }
  if(array_key_exists('today_plan',$in)){
    $props[$kPlan] = ['number'=> (is_null($in['today_plan'])? null : (float)$in['today_plan'])];
  }
  if(array_key_exists('actual',$in)){
    $props[$kActual] = ['number'=> (is_null($in['actual'])? null : (float)$in['actual'])];
  }
  if(array_key_exists('assignee_id',$in)){
    $id = $in['assignee_id'];
    $props[$kUser] = ['people'=> ($id? [['id'=>$id]] : [])]; // null/空ならクリア
  }

  if(!$props){
    http_response_code(400);
    echo json_encode(['error'=>'no updatable fields']);
    exit;
  }

  $payload=json_encode(['properties'=>$props], JSON_UNESCAPED_UNICODE);
  list($code,$body)=notion_req('PATCH',"https://api.notion.com/v1/pages/$pageId",$token,$payload);

  // 成功/失敗の詳細をそのまま返す（デバッグしやすくする）
  http_response_code($code===200?200:500);
  $out=['status'=>$code,'request'=>json_decode($payload,true)];
  $js=json_decode($body,true);
  if(is_array($js)) $out['notion']=$js; else $out['raw']=$body;
  echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);

}catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
