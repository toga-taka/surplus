<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';

function notion_req($url,$token,$payload=null){
  $ch=curl_init($url);
  $hdr=['Authorization: Bearer '.$token,'Notion-Version: 2022-06-28','Content-Type: application/json'];
  $opt=[CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$hdr];
  if($payload!==null){ $opt[CURLOPT_POST]=true; $opt[CURLOPT_POSTFIELDS]=$payload; }
  curl_setopt_array($ch,$opt);
  $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
  return [$code,$body];
}

function page_title($page_id,$token){
  static $cache=[]; if(isset($cache[$page_id])) return $cache[$page_id];
  list($code,$body)=notion_req("https://api.notion.com/v1/pages/$page_id",$token);
  $title='';
  if($code===200){
    $j=json_decode($body,true);
    if(isset($j['properties'])){
      foreach($j['properties'] as $prop){
        if(($prop['type']??'')==='title'){
          foreach($prop['title'] as $t){ $title .= $t['plain_text'] ?? ''; }
          break;
        }
      }
    }
  }
  return $cache[$page_id] = ($title!=='' ? $title : $page_id);
}

function relation_titles($prop,$token){
  if(($prop['type']??'')!=='relation') return null;
  $names=[];
  foreach(($prop['relation']??[]) as $r){
    $id = $r['id'] ?? '';
    if($id) $names[] = page_title($id,$token);
  }
  // 複数関連は「, 」連結
  return implode(', ', array_filter($names, fn($s)=>$s!==''));
}

function pick_key($props, $keywords){
  foreach($keywords as $kw){
    foreach($props as $name => $_){
      if(mb_strpos($name, $kw) !== false) return $name;
    }
  }
  return null;
}

$token=getenv('NOTION_TOKEN');
$db   =getenv('NOTION_DATABASE_ID');
if(!$token||!$db){ http_response_code(500); header('Content-Type:text/plain; charset=utf-8'); echo "TOKEN/DB missing\n"; exit; }

$limit   = max(1, min(100, intval($_GET['limit'] ?? 20)));
$payload = json_encode(['page_size'=>$limit], JSON_UNESCAPED_UNICODE);
list($code,$body) = notion_req("https://api.notion.com/v1/databases/$db/query",$token,$payload);
$j = json_decode($body, true);

$out = ['status'=>$code, 'items'=>[]];

if($code===200 && ($j['object'] ?? '') === 'list'){
  foreach($j['results'] as $pg){
    $p = $pg['properties'] ?? [];

    // プロパティ名ゆらぎに強いキー検出
    $kTask = pick_key($p, ['タスク','Task']);
    $kCalc = pick_key($p, ['計算案件','計算','calc']);

    $task = $kTask && isset($p[$kTask]) ? (
      ($p[$kTask]['type'] ?? '') === 'relation'
        ? relation_titles($p[$kTask], $token)
        : // 万一 relation 以外でも文字列を返せるように
          (function($prop){
            $t=$prop['type']??''; $s='';
            if($t==='title'||$t==='rich_text'){ foreach($prop[$t] as $x){ $s.=$x['plain_text']??''; } }
            return $s!=='' ? $s : null;
          })($p[$kTask])
    ) : null;

    $calc = $kCalc && isset($p[$kCalc]) && (($p[$kCalc]['type']??'')==='relation')
      ? relation_titles($p[$kCalc], $token)
      : null;

    $out['items'][] = [
      'id'       => $pg['id'],
      'タスク'   => $task,
      '計算案件' => $calc,
    ];
  }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
