<?php
mb_internal_encoding('UTF-8');
require __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

/* ===================== 共通ヘルパ ===================== */
function notion_req($method, $url, $token, $payloadArr = null){
  $ch = curl_init($url);
  $hdr = [
    'Authorization: Bearer '.$token,
    'Notion-Version: 2022-06-28',
    'Content-Type: application/json',
  ];
  $opt = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $hdr,
    CURLOPT_CUSTOMREQUEST  => strtoupper($method),
  ];
  if ($payloadArr !== null){
    $opt[CURLOPT_POSTFIELDS] = json_encode($payloadArr, JSON_UNESCAPED_UNICODE);
  }
  curl_setopt_array($ch, $opt);
  $body = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  return [$code, $body];
}

function jerr($code, $msg, $extra = []){
  http_response_code($code);
  echo json_encode(['status'=>$code, 'error'=>$msg] + $extra, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
  exit;
}

/* ============== メソッド判定（PATCH/POST 両対応） ============== */
$method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['PATCH','POST'], true)){
  jerr(405, 'method not allowed');
}

/* ===================== 入力 ===================== */
$raw  = file_get_contents('php://input') ?: '';
$in   = json_decode($raw, true);
if (!is_array($in)) jerr(400, 'invalid json');

$pageId = $in['id'] ?? '';
if (!$pageId) jerr(400, 'missing page id');

/* ========= 環境変数 ========= */
$token = getenv('NOTION_TOKEN');
if (!$token) jerr(500, 'missing NOTION_TOKEN');

/* ========= プロパティ名（あなたのDBに合わせて固定） =========
   担当者(people) / 日付(date) / 順番(number) / 当日予定(number) / 実績(number) / 内容(title)
   必要に応じてここを変えるだけで動きます。
*/
$kAssignee = '担当者';
$kDate     = '日付';
$kOrder    = '順番';
$kPlan     = '当日予定';
$kActual   = '実績';
$kContent  = '内容';

/* ===================== 反映する fields を組み立て ===================== */
$props = [];
$updated = [];

/* 担当者 people */
if (array_key_exists('assignee_id', $in)){
  $pid = trim((string)$in['assignee_id']);
  $props[$kAssignee] = ['people' => ($pid !== '') ? [['id'=>$pid]] : []];
  $updated[] = $kAssignee;
}

/* 日付 date (YYYY-MM-DD) */
if (array_key_exists('date', $in)){
  $d = trim((string)$in['date']);
  $props[$kDate] = ($d !== '') ? ['date'=>['start'=>$d]] : ['date'=>null];
  $updated[] = $kDate;
}

/* 順番 number */
if (array_key_exists('order', $in)){
  $v = $in['order'];
  $num = ($v === '' || $v === null) ? null : (int)$v;
  $props[$kOrder] = ['number'=>$num];
  $updated[] = $kOrder;
}

/* 当日予定 number（today_plan でも plan でもOK） */
if (array_key_exists('today_plan', $in) || array_key_exists('plan', $in)){
  $v = $in['today_plan'] ?? $in['plan'];
  $num = ($v === '' || $v === null) ? null : (float)$v;
  $props[$kPlan] = ['number'=>$num];
  $updated[] = $kPlan;
}

/* 実績 number */
if (array_key_exists('actual', $in)){
  $v = $in['actual'];
  $num = ($v === '' || $v === null) ? null : (float)$v;
  $props[$kActual] = ['number'=>$num];
  $updated[] = $kActual;
}

/* タイトル（必要な場合のみ） */
if (array_key_exists('content', $in)){
  $s = (string)$in['content'];
  $props[$kContent] = [
    'title' => ($s === '')
      ? []   // 空にしたいなら空配列
      : [[ 'text' => ['content'=>$s] ]]
  ];
  $updated[] = $kContent;
}

if (!$props){
  jerr(400, 'no updatable fields in payload', ['payload'=>$in]);
}

/* ===================== Notion へ反映 ===================== */
list($code, $body) = notion_req('PATCH', "https://api.notion.com/v1/pages/{$pageId}", $token, [
  'properties' => $props,
]);

/* ===================== 応答 ===================== */
if ($code === 200){
  echo json_encode([
    'status'  => 200,
    'page_id' => $pageId,
    'updated' => $updated,
  ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
} else {
  // Notion からのエラー内容もそのまま返す
  jerr($code ?: 500, 'notion update failed', ['response'=>json_decode($body, true)]);
}
