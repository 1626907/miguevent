<?php
date_default_timezone_set('Asia/Shanghai');

// 用户信息
$userId    = $_GET['userId'] ?? '';
$userToken = $_GET['userToken'] ?? '';
$id        = $_GET['id'] ?? null;

// 缓存文件
$cacheFile = __DIR__ . "/miguevent_id.txt";
$cacheExpire = 3600; // 1小时

function isCacheValid($file, $expire) {
    return file_exists($file) && (time() - filemtime($file) < $expire);
}

// ================= 频道列表模式 =================
if ($id === null) {
    header('Content-Type: text/plain; charset=utf-8');

    if (!isCacheValid($cacheFile, $cacheExpire)) {
        // 目标 URL
        $url = "https://vms-sc.miguvideo.com/vms-match/v6/staticcache/basic/match-list/normal-match-list/0/all/default/1/miguvideo";
        
        // 获取数据
        $json = file_get_contents($url);
        if ($json === false) {
            die("无法获取数据\n");
        }
        
        // 解析 JSON
        $data = json_decode($json, true);
        if ($data === null) {
            die("JSON解析失败\n");
        }
        
        // 获取今天和明天日期
        $dates = [
            date("Ymd"),                      // 今天
            date("Ymd", strtotime("+1 day"))  // 明天
        ];
        
        $output = ""; // 保存最终结果
        $output .= "咪咕体育,#genre#\n";
        foreach ($dates as $i => $today) {
            //$output .= "===== 日期: {$today} =====\n";
        
            if (isset($data['body']['matchList'][$today])) {
                $matches = $data['body']['matchList'][$today];
                foreach ($matches as $match) {
                    $competitionName = $match['competitionName'] ?? '无标题';
                    $keyword        = $match['keyword'] ?? '无标题';
                    $pkInfoTitle    = $match['pkInfoTitle'] ?? '无标题';
                    $mgdbId         = $match['mgdbId'] ?? '无ID';
                    $pID_in_list    = $match['pID'] ?? '';
        
                    // 第一天（今天）：抓取 mgdbId 页面，解析 liveList
                    if ($i === 0) {
                        $html = file_get_contents("https://m.miguvideo.com/m/live/home/$mgdbId/matchDetail");
                        if ($html === false) {
                            $output .= "无法获取 mgdbId={$mgdbId} 的页面\n";
                            continue;
                        }
        
                        if (preg_match('/window\.__INITIAL_BASIC_DATA__\s*=\s*(\{.*?\});/s', $html, $matches_forID)) {
                            $json_forID = $matches_forID[1];
                            $data_forID = json_decode($json_forID, true);
        
                            if ($data_forID === null) {
                                $output .= "mgdbId={$mgdbId} 的 JSON解析失败\n";
                                continue;
                            }
        
                            if (isset($data_forID[$mgdbId]['body']['multiPlayList']['liveList'])) {
                                $matches_info = $data_forID[$mgdbId]['body']['multiPlayList']['liveList'];
                                foreach ($matches_info as $match_info) {
                                    $name = $match_info['name'] ?? '';
                                    $pID  = $match_info['pID'] ?? '';
                                    $output .= "[$competitionName] $keyword $pkInfoTitle $name,{$pID}\n";
                                }
                            } else {
                                continue;
                            }
                        } else {
                            continue;
                        }
                    } 
                    // 第二天（明天）：只读取 matchList 里的 pID
                    else {
                        if ($pID_in_list !== '') {
                            $output .= "[$competitionName] $keyword $pkInfoTitle,{$pID_in_list}\n";
                        } else {
                            continue;
                        }
                    }
                }
            } else {
                continue;
            }
        
            $output .= "\n";
        }
        
        // 写入缓存文件
        file_put_contents($cacheFile, $output);
    }



    // 获取当前脚本 URL
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host   = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $baseUrl = $scheme . "://" . $host . $script;

    // 读取缓存并输出
    $cachedLines = file($cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($cachedLines as $line) {
        if (preg_match('/^(.+),#genre#$/u', $line, $m)) {
            echo "{$m[1]},#genre#\n";
            continue;
        }
        if (preg_match('/^\[(.+?)\]\s*(.+),\s*\s*(.+)$/u', $line, $m)) {
            $title = "[" . $m[1] . "] " . $m[2];
            $fileName = trim($m[3]);
            echo "{$title},{$baseUrl}?id={$fileName}&userId=$userId&userToken=$userToken\n";
        }
    }
    exit;

}





/**
 * 调用 API 并返回 JSON
 */
function fetchPlayUrl($id, $userId, $userToken) {
    $apiUrl = "https://webapi.miguvideo.com/gateway/playurl/v3/play/playurl?contId={$id}&rateType=4&channelId=0132_10010001005";

    $headers = [
        "terminalId: www",
        "userId: $userId",
        "userToken: $userToken"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ["error" => $error];
    }
    curl_close($ch);

    return json_decode($response, true);
}

function generateDdCalcu_www($userid, $programId, $puData, $channelId, $timestamp) {
    $len = strlen($puData);
    $result = "";

    // 1. 拼接最后字符+第一字符，倒数第二+第二
    $result .= $puData[$len - 1] . $puData[0];
    $result .= $puData[$len - 2] . $puData[1];

    // 2. 插入 基于userid第2位判断
    switch ($userid[1]) {
        case '0': case '1': case '8': case '9':
            $result .= "a"; break;
        case '2': case '3':
            $result .= "b"; break;
        case '4': case '5':
            $result .= "c"; break;
        case '6': case '7':
            $result .= "d"; break;
    }

    // 3. 拼接倒数第三+第三
    $result .= $puData[$len - 3] . $puData[2];

    // 4. 基于time首字符（2025的2 -> b）
    $yearFirst = substr($timestamp, 0, 1);
    if ($yearFirst == '2') {
        $result .= "b";
    } else {
        $result .= "a"; // 其它年份默认a
    }

    // 5. 拼接倒数第四+第四
    $result .= $puData[$len - 4] . $puData[3];

    // 6. 插入 基于ProgramID第6位判断
    switch ($programId[5]) {
        case '0': case '1': case '8': case '9':
            $result .= "a"; break;
        case '2': case '3':
            $result .= "b"; break;
        case '4': case '5':
            $result .= "c"; break;
        case '6': case '7':
            $result .= "d"; break;
    }

    // 7. 拼接倒数第五+第五
    $result .= $puData[$len - 5] . $puData[4];

    // 8. 基于Channel_ID，下划线后第三位（没有明确规则，默认a）
    $result .= "a";

    // 9. 继续拼接 n=6 到 n=16
    for ($n = 6; $n <= 16; $n++) {
        $result .= $puData[$len - $n] . $puData[$n - 1];
    }

    return $result;
}

// 1. 取原始地址
$result = fetchPlayUrl($id, $userId, $userToken);
$rawUrl = $result['body']['urlInfo']['url'] ?? '';

function getQueryParams($url) {
    $query = parse_url($url, PHP_URL_QUERY);
    $result = [];
    foreach (explode('&', $query) as $pair) {
        $parts = explode('=', $pair, 2);
        $key = urldecode($parts[0]);
        $value = isset($parts[1]) ? urldecode($parts[1]) : '';
        $result[$key] = $value;
    }
    return $result;
}

$params = getQueryParams($rawUrl);

$userid    = $params['userid'] ?? '';
$programId = $params['ProgramID'] ?? '';
$puData    = $params['puData'] ?? '';
$channelId = $params['Channel_ID'] ?? '';
$timestamp = $params['timestamp'] ?? '';

// 生成ddCalcu
$ddCalcu = generateDdCalcu_www($userid, $programId, $puData, $channelId, $timestamp);

// 拼接最终URL
$finalUrl = $rawUrl . "&ddCalcu=" . $ddCalcu . "_s002&sv=10010&crossdomain=www";

// 输出
//echo $finalUrl;

$finalUrl = $rawUrl . "&ddCalcu=" . urlencode($ddCalcu) . "_s002&sv=10010&crossdomain=www";


// 获取当前时间
$time = date('Y-m-d H:i:s');

// 获取客户端IP
function getClientIp() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        // Cloudflare 客户端 IP
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // 可能有多级代理，取第一个
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? 'CLI';
    }
}

