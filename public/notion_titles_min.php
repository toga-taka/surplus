<?php
ini_set('display_errors',1); error_reporting(E_ALL);
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';

function notion_req($url,$token,$payload=null){
  $ch=curl_init($url);
  $hdr=['Authorization: Bearer '.$token,'Notion-Version: 2022-06-28','Content-Type: application/json'];
  $opt=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$hdr];
  if($payload!==null){ $opt[CURLOPT_POST]=true; $opt[CURLOPT_POSTFIELDS]=$payload; }
  curl_setopt_array($ch,$opt);
  $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
  return [$code,$body];
}
function page_title($id,$token){
  static $C=[]; if(isset($C[$id])) return $C[$id];
  list($c,$b)=notion_req("https://api.notion.com/v1/pages/$id",$token);
  $j=json_decode($b,true); $t='';
  if($c===200 && isset($j['properties'])){
    foreach($j['properties'] as $prop){
      if(($prop['type']??'')==='title'){
        foreach($prop['title'] as $x){ $t.=$x['plain_text']??''; }
        break;
      }
    }
  }
  return $C[$id] = $t ?: $id;
}

$token=getenv('NOTION_TOKEN'); $db=getenv('NOTION_DATABASE_ID');
if(!$token||!$db){ http_response_code(500); echo json_encode(['error'=>'TOKEN/DB missing']); exit; }

$limit=max(1,min(100,intval($_GET['limit']??20)));
$payload=json_encode(['page_size'=>$limit], JSON_UNESCAPED_UNICODE);
list($code,$body)=notion_req("https://api.notion.com/v1/databases/$db/query",$token,$payload);
$res=json_decode($body,true);

$out=['status'=>$code,'items'=>[]];
if($code===200 && ($res['object']??'')==='list'){
  foreach(($res['results']??[]) as $pg){
    $p=$pg['properties']??[];
    // タスク
    $task=null;
    if(isset($p['タスク'])){
      $prop=$p['タスク'];
      if(($prop['type']??'')==='relation'){
        $names=[]; foreach($prop['relation'] as $r){ $id=$r['id']??''; if($id) $names[]=page_title($id,$token); }
        $task=implode('、',$names);
      }else{
        $s=''; $arr=$prop['title'] ?? ($prop['rich_text'] ?? []);
        foreach($arr as $x){ $s.=$x['plain_text']??''; } $task=$s;
      }
    }
    // 計算案件
    $calc=null;
    if(isset($p['計算案件'])){
      $prop=$p['計算案件'];
      if(($prop['type']??'')==='relation'){
        $names=[]; foreach($prop['relation'] as $r){ $id=$r['id']??''; if($id) $names[]=page_title($id,$token); }
        $calc=implode('、',$names);
      }else{
        $s=''; $arr=$prop['title'] ?? ($prop['rich_text'] ?? []);
        foreach($arr as $x){ $s.=$x['plain_text']??''; } $calc=$s;
      }
    }
    $out['items'][]=['id'=>$pg['id'],'タスク'=>$task,'計算案件'=>$calc];
  }
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
