# SubSieve

订阅清洗网关 + 可视化管理后台，Docker Compose 一键部署。

订阅请求先经过黑名单、云厂商 IP 识别、UA 过滤、速率限制等多层拦截，通过后才反代到机场后端，防止订阅链接被扫描或滥用。

---

## 目录结构

```
sgw/
├── setup.sh                 ← 首次部署向导（一键运行）
├── update.sh                ← 已安装用户更新脚本
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
- 如需自动申请 SSL 证书：提前把域名解析到本机（A 记录），且 **80 端口未被占用**
- 不要装其他任何玩意儿

### 一键部署

```bash
git clone https://github.com/zona-165/SubSieve.git
cd SubSieve/sgw
chmod +x setup.sh
./setup.sh
```

向导会依次询问：

| 提示 | 说明 |
|------|------|
| 机场地址 | 你的机场面板域名，如 `panel.example.com`，不含 `https://` |
| 订阅路径 | 默认 `/api/v1/client/subscribe`，直接回车即可 |
| 订阅端口 | 机场后端监听端口，默认 `443` |
| 网关端口 | 客户端订阅链接对外暴露的端口，默认 `443` |
| 域名（SSL） | 输入已解析到本机的域名，脚本自动调用 acme.sh 申请证书；留空则手动放证书 |

部署完成后，访问信息会打印在终端，同时保存到 `DEPLOY_INFO.txt`。
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

脚本会自动 git pull 最新代码、保留 `.env`、重新构建容器。

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

---

## 后台功能

| 选项卡 | 功能 |
|--------|------|
| 日志 | 今日/全部日志切换，按 IP / 状态码 / Token / UA 过滤；“仅订阅相关”会自动读取系统设置里的订阅路径；Token 全文展示并支持一键复制，一键封禁 IP，删除7日前旧日志 |
| 分析 | Top IP、Top Token（支持复制）、可疑 Token、可疑 IP、脚本/扫描器拉取订阅、用户画像、可疑 UA；可疑 IP 会显示风险评分和高危成立依据；脚本/扫描器报告会查询 IP 地理位置、ASN、运营商和代理/机房画像，并支持复制报告、一键封禁 IP |
| 封禁UA | 添加/删除自定义封禁 UA 关键词，大小写不敏感，立即 reload nginx 生效 |
| 白名单 | 增删、导入白名单 IP，立即生效；白名单 IP 跳过所有拦截 |
| 黑名单 | 增删黑名单 IP（nginx deny 444），增删后立即生效 |
| Token黑名单 | 封禁指定订阅 Token，命中后返回 403，支持添加备注 |
| 设置 | 修改网站标题、后台账号密码、机场上游地址、订阅路径、网关对外端口；订阅路径变更后日志筛选会同步更新；支持 Webhook / Telegram 高危告警推送 |

---

## 拦截层说明

订阅请求按以下顺序过滤，通过全部拦截后才反代到机场后端：

1. **黑名单**：精确 IP 封禁，`deny` 返回 444
2. **云厂商 IP**：自动识别Ucloud、阿里云、腾讯云、字节、华为云、Google、AWS、Azure、DigitalOcean 等，返回 403
3. **可疑 UA**：空 UA、curl、wget、python、Go、Java 等爬虫特征，返回 403
4. **自定义封禁 UA**：管理员在后台手动添加的 UA 关键词，返回 403
5. **Token 黑名单**：命中封禁 Token 返回 403
6. **速率限制**：每分钟 20 次，burst 5，超出返回 429
7. **白名单**：白名单 IP 跳过上述所有拦截，直接放行

云厂商 IP 库每周自动更新，更新日志见容器内 `/var/log/subscribe/update_cloud_geo.log`。

脚本/扫描器报告中的 IP 情报默认通过 `ip-api.com` 查询，结果缓存到 `/etc/nginx/subscribe/ip_intel_cache.json`，缓存有效期 7 天；接口不可用时会显示“查询失败”，但不会影响后台统计。

后台容器启动后会每分钟自动预热分析统计缓存，写入 `/etc/nginx/subscribe/stats_cache.json`。分析页默认读取缓存，避免日志量增长后每次打开页面都扫描大日志；首次部署和后续更新都会自动启用，无需手动配置。当前统计默认基于最近 30000 行网关日志生成。

告警推送默认关闭，可在后台「系统设置 → 告警推送」中开启。当前支持 Webhook 和 Telegram；后台维护任务每分钟读取统计缓存，发现脚本/扫描器拉取订阅、可疑 IP、多 IP 拉取同一 Token 等高危事件时推送。告警阈值可在后台调整，包括扫描器评分、可疑 IP 评分、Token 被多少个 IP 拉取才告警、同一事件去重分钟数、历史保留条数；也可一键套用「严格 / 均衡 / 安静」预设。可开启静默时段，静默期间命中的事件会写入历史并计入去重，但不会外发 Webhook / Telegram，后台会显示最近静默摘要、历史状态分布和历史覆盖时间。去重状态保存在 `/etc/nginx/subscribe/alert_state.json`，最近检查状态和推送历史保存在 `/etc/nginx/subscribe/alert_history.json`，最近记录可选择每页显示 10 / 25 / 50 条，支持分页查看，并可快速跳到首页、末页或指定页码，也可按状态和时间范围筛选，支持按 IP、Token 片段、错误原因等关键词搜索，筛选条件可一键重置；记录会同时显示精确时间和相对时间，单条记录、当前页和当前页摘要均可复制文本报告，可删除单条展示记录，也可导出当前页或全量历史 JSON 留档；导入前会预览条数、状态分布和时间范围，确认后只替换展示历史，不修改告警配置和去重状态。建议先使用「测试推送」确认渠道可用；需要立刻验证当前统计是否会触发告警时，可点击「立即检查」手动执行一次巡检；如需重新推送同一事件，可在告警状态区点击「重置去重」。

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

