# SubSieve

订阅清洗网关 + 可视化管理后台，Docker Compose 一键部署。

订阅请求先经过黑名单、云厂商 IP 识别、UA、Token 和速率限制等多层拦截，通过后才反代到机场后端，防止订阅链接被扫描或滥用。

---

## 目录结构

```
sgw/
├── setup.sh                 ← 首次部署向导（一键运行）
├── update.sh                ← 已安装用户更新脚本
├── healthcheck.sh           ← 容器健康检查与排障入口
├── docker-compose.yml
├── .env                     ← 由 setup.sh 自动生成，含账号密码等敏感信息
├── DEPLOY_INFO.txt          ← 部署完成后生成，记录访问地址和账号
├── ssl/
│   ├── cert.pem             ← 由 setup.sh 自动申请，或手动放入
│   └── key.pem
├── gateway/                 ← nginx 拦截层 + proxy_pass
│   ├── Dockerfile
│   ├── nginx/
│   │   ├── nginx.conf
│   │   └── subscribe_protect.conf.template
│   └── scripts/
│       ├── entrypoint.sh
│       ├── update_cloud_geo.sh   ← 每周自动更新云IP库
│       └── reload_whitelist.sh   ← 白名单生效脚本
└── admin/                   ← PHP 管理后台
    ├── Dockerfile
    ├── nginx.conf
    └── src/
        ├── index.php             ← 路由 + 鉴权 + API转发
        ├── config.php            ← 配置常量 + 工具函数
        ├── api/
        │   ├── _auth.php         ← API 鉴权中间件
        │   ├── logs.php          ← 日志读取 / 删除旧日志
        │   ├── stats.php         ← IP/Token/UA 分析
        │   ├── whitelist.php     ← 白名单 CRUD
        │   ├── blacklist.php     ← 黑名单（nginx deny，即时生效）
        │   ├── token_blacklist.php ← Token 黑名单
        │   ├── ua_blacklist.php  ← 自定义封禁UA（nginx map，即时生效）
        │   └── settings.php      ← 系统设置
        └── views/
            ├── login.php
            └── dashboard.php     ← 主界面（7个选项卡）
```

---

## 首次部署

### 前置要求

- 一台有公网 IP 的 VPS（Debian/Ubuntu 最低1c0.5g）
- 已安装 Docker + Docker Compose
- `80`、`64444` 以及计划使用的网关端口未被其他服务占用
- 如需自动申请 SSL 证书：提前把域名解析到本机（A 记录）

### 一键部署

```bash
git clone https://github.com/zona-165/SubSieve.git
cd SubSieve/sgw
./setup.sh
```

如果系统提示 `Permission denied`，再执行一次 `chmod +x setup.sh update.sh` 后重试即可。

向导会依次询问：

| 提示 | 说明 |
|------|------|
| 机场地址 | 你的机场面板域名，如 `panel.example.com`，不含 `https://` |
| 订阅路径 | 默认 `/api/v1/client/subscribe`，直接回车即可 |
| 订阅端口 | 机场后端监听端口，默认 `443` |
| 网关端口 | 客户端订阅链接对外暴露的端口，默认 `443` |
| 域名（SSL） | 输入已解析到本机的域名，脚本自动调用 acme.sh 申请证书；留空则手动放证书 |

部署脚本会等待 gateway 和 admin 均通过健康检查后再报告成功。访问信息会打印在终端，同时保存到 `DEPLOY_INFO.txt`。

## 食用方法
部署完成后，将原订阅链接中的域名和端口替换为部署了本项目的域名和端口即可。
```
示例：
# 原订阅链接
https://aaaa.bbbb.com:11111/api/v1/client/subscribe?token=xxxxxxxxxxxxxxxxxxxxxxx

# 替换为
https://your-domain.com:端口/api/v1/client/subscribe?token=xxxxxxxxxxxxxxxxxxxxxxx
```

## 套 AxisNow CDN 时获取真实客户端 IP

如果订阅域名前套了 AxisNow CDN，需要在 AxisNow 侧启用真实客户端 IP 传递，并在 SubSieve 侧只信任 AxisNow Edge/EIP。否则 nginx 的 `$remote_addr` 会是 AxisNow 回源 IP，日志、云 IP 判断、黑白名单和速率限制都会按 CDN IP 生效。

