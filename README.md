# 学员对教员满意度测评系统

[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.2.5%2B-777bb4.svg)](https://www.php.net/)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-6.1-1e90ff.svg)](https://www.thinkphp.cn/)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ed.svg)](docker-compose.yml)
[![Stars](https://img.shields.io/github/stars/lvlvshan/teacher-eval.svg)](https://github.com/lvlvshan/teacher-eval/stargazers)

学生每月匿名给任课教员打分，系统按机房 IP 自动识别班级，后台自动统计并导出 Excel。单校区部署，基于 **ThinkPHP 6.1 + SQLite**，支持 Docker 一键运行。

> 适合学校/培训机构在内网或校园网中使用：学生免登录、免扫码，打开网址即可作答；管理员在后台完成周期管理、问卷配置、异常复核与报表导出。

## 目录

- [功能特性](#功能特性)
- [技术栈](#技术栈)
- [快速开始](#快速开始)
- [使用流程](#使用流程)
- [统计与异常口径](#统计与异常口径)
- [数据与隐私](#数据与隐私)
- [常用命令](#常用命令)
- [配置（.env）](#配置env)
- [目录结构](#目录结构)
- [备份与升级](#备份与升级)
- [文档](#文档)
- [安全说明](#安全说明)
- [License](#license)

## 功能特性

- **学生端免登录**：按机房 IP 段自动识别班级，无需班级码、账号或扫码；命中多个班级时由学生选择本班。
- **三层时间窗**：测评周期 → 机房场次 → 单次作答（默认 30 分钟）；超时自动提交并标记 `timeout`，仍计入统计。
- **匿名防重**：不采集姓名、学号；同一台设备（IP + 浏览器 UA 指纹）对同一份问卷只能提交一次。
- **问卷可配置**：默认 4 个维度、20 道量表题（1~5 分）+ 2 道开放题，支持修改题干、维度、排序与权重。
- **异常提示而非默认排除**：过快、全题同分、超时等答卷自动打标但**默认计入统计**，后台只做提示；确认是脏数据时可人工「剔除」，也可随时恢复。
- **按设备查看投票详情**：后台以「一台机房电脑 ≈ 一名学生」汇总，查看明文 IP、ARP MAC、UA、逐题得分、开放题原文及同设备其他提交。
- **完整报表导出**：教员年度得分表（12 个月 × 20 题 + 总分 + 参评人数）、全部教员 ZIP、年度汇总包（7 个工作表）。
- **归档只读**：周期归档后固化名单、IP 规则与问卷快照并生成校验哈希，严格只读；超级管理员可填原因解冻。
- **审计日志**：登录、复核/剔除、查看投票详情、导出等敏感操作均记录审计日志。

## 技术栈

| 项目 | 说明 |
| --- | --- |
| 后端 | PHP 8.1（兼容 7.2.5+），ThinkPHP 6.1 |
| 数据库 | SQLite（默认，WAL 模式，零配置） |
| 模板 | ThinkPHP 内置模板引擎 + 原生 CSS |
| 导出 | PhpSpreadsheet 1.25（xlsx / zip） |
| 部署 | Docker（`php:8.1-apache`）或传统 LNMP / 宝塔面板 |

## 快速开始

### Docker（推荐）

```bash
git clone https://github.com/lvlvshan/teacher-eval.git
cd teacher-eval

# 可选：固定端口（不设置则默认 8080）
printf 'HTTP_PORT=8082\n' > .env

docker compose up -d --build
docker compose ps
```

- 学生端：`http://<服务器IP>:<端口>/`
- 管理后台：`http://<服务器IP>:<端口>/admin`
- 初始账号：`admin / admin123`，**首次登录后请立即修改**

```bash
docker exec -it teacher-eval php think admin:password admin '你的新密码'
```

容器使用 `network_mode: host`，以便 Apache 直接看到学生真实 IP；数据库、导出文件、日志都保存在命名卷 `eval_runtime`，重建容器不会丢数据。

> 默认监听 8080。若该端口已被占用，请通过项目根目录 `.env` 的 `HTTP_PORT` 修改，避免重启后回落到 8080。

### 本地开发

```bash
# 依赖已随仓库提供（vendor），如需重装可执行 composer install
php think install --demo          # 建库 + 演示数据
php -S 127.0.0.1:8010 -t public public/router.php
```

- 后台：<http://127.0.0.1:8010/admin>（`admin / admin123`）
- 学生端：<http://127.0.0.1:8010/>（演示数据已将本机 IP 绑定到示例班级，打开即自动进入）

生产环境不要使用 `--demo`，直接执行 `php think install` 初始化空库。

## 使用流程

1. **维护基础数据**：在后台录入教员、班级、课程（教员支持 Excel 导入）。
2. **新建周期**：选择「教员 + 班级 + 课程」，填写本班机房 IP 段（CIDR），设置起止时间并开始测评。
3. **调整名单与 IP**：在周期「名单」页设置必评/选评/不参评，或调整各班 IP 段。
4. **排场次（可选）**：按班级设置机房作答时段；不排场次则整个周期内均可作答。
5. **检查问卷**：每月问卷独立，可修改题目、维度、开放题。
6. **学生作答**：学生打开学生端网址，按 IP 自动进入本班问卷。
7. **查看与导出**：在「看板」「明细」「投票详情」查看结果，在「导出」生成 Excel。
8. **结束并归档**：周期结束后归档，归档后只读；需要修改时由超级管理员填原因解冻。

## 统计与异常口径

| flag | 含义 | 是否计入统计 |
| --- | --- | --- |
| `ok` | 正常提交 | 计入 |
| `timeout` | 超时自动提交 | 计入 |
| `fast` | 作答时长 < 20 秒 | **默认计入，仅提示** |
| `uniform` | 20 题全部同分 | **默认计入，仅提示** |

- 计入条件：`submission.excluded = 0`（默认值），异常答卷同样参与均分、排名与导出。
- 管理员确认某条是脏数据时，可在「明细」或「投票详情」点「剔除统计」（`excluded = 1`），之后可随时「恢复计入」。
- `reviewed` 仅表示人工处理过，不再决定是否计入。
- 每题月均分 = 当月该题所有计入答卷的算术平均；维度均分 = 维度内题目平均；总分 = 20 道量表题得分之和（满分 100）。

## 数据与隐私

- 不采集姓名、学号等身份信息，学生端全程匿名。
- 设备指纹使用 `IP + UA` 的 HMAC 哈希；Excel 导出使用 `/24` 掩码 IP。
- 明文 IP、UA、MAC 仅后台「投票详情」可见，查看行为写入审计日志。
- MAC 由服务端读取 ARP 邻居表获得，仅当服务器与机房电脑处于同一二层网段时有效；跨网段/代理/NAT 显示「—」。
- `EVAL_SECRET` 用于 IP/UA 哈希，**上线后不要修改**，否则历史设备指纹与归档哈希会失效。

## 常用命令

```bash
php think install                       # 建表 + 初始化管理员（空库）
php think install --demo                # 建表 + 演示数据
php think period:switch                 # 按时间自动开/闭周期（建议 crontab 每 5 分钟）
php think admin:password admin 新密码    # 重置管理员密码，留空则随机生成
```

## 配置（.env）

```ini
APP_DEBUG = false            ; 生产必须 false

[APP]
DEFAULT_TIMEZONE = Asia/Shanghai
IP_WHITELIST = true          ; 是否启用按 IP 段自动进班

[DATABASE]
TYPE = sqlite                ; 当前建表语句为 SQLite 方言
PREFIX =

[EVAL]
SECRET = 换成随机长字符串     ; 用于 IP/UA 哈希，上线后不要再改
SESSION_TTL = 30             ; 单次作答分钟数

[SESSION]
expire = 7200                ; 秒，应大于单次作答上限 + 宽限
```

## 目录结构

```text
teacher-eval/
├─ app/
│  ├─ command/           # install / period:switch / admin:password
│  ├─ controller/
│  │  ├─ Evaluate.php     # 学生端
│  │  └─ admin/           # 后台：登录、周期、问卷、基础数据、报表导出
│  ├─ middleware/         # AdminAuth（后台鉴权）、ShareView（公共变量）
│  ├─ service/            # 安装、问卷、IP、周期、作答、统计、导出
│  └─ common.php          # 公共函数（哈希、IP、状态文案等）
├─ config/                # 数据库、路由、控制台、会话
├─ route/app.php          # 全部路由
├─ view/
│  ├─ layout/             # base（学生端）、admin（后台）、style（共用 CSS）
│  ├─ evaluate/           # 首页 / 列表 / 问卷 / 完成 / 提示
│  └─ admin/              # 后台各页
├─ public/                # 站点根目录（index.php、router.php）
├─ docker/                # Dockerfile 相关配置与启动脚本
├─ docs/                  # 快速上手、使用手册、部署文档
├─ runtime/               # SQLite 库、模板缓存、导出文件（勿对外暴露）
└─ vendor/                # Composer 依赖（已随仓库提供）
```

## 备份与升级

```bash
# 备份（容器内没有 sqlite3 命令，使用 PHP 的 VACUUM INTO）
docker exec teacher-eval php -r '$d=new PDO("sqlite:/var/www/html/runtime/teacher_eval.sqlite"); $d->exec("VACUUM INTO \"/tmp/backup.sqlite\"");'
docker cp teacher-eval:/tmp/backup.sqlite ./teacher_eval-$(date +%F).sqlite
docker cp teacher-eval:/var/www/html/runtime/.secret ./secret-$(date +%F)
```

升级前请先备份数据库和 `runtime/.secret`；启动时 `entrypoint.sh` 会自动执行幂等的 `php think install` 补齐新增字段。`runtime` 是独立卷，升级不会覆盖数据库。

## 文档

| 文档 | 内容 |
| --- | --- |
| [docs/quick-start.md](docs/quick-start.md) | 管理员最短上手流程、日常运维、备份、常见问题 |
| [docs/user-manual.md](docs/user-manual.md) | 完整使用手册：术语、学生/教师/管理员操作、统计口径、导出解读 |
| [docs/deploy-docker.md](docs/deploy-docker.md) | Docker 构建、配置、备份恢复、升级与排错 |
| [docs/deploy-centos7-bt.md](docs/deploy-centos7-bt.md) | CentOS 7 + 宝塔 + Nginx 部署 |

## 安全说明

- **后台账号**：初始 `admin / admin123`，上线第一件事是修改密码；后台没有注册入口。
- **后台暴露面**：学生端有 IP 白名单，后台没有。建议在 Nginx 或防火墙层对 `/admin` 再加内网/办公网限制。
- **部署定位**：本项目按校园网/内网系统设计，不建议直接暴露在公网。
- **防重是软约束**：依赖「IP 段 + 时间窗 + IP/UA 指纹 + 事后异常提示」，不是强身份认证；换设备、清缓存或改 UA 仍可能绕过，因此仍需管理员抽查异常答卷。
- **依赖安全**：`composer.json` 中设置了 `config.policy.advisories.block = false` 以兼容当前 ThinkPHP 6.1 依赖；如后续升级框架，应移除该豁免。

## License

本项目基于 [Apache-2.0](LICENSE) 协议开源。框架 `vendor/topthink/*` 及其余第三方依赖遵循各自许可证，ThinkPHP 的版权声明见 [LICENSE.txt](LICENSE.txt)。
