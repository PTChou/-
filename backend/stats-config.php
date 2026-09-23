<?php
/**
 * 科學探索站 — 統計後台設定檔
 * ------------------------------------------------------------
 * 這個檔案集中放「密碼」和幾個開關，log.php 和 stats-admin.php
 * 都會讀取這裡的設定。搬家到別的主機時，通常只需要把整個
 * 資料夾複製過去，不用改程式碼。
 */

return [

    // 後台登入密碼（雜湊過，不是明文）。
    // 預設密碼是 science2026 — 第一次上線後請務必更換！
    // 換密碼的方法：在終端機或本機 PHP 執行
    //   php -r "echo password_hash('你的新密碼', PASSWORD_DEFAULT), PHP_EOL;"
    // 把印出來的一長串文字整個貼到下面單引號中間即可。
    'admin_password_hash' => '$2y$12$t1N2sqEhtta9jMTkoC1E8.yv.iybnxuhqyBm9G6hK8Xzgc29mvOu.',

    // SQLite 資料庫檔案位置（會自動建立，不用手動新增）
    'db_path' => __DIR__ . '/stats-data/stats.sqlite',

    // 網站時區，統計報表的「今天／本週」都以此為準
    'timezone' => 'Asia/Taipei',

    // 是否呼叫免費的 IP 對照國家/城市服務（ip-api.com，無需申請帳號）
    // 如果主機的對外連線被封鎖（部分免費空間會限制），可以關閉，
    // 記錄還是會照常運作，只是不會顯示國家/城市。
    'geoip_lookup' => true,

    // 同一支 IP 在 10 分鐘內的瀏覽次數超過這個數字，
    // 後台會把它標記為「可疑（疑似機器人）」，僅供參考、不會自動封鎖。
    'bot_flag_threshold' => 30,

    // 每頁在後台「最近造訪紀錄」要顯示幾筆
    'recent_rows' => 50,
];
