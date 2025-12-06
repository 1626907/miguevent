<?php
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '0'); // 如果不想记录到日志也关闭

date_default_timezone_set('Asia/Shanghai');

// ========== 用户信息（固定写死防泄露） ==========
// 默认写死的会员信息
$defaultUserId    = "你的userId";
$defaultUserToken = "你的userToken";

// 优先使用 URL 传参，如果没有则使用默认值
$userId    = $_GET['userId'] ?? $defaultUserId;
$userToken = $_GET['userToken'] ?? $defaultUserToken;
$id        = $_GET['id'] ?? null;

// ========== 缓存配置 ==========
$cacheFile = __DIR__ . "/miguevent_jpid.txt";
$cacheExpire = 7200; // 1小时

function isCacheValid($file, $expire) {
    return file_exists($file) && (time() - filemtime($file) < $expire);
}

// ========== 频道列表模式 ==========
if ($id === null) {
    header('Content-Type: text/plain; charset=utf-8');

    // 如果缓存无效，重新生成
    if (!isCacheValid($cacheFile, $cacheExpire)) {
        // 目标网页URL
        $url = 'https://www.miguvideo.com/p/home/';
        
        // 获取网页
        $html = file_get_contents($url);
        if ($html === false) {
            echo "错误：无法获取网页内容\n";
            exit;
        }
        
        // 提取 __INITIAL_COMPS_STATE__ 中的 JSON
        preg_match('/window\.__INITIAL_COMPS_STATE__ = ({.*?});/', $html, $matches);
        
        if (!isset($matches[1])) {
            echo "错误：未能提取到数据\n";
            exit;
        }
        
        // 原始 JSON
        $rawJson = $matches[1];
        $data = json_decode($rawJson, true);
        
        // 找到动态 key（第一级 key）
        $events = null;
        foreach ($data as $key => $item) {
            if (isset($item['body']['data'])) {
                $events = $item['body']['data'];
                break;
            }
        }
        
        if (!isset($events)) {
            echo "错误：未找到赛事数据\n";
            exit;
        }
        
        // 第一步：先收集所有赛事基本信息并按时间排序
        $allEvents = [];
        $today = date("Ymd");
        foreach ($events as $ev) {
            $competition = $ev["competitionName"] ?? '';
            $mgdbId = $ev["pID"] ?? '';
            $title = $ev["title"] ?? '';
            $startTimeRaw = $ev["startTime"] ?? '';
            
            
            // 提取 startTime 的日期部分
            $startDate = substr($startTimeRaw, 0, 8);
            if ($startDate != $today) {
                continue;
            }
            
            // 转换时间格式
            if ($startTimeRaw && strlen($startTimeRaw) === 12) {
                $startTime = intval(substr($startTimeRaw,4,2)) . "月" . intval(substr($startTimeRaw,6,2)) . "日" . substr($startTimeRaw,8,2) . ":" . substr($startTimeRaw,10,2);
                $sortTime = $startTimeRaw; // 用于排序的原始时间
            } else {
                $startTime = $startTimeRaw;
                $sortTime = $startTimeRaw;
            }
            
            // 存储基本信息
            $allEvents[] = [
                'sortTime' => $sortTime,
                'startTime' => $startTime,
                'competition' => $competition,
                'mgdbId' => $mgdbId,
                'title' => $title
            ];
        }
        
        // 按时间排序
        usort($allEvents, function($a, $b) {
            return strcmp($a['sortTime'], $b['sortTime']);
        });
        
        // 第二步：按排序后的顺序获取详细直播信息
        $output = "咪咕体育-精选赛事,#genre#\n";
        
        foreach ($allEvents as $event) {
            $detailHtml = @file_get_contents("https://m.miguvideo.com/m/live/home/{$event['mgdbId']}/matchDetail");
            if ($detailHtml && preg_match('/window\.__INITIAL_BASIC_DATA__\s*=\s*(\{.*?\});/s', $detailHtml, $m)) {
                $liveData = json_decode($m[1], true);
                $eventData = $liveData[$event['mgdbId']] ?? [];
                //echo json_encode($liveData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                
                // 处理直播列表
                if (!empty($eventData['body']['multiPlayList']['liveList'])) {
                    foreach ($eventData['body']['multiPlayList']['liveList'] as $li) {
                        $name = $li['name'] ?? '';
                        $pidLive = $li['pID'] ?? '';
                        if (!empty($pidLive)) {
                            $output .= "[{$event['competition']}] {$event['startTime']} {$event['title']} {$name},{$pidLive}\n";
                        }
                    }
                } 
                
                // 处理回放列表
                if (!empty($eventData['body']['replayList'])) {
                    foreach ($eventData['body']['replayList'] as $li) {
                        $pidLive = $li['pID'] ?? '';
                        if (!empty($pidLive)) {
                            $response = @file_get_contents("https://v2-sc.miguvideo.com/program/v3/cont/content-info/$pidLive/1");
                            if ($response) {
                                $json = json_decode($response, true);
                                $name = $json['body']['data']['name'] ?? $event['title'];
                                $output .= "[{$event['competition']}] {$event['startTime']} {$name},{$pidLive}\n";
                            }
                        }
                    }
                }
            }
        }
        
        $output .= "\n";
        file_put_contents($cacheFile, $output, LOCK_EX);
    }
    
    // ========== 输出缓存 ==========
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $baseUrl = "$scheme://$host$script";

    // 读取缓存文件并输出
    if (file_exists($cacheFile)) {
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
    } else {
        echo "错误：缓存文件不存在\n";
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

    switch ($userid[1] ?? '0') {
        case '0': case '1': case '8': case '9': $result .= "a"; break;
        case '2': case '3': $result .= "b"; break;
        case '4': case '5': $result .= "c"; break;
        case '6': case '7': $result .= "d"; break;
    }

    $result .= $puData[$len-3].($puData[2] ?? '0');
    $result .= (substr($timestamp,0,1) == '2') ? 'b' : 'a';
    $result .= $puData[$len-4].($puData[3] ?? '0');

    switch ($programId[5] ?? '0') {
        case '0': case '1': case '8': case '9': $result .= "a"; break;
        case '2': case '3': $result .= "b"; break;
        case '4': case '5': $result .= "c"; break;
        case '6': case '7': $result .= "d"; break;
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
$finalUrl = $rawUrl . "&ddCalcu=" . urlencode($ddCalcu) . "_s002&sv=10010&crossdomain=www";

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
