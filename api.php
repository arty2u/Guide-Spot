<?php
/**
 * 日本散步達人 — サーバー側 API
 *
 *   POST {"action":"ask","keyword":"..."}  保存済みカードを探し、なければ Claude に書かせて保存
 *   POST {"action":"list"}                 保存済みカードをすべて返す
 *   POST {"action":"save","card":{...}}    カードを1枚保存（同じ名前があれば何もしない）
 *   POST {"action":"delete","place":"..."} カードを削除（.trash に移す）
 *   POST {"action":"fav","place":"...","fav":true}  我的最愛の ON / OFF
 *
 * カードは 1 枚 = 1 ファイル（Markdown）で CARDS_DIR に置く。
 * 先頭の <!-- card: {...} --> にデータ、その下は人が読める本文。
 * API キーと合言葉は公開フォルダの外:
 *   /home/arty4u/.secrets/anthropic_api_key, /home/arty4u/.secrets/spot_guide_pass
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require __DIR__ . '/cards-lib.php';

function fail($code, $status) {
  http_response_code($status);
  echo json_encode(array('error' => $code));
  exit;
}
function out($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('method_not_allowed', 405);

$passes = is_readable(PASS_FILE) ? array_values(array_filter(array_map('trim', file(PASS_FILE)), 'strlen')) : array();   // 合言葉は 1 行に 1 つ（どれか一つで入れる）
$given = isset($_SERVER['HTTP_X_PASS']) ? trim($_SERVER['HTTP_X_PASS']) : '';
$ok = false; foreach ($passes as $p) if (function_exists('hash_equals') ? hash_equals($p, $given) : $p === $given) $ok = true;
if ($passes && !$ok) fail('need_pass', 401);   // 合言葉（ログインパスワード）

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = array();
$action = isset($in['action']) ? $in['action'] : 'ask';
if (!is_dir(CARDS_DIR)) @mkdir(CARDS_DIR, 0700, true);

/* ---------- list / save / delete ---------- */
if ($action === 'list') out(array('cards' => all_cards(), 'requests' => pending_requests()));

/* 作成待ちリストに入れる（API は使わない）。保存済みなら、そのカードを返す */
if ($action === 'request') {
  $kw = isset($in['keyword']) ? trim(preg_replace('/[\x00-\x1F\x7F`]/u', '', (string)$in['keyword'])) : '';
  if ($kw === '' || mb_strlen($kw, 'UTF-8') > 40) fail('bad_keyword', 400);
  $hit = find_card($kw);
  if ($hit) { $hit['cached'] = true; out($hit); }
  out(array('queued' => true, 'requests' => add_request($kw)));
}

if ($action === 'rebuild') {   // 既存カードを今の書式で書き直す
  foreach (all_cards() as $c) {   // 今の書式で書き直し、地域フォルダへ移す
    $old = CARDS_DIR . '/' . $c['id'] . '.md'; unset($c['id']);
    $c = clean_card($c); $new = card_path($c);
    write_card_file($new, $c);
    if ($new !== $old) @unlink($old);
  }
  foreach (glob(CARDS_DIR . '/*', GLOB_ONLYDIR) as $d) @rmdir($d);   // 空になったフォルダを片付け
  out(array('ok' => true));
}

if ($action === 'save') {
  $c = save_card(isset($in['card']) && is_array($in['card']) ? $in['card'] : array());
  if (!$c) fail('bad_card', 400);
  out($c);
}

if ($action === 'fav') {   // 我的最愛の ON / OFF（ファイルの場所は変えない）
  $c = find_card(isset($in['place']) ? (string)$in['place'] : '');
  if (!$c) fail('not_found', 404);
  $path = CARDS_DIR . '/' . $c['id'] . '.md'; unset($c['id']);
  $c['fav'] = !empty($in['fav']);
  write_card_file($path, clean_card($c));
  out(array('ok' => true, 'fav' => $c['fav']));
}

if ($action === 'delete') {
  $place = isset($in['place']) ? (string)$in['place'] : '';
  $c = find_card($place);
  if ($c) {
    $trash = CARDS_DIR . '/.trash';
    if (!is_dir($trash)) @mkdir($trash, 0700, true);
    @rename(CARDS_DIR . '/' . $c['id'] . '.md', $trash . '/' . str_replace('/', '_', $c['id']) . '-' . time() . '.md');
  }
  out(array('ok' => true));
}

/* ---------- ask：まず保存済みカードを探す ---------- */
$kw = isset($in['keyword']) ? trim((string)$in['keyword']) : '';
$kw = preg_replace('/[\x00-\x1F\x7F`]/u', '', $kw);
if ($kw === '' || mb_strlen($kw, 'UTF-8') > 40) fail('bad_keyword', 400);

