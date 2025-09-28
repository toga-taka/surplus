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

function page_title_by_id($id,$token){
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

/** 任意のプロパティ値を文字列化（relation はタイトル解決） */
function stringify_prop($prop,$token){
  if(!is_array($prop) || !isset($prop['type'])) return null;
  $t=$prop['type'];
  if($t==='title' || $t==='rich_text'){
    $s=''; foreach($prop[$t] as $x){ $s.=$x['plain_text']??''; } return $s;
  }
  if($t==='relation'){
    $names=[]; foreach(($prop['relation']??[]) as $r){ $id=$r['id']??''; if($id) $names[]=page_title_by_id($id,$token); }
    return implode('、',$names);
  }
  if($t==='rollup'){
    $rt=$prop['rollup']['type']??'';
    if($rt==='array'){
      $arr=[]; foreach($prop['rollup']['array'] as $it){ $arr[]=stringify_prop($it,$token); }
      return implode('、', array_filter($arr, fn($x)=>$x!==null && $x!==''));
    }
    return $prop['rollup'][$rt]??null;
  }
  if($t==='select') return $prop['select']['name']??null;
  if($t==='multi_select') return implode('、', array_map(fn($x)=>$x['name']??'', $prop['multi_select']));
  if($t==='number') return $prop['number'];
  if($t==='status') return $prop['status']['name']??null;
  if($t==='date'){ $d=$prop['date']; if(!$d) return null; return (isset($d['end'])&&$d['end'])?($d['start'].'/'.$d['end']):$d['start']; }
  return null;
}

/** 部分一致で最初に見つかったキーを返す */
function pick_key_fuzzy($props,$cands){
  foreach($cands as $kw){
    foreach($props as $name => $_){
      if(mb_strpos($name,$kw)!==false) return $name;
    }
  }
  return null;
}
/** 正確一致があればそれを返し、なければ部分一致を使う */
function choose_key($props,$exact,$fuzzyList){
  if($exact && array_key_exists($exact,$props)) return $exact;
  return pick_key_fuzzy($props,$fuzzyList);
}

$token=getenv('NOTION_TOKEN'); $db=getenv('NOTION_DATABASE_ID');
if(!$token||!$db){ http_response_code(500); echo json_encode(['error'=>'TOKEN/DB missing']); exit; }

$limit=max(1,min(100,intval($_GET['limit']??20)));
$only = $_GET['only']  ?? '';      // '' | 'filled'
$field= $_GET['field'] ?? '';      // '' | 'task' | 'calc'
$taskName = $_GET['task_name'] ?? ''; // ここで列名を明示できる
$calcName = $_GET['calc_name'] ?? ''; // ここで列名を明示できる

// 既定のあいまい候補（必要に応じて増やせます）
$taskCands = ['タスク','課題','Task','タスク名'];
$calcCands = ['計算案件','計算','案件'];  // 「顧客案件」等と紛れそうなら calc_name を使うのが確実

$payload=json_encode(['page_size'=>$limit], JSON_UNESCAPED_UNICODE);
list($code,$body)=notion_req("https://api.notion.com/v1/databases/$db/query",$token,$payload);
$j=json_decode($body,true);

$out=['status'=>$code,'items'=>[]];
if($code===200 && ($j['object']??'')==='list'){
  foreach($j['results'] as $pg){
    $p=$pg['properties']??[];

    $kTask = choose_key($p, $taskName, $taskCands);
    $kCalc = choose_key($p, $calcName, $calcCands);

    $task = $kTask && isset($p[$kTask]) ? stringify_prop($p[$kTask],$token) : null;
    $calc = $kCalc && isset($p[$kCalc]) ? stringify_prop($p[$kCalc],$token) : null;

    if($only==='filled'){
      if($field==='task' && !$task) continue;
      if($field==='calc' && !$calc) continue;
      if($field==='' && !$task && !$calc) continue;
    }

    $out['items'][]=[
      'id'=>$pg['id'],
      'タスク'=>$task,
      '計算案件'=>$calc,
    ];
  }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
