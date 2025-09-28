<?php
// /home/USER/app/public/notion_update.php
mb_internal_encoding('UTF-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../bootstrap.php'; // NOTION_TOKEN / NOTION_DATABASE_ID を使います

header('Content-Type: application/json; charset=utf-8');

/* --------------- helpers --------------- */
function notion_req($method, $url, $token, $payload = null){
  $ch = curl_init($url);
  $hdr = [
    'Authorization: Bearer '.$token,
    'Notion-Version: 2022-06-28',
    'Content-Type: application/json'
  ];
  $opt = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $hdr,
    CURLOPT_CUSTOMREQUEST  => $method,
  ];
  if ($payload !== null){
    $opt[CURLOPT_POSTFIELDS] = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE);
  }
  curl_setopt_array($ch, $opt);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  return [$code, $body, $err];
}

function pick_key($props, $cands, $type=null){
  // 候補文字列に部分一致で優先マッチ。無ければ type で最初の1つを返す。
  foreach ($cands as $w){
    foreach ($props as $name => $p){
      if (mb_strpos($name, $w) !== false){
        if ($type===null || ($p['type']??null)===$type) return $name;
      }
    }
  }
  if ($type!==null){
    foreach ($props as $name => $p){
      if (($p['type']??null)===$type) return $name;
    }
  }
  return null;
}

/* --------------- main --------------- */
$token = getenv('NOTION_TOKEN');
if (!$token){ http_response_code(500); echo json_encode(['status'=>500,'error'=>'NOTION_TOKEN missing']); exit; }

// メソッド判定：PATCH または POST+X-HTTP-Method-Override: PATCH を受け付け
$req_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$override   = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '';
if (!($req_method==='PATCH' || ($req_method==='POST' && strtoupper($override)==='PATCH'))){
  http_response_code(405);
  echo json_encode(['status'=>405,'error'=>'Method Not Allowed (use PATCH or POST + X-HTTP-Method-Override: PATCH)']);
  exit;
}

// JSON Body
$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)){
  http_response_code(400);
  echo json_encode(['status'=>400,'error'=>'Invalid JSON body','raw'=>$raw]);
  exit;
}

// 必須: id
$page_id = trim((string)($data['id'] ?? ''));
if ($page_id===''){
  http_response_code(400);
  echo json_encode(['status'=>400,'error'=>'"id" is required']);
  exit;
}

// 更新候補（どれかあればOK）
$want = [
  'date'        => $data['date']        ?? null,       // 'YYYY-MM-DD'
  'assignee_id' => $data['assignee_id'] ?? null,       // Notion user id
  'order'       => $data['order']       ?? null,       // number
  'today_plan'  => $data['today_plan']  ?? null,       // number
  'actual'      => $data['actual']      ?? null,       // number
];

// ページのプロパティを取得してプロパティ名を推定
list($pc, $pb, $perr) = notion_req('GET', "https://api.notion.com/v1/pages/$page_id", $token);
if ($pc!==200){
  http_response_code($pc);
  echo json_encode(['status'=>$pc,'error'=>'Failed to GET page','curl_error'=>$perr,'body'=>$pb]);
  exit;
}
$page_json = json_decode($pb, true);
$props = $page_json['properties'] ?? [];

// 日本語名優先の候補
$kDate    = pick_key($props, ['日付','Date','date'],            'date');
$kPeople  = pick_key($props, ['担当者','社員','Assignee'],       'people');
$kOrder   = pick_key($props, ['順番','order','Order'],           'number');
$kPlan    = pick_key($props, ['当日予定','today','Today'],       'number');
$kActual  = pick_key($props, ['実績','actual','Actual'],         'number');

// 更新ペイロード生成
$properties = [];

if ($want['date'] && $kDate){
  $properties[$kDate] = ['date' => ['start' => $want['date']]];
}
if ($want['assignee_id'] && $kPeople){
  $properties[$kPeople] = ['people' => [['object'=>'user','id'=>$want['assignee_id']]]];
}
if ($want['order']!==null && $kOrder){
  $properties[$kOrder] = ['number' => (is_numeric($want['order']) ? $want['order']+0 : null)];
}
if ($want['today_plan']!==null && $kPlan){
  $properties[$kPlan] = ['number' => (is_numeric($want['today_plan']) ? $want['today_plan']+0 : null)];
}
if ($want['actual']!==null && $kActual){
  $properties[$kActual] = ['number' => (is_numeric($want['actual']) ? $want['actual']+0 : null)];
}

if (!$properties){
  http_response_code(400);
  echo json_encode([
    'status'=>400,
    'error'=>'No updatable fields in request (date/assignee_id/order/today_plan/actual)',
    'guessed_keys'=>['date'=>$kDate,'people'=>$kPeople,'order'=>$kOrder,'today_plan'=>$kPlan,'actual'=>$kActual],
    'received'=>$want
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$payload = ['properties' => $properties];

list($uc, $ub, $uerr) = notion_req('PATCH', "https://api.notion.com/v1/pages/$page_id", $token, $payload);

http_response_code($uc);
if ($uc===200){
  echo json_encode(['status'=>200,'result'=>'ok','sent'=>$payload], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
} else {
  echo json_encode(['status'=>$uc,'error'=>'Notion PATCH failed','curl_error'=>$uerr,'payload'=>$payload,'body'=>$ub], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}
