# 学员对教员满意度测评系统

学生每月匿名给任课教员打分，后台自动统计并导出 Excel。单校区部署，ThinkPHP 6.1 + SQLite，PHP 7.2+ / 8.x 都能跑。

> 🚀 只想快速上手：看 [docs/quick-start.md](docs/quick-start.md)（四步开通测评、看结果、备份、常见问题）。
>
> 📘 详细的使用说明（术语、学生/教师/管理员操作指引、统计口径、导出解读、常见问题）见 [docs/user-manual.md](docs/user-manual.md)。

## 功能

- **学生端免登录**：学生打开统一网址，系统按机房 IP 自动识别班级并列出可参与的调研（无需班级码 / 登录 / 扫码）。
- **准入控制**：校园网 IP 段白名单（CIDR），在创建测评时按班级填写，同时决定学生首页看到哪些班级的调研；归档后只读。
- **三层时间窗**：测评周期 → 机房场次 → 单次作答 30 分钟；超时自动提交并标记 `timeout`（仍计入统计）。
- **匿名防重**：不记姓名学号；同一台设备（IP + 浏览器 UA 指纹）对同一份问卷只能提交一次；异常答卷自动打标但默认计入，确认是脏数据时可人工剔除。
- **问卷按 docx 原表**：4 个维度 20 道量表题（1~5 分）+ 2 道开放题。
- **统计口径**：每题当月平均分、维度均分、总分（20 题之和，满分 100）。
- **导出**：教员年度得分表（12 个月 × 20 题 + 总分 + 参评人数）、全部教员 ZIP、年度汇总包（7 个工作表）。

## 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 7.2 ~ 8.2（推荐 8.1） |
| 扩展 | `pdo_sqlite`、`sqlite3`、`mbstring`、`zip`、`fileinfo`、`gd`、`curl`、`openssl` |
| 数据库 | SQLite（默认，零配置）；MySQL 5.7+ 可选，见下文 |
| 磁盘 | `runtime/` 可写（数据库、缓存、导出文件都在这里） |

## 目录结构

```
teacher-eval/
├─ app/
│  ├─ command/           # install / period:switch / admin:password
│  ├─ controller/
│  │  ├─ Evaluate.php     # 学生端
│  │  └─ admin/           # 后台：登录、周期、问卷、基础数据、报表导出
│  ├─ middleware/        # AdminAuth（后台鉴权）、ShareView（公共变量）
│  ├─ service/           # 业务：安装、问卷、IP、周期、作答、统计、导出
│  └─ common.php         # 公共函数（哈希、IP、状态文案等）
├─ config/               # 数据库、路由、控制台
├─ route/app.php         # 全部路由
├─ view/
│  ├─ layout/            # base（学生端）、admin（后台）、style（共用 CSS）
│  ├─ evaluate/          # 首页 / 列表 / 问卷 / 完成 / 提示
│  └─ admin/             # 后台各页
├─ public/               # 站点根目录（index.php、router.php）
└─ runtime/              # SQLite 库、模板缓存、导出文件（勿对外暴露）
```

## 本地快速开始

```bash
# 1. 安装依赖（已有 vendor 可跳过）
composer install

# 2. 建库 + 演示数据
php think install --demo

# 3. 起开发服务器
php -S 127.0.0.1:8010 -t public public/router.php
```

- 后台：<http://127.0.0.1:8010/admin>，初始账号 `admin / admin123`
- 学生端：<http://127.0.0.1:8010/>（演示数据已把本机 IP 绑定到 JAVA2601，打开即自动进入）

生产环境**不要**用 `--demo`：

```bash
php think install
```

> 演示数据会写入示例教员/班级/课程/周期/场次，并且第一场场次覆盖当前时间，方便马上试跑。

## 每月操作流程（后台）

1. **基础数据**（首次配置一次）：教员、班级、课程。
2. **周期**：新建本月周期，选「教员 + 班级 + 课程」并填写**本班 IP 段**，填开始/结束时间，点「创建并开始测评」；系统会生成问卷与班级名单。
3. **名单**：核对本月被测评的班级-课程-教员，可标记「必评 / 选评 / 不参评」，填写不参评原因；也可在这里调整各班 IP 段。
4. **场次**（可选）：按班级排机房时段。不排场次时，全周期内本班随时可评；排了场次就只能在对应时段作答。
5. **问卷**：每月问卷独立，默认按 docx 模板生成 20 题 + 2 题开放题；可改题干、维度、排序。
6. **结束**：随时可在「周期」点「结束问卷」，然后在「汇总」查看结果。
7. **归档**：本月结束后归档。归档会固化名单、IP 规则、问卷快照并生成校验哈希，之后严格只读。需要改动时由超级管理员填原因解冻。
8. **导出**：见下节。

## 学生端

- `/` 按 IP 自动列出可参与的班级调研；只命中一个班级时直接进入该班列表。
- 列表显示本班本月要评价的教员和已完成状态，倒计时为本次作答截止时间。
- 问卷：20 道 1~5 分题（可键盘 `1~5` 打分、`↑↓` 切题），2 道选填开放题；本地保留草稿，断网刷新不丢。
- 提交后不可修改；同一台设备对同一份问卷重复提交会被拦截。
- 时间到会自动提交，标记为「超时提交」，仍计入统计。

## 时间窗规则

```
周期窗口：period.starts_at ≤ now ≤ period.ends_at 且 state = open
场次窗口：本班当天的 eval_slot（未排场次则不限）
单次作答：min(30 分钟, 场次剩余时间)，到期自动提交
```

