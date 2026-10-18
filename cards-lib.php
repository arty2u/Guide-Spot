<?php
/** 日本散步達人 — カード保存の共通処理（api.php と import-inbox.php が使う） */
if (!defined('CARDS_DIR')) {
  define('KEY_FILE', '/home/arty4u/.secrets/anthropic_api_key');
  define('PASS_FILE', '/home/arty4u/.secrets/spot_guide_pass');
  define('DATA_DIR', '/home/arty4u/.spot-guide');
  define('CARDS_DIR', '/home/arty4u/.spot-guide/cards');
  define('MODEL', 'claude-sonnet-5');
  define('PER_DAY', 1000);  // 念のための 1 日の上限（Claude を呼んだ回数だけ数える）
}

/* ---------- 名前の比較（空白・記号・括弧内の補足は無視） ---------- */
function norm_name($t) {
  $t = preg_replace('/[\s・·･\-－ー_「」『』"\']/u', '', (string)$t);
  return mb_strtolower($t, 'UTF-8');
}
function base_name($t) { return norm_name(preg_replace('/[（(].*?[）)]/u', '', (string)$t)); }
function same_name($a, $b) {
  if ($a === '' || $b === '') return false;
  return norm_name($a) === norm_name($b) || base_name($a) === base_name($b);
}
/* 地域フォルダ：場所から「縣/市」を取り出す（例：福岡縣/福岡市）。アプリの地區分類と同じ規則 */
function safe_seg($t) { $t = preg_replace('/[\/\\\\:*?<>|\x00-\x1F]/u', '', (string)$t); return $t === '' ? '其他' : $t; }
function region_dir($c) {
  $loc = trim(preg_replace('/[（(].*$/u', '', isset($c['location']) ? (string)$c['location'] : ''));
  if ($loc === '' || mb_substr($loc, 0, 2, 'UTF-8') === '日本') return '日本全國';
  if (!preg_match('/^(東京都|北海道|京都府|大阪府|[^都道府縣]{2,3}[縣県])?(.+?[市郡區町村])?/u', $loc, $m)) return '其他';
  $pref = isset($m[1]) && $m[1] !== '' ? preg_replace('/県$/u', '縣', $m[1]) : '';
  $city = isset($m[2]) ? $m[2] : '';
  if ($pref === '' && $city === '') return '其他';
  if ($pref === '') return safe_seg($city);
  return safe_seg($pref) . ($city !== '' ? '/' . safe_seg($city) : '');
}
function card_path($c) {
  $n = safe_seg(norm_name($c['place']));
  return CARDS_DIR . '/' . region_dir($c) . '/' . $n . '.md';
}