首次部署时，`setup.sh` 会询问 `AxisNow Edge/EIP`，填入 AxisNow 面板中 Edge/EIP 列表里的回源地址，多个用英文逗号分隔：

```env
AXISNOW_TRUSTED_IPS=1.2.3.4,5.6.7.8
REAL_IP_HEADER=X-Forwarded-For
```

已部署用户可直接在 `sgw/.env` 追加上述配置，然后重建：

```bash
cd SubSieve/sgw
docker compose up -d --build
```

验证配置是否进入容器：

```bash
docker exec subscribe-gateway nginx -T | grep -E "set_real_ip_from|real_ip_header|real_ip_recursive"
docker exec subscribe-gateway tail -n 20 /var/log/subscribe/access.log
```

如果日志第一列仍是 AxisNow IP，请确认 `AXISNOW_TRUSTED_IPS` 填的是 SubSieve 当前看到的 AxisNow 回源 IP；如果 AxisNow 只传 `X-Real-IP`，将 `REAL_IP_HEADER` 改为 `X-Real-IP` 后重建。

---

## 后续更新

已部署的用户，直接运行：

```bash
cd SubSieve/sgw
./update.sh
```

脚本会自动 git pull 最新代码、保留 `.env`、重新构建容器，并等待两个服务通过健康检查。若 nginx、PHP-FPM 或共享存储异常，脚本会打印容器日志并以非零状态退出，避免把启动失败误报为更新成功。

> **注意**：如果在后台修改了**网关端口**，需要在宿主机执行一次 `./update.sh` 才能让新端口生效（`.env` 中的 `GATEWAY_PORT` 由该脚本同步更新）。

如果你是从原仓库 `Null404-0/SubSieve` 部署的旧实例，先把远端切到本维护仓库：

```bash
cd SubSieve
git remote set-url origin https://github.com/zona-165/SubSieve.git
git pull origin main
cd sgw
./update.sh
```

---

## 访问后台

```
https://你的域名或IP:64444/<随机路径>
```

路径和账号密码见 `DEPLOY_INFO.txt`，或查看 `.env` 中的 `ADMIN_SECRET_PATH` / `ADMIN_PASS`。

后台会话 Cookie 默认启用 `Secure`、`HttpOnly` 和 `SameSite=Strict`，登录成功后会更换 Session ID。所有页面和 API 都必须先经过 Secret Path 路由，不能绕过隐藏路径直接执行 PHP 接口。

---

## 后台功能

| 选项卡 | 功能 |
|--------|------|
| 日志 | 今日/全部日志切换，按 IP / 状态码 / Token / UA 过滤；“仅订阅相关”会自动读取系统设置里的订阅路径；Token 全文展示并支持一键复制，一键封禁 IP，删除7日前旧日志 |
| 分析 | Top IP、Top Token（支持复制）、可疑 Token、可疑 IP、脚本/扫描器拉取订阅、用户画像、可疑 UA；可疑 IP 会显示风险评分和高危成立依据；脚本/扫描器报告会交叉查询 IP 地理位置、ASN、路由网段、运营商和代理/机房画像，显示来源数量、结果一致性与置信度，并支持复制报告、一键封禁 IP |
| 封禁UA | 添加/删除自定义封禁 UA 关键词，大小写不敏感，立即 reload nginx 生效 |
| 白名单 | 增删、导入白名单 IP，立即生效；白名单 IP 跳过云 IP、UA 和 Token 风险拦截 |
| 黑名单 | 增删黑名单 IPv4/CIDR（nginx deny 403），增删后立即生效 |
| Token黑名单 | 封禁指定订阅 Token，命中后返回 403，支持添加备注 |
| 设置 | 修改网站标题、后台账号密码、机场上游地址、订阅路径、网关对外端口；上游和路径会持久化并在容器重启后继续生效；支持 Webhook / Telegram 高危告警推送 |

---

## 拦截层说明

订阅请求按以下顺序过滤，通过全部拦截后才反代到机场后端：