# 查看后台维护任务日志（统计预热 / 告警检查 / 日志清理）
docker exec subscribe-admin sh -lc 'tail -50 /var/log/subscribe/maintenance.log'

# 进入 gateway 容器调试
docker exec -it subscribe-gateway sh
```

---

## 更新日志

- 2026-07-12：维护仓库切换为 `zona-165/SubSieve`，README 部署地址同步更新。
- 2026-07-12：日志页“仅订阅相关”改为读取系统设置里的订阅路径，支持后台修改路径后同步过滤。
- 2026-07-12：分析页增强可疑 IP 展示，新增风险评分、高危成立依据、紧凑排版和一键封禁/白名单操作。
- 2026-07-12：分析页新增“脚本/扫描器拉取订阅”报告，从日志识别 clash、curl、wget、python 等 UA 拉取订阅 Token 的行为，支持复制报告和一键封禁 IP。
- 2026-07-13：脚本/扫描器报告接入 IP 情报查询，补充国家/地区/城市、ASN、运营商、代理/VPN/机房/移动网络画像，并缓存查询结果。
- 2026-07-13：分析页新增“用户画像”分类，并在 admin 容器启动后自动后台预热统计缓存，降低大日志场景下的页面等待和 500 风险。
- 2026-07-13：admin 容器新增后台维护任务，默认保留最近 14 天访问日志，可通过 `LOG_RETENTION_DAYS` 调整或关闭。
- 2026-07-13：新增高危告警推送，支持 Webhook / Telegram，并对同一事件做 1 小时去重。
- 2026-07-13：系统设置页新增告警状态和最近推送记录，方便确认后台检查、去重和失败原因。
- 2026-07-13：告警推送新增「立即检查」，可在后台手动触发一次高危事件巡检并刷新状态。
- 2026-07-13：告警状态区新增清空记录和重置去重，便于测试推送和重复复核同一高危事件。
- 2026-07-13：告警规则支持后台调整阈值和去重分钟数，适配不同日志量与风险偏好。
- 2026-07-13：告警规则新增严格 / 均衡 / 安静预设，一键切换灵敏度。
- 2026-07-13：告警推送新增静默时段，夜间等时段只记录不外发。
- 2026-07-13：告警状态区新增静默摘要，汇总最近静默事件数量和最后一条事件。
- 2026-07-13：告警状态区新增导出记录，可下载告警历史 JSON。
- 2026-07-13：告警状态区新增导入记录，支持迁移或回放告警历史。
- 2026-07-13：告警最近记录新增状态筛选，可只看已推送、静默或失败记录。
- 2026-07-13：告警最近记录新增关键词搜索，支持按 IP、Token 片段、错误原因等快速定位。
- 2026-07-13：告警最近记录支持显示 10 / 25 / 50 条，筛选和搜索范围随显示数量变化。
- 2026-07-13：告警最近记录新增单条复制，便于转发或留档。
- 2026-07-13：告警最近记录新增复制当前筛选结果，便于批量转发排查信息。
- 2026-07-13：告警最近记录新增删除单条展示记录，不影响告警去重状态。
- 2026-07-13：告警最近记录新增分页查看，筛选和搜索由后端按完整历史计算。
- 2026-07-13：告警历史导入新增预览确认，导入前展示条数、状态分布和时间范围。
- 2026-07-13：告警历史保留条数支持后台配置，默认 200 条，范围 50-1000。
- 2026-07-13：告警状态区新增历史摘要，展示保留总数、已推送、静默、失败和上限。
- 2026-07-13：告警最近记录新增时间范围筛选，支持今天、近 24 小时和近 7 天。
- 2026-07-13：告警最近记录新增一键重置筛选，快速回到默认视图。
- 2026-07-13：告警最近记录新增导出当前页 JSON，并区分当前页导出和全量导出。
- 2026-07-13：告警最近记录新增复制当前页摘要，便于快速转发筛选条件和状态分布。
- 2026-07-13：告警最近记录新增相对时间显示，复制文本也会附带距今信息。
- 2026-07-13：告警状态区新增历史覆盖时间，展示当前保留历史的最早和最新记录时间。
- 2026-07-13：告警最近记录分页新增首页和末页跳转，历史较多时翻页更快。
- 2026-07-13：告警最近记录分页新增页码输入跳转，可直接跳到指定页。
- 2026-07-12：前端 API 请求失败提示更细化，便于定位 HTTP 500、JSON 解析失败等后台问题。
- 2026-06-13：修复存储型 XSS 与 Nginx 配置注入两处高危漏洞，加固后台输入过滤与转义。