/* ---------- カードの読み書き ---------- */
function clean_card($c) {
  $kws = array();
  if (isset($c['keywords']) && is_array($c['keywords'])) foreach ($c['keywords'] as $k) $kws[] = (string)$k;
  $type = isset($c['type']) ? (string)$c['type'] : 'spot';
  if ($type !== 'person' && $type !== 'hotel') $type = 'spot';
  $r = array(
    'type'     => $type,
    'place'    => isset($c['place']) ? trim((string)$c['place']) : '',
    'location' => isset($c['location']) ? (string)$c['location'] : '',
    'era'      => isset($c['era']) ? (string)$c['era'] : '',
    'intro'    => isset($c['intro']) ? (string)$c['intro'] : '',
    'keywords' => array_slice($kws, 0, 10),
    'origin'   => isset($c['origin']) ? (string)$c['origin'] : '',
    'ja'       => isset($c['ja']) ? (string)$c['ja'] : '',      // 日本語の名前
    'yomi'     => isset($c['yomi']) ? (string)$c['yomi'] : '',  // 日本語の読み（ひらがな）
    'savedAt'  => isset($c['savedAt']) ? (string)$c['savedAt'] : gmdate('Y-m-d\TH:i:s\Z'),
    'lastUsed' => isset($c['lastUsed']) ? (string)$c['lastUsed'] : (isset($c['savedAt']) ? (string)$c['savedAt'] : gmdate('Y-m-d\TH:i:s\Z')),
    'order'    => isset($c['order']) && is_numeric($c['order']) ? 0 + $c['order'] : -round(microtime(true) * 1000),
  );
  if (!empty($c['no']) && is_numeric($c['no'])) $r['no'] = (int)$c['no'];   // 通し番号（0001〜9999・変えない・使い回さない）
  if (!empty($c['fav'])) $r['fav'] = true;   // 我的最愛
  if (!empty($c['url']) && preg_match('#^https?://#', (string)$c['url'])) $r['url'] = (string)$c['url'];   // 公式サイト
  if (!empty($c['shops']) && is_array($c['shops'])) {   // 推薦店家
    $sh = array();
    foreach ($c['shops'] as $x) {
      if (!is_array($x) || empty($x['name'])) continue;
      $o = array('name' => (string)$x['name']);
      foreach (array('ja', 'area', 'note') as $k) if (!empty($x[$k])) $o[$k] = (string)$x[$k];
      if (!empty($x['url']) && preg_match('#^https?://#', (string)$x['url'])) $o['url'] = (string)$x['url'];
      $sh[] = $o; if (count($sh) >= 5) break;
    }
    if ($sh) $r['shops'] = $sh;
  }
  if (!empty($c['tr']) && is_array($c['tr'])) {   // 日本語・英語の訳
    $tr = array();
    foreach (array('ja', 'en') as $lg) {
      if (empty($c['tr'][$lg]) || !is_array($c['tr'][$lg])) continue;
      $o = array();
      foreach (array('name', 'location', 'era', 'intro', 'origin') as $k) if (isset($c['tr'][$lg][$k])) $o[$k] = (string)$c['tr'][$lg][$k];
      if (!empty($c['tr'][$lg]['keywords']) && is_array($c['tr'][$lg]['keywords'])) $o['keywords'] = array_slice(array_map('strval', $c['tr'][$lg]['keywords']), 0, 10);
      if ($o) $tr[$lg] = $o;
    }
    if ($tr) $r['tr'] = $tr;
  }
  return $r;
}
function shops_md($c) {
  if (empty($c['shops'])) return '';
  $s = "## 推薦店家\n\n"; $i = 0;
  foreach ($c['shops'] as $x) { $i++; $s .= "{$i}. {$x['name']}" . (!empty($x['area']) ? "（{$x['area']}）" : '') . (!empty($x['note']) ? "：{$x['note']}" : '') . (!empty($x['url']) ? " {$x['url']}" : '') . "\n"; }
  return $s . "\n";
}
function card_md($c) {
  $label = $c['type'] === 'person' ? '人物' : ($c['type'] === 'hotel' ? '住宿' : '景點');
  $l2 = $c['type'] === 'person' ? '相關地' : '場所';
  $l3 = $c['type'] === 'person' ? '生卒年' : ($c['type'] === 'hotel' ? '開業' : '年代');
  $meta = str_replace('--', '--', json_encode($c, JSON_UNESCAPED_UNICODE));
  return "<!-- card: $meta -->\n# {$c['place']}\n\n"
    . "- 類型：{$label}\n" . ($c['ja'] !== '' ? "- 日文：{$c['ja']}" . ($c['yomi'] !== '' ? "（{$c['yomi']}）" : '') . "\n" : '') . "- {$l2}：{$c['location']}\n- {$l3}：{$c['era']}\n\n"
    . "## 介紹\n\n{$c['intro']}\n\n" . ($c['origin'] !== '' ? "## 由來\n\n{$c['origin']}\n\n" : '') . shops_md($c) . (!empty($c['url']) ? "## 連結\n\n官方網站：{$c['url']}\n\n" : '') . "## 關鍵字\n\n" . implode('、', $c['keywords']) . "\n";
}
function read_card($path) {
  $s = @file_get_contents($path);
  if ($s === false || !preg_match('/^<!-- card: (.*?) -->/s', $s, $m)) return null;
  $c = json_decode($m[1], true);
  return is_array($c) ? $c : null;
}
/* カードは地域フォルダの中も含めてすべて読む（.trash と . で始まるフォルダは除く） */
function card_files() {
  $files = array();
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CARDS_DIR, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen(CARDS_DIR) + 1);
    if (substr($rel, -3) !== '.md' || preg_match('#(^|/)\.#', $rel) || basename($rel) === 'README.md') continue;
    $files[] = $rel;
  }
  return $files;
}
function all_cards() {
  $cards = array();
  foreach (card_files() as $rel) {
    $c = read_card(CARDS_DIR . '/' . $rel);
    if ($c) { $c['id'] = substr($rel, 0, -3); $cards[] = $c; }
  }
  return $cards;
}
function write_card_file($path, $c) {
  if (!is_dir(dirname($path))) @mkdir(dirname($path), 0700, true);
  file_put_contents($path, card_md($c), LOCK_EX);
  @chmod($path, 0600);
}
function find_card($name) {
  foreach (all_cards() as $c) if (same_name($c['place'], $name)) return $c;
  return null;
}
/* 通し番号：いちばん大きい番号か控えの大きいほう＋1。削除しても戻らない */
function next_card_no() {
  $fp = @fopen(DATA_DIR . '/next_no', 'c+'); $n = 1;
  if ($fp) { flock($fp, LOCK_EX); $n = max(1, (int)trim(stream_get_contents($fp))); }
  foreach (all_cards() as $c) if (!empty($c['no'])) $n = max($n, (int)$c['no'] + 1);
  if ($fp) { ftruncate($fp, 0); rewind($fp); fwrite($fp, (string)($n + 1)); flock($fp, LOCK_UN); fclose($fp); }
  return $n;
}
function save_card($c) {
  $c = clean_card($c);
  if ($c['place'] === '' || $c['intro'] === '') return null;
  $old = find_card($c['place']);
  if ($old) return $old;                       // 同じ名前は重複させない
  if (empty($c['no'])) $c['no'] = next_card_no();
  $path = card_path($c);
  write_card_file($path, $c);
  $c['id'] = substr($path, strlen(CARDS_DIR) + 1, -3);
  return $c;
}

/* ---------- 作成待ちリスト（料金のかからない作り方：Mac の Claude Code がまとめて作る） ---------- */
function requests_path() { return DATA_DIR . '/requests.json'; }
function load_requests() {
  $r = is_readable(requests_path()) ? json_decode(file_get_contents(requests_path()), true) : array();
  return is_array($r) ? $r : array();
}
function save_requests($r) { file_put_contents(requests_path(), json_encode(array_values($r), JSON_UNESCAPED_UNICODE), LOCK_EX); }
/* すでにカードができた言葉はリストから外す */
function pending_requests() {
  $out = array();
  foreach (load_requests() as $q) if (!find_card($q['keyword'])) $out[] = $q;
  save_requests($out);
  return $out;
}
function add_request($kw) {
  $r = pending_requests();
  foreach ($r as $q) if (same_name($q['keyword'], $kw)) return $r;
  $r[] = array('keyword' => $kw, 'at' => gmdate('Y-m-d\TH:i:s\Z'));
  save_requests($r);
  return $r;
}
function done_request($kw) {
  $r = array();
  foreach (load_requests() as $q) if (!same_name($q['keyword'], $kw)) $r[] = $q;
  save_requests($r);
}
