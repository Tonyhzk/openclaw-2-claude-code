# 更新日志

本项目的所有重要更改都将记录在此文件中。

[English](CHANGELOG.md) | **中文**

---

## [Unreleased]

### 新增

- **按代理商路径映射** - 每个代理商可配置 `pathMap`，在转发前将请求路径替换为目标路径，例如将 `/v1/messages` 映射为 `/claude`，适配使用非标准路径的服务商
- **按代理商请求体注入** - 每个代理商可配置 `bodyInject`，在转发前覆盖或注入请求体字段，例如强制设置 `max_tokens` 或注入固定 `system` prompt
- **熔断器** - 连续失败达到阈值的代理商将被临时跳过；可通过 `circuit_breaker.threshold`（默认 3 次）和 `circuit_breaker.timeout`（默认 60 秒）配置。状态持久化至 `logs/circuit.json`，恢复后自动重置

---

## [1.1.0] - 2026-03-04

### 新增

- **按代理商模型替换** - 每个代理商可配置 `modelMap`，在转发前将请求中的模型名替换为目标模型，实现按后端灵活映射
- **按代理商推理开关** - 每个代理商可独立控制 `thinking` 字段：`false` 强制剥除（适用于不支持推理的服务商），`true` 强制注入默认 8000 token 额度，`{"budget_tokens": N}` 自定义额度

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
