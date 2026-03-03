# 更新日志

本项目的所有重要更改都将记录在此文件中。

[English](CHANGELOG.md) | **中文**

---

## [1.0.0] - 2026-03-04

### 首次发布

- **自动故障转移** - 按优先级依次尝试代理商，连接失败或 5xx 时自动切换下一个
- **Prompt Cache 修复** - 自动注入由客户端 IP 派生的稳定 `metadata.user_id`，使代理商对同一客户端做会话亲和路由，解决缓存命中为零的问题
- **按代理商注入 Header** - 支持 `+value`（追加）和 `value`（替换）两种模式，通过 `injectHeaders` 配置
- **Beta Flag 注入** - 自动追加 `prompt-caching-2024-07-31` 和 `extended-cache-ttl-2025-04-11`，开启 1 小时缓存
- **SSE 流式透明转发** - 实时透传流式响应，同时记录每次请求的缓存命中状态
- **缓存使用日志** - 从 `message_start` SSE 事件中提取 `cache_creation_input_tokens` / `cache_read_input_tokens`，在日志中标注 `HIT` / `MISS` / `NONE`
- **Debug 模式** - 完整的请求级 JSON 日志（Headers、Body、转发 Headers），保存于 `logs/requests/`
- **健康检查接口** - `GET /health` 或 `GET /status` 返回代理商数量和当前时间
