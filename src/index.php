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

// ── 熔断器（Circuit Breaker）────────────────────────────────────────────
$cbConfig  = $config['circuit_breaker'] ?? [];
$cbEnabled = !empty($cbConfig['enabled']);
$cbThresh  = (int)($cbConfig['threshold'] ?? 3);  // 触发熔断的连续失败次数
$cbTimeout = (int)($cbConfig['timeout'] ?? 60);   // 熔断后等待恢复的秒数
$cbFile    = __DIR__ . '/logs/circuit.json';
$cbState   = [];

function cbLoad($file) {
    if (!file_exists($file)) return [];
    $fp = @fopen($file, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return json_decode($content, true) ?? [];
}

function cbSave($file, $state) {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fp = @fopen($file, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
}

if ($cbEnabled) {
    $cbState = cbLoad($cbFile);
}
// ─────────────────────────────────────────────────────────────────────────

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
    $tried[] = $providerName;

    // 熔断器检查：跳过近期连续失败的 provider
    if ($cbEnabled) {
        $pState = $cbState[$providerName] ?? null;
        if ($pState && $pState['failures'] >= $cbThresh && (time() - $pState['last_failure']) < $cbTimeout) {
            logEvent("CB_SKIP", ['provider' => $providerName, 'failures' => $pState['failures']]);
            logDebug("CB_SKIP", ['provider' => $providerName, 'failures' => $pState['failures'], 'last_failure' => $pState['last_failure']]);
            continue;
        }
    }

    // 路径映射：pathMap 中匹配则替换请求路径
    $targetPath = $path;
    if (!empty($provider['pathMap']) && is_array($provider['pathMap']) && isset($provider['pathMap'][$path])) {
        $targetPath = $provider['pathMap'][$path];
        logEvent("PATH_MAP", ['provider' => $providerName, 'from' => $path, 'to' => $targetPath]);
        logDebug("PATH_MAP", ['provider' => $providerName, 'from' => $path, 'to' => $targetPath]);
    }

    $url = rtrim($provider['baseUrl'], '/') . $targetPath;

    // ── 按 provider 修改请求体 ──────────────────────────────────────────
    $providerBody = $body;
    $providerBodyData = null; // 惰性 clone，有修改时才创建

    // 模型替换：modelMap 中匹配则替换 model 字段
    if (!empty($provider['modelMap']) && is_array($provider['modelMap']) && $bodyData && isset($bodyData->model)) {
        $originalModel = $bodyData->model;
        if (isset($provider['modelMap'][$originalModel])) {
            $mappedModel = $provider['modelMap'][$originalModel];
            $providerBodyData = $providerBodyData ?? clone $bodyData;
            $providerBodyData->model = $mappedModel;
            logEvent("MODEL_MAP", ['provider' => $providerName, 'from' => $originalModel, 'to' => $mappedModel]);
            logDebug("MODEL_MAP", ['provider' => $providerName, 'from' => $originalModel, 'to' => $mappedModel]);
        }
    }

    // 推理开关：控制请求体中的 thinking 字段
    //   false                    → 强制关闭（移除 thinking 字段）
    //   true                     → 强制开启，默认 budget_tokens=8000
    //   {"budget_tokens": N}     → 强制开启，自定义额度
    if (isset($provider['thinking']) && $bodyData) {
        $providerBodyData = $providerBodyData ?? clone $bodyData;
        if ($provider['thinking'] === false) {
            unset($providerBodyData->thinking);
            logEvent("THINKING_OFF", ['provider' => $providerName]);
            logDebug("THINKING_OFF", ['provider' => $providerName]);
        } else {
            $budgetTokens = is_array($provider['thinking']) && isset($provider['thinking']['budget_tokens'])
                ? (int)$provider['thinking']['budget_tokens']
                : 8000;
            $providerBodyData->thinking = (object)['type' => 'enabled', 'budget_tokens' => $budgetTokens];
            logEvent("THINKING_ON", ['provider' => $providerName, 'budget_tokens' => $budgetTokens]);
            logDebug("THINKING_ON", ['provider' => $providerName, 'budget_tokens' => $budgetTokens]);
        }
    }

    // 请求体字段注入：bodyInject 中的字段直接覆盖请求体对应字段
    if (!empty($provider['bodyInject']) && is_array($provider['bodyInject']) && $bodyData) {
        $providerBodyData = $providerBodyData ?? clone $bodyData;
        foreach ($provider['bodyInject'] as $field => $value) {
            $providerBodyData->$field = $value;
        }
        logEvent("BODY_INJECT", ['provider' => $providerName, 'fields' => array_keys($provider['bodyInject'])]);
        logDebug("BODY_INJECT", ['provider' => $providerName, 'inject' => $provider['bodyInject']]);
    }

    // 有修改则统一序列化
    if ($providerBodyData !== null) {
        $providerBody = json_encode($providerBodyData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    // ────────────────────────────────────────────────────────────────────

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
    // 有请求体时才发送（使用可能经过模型替换的 providerBody）
    if ($providerBody !== '' && $providerBody !== false) {
        $curlOpts[CURLOPT_POSTFIELDS] = $providerBody;
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
        'body_length' => strlen($providerBody),
    ]);

    if ($isStreaming) {
        // === 流式模式 ===
        $httpCode = 0;
        $headersSent = false;
        $failed = false;
        $upstreamContentType = 'application/octet-stream';
        $cacheUsage = null; // 捕获缓存使用数据
        $sseBuffer = ''; // SSE 事件缓冲区（仅用于提取 cache usage，有大小上限）
        $responseLog = ''; // 完整响应体（仅 debug 模式下累积）

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$httpCode, &$upstreamContentType) {
            if (preg_match('/^HTTP\/\S+ (\d+)/', $header, $m)) {
                $httpCode = (int)$m[1];
            }
            if (preg_match('/^Content-Type:\s*(.+)/i', $header, $m)) {
                $upstreamContentType = trim($m[1]);
            }
            return strlen($header);
        });

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$httpCode, &$headersSent, &$failed, &$upstreamContentType, $providerName, &$cacheUsage, &$sseBuffer, &$responseLog) {
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
            // 累积完整响应用于日志（仅 debug 模式）
            global $debug;
            if ($debug) {
                $responseLog .= $data;
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
            // 熔断器：成功，重置失败计数
            if ($cbEnabled && isset($cbState[$providerName])) {
                unset($cbState[$providerName]);
                cbSave($cbFile, $cbState);
            }
            // Debug: 将完整响应体写入独立的响应日志文件
            if ($debug && $responseLog !== '') {
                $resLogDir = __DIR__ . '/logs/responses';
                if (!is_dir($resLogDir)) @mkdir($resLogDir, 0755, true);
                @file_put_contents($resLogDir . '/' . $requestId . '.txt', $responseLog);
            }
            exit;
        }

        // 未输出任何数据，且失败了 → 尝试下一个
        if ($curlErr || $failed) {
            $lastError = $curlErrMsg ?: "HTTP $finalCode";
            logEvent("STREAM_FAIL", ['provider' => $providerName, 'error' => $lastError]);
            logDebug("STREAM_FAIL", ['provider' => $providerName, 'code' => $finalCode, 'curl_errno' => $curlErr, 'error' => $lastError]);
            // 熔断器：记录失败
            if ($cbEnabled) {
                $cbState[$providerName]['failures'] = ($cbState[$providerName]['failures'] ?? 0) + 1;
                $cbState[$providerName]['last_failure'] = time();
                cbSave($cbFile, $cbState);
            }
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
            // 熔断器：记录失败
            if ($cbEnabled) {
                $cbState[$providerName]['failures'] = ($cbState[$providerName]['failures'] ?? 0) + 1;
                $cbState[$providerName]['last_failure'] = time();
                cbSave($cbFile, $cbState);
            }
            continue;
        }

        // 成功（包括 4xx 客户端错误，原样返回）
        http_response_code($httpCode);
        header('Content-Type: ' . ($responseHeaders['content-type'] ?? 'application/json'));
        header('X-Provider: ' . $providerName);
        logEvent("OK", ['provider' => $providerName, 'code' => $httpCode]);
        // 熔断器：成功，重置失败计数
        if ($cbEnabled && isset($cbState[$providerName])) {
            unset($cbState[$providerName]);
            cbSave($cbFile, $cbState);
        }
        // Debug: 记录上游响应（含完整响应体写入请求日志文件）
        logDebug("UPSTREAM_RESPONSE", [
            'provider' => $providerName,
            'code' => $httpCode,
            'content_type' => $responseHeaders['content-type'] ?? 'N/A',
            'response_length' => strlen($response),
            'response' => mb_substr($response, 0, 2000),
        ]);
        if ($debug) {
            $resLogDir = __DIR__ . '/logs/responses';
            if (!is_dir($resLogDir)) @mkdir($resLogDir, 0755, true);
            $resData = [
                'request_id' => $requestId,
                'provider' => $providerName,
                'code' => $httpCode,
                'response_length' => strlen($response),
                'response' => json_decode($response, true) ?? $response,
            ];
            @file_put_contents($resLogDir . '/' . $requestId . '.json', json_encode($resData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
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