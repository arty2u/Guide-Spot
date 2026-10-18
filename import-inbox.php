<?php
/**
 * 日本散步達人 — Dropbox の inbox からカードを取り込む（サーバーの cron で 5 分ごとに実行）
 * iPhone の Claude アプリが Dropbox の spot-guide-cards/inbox/ に置いたカードを、
 * 共有リンク（zip）で受け取り、地域フォルダに保存する。取り込んだファイルは記録して二度読まない。
 * 共有リンクは公開フォルダの外 /home/arty4u/.secrets/spot_guide_inbox_url に置く。
 */
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }   // ブラウザからは動かさない
require __DIR__ . '/cards-lib.php';

$urlFile = '/home/arty4u/.secrets/spot_guide_inbox_url';
$url = is_readable($urlFile) ? trim(file_get_contents($urlFile)) : '';
if ($url === '') exit(0);
$url = preg_replace('/([?&])dl=0/', '$1dl=1', $url);
if (strpos($url, 'dl=1') === false) $url .= (strpos($url, '?') === false ? '?' : '&') . 'dl=1';

$tmp = sys_get_temp_dir() . '/spot-inbox-' . getmypid();
@mkdir($tmp, 0700, true);
$zip = "$tmp/inbox.zip";
$ch = curl_init($url);
$fp = fopen($zip, 'w');
curl_setopt_array($ch, array(CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60));
curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fp);
if ($code !== 200 || filesize($zip) < 22) { exec('rm -rf ' . escapeshellarg($tmp)); exit(0); }   // 空のフォルダなど
exec('unzip -qq -o ' . escapeshellarg($zip) . ' -d ' . escapeshellarg("$tmp/x") . ' 2>/dev/null');

/* 取り込み済みの記録（ファイルの中身のハッシュ） */
$doneFile = DATA_DIR . '/inbox-imported.json';
$done = is_readable($doneFile) ? json_decode(file_get_contents($doneFile), true) : array();
if (!is_array($done)) $done = array();

function extract_card($text) {
  $cands = array();
  if (preg_match('/<!--\s*card:\s*(\{.*?\})\s*-->/su', $text, $m)) $cands[] = $m[1];
  if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/su', $text, $mm)) $cands = array_merge($cands, $mm[1]);
  $a = strpos($text, '{'); $z = strrpos($text, '}');
  if ($a !== false && $z > $a) $cands[] = substr($text, $a, $z - $a + 1);
  foreach ($cands as $c) {
    $d = json_decode($c, true);
    if (is_array($d) && !empty($d['place']) && !empty($d['intro'])) return $d;
  }
  return null;
}

$added = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$tmp/x", FilesystemIterator::SKIP_DOTS));
$i = 0;
foreach ($it as $f) {
  $rel = substr($f->getPathname(), strlen("$tmp/x") + 1);
  if (preg_match('#(^|/)\.#', $rel) || !preg_match('/\.(md|txt|json)$/i', $rel)) continue;
  $text = file_get_contents($f->getPathname());
  $h = sha1($text);
  if (isset($done[$h])) continue;
  $done[$h] = gmdate('c');
  $d = extract_card($text);
  if (!$d) continue;
  if (isset($d['intro'])) $d['intro'] = str_replace('\\n', "\n", $d['intro']);
  $d['savedAt'] = $d['lastUsed'] = gmdate('Y-m-d\TH:i:s\Z');
  $d['order'] = -round(microtime(true) * 1000) + ($i++);
  if (find_card($d['place'])) continue;          // 同じ名前は重複させない
  $c = save_card($d);
  if ($c) $added[] = $c['id'];
}
file_put_contents($doneFile, json_encode($done), LOCK_EX);
exec('rm -rf ' . escapeshellarg($tmp));
foreach ($added as $a) echo "added: $a\n";