$clientIp = getClientIp();

// 日志内容
$logLine = "[$time] IP: $clientIp URL: $finalUrl" . PHP_EOL;

// 写入日志文件（追加模式）
$logFile = __DIR__ . '/url_log.txt';
file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);


// 初始化 cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $finalUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // 返回内容
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // 不自动跟随重定向
curl_setopt($ch, CURLOPT_HEADER, true); // 获取头信息
curl_setopt($ch, CURLOPT_NOBODY, true); // 只获取头，不获取主体
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');

// 执行请求
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch);
} else {
    // 检查是否302跳转
    if ($httpCode == 302 || $httpCode == 301) {
        // 从Header中提取Location
        if (preg_match('/Location:\s*(\S+)/i', $response, $matches)) {
            $redirectUrl = trim($matches[1]);
            //echo "302 Redirect URL: " . $redirectUrl;
            header("Location: $redirectUrl");
        } else {
            //echo "302 Redirect, but no Location found.";
            header("Location: https://cdn.jsdelivr.net/gh/feiyang666999/testvideo/sdr1080pvideo/playlist.m3u8");
        }
    } else {
        // 没有跳转，获取内容
        curl_setopt($ch, CURLOPT_NOBODY, false); // 获取主体
        curl_setopt($ch, CURLOPT_HEADER, false); // 不获取头
        $content = curl_exec($ch);
        //echo $content;
        header("Location: $content");
    }
}

curl_close($ch);

?>


