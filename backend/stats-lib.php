<?php
/**
 * 科學探索站 — 統計共用函式庫
 * log.php（記錄）和 stats-admin.php（後台）都會 include 這個檔案。
 * 一般不需要修改這裡的內容。
 */

function stats_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/stats-config.php';
        date_default_timezone_set($cfg['timezone'] ?? 'Asia/Taipei');
    }
    return $cfg;
}

/** 建立 / 取得 SQLite 連線，第一次執行會自動建表 */
function stats_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = stats_config();
    $dbDir = dirname($cfg['db_path']);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $cfg['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');

    $pdo->exec('CREATE TABLE IF NOT EXISTS visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts TEXT NOT NULL,
        ip TEXT NOT NULL,
        country TEXT,
        city TEXT,
        path TEXT,
        referrer TEXT,
        lang TEXT,
        ua TEXT,
        is_bot INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visits_ts ON visits (ts)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visits_ip ON visits (ip)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS blocked_ips (
        ip TEXT PRIMARY KEY,
        reason TEXT,
        created_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS ip_geo_cache (
        ip TEXT PRIMARY KEY,
        country TEXT,
        city TEXT,
        fetched_at TEXT NOT NULL
    )');

    return $pdo;
}

/** 取得訪客真實 IP（含常見反向代理 / CDN 標頭的處理） */
function stats_client_ip(): string {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            $val = $_SERVER[$h];
            // X-Forwarded-For 可能是「訪客IP, 代理1, 代理2」，取第一段
            $parts = explode(',', $val);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** 用 User-Agent 粗略判斷是否為已知爬蟲／機器人 */
function stats_is_known_bot(string $ua): bool {
    if ($ua === '') return true;
    $needles = ['bot', 'spider', 'crawl', 'slurp', 'curl', 'wget', 'python-requests',
                'facebookexternalhit', 'preview', 'monitor', 'headless'];
    $uaLower = strtolower($ua);
    foreach ($needles as $n) {
        if (strpos($uaLower, $n) !== false) return true;
    }
    return false;
}

/** IP 是否已被手動封鎖 */
function stats_is_blocked(PDO $pdo, string $ip): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM blocked_ips WHERE ip = ?');
    $stmt->execute([$ip]);
    return (bool) $stmt->fetchColumn();
}

/**
 * 查詢 IP 的國家/城市，有快取（同一支 IP 30 天內不重查）。
 * 使用免費、不需申請帳號的 ip-api.com；查不到或主機被限制對外連線時
 * 會靜默失敗，回傳 [null, null]，不影響記錄功能。
 */
function stats_geo_lookup(PDO $pdo, string $ip): array {
    $cfg = stats_config();
    if (empty($cfg['geoip_lookup'])) return [null, null];
    if (!filter_var($ip, FILTER_VALIDATE_IP) || in_array($ip, ['127.0.0.1', '::1'])) {
        return [null, null];
    }

    $stmt = $pdo->prepare('SELECT country, city, fetched_at FROM ip_geo_cache WHERE ip = ?');
    $stmt->execute([$ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && strtotime($row['fetched_at']) > time() - 30 * 86400) {
        return [$row['country'], $row['city']];
    }

    $country = null;
    $city = null;
    if (function_exists('curl_init')) {
        $ch = curl_init("http://ip-api.com/json/{$ip}?fields=status,country,city");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        if ($resp) {
            $data = json_decode($resp, true);
            if (($data['status'] ?? '') === 'success') {
                $country = $data['country'] ?? null;
                $city = $data['city'] ?? null;
            }
        }
    }

    $up = $pdo->prepare('INSERT INTO ip_geo_cache (ip, country, city, fetched_at) VALUES (?, ?, ?, ?)
                          ON CONFLICT(ip) DO UPDATE SET country = excluded.country, city = excluded.city, fetched_at = excluded.fetched_at');
    $up->execute([$ip, $country, $city, date('c')]);

    return [$country, $city];
}

/** 同一支 IP 在過去 10 分鐘的造訪次數是否超過門檻（可疑流量參考指標） */
function stats_is_suspicious(PDO $pdo, string $ip): bool {
    $cfg = stats_config();
    $threshold = $cfg['bot_flag_threshold'] ?? 30;
    $since = date('c', time() - 600);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM visits WHERE ip = ? AND ts > ?');
    $stmt->execute([$ip, $since]);
    return ((int) $stmt->fetchColumn()) >= $threshold;
}
