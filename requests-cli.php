<?php
/**
 * 日本散步達人 — 作成待ちリストの操作（Mac の Claude Code が SSH で使う。ブラウザからは動かない）
 *   php requests-cli.php list          待ちの言葉を JSON で出す
 *   php requests-cli.php done <言葉>   リストから外す
 */
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/cards-lib.php';
$cmd = isset($argv[1]) ? $argv[1] : 'list';
if ($cmd === 'done' && isset($argv[2])) { done_request($argv[2]); echo "ok\n"; exit; }
echo json_encode(pending_requests(), JSON_UNESCAPED_UNICODE), "\n";
