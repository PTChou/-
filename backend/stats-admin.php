<?php
/**
 * 科學探索站 — 統計後台
 * ------------------------------------------------------------
 * 用瀏覽器打開 你的網址/stats-admin.php，輸入密碼即可查看。
 * 預設密碼：science2026（請在 stats-config.php 裡更換）
 */

require __DIR__ . '/stats-lib.php';
session_start();

$cfg = stats_config();
$pdo = stats_db();
$error = '';

// ---------- 登入 / 登出 ----------
if (isset($_GET['logout'])) {
    unset($_SESSION['stats_admin']);
    session_destroy();
    header('Location: stats-admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && empty($_SESSION['stats_admin'])) {
    if (password_verify($_POST['password'], $cfg['admin_password_hash'])) {
        $_SESSION['stats_admin'] = true;
        header('Location: stats-admin.php');
        exit;
    }
    $error = '密碼錯誤，請再試一次。';
}

$loggedIn = !empty($_SESSION['stats_admin']);

// ---------- IP 封鎖名單管理（需登入） ----------
if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'block' && !empty($_POST['ip']) && filter_var($_POST['ip'], FILTER_VALIDATE_IP)) {
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO blocked_ips (ip, reason, created_at) VALUES (?, ?, ?)');
        $stmt->execute([$_POST['ip'], substr((string) ($_POST['reason'] ?? ''), 0, 200), date('c')]);
    } elseif ($_POST['action'] === 'unblock' && !empty($_POST['ip'])) {
        $stmt = $pdo->prepare('DELETE FROM blocked_ips WHERE ip = ?');
        $stmt->execute([$_POST['ip']]);
    }
    header('Location: stats-admin.php#ip-management');
    exit;
}

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