学生端每次进入会复用 10 分钟宽限期内的同一会话；重新开会话不会解除已有提交记录（唯一键是 `周期 + 测评对象 + IP 哈希 + UA 哈希`）。

## 异常标记与复核

| flag | 含义 | 是否计入统计 |
| --- | --- | --- |
| `ok` | 正常提交 | 计入 |
| `timeout` | 超时自动提交 | 计入 |
| `fast` | 作答时长 < 20 秒 | 默认计入，仅提示 |
| `uniform` | 20 题全部同分 | 默认计入，仅提示 |

异常答卷默认参与统计，看板/列表只显示「异常提示」标签。如确认某条是脏数据，管理员可在「明细」或「投票详情」点「剔除统计」（`excluded = 1`）；之后可随时「恢复计入」。`reviewed` 仅表示人工处理过，不再决定是否计入。

## 统计口径

- 单校区，不含校区字段。
- 每题月均分 = 当月该题所有计入答卷的算术平均。
- 维度均分 = 该维度题目的算术平均。
- 总分 = 20 道量表题得分之和，满分 100（非加权时即 20 题平均分 × 20）。
- 计入答卷 = `excluded = 0` 的全部提交（含 `fast` / `uniform` / `timeout`）。

## Excel 导出

后台「导出」页有三个入口：

1. **单个教员年度得分表**：一个 xlsx，A 列分类、B 列事件（20 题固定顺序），C~N 为 1~12 月，另有「总分」「参评人数」两行。
2. **全部教员 ZIP**：按教员各生成一份年度表后打包。
3. **年度汇总包**（一个 xlsx，7 页）：汇总、明细、分布、趋势、场次、优点与建议、配置存档（归档信息 + 哈希）。
   - 「优点与建议」把开放题单独成页，按教员/班级归类。
   - 汇总包可选「附带归档快照」。

导出文件写在 `runtime/export/`，下载后可按需清理。

## 常用命令

```bash
php think install                # 建表 + 初始化管理员（空库）
php think install --demo         # 建表 + 演示数据
php think period:switch          # 按时间自动开/闭周期（配 crontab）
php think admin:password admin 新密码   # 重置管理员密码，留空则随机生成
```

## 配置（.env）

```ini
APP_DEBUG = false            ; 生产必须 false

[APP]
DEFAULT_TIMEZONE = Asia/Shanghai
IP_WHITELIST = true          ; 学生端是否启用 IP 段准入（按班级 IP 段自动进班）

[DATABASE]
TYPE = sqlite                ; 改 mysql 即切换连接
PREFIX =

[EVAL]
SECRET = 换成随机长字符串     ; 用于 IP/UA 哈希，上线后不要再改
SESSION_TTL = 30             ; 单次作答分钟数

[SESSION]
expire = 7200                ; 秒，大于单次作答上限（30 分钟）+ 宽限
```

> 修改 `EVAL.SECRET` 会让历史 `ip_hash/ua_hash` 与归档哈希对不上，上线前定好。

## 数据库

默认 SQLite，文件 `runtime/teacher_eval.sqlite`，WAL 模式，几十万条答卷也够用。

要换 MySQL：`.env` 设 `TYPE = mysql` 并补 `HOSTNAME / DATABASE / USERNAME / PASSWORD / HOSTPORT`，然后在 MySQL 里建库，再把 `app/service/InstallService.php` 里的建表语句改成 MySQL 语法（当前是 SQLite 方言，含 `AUTOINCREMENT`、`TEXT` 时间列）。除非有集中运维要求，建议保持 SQLite。

## Docker 部署（推荐）

```bash
cd teacher-eval
docker compose up -d --build
```

默认监听 `8080`，使用 host 网络以保留学生真实 IP（按 IP 自动进班必需）。数据库在命名卷 `eval_runtime`，重建容器不丢数据。详细配置、备份、镜像加速与运维见 [docs/deploy-docker.md](docs/deploy-docker.md)。

## 部署到 CentOS 7 + 宝塔

见 [docs/deploy-centos7-bt.md](docs/deploy-centos7-bt.md)，包含 Nginx 伪静态、PHP 扩展、目录权限、计划任务、真实 IP、备份与排错。

## 安全说明

- **后台账号**：初始 `admin / admin123`，上线第一件事就是 `php think admin:password admin 新密码`。后台没有注册入口。
- **后台暴露面**：学生端有 IP 白名单，后台没有。建议在 Nginx 或宝塔面板给 `/admin` 再加一层内网/办公网限制。
- **匿名性**：不采集姓名、学号；IP 只存 HMAC 哈希与 `/24` 掩码，用于事后清洗。
- **依赖安全公告**：ThinkPHP 6.1 曾被 Composer 安全公告拦截，`composer.json` 里设置了 `config.policy.advisories.block = false` 才能安装。生产环境请把它当内网系统用，不要直接暴露在公网；后续如能升级框架，应优先升级并移除该豁免。
- **防重是软约束**：靠「校园网 IP 段 + 时间窗 + 设备（IP+UA）指纹 + 事后异常清洗」，不是强身份认证。换电脑或清缓存/改 UA 可以绕，所以异常答卷必须人工看一遍。

## 自测

开发期用过的端到端脚本（不在仓库内）：登录 → 按 IP 进班 → 列表 → 20 题作答 → 提交 → 防重 → 明细复核 → 看板 → 三种导出（含 xlsx 回读校验）→ 归档/解冻 → IP 拒绝 → 定时切换。当前全部通过。