$hit = find_card($kw);
if ($hit) {   // 検索した時刻を記録して、一覧の上に来るようにする
  $id = $hit['id']; unset($hit['id']);
  $hit['lastUsed'] = gmdate('Y-m-d\TH:i:s\Z');
  $hit = clean_card($hit);
  write_card_file(CARDS_DIR . '/' . $id . '.md', $hit);
  $hit['id'] = $id; $hit['cached'] = true; out($hit);
}

$key = is_readable(KEY_FILE) ? trim(file_get_contents(KEY_FILE)) : '';
if ($key === '') fail('not_configured', 500);

/* 1 日の上限（Claude を呼ぶときだけ数える） */
$fp = @fopen(DATA_DIR . '/usage.json', 'c+');
if ($fp) {
  flock($fp, LOCK_EX);
  $raw = stream_get_contents($fp);
  $u = $raw ? json_decode($raw, true) : null;
  if (!is_array($u) || !isset($u['day']) || $u['day'] !== date('Y-m-d')) $u = array('day' => date('Y-m-d'), 'count' => 0);
  if ($u['count'] >= PER_DAY) { flock($fp, LOCK_UN); fclose($fp); fail('daily_limit', 429); }
  $u['count']++;
  ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($u));
  flock($fp, LOCK_UN); fclose($fp);
}

$prompt = str_replace('{{KEYWORD}}', $kw, <<<'PROMPT'
你是專為台灣觀光客撰寫日本旅遊介紹的導遊作家。請針對以下關鍵字撰寫景點介紹，全部使用台灣慣用的繁體中文（台灣用語，不可使用簡體字或日文假名）。

關鍵字：「{{KEYWORD}}」

要求：
- 重要：關鍵字可能以日文輸入（日本新字體漢字或假名）。所有輸出（包括 place、location、keywords）一律轉換為台灣繁體字，例如：天満宮→天滿宮、国→國、宮崎県→宮崎縣、広島→廣島、沢→澤、桜→櫻、竜→龍、県→縣、駅→站、柳川川下り→柳川遊船，假名部分改為對應的中文說法。
- type：若關鍵字是人物（歷史人物、武將、藩主、文人、名人等）填 "person"，其他一律填 "spot"
- place：景點正式名稱或人物姓名（繁體中文，日本固有名詞保留漢字寫法）
- location：所在地，例如「福岡縣柳川市」
- era：創建、開館或相關年代，一句話簡述（不確定時寫「不詳」）
- intro：約200字（180～230字）的介紹文，內容包含特色、歷史背景與參觀亮點，語氣親切、資訊正確，不確定的事實不要編造；可分2～3段，段落間以\n分隔
- keywords：恰好10個相關關鍵字（繁體中文），第一個為景點名稱本身

若為人物：location 填與該人物最相關的地方（例如「福岡縣柳川市」），era 填生卒年（例如「1567年～1643年（安土桃山～江戶時代初期）」），intro 介紹其生平、事蹟，以及台灣旅客可造訪的相關景點。
若關鍵字是料理、祭典等其他主題，仍以旅遊角度介紹，type 填 "spot"，place 填該主題名稱。

只回覆一個 JSON 物件，不要其他文字，格式：
{"type":"spot","place":"","location":"","era":"","intro":"","keywords":["",...]}
PROMPT
);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, array(
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => json_encode(array(
    'model' => MODEL,
    'max_tokens' => 2000,
    'messages' => array(array('role' => 'user', 'content' => $prompt)),
  )),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 90,
  CURLOPT_HTTPHEADER => array(
    'content-type: application/json',
    'anthropic-version: 2023-06-01',
    'x-api-key: ' . $key,
  ),
));
$res = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($res === false) fail('upstream_error', 502);
$data = json_decode($res, true);
if ($status === 429) fail('rate_limited', 429);
if ($status !== 200 || !isset($data['content'])) fail('upstream_error', 502);
if (isset($data['stop_reason']) && $data['stop_reason'] === 'refusal') fail('refused', 422);

$text = '';
foreach ($data['content'] as $b) if (isset($b['type']) && $b['type'] === 'text') $text .= $b['text'];
$a = strpos($text, '{'); $z = strrpos($text, '}');
$card = ($a !== false && $z !== false) ? json_decode(substr($text, $a, $z - $a + 1), true) : null;
if (!is_array($card) || empty($card['intro'])) fail('invalid_json', 502);
if (empty($card['place'])) $card['place'] = $kw;

$saved = save_card($card);   // 保存（生成した名前がすでにあれば、そのカードを返す）
if (!$saved) fail('invalid_json', 502);
out($saved);
