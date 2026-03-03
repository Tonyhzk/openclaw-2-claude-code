# Anthropic API Failover Proxy

**[中文文档](README.zh-CN.md)** | English

A lightweight PHP proxy that sits between AI clients (like [OpenClaw](https://github.com/openclaw)) and third-party Anthropic API providers. Enables automatic failover across multiple providers and fixes prompt caching for clients that don't work out of the box.

## The Problem This Solves

When using third-party Anthropic API providers (resellers) through clients like OpenClaw, **prompt caching never works** — every request creates a new cache entry, zero cache reads, wasting money on repeated context.

**Root cause discovered:** Many third-party providers use `metadata.user_id` for sticky routing (session affinity). Without it, requests get distributed across multiple backend API keys, each with an isolated cache namespace. Cache is created but never read.

Claude Code works because it automatically includes `metadata.user_id`. OpenClaw doesn't.

**This proxy fixes it** by injecting a stable `metadata.user_id` (derived from client IP) into every request that's missing one.

## Features

- **Automatic failover** — tries providers in order, switches on connection error or 5xx
- **Prompt caching fix** — auto-injects `metadata.user_id` for cache routing affinity
- **Per-provider header injection** — add/append any headers per provider (e.g. beta flags)
- **Transparent passthrough** — supports any path, method, streaming SSE and non-streaming
- **Cache hit/miss logging** — logs `cache_creation_input_tokens` and `cache_read_input_tokens` per request
- **Health check endpoint** — `GET /health` or `GET /status`
- **Debug mode** — full per-request JSON logs with headers, body, and forwarded headers

## Setup

### Requirements

- PHP 7.4+ with `curl` extension
- A web server (Nginx/Apache) pointing to `index.php`

### Installation

```bash
git clone https://github.com/Tonyhzk/openclaw-2-claude-code.git
cd openclaw-2-claude-code
cp config.example.json config.json
# Edit config.json with your providers and keys
```

### Nginx Config (recommended)

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

## Configuration

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

### `injectHeaders` Syntax

| Value | Behavior |
|-------|----------|
| `"value"` | Replace the header entirely |
| `"+value"` | Append to existing header (comma-separated) |

### Why inject `prompt-caching-2024-07-31`?

Clients like OpenClaw (using the Anthropic JS SDK) don't include prompt caching beta flags. The proxy appends them so the provider activates caching.

## How the Caching Fix Works

```
Without proxy:
  Request 1 → Provider (key A) → creates cache
  Request 2 → Provider (key B) → creates cache (different namespace!)
  Request 3 → Provider (key C) → creates cache (different namespace!)
  Result: 0 cache reads, full cost every time

With proxy (metadata.user_id injected):
  Request 1 → Provider (key A, sticky) → creates cache
  Request 2 → Provider (key A, sticky) → cache HIT ✓
  Request 3 → Provider (key A, sticky) → cache HIT ✓
  Result: ~90% cost savings on repeated context
```

The proxy generates a deterministic `user_id` from the client IP + auth key hash:

```php
$stableUserId = 'proxy_' . hash('sha256', $clientIp . $authKey);
```

## Usage

Point your client to the proxy instead of the provider directly:

```
Base URL:  https://your-proxy.example.com
API Key:   (your auth_key from config.json)
```

The proxy transparently handles everything else.

## Logs

When `"debug": true` in config:

- `logs/proxy.log` — one line per request with cache hit/miss status
- `logs/debug.log` — detailed forwarding info
- `logs/requests/*.json` — full per-request dump (headers, body, forward headers)

Example proxy.log entry:
```
2026-03-03 20:22:57 STREAM_DONE {"provider":"ai580","code":200,"cache_usage":{"cache_creation_input_tokens":65,"cache_read_input_tokens":51566},"cache_status":"HIT(read=51566)"}
```

## Client Configuration

### OpenClaw

In `openclaw.json`, set `baseUrl` to your proxy:

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

### Claude Code

```bash
claude config set apiBaseUrl https://your-proxy.example.com
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for full release history.

## License

[MIT](LICENSE)
