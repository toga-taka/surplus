<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';


// プロパティ名（必要に応じて変更）
const P_DATE = '日付';
const P_OWNER = '担当者';
const P_TODAY = '当日予定';
const P_ACTUAL = '実績';
const P_ORDER = '順番';


$token = getenv('NOTION_TOKEN');
if(!$token){ http_response_code(500); echo json_encode(['error'=>'TOKEN missing']); exit; }


$raw = file_get_contents('php://input');
$in = json_decode($raw,true);
$id = $in['id'] ?? '';
if(!$id){ http_response_code(400); echo json_encode(['error'=>'id required']); exit; }


function req($method,$url,$tok,$payload=null){
$ch=curl_init($url); $hdr=['Authorization: Bearer '.$tok,'Notion-Version: 2022-06-28','Content-Type: application/json'];
$opt=[CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$hdr, CURLOPT_CUSTOMREQUEST=>$method];
if($payload!==null){ $opt[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE); }
curl_setopt_array($ch,$opt); $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch); return [$code,$body];
}


$props = [];
if(array_key_exists('plan',$in)) $props[P_TODAY] = ['number' => ($in['plan']===null? null : floatval($in['plan']))];
if(array_key_exists('actual',$in)) $props[P_ACTUAL] = ['number' => ($in['actual']===null? null : floatval($in['actual']))];
if(array_key_exists('order',$in)) $props[P_ORDER] = ['number' => ($in['order']===null? null : intval($in['order']))];
if(!empty($in['date'])) $props[P_DATE] = ['date' => ['start'=>$in['date']]];
if(array_key_exists('assignee_id',$in)){
$uid = $in['assignee_id'];
$props[P_OWNER] = ['people' => ($uid? [['id'=>$uid]] : [])];
}


$payload = ['properties'=>$props];
list($code,$body) = req('PATCH', "https://api.notion.com/v1/pages/$id", $token, $payload);


http_response_code($code);
header('Content-Type: application/json; charset=utf-8');
echo $body;