1. **黑名单**：IPv4/CIDR 封禁，nginx `deny` 返回 403
2. **云厂商 IP**：自动识别Ucloud、阿里云、腾讯云、字节、华为云、Google、AWS、Azure、DigitalOcean 等，返回 403
3. **可疑 UA**：空 UA、curl、wget、python、Go、Java 等爬虫特征，返回 403
4. **自定义封禁 UA**：管理员在后台手动添加的 UA 关键词，返回 403
5. **Token 黑名单**：命中封禁 Token 返回 403
6. **速率限制**：每分钟 20 次，burst 5，超出返回 429
7. **白名单**：白名单 IP 跳过云 IP、UA 和 Token 风险拦截；显式 IP 黑名单和速率限制仍然生效

云厂商 IP 库每周自动更新，更新日志见容器内 `/var/log/subscribe/update_cloud_geo.log`。

脚本/扫描器报告中的 IP 情报由后台预热任务通过 `ip-api`、`ipwho.is`、`GeoJS` 和 `RIPEstat` 多源查询：前三者交叉验证国家、地区、城市、ASN 与运营商，`RIPEstat` 补充 BGP 宣告 ASN 和路由网段；报告会展示来源数量、一致性和置信度。网页请求本身不会等待外部接口，每轮最多补全 5 个新 IP；`ipwho.is` 设有每日调用预算并预留余量。结果按来源完整度和置信度缓存 6 小时至 7 天，失败结果 10 分钟后重试；单个来源不可用时仍会合并其他可用结果并缩短重查周期。

后台容器启动后会每分钟自动预热分析统计缓存，写入 `/etc/nginx/subscribe/stats_cache.json`。分析页默认读取缓存，避免日志量增长后每次打开页面都扫描大日志；首次部署和后续更新都会自动启用，无需手动配置。当前统计默认基于最近 30000 行网关日志生成。

### 告警推送与历史

告警推送默认关闭，可在后台「系统设置 → 告警推送」中开启。后台维护任务每分钟读取统计缓存，发现脚本/扫描器拉取订阅、可疑 IP、多 IP 拉取同一 Token 等高危事件时推送。

- **推送渠道**：支持 Webhook 和 Telegram；建议先用「测试推送」确认渠道可用。
- **规则与去重**：可调整扫描器评分、可疑 IP 评分、Token 被多少个 IP 拉取才告警、同一事件去重分钟数和历史保留条数，也可套用「严格 / 均衡 / 安静」预设。
- **静默时段**：静默期间命中的事件会写入历史并计入去重，但不会外发 Webhook / Telegram；后台会显示最近静默摘要、历史状态分布和历史覆盖时间。
- **手动巡检**：点击「立即检查」可立刻执行一次高危事件巡检；如需重新推送同一事件，可点击「重置去重」。
- **历史查看**：最近记录支持 10 / 25 / 50 条分页、首页/末页/页码跳转、状态筛选、时间范围筛选、关键词搜索、防抖搜索、Enter 搜索、Esc/按钮清空、筛选摘要标签和一键重置。
- **复制与导出**：单条记录、当前页、当前页摘要均可复制；当前页 JSON 和全量历史 JSON 均可导出。复制文本和当前页 JSON 会包含筛选条件、页码范围和记录范围，当前页导出文件名也会带状态、时间范围和页码。
- **导入与迁移**：导入兼容全量历史和当前页导出文件。导入前会预览文件名、文件大小、导出时间、条数、状态分布、时间范围、来源筛选和来源关键词；旧备份、未来导出时间或空记录文件会额外提示。导入只替换展示历史，不修改告警配置和去重状态，成功后自动回到全部状态、全部时间、第 1 页查看结果。
- **移动端体验**：搜索区会自动换行，列表操作按钮会下沉为更易点击的双按钮布局。

告警去重状态保存在 `/etc/nginx/subscribe/alert_state.json`，最近检查状态和推送历史保存在 `/etc/nginx/subscribe/alert_history.json`。

后台容器还会定时清理旧访问日志，默认保留最近 14 天，避免 `access.log` 长期增长拖慢统计或占满磁盘。如需调整，可在 `sgw/.env` 中设置：

```env
LOG_RETENTION_DAYS=14
```

设为 `0` 可关闭自动清理。维护日志见容器内 `/var/log/subscribe/maintenance.log`。

---

## 常用命令

