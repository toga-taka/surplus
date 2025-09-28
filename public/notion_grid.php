<?php
mb_internal_encoding('UTF-8');
require __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

/* ---------------- Notion helpers ---------------- */
function notion_req($url, $token, $payload = null){
    $ch = curl_init($url);
    $hdr = [
        'Authorization: Bearer '.$token,
        'Notion-Version: 2022-06-28',
        'Content-Type: application/json',
    ];
    $opt = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$hdr];
    if ($payload !== null){ $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = $payload; }
    curl_setopt_array($ch, $opt);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body];
}
function page_title($id, $token){
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    list($c, $b) = notion_req("https://api.notion.com/v1/pages/$id", $token);
    $title = '';
    if ($c === 200){
        $j = json_decode($b, true);
        if (isset($j['properties'])){
            foreach ($j['properties'] as $prop){
                if (($prop['type'] ?? '') === 'title'){
                    foreach (($prop['title'] ?? []) as $t){ $title .= ($t['plain_text'] ?? ''); }
                    break;
                }
            }
        }
    }
    return $cache[$id] = ($title !== '' ? $title : $id);
}
function relation_titles($prop, $token){
    if (($prop['type'] ?? '') !== 'relation') return null;
    $names = [];
    foreach (($prop['relation'] ?? []) as $r){
        $rid = $r['id'] ?? '';
        if ($rid) $names[] = page_title($rid, $token);
    }
    return implode(', ', $names);
}
/* 値を文字列に */
function val($prop){
    if (!is_array($prop) || !isset($prop['type'])) return null;
    $t = $prop['type'];
    if ($t === 'title' || $t === 'rich_text'){
        $s = '';
        foreach (($prop[$t] ?? []) as $x){ $s .= ($x['plain_text'] ?? ''); }
        return $s;
    }
    if ($t === 'number') return $prop['number'];
    if ($t === 'status') return $prop['status']['name'] ?? null;
    if ($t === 'select') return $prop['select']['name'] ?? null;
    if ($t === 'multi_select') return implode(', ', array_map(fn($m)=>$m['name'] ?? '', $prop['multi_select']));
    if ($t === 'date'){ $d = $prop['date'] ?? null; return $d ? ($d['start'] ?? null) : null; }
    if ($t === 'formula'){ $ft = $prop['formula']['type'] ?? null; return $ft ? ($prop['formula'][$ft] ?? null) : null; }
    if ($t === 'rollup'){
        $rt = $prop['rollup']['type'] ?? null;
        if ($rt === 'array'){
            $arr = [];
            foreach (($prop['rollup']['array'] ?? []) as $it){ $arr[] = val($it); }
            return implode(', ', array_filter($arr, fn($x)=>$x!==null && $x!==''));
        }
        return $prop['rollup'][$rt] ?? null;
    }
    return null;
}
/* 部分一致でプロパティ名を拾う */
function pick_key($props, $cands){
    foreach ($cands as $w){
        foreach ($props as $name => $_){
            if (mb_strpos($name, $w) !== false) return $name;
        }
    }
    return null;
}

/* ---------------- main ---------------- */
$token = getenv('NOTION_TOKEN');
$db    = getenv('NOTION_DATABASE_ID');
if (!$token || !$db){ http_response_code(500); echo json_encode(['error'=>'TOKEN/DB missing']); exit; }

$start = $_GET['start'] ?? date('Y-m-d');
$days  = max(1, min(31, intval($_GET['days'] ?? 14)));

$dates = [];
$dt = new DateTime($start);
for ($i=0; $i<$days; $i++){
    $dates[] = [
        'date' => $dt->format('Y-m-d'),
        'weekday' => intval($dt->format('w')),
        'is_holiday' => false  // 必要なら別途判定
    ];
    $dt->modify('+1 day');
}
$end = (new DateTime($start))->modify("+$days day")->format('Y-m-d');

/* DB クエリ */
$payload = json_encode(['page_size'=>100], JSON_UNESCAPED_UNICODE);
list($code, $body) = notion_req("https://api.notion.com/v1/databases/$db/query", $token, $payload);
if ($code !== 200){ echo json_encode(['status'=>$code, 'error'=>'notion query failed']); exit; }
$j = json_decode($body, true);

$items = [];
$userMap = [];
$users = [];

foreach (($j['results'] ?? []) as $pg){
    $p = $pg['properties'] ?? [];

    $kAssignee = pick_key($p, ['担当者','社員']);
    $kDate     = pick_key($p, ['日付','Date','date']);
    $kOrder    = pick_key($p, ['順番','order']);
    $kCust     = pick_key($p, ['顧客','顧客名','得意先']);
    $kTask     = pick_key($p, ['タスク']);
    $kContent  = pick_key($p, ['内容']);
    $kPlan     = pick_key($p, ['当日予定','予定']);
    $kActual   = pick_key($p, ['実績']);

    $date = ($kDate && isset($p[$kDate])) ? val($p[$kDate]) : null;
    if (!$date) continue;
    $d10 = substr($date, 0, 10);
    if (!($d10 >= $start && $d10 < $end)) continue;

    $assignee_id = null; $assignee_name = null;
    if ($kAssignee && isset($p[$kAssignee]) && ($p[$kAssignee]['type'] ?? '') === 'people'){
        $u = $p[$kAssignee]['people'][0] ?? null;
        if ($u){ $assignee_id = $u['id'] ?? null; $assignee_name = $u['name'] ?? null; }
    }
    if ($assignee_id && !isset($userMap[$assignee_id])){
        $userMap[$assignee_id] = $assignee_name ?: $assignee_id;
        $users[] = ['id'=>$assignee_id, 'name'=>$userMap[$assignee_id]];
    }

    $task = null;
    if ($kTask && isset($p[$kTask])){
        $pr = $p[$kTask];
        if (($pr['type'] ?? '') === 'relation') $task = relation_titles($pr, $token);
        else $task = val($pr);
    }

    $items[] = [
        'id'          => $pg['id'],
        'assignee_id' => $assignee_id,
        'date'        => $d10,
        'order'       => ($kOrder && isset($p[$kOrder])) ? intval(val($p[$kOrder])) : 0,
        'customer'    => ($kCust && isset($p[$kCust])) ? val($p[$kCust]) : null,
        'task'        => $task,
        'content'     => ($kContent && isset($p[$kContent])) ? val($p[$kContent]) : null,
        'today_plan'  => ($kPlan && isset($p[$kPlan])) ? val($p[$kPlan]) : null,
        'actual'      => ($kActual && isset($p[$kActual])) ? val($p[$kActual]) : null,
    ];
}

echo json_encode([
    'status' => 200,
    'users'  => $users,
    'dates'  => $dates,
    'items'  => $items,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