if (!$loggedIn) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-Hant">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>後台登入 — 科學探索站</title>
    <style>
      body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#FAF6EC;font-family:"Noto Sans TC","PingFang TC",-apple-system,sans-serif;}
      form{background:#F1EAD8;border:1px solid #DED1AE;border-radius:14px;padding:36px 32px;width:min(320px,90vw);box-shadow:0 14px 30px rgba(45,35,15,.10);}
      h1{font-size:18px;margin:0 0 18px;color:#1B2A2F;}
      input[type=password]{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #DED1AE;border-radius:8px;font-size:15px;margin-bottom:14px;}
      button{width:100%;padding:11px;border:none;border-radius:8px;background:#E85B2C;color:#fff9f4;font-weight:600;font-size:14.5px;cursor:pointer;}
      button:hover{background:#c94e24;}
      .err{color:#b4432b;font-size:13px;margin:-6px 0 14px;}
    </style>
    </head>
    <body>
      <form method="post">
        <h1>科學探索站 · 統計後台</h1>
        <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
        <input type="password" name="password" placeholder="請輸入密碼" autofocus required>
        <button type="submit">登入</button>
      </form>
    </body>
    </html>
    <?php
    exit;
}

// ---------- 統計查詢 ----------
$todayStart = date('c', strtotime('today'));
$d7Start = date('c', strtotime('-6 days midnight'));
$d30Start = date('c', strtotime('-29 days midnight'));

function countSince(PDO $pdo, string $since, bool $humanOnly = true): int {
    $sql = 'SELECT COUNT(*) FROM visits WHERE ts >= ?' . ($humanOnly ? ' AND is_bot = 0' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$since]);
    return (int) $stmt->fetchColumn();
}
function uniqueIpsSince(PDO $pdo, string $since, bool $humanOnly = true): int {
    $sql = 'SELECT COUNT(DISTINCT ip) FROM visits WHERE ts >= ?' . ($humanOnly ? ' AND is_bot = 0' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$since]);
    return (int) $stmt->fetchColumn();
}

$todayVisits = countSince($pdo, $todayStart);
$todayUnique = uniqueIpsSince($pdo, $todayStart);
$d7Visits = countSince($pdo, $d7Start);
$d7Unique = uniqueIpsSince($pdo, $d7Start);
$d30Visits = countSince($pdo, $d30Start);
$d30Unique = uniqueIpsSince($pdo, $d30Start);
$totalVisits = countSince($pdo, '0000-01-01', false);
$totalBots = (int) $pdo->query('SELECT COUNT(*) FROM visits WHERE is_bot = 1')->fetchColumn();

// 近 30 天每日造訪數（長條圖用）
$daily = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $daily[$day] = 0;
}
$stmt = $pdo->prepare("SELECT substr(ts,1,10) AS d, COUNT(*) AS c FROM visits WHERE ts >= ? AND is_bot = 0 GROUP BY d");
$stmt->execute([$d30Start]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($daily[$row['d']])) $daily[$row['d']] = (int) $row['c'];
}
$maxDaily = max(1, max($daily));

// 熱門頁面
$topPages = $pdo->query("SELECT path, COUNT(*) c FROM visits WHERE is_bot = 0 GROUP BY path ORDER BY c DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
// 熱門國家
$topCountries = $pdo->query("SELECT COALESCE(country,'（未知）') country, COUNT(*) c FROM visits WHERE is_bot = 0 GROUP BY country ORDER BY c DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// 最近造訪紀錄
$recent = $pdo->query("SELECT * FROM visits ORDER BY ts DESC LIMIT " . (int) $cfg['recent_rows'])->fetchAll(PDO::FETCH_ASSOC);

// IP 清單（依造訪次數排序）
$ipRows = $pdo->query("SELECT ip, COUNT(*) visits, MIN(ts) first_seen, MAX(ts) last_seen,
                        MAX(country) country, MAX(city) city, MAX(is_bot) any_bot
                        FROM visits GROUP BY ip ORDER BY visits DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$blockedIps = $pdo->query("SELECT * FROM blocked_ips ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$blockedSet = array_column($blockedIps, 'reason', 'ip');
$blockedIpList = array_column($blockedIps, 'ip');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>統計後台 — 科學探索站</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+TC:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --paper:#FAF6EC;--paper-2:#F1EAD8;--paper-3:#EDE4CC;--ink:#1B2A2F;--ink-soft:#52666B;
    --accent:#E85B2C;--accent-soft:#FCE3D4;--accent-ink:#7A2E0E;
    --moss:#4E7C59;--moss-soft:#E1EDE2;--sky:#2C7A94;--sky-soft:#DDEEF1;
    --line:#DED1AE;--danger:#B4432B;--danger-soft:#F7DCD3;
    --font-body:"Noto Sans TC","PingFang TC",-apple-system,sans-serif;
    --font-mono:"JetBrains Mono","SF Mono",Menlo,monospace;
  }
  *{box-sizing:border-box;}
  body{margin:0;background:var(--paper);color:var(--ink);font-family:var(--font-body);line-height:1.55;}
  a{color:var(--sky);}
  .wrap{max-width:1180px;margin:0 auto;padding:0 20px 60px;}
  header{display:flex;align-items:center;justify-content:space-between;padding:18px 0;border-bottom:1px solid var(--line);margin-bottom:28px;flex-wrap:wrap;gap:10px;}
  header h1{font-size:19px;margin:0;}
  header .sub{font-family:var(--font-mono);font-size:12px;color:var(--ink-soft);}
  header a.logout{font-size:13px;color:var(--ink-soft);text-decoration:none;border:1px solid var(--line);padding:6px 12px;border-radius:8px;}
  header a.logout:hover{border-color:var(--ink-soft);}

  .kpi-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:28px;}
  .kpi{background:var(--paper-2);border:1px solid var(--line);border-radius:12px;padding:16px 18px;}
  .kpi .label{font-family:var(--font-mono);font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--moss);font-weight:600;}
  .kpi .num{font-size:26px;font-weight:700;margin-top:6px;font-variant-numeric:tabular-nums;}
  .kpi .sub{font-size:12px;color:var(--ink-soft);margin-top:2px;}

  section{margin-bottom:36px;}
  h2{font-size:15px;margin:0 0 14px;display:flex;align-items:center;gap:8px;}
  h2 .count{font-family:var(--font-mono);font-size:11px;color:var(--ink-soft);font-weight:500;background:var(--paper-3);border-radius:999px;padding:2px 9px;}

  .chart{display:flex;align-items:flex-end;gap:3px;height:120px;border-bottom:1px solid var(--line);padding-bottom:2px;overflow-x:auto;}
  .chart .bar-wrap{flex:1 0 10px;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;min-width:10px;}
  .chart .bar{width:100%;background:var(--sky);border-radius:2px 2px 0 0;min-height:1px;}
  .chart .bar-wrap:hover .bar{background:var(--accent);}
  .chart-labels{display:flex;justify-content:space-between;font-family:var(--font-mono);font-size:10.5px;color:var(--ink-soft);margin-top:6px;}

  table{width:100%;border-collapse:collapse;font-size:13px;background:var(--paper-2);border:1px solid var(--line);border-radius:10px;overflow:hidden;}
  th,td{text-align:left;padding:9px 12px;border-bottom:1px solid var(--line);}
  th{font-family:var(--font-mono);font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-soft);font-weight:600;background:var(--paper-3);}
  tr:last-child td{border-bottom:none;}
  td.num, th.num{font-family:var(--font-mono);text-align:right;font-variant-numeric:tabular-nums;}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
  @media (max-width:760px){.grid-2{grid-template-columns:1fr;}}

  .table-scroll{overflow-x:auto;}
  .mono{font-family:var(--font-mono);font-size:12px;}
  .muted{color:var(--ink-soft);}
  .chip{display:inline-block;font-family:var(--font-mono);font-size:10.5px;font-weight:600;padding:2px 8px;border-radius:999px;}
  .chip-bot{background:var(--paper-3);color:var(--ink-soft);}
  .chip-suspicious{background:var(--danger-soft);color:var(--danger);}
  .chip-blocked{background:var(--danger);color:#fff;}

  form.inline{display:inline-flex;gap:6px;align-items:center;margin:0;}
  form.inline input[type=text]{font-family:var(--font-mono);font-size:12px;padding:5px 8px;border:1px solid var(--line);border-radius:6px;width:120px;}
  form.inline button{font-size:12px;padding:5px 11px;border:none;border-radius:6px;cursor:pointer;font-weight:600;}
  .btn-block{background:var(--danger);color:#fff;}
  .btn-unblock{background:var(--moss);color:#fff;}

  .htaccess-box{background:var(--ink);color:var(--paper);font-family:var(--font-mono);font-size:12.5px;padding:16px;border-radius:10px;white-space:pre-wrap;word-break:break-all;}
  .note{font-size:12.5px;color:var(--ink-soft);margin-top:8px;}
</style>
</head>
<body>
<div class="wrap">
  <header>
    <div>
      <h1>科學探索站 · 統計後台</h1>
      <div class="sub">總計 <?= number_format($totalVisits) ?> 筆造訪（含機器人 <?= number_format($totalBots) ?> 筆）</div>
    </div>
    <a class="logout" href="?logout=1">登出</a>
  </header>

  <div class="kpi-row">
    <div class="kpi"><div class="label">今天</div><div class="num"><?= number_format($todayVisits) ?></div><div class="sub"><?= number_format($todayUnique) ?> 個不重複 IP</div></div>
    <div class="kpi"><div class="label">近 7 天</div><div class="num"><?= number_format($d7Visits) ?></div><div class="sub"><?= number_format($d7Unique) ?> 個不重複 IP</div></div>
    <div class="kpi"><div class="label">近 30 天</div><div class="num"><?= number_format($d30Visits) ?></div><div class="sub"><?= number_format($d30Unique) ?> 個不重複 IP</div></div>
  </div>

  <section>
    <h2>近 30 天造訪趨勢</h2>
    <div class="chart">
      <?php foreach ($daily as $day => $c): $pct = max(2, round($c / $maxDaily * 100)); ?>
        <div class="bar-wrap" title="<?= h($day) ?>：<?= $c ?> 次">
          <div class="bar" style="height:<?= $pct ?>%"></div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="chart-labels"><span><?= h(array_key_first($daily)) ?></span><span><?= h(array_key_last($daily)) ?></span></div>
  </section>

  <section class="grid-2">
    <div>
      <h2>熱門頁面 <span class="count">TOP <?= count($topPages) ?></span></h2>
      <div class="table-scroll"><table>
        <thead><tr><th>路徑</th><th class="num">造訪數</th></tr></thead>
        <tbody>
        <?php foreach ($topPages as $r): ?>
          <tr><td class="mono"><?= h($r['path'] ?: '/') ?></td><td class="num"><?= number_format($r['c']) ?></td></tr>
        <?php endforeach; if (!$topPages): ?><tr><td colspan="2" class="muted">尚無資料</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
    <div>
      <h2>訪客國家／地區 <span class="count">TOP <?= count($topCountries) ?></span></h2>
      <div class="table-scroll"><table>
        <thead><tr><th>國家</th><th class="num">造訪數</th></tr></thead>
        <tbody>
        <?php foreach ($topCountries as $r): ?>
          <tr><td><?= h($r['country']) ?></td><td class="num"><?= number_format($r['c']) ?></td></tr>
        <?php endforeach; if (!$topCountries): ?><tr><td colspan="2" class="muted">尚無資料</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </section>

  <section id="ip-management">
    <h2>IP 清單與封鎖管理 <span class="count"><?= count($ipRows) ?> 支 IP</span></h2>
    <p class="note">「可疑」是指同一支 IP 在 10 分鐘內造訪超過 <?= (int) $cfg['bot_flag_threshold'] ?> 次，僅供參考判斷，不會自動封鎖。點「封鎖」只會把 IP 加進下方名單，實際要擋下該 IP，需要把名單貼到 .htaccess（下方已幫你組好文字，複製貼上即可）。</p>
    <div class="table-scroll"><table>
      <thead><tr><th>IP</th><th>地區</th><th class="num">造訪數</th><th>首次／最近</th><th>狀態</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($ipRows as $r):
        $ip = $r['ip'];
        $isBlocked = isset($blockedSet[$ip]);
        $isSuspicious = stats_is_suspicious($pdo, $ip);
      ?>
        <tr>
          <td class="mono"><?= h($ip) ?></td>
          <td><?= h(trim(($r['city'] ?: '') . ' ' . ($r['country'] ?: '')) ?: '—') ?></td>
          <td class="num"><?= number_format($r['visits']) ?></td>
          <td class="mono muted" style="font-size:11px;"><?= h(substr($r['first_seen'],0,16)) ?><br><?= h(substr($r['last_seen'],0,16)) ?></td>
          <td>
            <?php if ($isBlocked): ?><span class="chip chip-blocked">已封鎖</span><?php endif; ?>
            <?php if ($r['any_bot']): ?><span class="chip chip-bot">機器人特徵</span><?php endif; ?>
            <?php if ($isSuspicious): ?><span class="chip chip-suspicious">可疑</span><?php endif; ?>
          </td>
          <td>
            <?php if ($isBlocked): ?>
              <form class="inline" method="post">
                <input type="hidden" name="action" value="unblock">
                <input type="hidden" name="ip" value="<?= h($ip) ?>">
                <button type="submit" class="btn-unblock">解除封鎖</button>
              </form>
            <?php else: ?>
              <form class="inline" method="post">
                <input type="hidden" name="action" value="block">
                <input type="hidden" name="ip" value="<?= h($ip) ?>">
                <input type="text" name="reason" placeholder="封鎖原因（選填）">
                <button type="submit" class="btn-block">封鎖</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; if (!$ipRows): ?><tr><td colspan="6" class="muted">尚無資料</td></tr><?php endif; ?>
      </tbody>
    </table></div>

    <?php if ($blockedIpList): ?>
    <h2 style="margin-top:22px;">.htaccess 封鎖名單（複製貼到網站根目錄的 .htaccess）</h2>
    <div class="htaccess-box"><?php foreach ($blockedIpList as $ip): ?>Deny from <?= h($ip) ?>
<?php endforeach; ?></div>
    <p class="note">如果 .htaccess 已經有 "Order allow,deny" 之類的規則，把上面幾行加進去就好，不用整份覆蓋；不確定的話，先備份原本的 .htaccess 再修改。</p>
    <?php endif; ?>
  </section>

  <section>
    <h2>最近造訪紀錄 <span class="count">最新 <?= count($recent) ?> 筆</span></h2>
    <div class="table-scroll"><table>
      <thead><tr><th>時間</th><th>IP</th><th>地區</th><th>頁面</th><th>語言</th><th>來源</th><th>狀態</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $r): ?>
        <tr>
          <td class="mono" style="font-size:11.5px;"><?= h(substr($r['ts'],0,19)) ?></td>
          <td class="mono"><?= h($r['ip']) ?></td>
          <td><?= h(trim(($r['city'] ?: '') . ' ' . ($r['country'] ?: '')) ?: '—') ?></td>
          <td class="mono" style="font-size:11.5px;"><?= h($r['path'] ?: '/') ?></td>
          <td><?= h($r['lang'] ?? '—') ?></td>
          <td class="mono" style="font-size:11px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($r['referrer'] ?: '直接造訪') ?></td>
          <td><?php if ($r['is_bot']): ?><span class="chip chip-bot">機器人</span><?php endif; ?></td>
        </tr>
      <?php endforeach; if (!$recent): ?><tr><td colspan="7" class="muted">尚無資料</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
</div>
</body>
</html>