```bash
# 查看实时日志
docker logs -f subscribe-gateway
docker logs -f subscribe-admin

# 重启服务
docker compose restart

# 完整重建
docker compose up -d --build

# 检查分析统计缓存是否在自动更新
docker exec subscribe-admin sh -lc 'ls -lh /etc/nginx/subscribe/stats_cache.json'

# 检查 gateway / admin 容器健康状态，异常时自动显示最近日志
bash healthcheck.sh --wait 30

# 查看后台维护任务日志（统计预热 / 告警检查 / 日志清理）
docker exec subscribe-admin sh -lc 'tail -50 /var/log/subscribe/maintenance.log'

# 进入 gateway 容器调试
docker exec -it subscribe-gateway sh
```

---

## 更新日志

- 2026-07-15：IP 情报升级为 `ip-api`、`ipwho.is`、`GeoJS`、`RIPEstat` 四源交叉验证，新增置信度、一致性、BGP 路由网段和每日调用预算；旧单源缓存会自动渐进刷新。
- 2026-07-15：新增运维与后台安全加固：容器级健康检查、安装/更新就绪等待和失败退出；后台统一经过 Secret Path 路由，强化 Session Cookie、登录会话更新、安全响应头及 FastCGI 大响应缓冲；修复无匹配 Docker volume 时更新器提前退出的问题。
- 2026-07-15：完成稳定性修复包：Token 黑名单接入 nginx 实际拦截；后台上游和订阅路径跨重启持久化；严格校验 IP/CIDR；修复 chmod-only 更新冲突；恢复后台 IP 情报补全；降低 Clash 客户端误报；消除统计缓存空窗；云 IP 拉取失败时保留旧规则。
- 2026-07-14：告警历史导入体验收尾，兼容当前页导出文件，预览显示文件名、大小、导出时间、来源筛选、关键词和备份新旧提示；导入成功后自动回到全部状态、全部时间和第 1 页。
- 2026-07-13：告警历史管理增强，支持状态/时间/关键词筛选、分页、页码跳转、筛选摘要标签、单条删除、复制单条/当前页/摘要，以及当前页和全量 JSON 导出。
- 2026-07-13：告警历史导入导出增强，当前页导出附带筛选上下文和页码范围，导出文件名包含状态、时间范围和页码；导入前展示预览并避免误选旧备份或空记录文件。
- 2026-07-13：告警推送系统上线，支持 Webhook / Telegram、立即检查、静默时段、阈值配置、严格 / 均衡 / 安静预设、去重、清空记录和重置去重。
- 2026-07-13：系统设置页新增告警状态、历史摘要、静默摘要和历史覆盖时间，方便确认后台检查、去重、失败原因和保留上限。
- 2026-07-13：移动端排版优化，告警搜索区自动换行，告警记录操作按钮下沉，分析页和右侧子界面更适合手机使用。
- 2026-07-13：分析页新增“用户画像”分类，并在 admin 容器启动后自动后台预热统计缓存，降低大日志场景下的页面等待和 500 风险。
- 2026-07-13：脚本/扫描器报告接入 IP 情报查询，补充国家/地区/城市、ASN、运营商、代理/VPN/机房/移动网络画像，并缓存查询结果。
- 2026-07-13：admin 容器新增后台维护任务，默认保留最近 14 天访问日志，可通过 `LOG_RETENTION_DAYS` 调整或关闭。
- 2026-07-12：维护仓库切换为 `zona-165/SubSieve`，README 部署地址同步更新。
- 2026-07-12：日志页“仅订阅相关”改为读取系统设置里的订阅路径，支持后台修改路径后同步过滤。
- 2026-07-12：分析页增强可疑 IP 展示，新增风险评分、高危成立依据、紧凑排版和一键封禁/白名单操作。
- 2026-07-12：分析页新增“脚本/扫描器拉取订阅”报告，从日志识别空 UA、通用 clash、curl、wget、python 等可疑拉取行为，支持复制报告和一键封禁 IP。
- 2026-07-12：前端 API 请求失败提示更细化，便于定位 HTTP 500、JSON 解析失败等后台问题。
- 2026-06-13：修复存储型 XSS 与 Nginx 配置注入两处高危漏洞，加固后台输入过滤与转义。
