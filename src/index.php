<?php
/**
 * Anthropic API Failover Proxy
 *
 * 透明转发所有请求到多个 provider，按优先级尝试。
 * 支持任意路径、任意 HTTP 方法、SSE 流式透传。
 * 连接失败/超时/5xx 时自动切换下一个 provider。
 */

// 禁用输出缓冲
if (ob_get_level()) ob_end_clean();
ini_set('output_buffering', 'Off');
ini_set('zlib.output_compression', 'Off');
ini_set('implicit_flush', 1);
set_time_limit(0);

// 加载配置
$configPath = __DIR__ . '/config.json';
if (!file_exists($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['type' => 'error', 'error' => ['type' => 'server_error', 'message' => 'Config not found']]);
    exit;
}
$config = json_decode(file_get_contents($configPath), true);

// 解析请求
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// 健康检查
if ($method === 'GET' && preg_match('#^/(health|status)$#', $uri)) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'providers' => count(array_filter($config['providers'], fn($p) => !isset($p['enabled']) || $p['enabled'])),
        'time' => date('c')
    ]);
    exit;
}

// 验证密钥：x-api-key 必须匹配 auth_key
$authKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($authKey !== $config['auth_key']) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'Invalid API key']]);
    exit;
}

// 提取原始请求路径（去掉 query string）
$path = parse_url($uri, PHP_URL_PATH) ?: $uri;

// 读取请求体
$body = file_get_contents('php://input');
// 用 false（stdClass）而非 true（array），保留 JSON 对象/数组类型区别，防止 input_schema 中空对象 {} 被序列化为 []
$bodyData = json_decode($body, false);
$isStreaming = !empty($bodyData->stream);

