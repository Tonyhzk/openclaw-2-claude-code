# Anthropic API 故障转移代理

[English](README.md) | **中文文档**

一个轻量 PHP 代理，部署在 AI 客户端（如 [OpenClaw](https://github.com/openclaw)）与第三方 Anthropic API 代理商之间。实现多代理商自动故障转移，并修复客户端开箱即用时无法命中 Prompt Cache 的问题。

## 解决了什么问题

通过 OpenClaw 等客户端使用第三方 Anthropic API 代理商时，**Prompt Cache 完全失效**——每次请求都创建新缓存，命中次数为零，大量浪费重复上下文的费用。

**根因**：多数第三方代理商使用 `metadata.user_id` 做会话亲和路由（Sticky Routing）。没有这个字段时，请求被随机分发到多个后端 API Key，每个 Key 有独立的缓存命名空间，缓存只创建、从不命中。

Claude Code 能正常缓存是因为它自动携带了 `metadata.user_id`，而 OpenClaw 没有。

**本代理的修复方式**：对每个缺少 `metadata.user_id` 的请求，自动注入一个由客户端 IP 派生的稳定 `user_id`。

## 功能特性

- **自动故障转移** — 按优先级依次尝试代理商，连接失败或 5xx 时自动切换
- **Prompt Cache 修复** — 自动注入 `metadata.user_id`，使代理商做缓存路由亲和
- **按代理商注入 Headers** — 可对每个代理商单独追加或覆盖任意 Header（如 Beta Flag）
- **透明转发** — 支持任意路径、任意 HTTP 方法、SSE 流式和非流式响应
- **缓存命中日志** — 每次请求记录 `cache_creation_input_tokens` 和 `cache_read_input_tokens`
- **健康检查接口** — `GET /health` 或 `GET /status`
- **Debug 模式** — 完整的请求级 JSON 日志，含 Headers、Body、转发详情

## 部署

### 环境要求

- PHP 7.4+，需启用 `curl` 扩展
- Nginx 或 Apache，将请求路由到 `index.php`

### 安装

```bash
git clone https://github.com/Tonyhzk/openclaw-2-claude-code.git
cd openclaw-2-claude-code
cp config.example.json config.json
# 编辑 config.json，填入代理商地址和 Key
```

### Nginx 配置（推荐）

```nginx
server {
    listen 443 ssl;
    server_name your-proxy.example.com;

    root /path/to/openclaw-2-claude-code;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
        fastcgi_buffering off;
    }
}
```

## 配置说明

```json
{
  "auth_key": "sk-your-proxy-key",
  "connect_timeout": 10,
  "timeout": 300,
  "debug": false,
  "providers": [
    {
      "name": "provider1",
      "enabled": true,
      "baseUrl": "https://api.provider1.com",
      "apiKey": "sk-provider1-key",
      "injectHeaders": {
        "anthropic-beta": "+prompt-caching-2024-07-31,extended-cache-ttl-2025-04-11"
      }
    }
  ]
}
```

### `injectHeaders` 语法

| 值格式 | 行为 |
|--------|------|
| `"value"` | 完全替换该 Header |
| `"+value"` | 追加到已有 Header 末尾（逗号分隔） |

### 为什么要注入 `prompt-caching-2024-07-31`？

OpenClaw 等客户端（使用 Anthropic JS SDK）默认不携带 Prompt Cache 相关的 Beta Flag。代理自动追加后，代理商才会激活缓存功能。

## 缓存修复原理

```
修复前（无代理）：
  请求 1 → 代理商（Key A）→ 创建缓存
  请求 2 → 代理商（Key B）→ 创建缓存（不同命名空间！）
  请求 3 → 代理商（Key C）→ 创建缓存（不同命名空间！）
  结果：缓存命中 0 次，每次全量计费

修复后（有代理注入 metadata.user_id）：
  请求 1 → 代理商（Key A，亲和）→ 创建缓存
  请求 2 → 代理商（Key A，亲和）→ 缓存命中 ✓
  请求 3 → 代理商（Key A，亲和）→ 缓存命中 ✓
  结果：重复上下文费用节省约 90%
```

代理用客户端 IP + Auth Key 的哈希生成稳定的 `user_id`：

```php
$stableUserId = 'proxy_' . hash('sha256', $clientIp . $authKey);
```

## 使用方式

将客户端的 Base URL 改为代理地址即可，其余配置不变：

```
Base URL:  https://your-proxy.example.com
API Key:   （config.json 中的 auth_key）
```

### OpenClaw 配置

在 `openclaw.json` 中修改 provider 的 `baseUrl`：

```json
{
  "models": {
    "providers": {
      "anthropic": {
        "baseUrl": "https://your-proxy.example.com",
        "apiKey": "sk-your-proxy-key"
      }
    }
  }
}
```

### Claude Code 配置

```bash
claude config set apiBaseUrl https://your-proxy.example.com
```

## 日志说明

在 config 中设置 `"debug": true` 后：

- `logs/proxy.log` — 每次请求一行，含缓存命中/未命中状态
- `logs/debug.log` — 详细转发信息
- `logs/requests/*.json` — 完整的请求快照（Headers、Body、转发 Headers）

proxy.log 示例：

```
2026-03-03 20:22:57 STREAM_DONE {"provider":"ai580","code":200,"cache_usage":{"cache_creation_input_tokens":65,"cache_read_input_tokens":51566},"cache_status":"HIT(read=51566)"}
```

## 更新日志

查看 [CHANGELOG_CN.md](CHANGELOG_CN.md) 了解完整版本历史。

## License

[MIT](LICENSE)