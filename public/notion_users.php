<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';
$token=getenv('NOTION_TOKEN');
if(!$token){ http_response_code(500); echo json_encode(['error'=>'TOKEN missing']); exit; }


function jreq($url,$tok){ $ch=curl_init($url); $hdr=['Authorization: Bearer '.$tok,'Notion-Version: 2022-06-28']; curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$hdr]); $b=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch); return [$c,json_decode($b,true)]; }


$users=[]; $cursor=null;
while(true){ $url='https://api.notion.com/v1/users'.($cursor?"?start_cursor=$cursor":''); list($c,$j)=jreq($url,$token); if($c!==200){ http_response_code($c); echo json_encode(['error'=>'users failed','body'=>$j]); exit; }
foreach(($j['results']??[]) as $u){ if(($u['type']??'')==='person') $users[]=['id'=>$u['id']??'','name'=>$u['name']??'']; }
if(!($j['has_more']??false)) break; $cursor=$j['next_cursor']??null;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['users'=>$users], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);