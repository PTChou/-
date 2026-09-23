<?php
/**
 * 科學探索站 — 造訪記錄端點
 * ------------------------------------------------------------
 * 由 science-station.html 的頁尾小段 JavaScript 呼叫（sendBeacon /
 * fetch），每次有人開啟頁面就會送一筆記錄過來。不需要手動呼叫。
 *
 * 設計原則：任何錯誤都「靜默失敗」——就算資料庫壞掉、對外連線被擋，
 * 也絕對不能讓訪客的網頁跳出錯誤或變慢，所以這裡全部包在 try/catch。
 */

require __DIR__ . '/stats-lib.php';

header('Content-Type: application/json; charset=utf-8');
// 這支端點只接受同站呼叫，不開放給其他網站的 JS 直接打
header('X-Content-Type-Options: nosniff');

try {
    $cfg = stats_config();
    $pdo = stats_db();

    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '{}', true) ?: [];

    $path = isset($body['path']) ? substr((string) $body['path'], 0, 300) : '/';
    $lang = isset($body['lang']) && in_array($body['lang'], ['zh', 'en'], true) ? $body['lang'] : null;
    $referrer = isset($body['referrer']) ? substr((string) $body['referrer'], 0, 500) : ($_SERVER['HTTP_REFERER'] ?? '');

    $ip = stats_client_ip();
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    $isBot = stats_is_known_bot($ua) ? 1 : 0;

    $country = null;
    $city = null;
    if (!$isBot) {
        [$country, $city] = stats_geo_lookup($pdo, $ip);
    }

    $stmt = $pdo->prepare('INSERT INTO visits (ts, ip, country, city, path, referrer, lang, ua, is_bot)
                            VALUES (:ts, :ip, :country, :city, :path, :referrer, :lang, :ua, :is_bot)');
    $stmt->execute([
        ':ts' => date('c'),
        ':ip' => $ip,
        ':country' => $country,
        ':city' => $city,
        ':path' => $path,
        ':referrer' => $referrer,
        ':lang' => $lang,
        ':ua' => $ua,
        ':is_bot' => $isBot,
    ]);

    http_response_code(204);
} catch (\Throwable $e) {
    // 記錄失敗不影響訪客瀏覽網站，直接回 204，不把錯誤細節丟給前端。
    http_response_code(204);
}