// 自动注入 metadata.user_id（如果缺失）——代理商用此字段做缓存路由亲和
if ($bodyData && empty($bodyData->metadata->user_id)) {
    // 用客户端 IP 生成稳定的 user_id，确保同一客户端请求路由到同一缓存后端
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $clientIp = explode(',', $clientIp)[0]; // 取第一个 IP
    $stableUserId = 'proxy_' . hash('sha256', $clientIp . ($config['auth_key'] ?? ''));
    if (!isset($bodyData->metadata)) {
        $bodyData->metadata = new stdClass();
    }
    $bodyData->metadata->user_id = $stableUserId;
    $body = json_encode($bodyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// 收集需要转发的请求头
$forwardHeaders = [];
$headerKeys = ['HTTP_ANTHROPIC_VERSION', 'HTTP_ANTHROPIC_BETA'];
foreach ($headerKeys as $key) {
    if (!empty($_SERVER[$key])) {
        $name = str_replace('_', '-', strtolower(substr($key, 5)));
        $forwardHeaders[] = $name . ': ' . $_SERVER[$key];
    }
}

// 日志函数
function logEvent($msg, $data = []) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $entry = date('Y-m-d H:i:s') . ' ' . $msg;
    if ($data) $entry .= ' ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    @file_put_contents($logDir . '/proxy.log', $entry . "\n", FILE_APPEND | LOCK_EX);
}

// Debug 日志函数（写入独立文件，便于分析）
$debug = !empty($config['debug']);
function logDebug($msg, $data = []) {
    global $debug;
    if (!$debug) return;
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $entry = date('Y-m-d H:i:s') . ' [DEBUG] ' . $msg;
    if ($data) $entry .= "\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    @file_put_contents($logDir . '/debug.log', $entry . "\n\n", FILE_APPEND | LOCK_EX);
}

// Debug: 每次请求写入独立文件（完整 body，不截断）
$requestId = date('Ymd_His') . '_' . substr(uniqid(), -6);
if ($debug) {
    $reqLogDir = __DIR__ . '/logs/requests';
    if (!is_dir($reqLogDir)) @mkdir($reqLogDir, 0755, true);
    $reqLogFile = $reqLogDir . '/' . $requestId . '.json';

    // 收集所有 HTTP 请求头
    $incomingHeaders = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $headerName = str_replace('_', '-', strtolower(substr($k, 5)));
            $incomingHeaders[$headerName] = $v;
        }
    }
    if (!empty($_SERVER['CONTENT_TYPE'])) {
        $incomingHeaders['content-type'] = $_SERVER['CONTENT_TYPE'];
    }

    // 分析 cache_control 放置位置
    $cacheMap = [];
    if ($bodyData) {
        if (!empty($bodyData->system) && is_array($bodyData->system)) {
            foreach ($bodyData->system as $j => $block) {
                if (isset($block->cache_control)) {
                    $cacheMap[] = 'system[' . $j . '] cache_control=' . json_encode($block->cache_control);
                }
            }
        }
        if (!empty($bodyData->messages) && is_array($bodyData->messages)) {
            foreach ($bodyData->messages as $j => $msg) {
                $role = $msg->role ?? '';
                $content = $msg->content ?? [];
                if (is_array($content)) {
                    foreach ($content as $k => $block) {
                        if (isset($block->cache_control)) {
                            $cacheMap[] = "msg[$j]($role).content[$k] cache_control=" . json_encode($block->cache_control);
                        }
                    }
                }
            }
        }
    }

    $reqData = [
        'request_id' => $requestId,
        'timestamp' => date('c'),
        'method' => $method,
        'uri' => $uri,
        'path' => $path,
        'stream' => $isStreaming,
        'headers' => $incomingHeaders,
        'body_length' => strlen($body),
        'cache_control_map' => $cacheMap,
        'body' => $bodyData,
    ];
    @file_put_contents($reqLogFile, json_encode($reqData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    logDebug("REQUEST_LOG", ['file' => $reqLogFile, 'cache_control_map' => $cacheMap]);
}

// 遍历 provider 尝试转发
$lastError = '';
$tried = [];

foreach ($config['providers'] as $i => $provider) {
    // 跳过未启用的 provider
    if (isset($provider['enabled']) && !$provider['enabled']) continue;

    $providerName = $provider['name'] ?? "provider_$i";
    $url = rtrim($provider['baseUrl'], '/') . $path;
    $tried[] = $providerName;

    // 构建请求头：替换 x-api-key 为当前 provider 的密钥
    $headers = array_merge([
        'Content-Type: application/json',
        'x-api-key: ' . $provider['apiKey'],
    ], $forwardHeaders);

    // 注入 provider 级别的自定义 headers
    if (!empty($provider['injectHeaders']) && is_array($provider['injectHeaders'])) {
        foreach ($provider['injectHeaders'] as $hName => $hValue) {
            $hNameLower = strtolower($hName);
            if (str_starts_with($hValue, '+')) {
                // "+" 前缀：追加到已有 header 值（逗号分隔）
                $appendVal = substr($hValue, 1);
                $found = false;
                foreach ($headers as &$h) {
                    if (stripos($h, $hNameLower . ':') === 0 || stripos($h, $hName . ':') === 0) {
                        $h = rtrim($h, ', ') . ',' . $appendVal;
                        $found = true;
                        break;
                    }
                }
                unset($h);
                if (!$found) {
                    $headers[] = $hName . ': ' . $appendVal;
                }
            } else {
                // 直接替换或新增
                $replaced = false;
                foreach ($headers as &$h) {
                    if (stripos($h, $hNameLower . ':') === 0 || stripos($h, $hName . ':') === 0) {
                        $h = $hName . ': ' . $hValue;
                        $replaced = true;
                        break;
                    }
                }
                unset($h);
                if (!$replaced) {
                    $headers[] = $hName . ': ' . $hValue;
                }
            }
        }
    }

    $ch = curl_init($url);
    $curlOpts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => $config['connect_timeout'] ?? 10,
        CURLOPT_TIMEOUT => $config['timeout'] ?? 300,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    // 有请求体时才发送
    if ($body !== '' && $body !== false) {
        $curlOpts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $curlOpts);

    // Debug: 记录转发详情（含注入后的最终 headers）
    if ($debug) {
        // 追加最终 headers 到请求日志文件
        if (isset($reqLogFile) && file_exists($reqLogFile)) {
            $reqJson = json_decode(file_get_contents($reqLogFile), true);
            if (!isset($reqJson['forward_headers'])) $reqJson['forward_headers'] = [];
            $reqJson['forward_headers'][$providerName] = $headers;
            @file_put_contents($reqLogFile, json_encode($reqJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }
    logDebug("FORWARD_REQUEST", [
        'provider' => $providerName,
        'url' => $url,
        'method' => $method,
        'headers' => $headers,
        'body_length' => strlen($body),
    ]);

    if ($isStreaming) {
        // === 流式模式 ===
        $httpCode = 0;
        $headersSent = false;
        $failed = false;
        $upstreamContentType = 'application/octet-stream';
        $cacheUsage = null; // 捕获缓存使用数据
        $sseBuffer = ''; // SSE 事件缓冲区

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$httpCode, &$upstreamContentType) {
            if (preg_match('/^HTTP\/\S+ (\d+)/', $header, $m)) {
                $httpCode = (int)$m[1];
            }
            if (preg_match('/^Content-Type:\s*(.+)/i', $header, $m)) {
                $upstreamContentType = trim($m[1]);
            }
            return strlen($header);
        });

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$httpCode, &$headersSent, &$failed, &$upstreamContentType, $providerName, &$cacheUsage, &$sseBuffer) {
            // 5xx 错误：中断并尝试下一个
            if ($httpCode >= 500) {
                $failed = true;
                return -1;
            }
            // 首次收到数据：发送响应头
            if (!$headersSent) {
                http_response_code($httpCode);
                header('Content-Type: ' . $upstreamContentType);
                if ($httpCode >= 200 && $httpCode < 300 && stripos($upstreamContentType, 'event-stream') !== false) {
                    header('Cache-Control: no-cache');
                    header('Connection: keep-alive');
                }
                header('X-Provider: ' . $providerName);
                $headersSent = true;
            }
            // 从 SSE 流中提取 cache usage（message_start 事件）
            if ($cacheUsage === null) {
                $sseBuffer .= $data;
                if (preg_match('/data:\s*(\{[^\n]*"type"\s*:\s*"message_start"[^\n]*\})/', $sseBuffer, $m)) {
                    $msgStart = json_decode($m[1], true);
                    if ($msgStart && isset($msgStart['message']['usage'])) {
                        $cacheUsage = $msgStart['message']['usage'];
                    }
                }
                // 只保留最后 4KB 防止内存溢出
                if (strlen($sseBuffer) > 4096) {
                    $sseBuffer = substr($sseBuffer, -2048);
                }
            }
            echo $data;
            if (ob_get_level()) ob_flush();
            flush();
            return strlen($data);
        });

        curl_exec($ch);
        $curlErr = curl_errno($ch);
        $curlErrMsg = curl_error($ch);
        $finalCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 已经开始向客户端输出了，无法重试
        if ($headersSent) {
            $logData = ['provider' => $providerName, 'code' => $finalCode];
            if ($cacheUsage) {
                $logData['cache_usage'] = $cacheUsage;
                // 高亮缓存状态
                $cacheCreate = $cacheUsage['cache_creation_input_tokens'] ?? 0;
                $cacheRead = $cacheUsage['cache_read_input_tokens'] ?? 0;
                $logData['cache_status'] = $cacheRead > 0 ? "HIT(read=$cacheRead)" : ($cacheCreate > 0 ? "MISS(create=$cacheCreate)" : "NONE");
            }
            logEvent("STREAM_DONE", $logData);
            logDebug("STREAM_DONE", $logData);
            exit;
        }

        // 未输出任何数据，且失败了 → 尝试下一个
        if ($curlErr || $failed) {
            $lastError = $curlErrMsg ?: "HTTP $finalCode";
            logEvent("STREAM_FAIL", ['provider' => $providerName, 'error' => $lastError]);
            logDebug("STREAM_FAIL", ['provider' => $providerName, 'code' => $finalCode, 'curl_errno' => $curlErr, 'error' => $lastError]);
            continue;
        }

        // 成功但无数据（不太可能）
        exit;

    } else {
        // === 非流式模式 ===
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
            if (preg_match('/^Content-Type:\s*(.+)/i', $header, $m)) {
                $responseHeaders['content-type'] = trim($m[1]);
            }
            return strlen($header);
        });
        $response = curl_exec($ch);
        $curlErr = curl_errno($ch);
        $curlErrMsg = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 连接失败或 5xx → 尝试下一个
        if ($curlErr || $httpCode >= 500) {
            $lastError = $curlErr ? $curlErrMsg : "HTTP $httpCode";
            logEvent("FAIL", ['provider' => $providerName, 'error' => $lastError]);
            logDebug("UPSTREAM_FAIL", [
                'provider' => $providerName,
                'code' => $httpCode,
                'curl_errno' => $curlErr,
                'error' => $lastError,
                'response' => mb_substr($response, 0, 2000),
            ]);
            continue;
        }

        // 成功（包括 4xx 客户端错误，原样返回）
        http_response_code($httpCode);
        header('Content-Type: ' . ($responseHeaders['content-type'] ?? 'application/json'));
        header('X-Provider: ' . $providerName);
        logEvent("OK", ['provider' => $providerName, 'code' => $httpCode]);
        // Debug: 记录上游响应
        logDebug("UPSTREAM_RESPONSE", [
            'provider' => $providerName,
            'code' => $httpCode,
            'content_type' => $responseHeaders['content-type'] ?? 'N/A',
            'response_length' => strlen($response),
            'response' => mb_substr($response, 0, 2000),
        ]);
        echo $response;
        exit;
    }
}

// 所有 provider 都失败了
http_response_code(502);
header('Content-Type: application/json');
logEvent("ALL_FAILED", ['tried' => $tried, 'lastError' => $lastError]);
echo json_encode([
    'type' => 'error',
    'error' => [
        'type' => 'overloaded_error',
        'message' => 'All providers failed. Tried: ' . implode(', ', $tried) . '. Last error: ' . $lastError
    ]
]);