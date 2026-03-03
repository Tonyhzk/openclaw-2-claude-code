# Changelog

All notable changes to this project will be documented in this file.

**[中文](CHANGELOG_CN.md)** | English

---

## [1.0.0] - 2026-03-04

### First Release

- **Automatic failover** - Tries providers in priority order, switches on connection error or 5xx response
- **Prompt caching fix** - Auto-injects `metadata.user_id` derived from client IP to enable cache routing affinity on third-party providers
- **Per-provider header injection** - Supports `+value` (append) and `value` (replace) modes per provider via `injectHeaders` config
- **Beta flag injection** - Automatically appends `prompt-caching-2024-07-31` and `extended-cache-ttl-2025-04-11` to enable 1-hour prompt caching
- **SSE streaming passthrough** - Transparent streaming with real-time cache hit/miss status logged per request
- **Cache usage logging** - Extracts `cache_creation_input_tokens` / `cache_read_input_tokens` from `message_start` SSE event and logs `HIT` / `MISS` / `NONE` status
- **Debug mode** - Full per-request JSON dumps (headers, body, forwarded headers) saved to `logs/requests/`
- **Health check endpoint** - `GET /health` or `GET /status` returns provider count and timestamp
