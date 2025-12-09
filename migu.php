<?php
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '0'); // 如果不想记录到日志也关闭

date_default_timezone_set('Asia/Shanghai');
header('Content-Type: text/plain; charset=utf-8');

// ========== 用户信息（固定写死防泄露） ==========
// 默认写死的会员信息
$defaultUserId    = "你的userId";
$defaultUserToken = "你的userToken";

// 优先使用 URL 传参，如果没有则使用默认值
$userId    = $_GET['userId'] ?? $defaultUserId;
$userToken = $_GET['userToken'] ?? $defaultUserToken;


$id        = $_GET['id'] ?? null;

// ========== 缓存配置 ==========
$cacheFile = __DIR__ . "/miguevent_id.txt";
$cacheExpire = 3600; // 1小时

function isCacheValid($file, $expire) {
    return file_exists($file) && (time() - filemtime($file) < $expire);
}

// ========== 频道列表模式 ==========
if ($id === null) {
    header('Content-Type: text/plain; charset=utf-8');

    if (!isCacheValid($cacheFile, $cacheExpire)) {
        $url = "https://vms-sc.miguvideo.com/vms-match/v6/staticcache/basic/match-list/normal-match-list/0/all/default/1/miguvideo";
        $json = @file_get_contents($url);
        if ($json === false) die("无法获取数据\n");

        $data = json_decode($json, true);
        if ($data === null) die("JSON解析失败\n");

        $dates = $data['body']['days'] ?? [];
        $todayDate = date('Ymd');

        $outputAll = '';

        foreach ($dates as $today) {
            $output = "咪咕体育-$today,#genre#\n";
            if (empty($data['body']['matchList'][$today])) continue;

            foreach ($data['body']['matchList'][$today] as $match) {
                $competition = $match['competitionName'] ?? '无标题';
                $keyword     = $match['keyword'] ?? '无标题';
                $pkTitle     = $match['pkInfoTitle'] ?? '无标题';
                $mgdbId      = $match['mgdbId'] ?? '';
                $pID         = $match['pID'] ?? '';

                if ($mgdbId && $today <= $todayDate) {
                    $html = @file_get_contents("https://m.miguvideo.com/m/live/home/$mgdbId/matchDetail");
                    if ($html && preg_match('/window\.__INITIAL_BASIC_DATA__\s*=\s*(\{.*?\});/s', $html, $m)) {
                        $liveData = json_decode($m[1], true);
                        if (isset($liveData[$mgdbId]['body']['multiPlayList']['liveList'])) {
                            foreach ($liveData[$mgdbId]['body']['multiPlayList']['liveList'] as $li) {
                                $name    = $li['name'] ?? '';
                                $pidLive = $li['pID'] ?? '';
                                $output .= "[$competition] $keyword $pkTitle $name,$pidLive\n";
                            }
                        }
                    }
                } elseif ($pID !== '') {
                    $output .= "[$competition] $keyword $pkTitle,$pID\n";
                }
            }

            $output .= "\n";
            $outputAll .= $output;
        }

        file_put_contents($cacheFile, $outputAll, LOCK_EX);
    }

    // ========== 输出缓存 ==========
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $baseUrl = "$scheme://$host$script";

    $cachedLines = file($cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($cachedLines as $line) {
        if (preg_match('/^(.+),#genre#$/u', $line, $m)) {
            echo "{$m[1]},#genre#\n";
            continue;
        }
        if (preg_match('/^\[(.+?)\]\s*(.+),\s*(.+)$/u', $line, $m)) {
            $title    = "[" . $m[1] . "] " . $m[2];
            $fileName = trim($m[3]);
			if (isset($_GET['userId'], $_GET['userToken']) && $_GET['userId'] && $_GET['userToken']) {
				echo "{$title},{$baseUrl}?id={$fileName}&userId=" . urlencode($_GET['userId']) . "&userToken=" . urlencode($_GET['userToken']) . "\n";
			} else {
				echo "{$title},{$baseUrl}?id={$fileName}\n";
			}
        }
    }
    exit;
}

// ========== 单节目模式 ==========
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
    curl_close($ch);

    return json_decode($response, true);
}

function generateDdCalcu_www($userid, $programId, $puData, $channelId, $timestamp) {
    $len = strlen($puData);
    $result = "";

    if ($len < 16) return '';

    $result .= $puData[$len-1].$puData[0];
    $result .= $puData[$len-2].$puData[1];

    switch ($userid[4] ?? '0') {
        case '0': case '1': case '8': case '9': $result .= "a"; break;
        case '2': case '3': $result .= "b"; break;
        case '4': case '5': $result .= "a"; break;
        case '6': case '7': $result .= "b"; break;
    }

    $result .= $puData[$len-3].($puData[2] ?? '0');
    $result .= (substr($timestamp,0,1) == '2') ? 'a' : 'b';
    $result .= $puData[$len-4].($puData[3] ?? '0');

    switch ($programId[1] ?? '0') {
        case '0': case '1': case '8': case '9': $result .= "a"; break;
        case '2': case '3': $result .= "b"; break;
        case '4': case '5': $result .= "a"; break;
        case '6': case '7': $result .= "b"; break;
    }

    $result .= $puData[$len-5].($puData[4] ?? '0');
    $result .= 'a';

    for ($n=6; $n<=16; $n++) {
        $result .= ($puData[$len-$n] ?? '0') . ($puData[$n-1] ?? '0');
    }

    return $result;
}

// ========== 获取原始播放地址 ==========
$result = fetchPlayUrl($id, $userId, $userToken);
//echo json_encode($result);

$rawUrl = $result['body']['urlInfo']['url'] ?? '';

$result_code = $result['code'];

// 处理特定的错误码进行重定向
switch ($result_code) {
	case 200:
        // 200状态码处理正常，不需要重定向，继续后续逻辑
        break;
    case 412:
        header("Location: https://migu.lifit.uk/hyqx.mp4");
        exit;
    case 409:
        header("Location: https://migu.lifit.uk/dlsx.mp4");
        exit;    
    default:
        header("Location: https://migu.lifit.uk/qtcw.mp4");
        exit;
}

if ($rawUrl == null){
   //echo "302 Redirect, but no Location found.";
   header("Location: https://cdn.jsdelivr.net/gh/feiyang666999/testvideo/sdr1080pvideo/playlist.m3u8");
   exit;
}

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

$params    = getQueryParams($rawUrl);
$userid    = $params['userid'] ?? '';
$programId = $params['ProgramID'] ?? '';
$puData    = $params['puData'] ?? '';
$channelId = $params['Channel_ID'] ?? '';
$timestamp = $params['timestamp'] ?? '';

$ddCalcu = generateDdCalcu_www($userid, $programId, $puData, $channelId, $timestamp);
$finalUrl = $rawUrl . "&ddCalcu=" . urlencode($ddCalcu) . "_s002&sv=10011&crossdomain=www";

// ========== 日志记录 ==========
$time = date('Y-m-d H:i:s');
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
$logLine = "[$time] IP: $clientIp URL: $finalUrl" . PHP_EOL;
// 写入日志文件（追加模式）
$logFile = __DIR__ . '/url_log.txt';
file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);


// ========== 访问最终 URL（处理 302 跳转） ==========
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $finalUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
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
exit;
?>
