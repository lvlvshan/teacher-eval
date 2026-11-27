# Docker 部署说明

镜像内容：`php:8.1-apache` + SQLite + zip/gd/mbstring/curl/opcache，项目根目录即镜像内 `/var/www/html`，Web 根目录为 `public/`。

## 0. 镜像加速（国内网络）

Docker 守护进程已配置 registry 镜像（`docker.1ms.run` / `docker.xuanyuan.me`），见 `/etc/docker/daemon.json`；基础镜像通过镜像站拉取。

Dockerfile 内 apt 源也已切到阿里云镜像站（`mirrors.aliyun.com`），构建时不再直连 `deb.debian.org`，可显著缩短编译时间。如需换源，修改 `Dockerfile` 顶部的 `sed` 规则后重新 build。

## 1. 构建与启动

```bash
cd teacher-eval
docker compose up -d --build
```

默认监听 **8080**（容器使用 host 网络，Apache 直接看到学生真实 IP，按 IP 自动进班依赖这一点）。

```bash
docker compose ps
docker compose logs -f teacher-eval
curl -I http://127.0.0.1:8080/
```

- 学生端：`http://<服务器IP>:8080/`
- 后台：`http://<服务器IP>:8080/admin`，初始账号 `admin / admin123`

## 2. 可配置环境变量

在项目目录新建 `.env`（compose 读取）或直接 `export`：

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `HTTP_PORT` | `8080` | 对外端口，也是 Apache 监听端口 |
| `APP_DEBUG` | `false` | 生产务必 false |
| `EVAL_SECRET` | 自动生成 | IP/UA 哈希密钥，首次启动写入 `runtime/.secret`；**上线后不要改** |
| `SESSION_TTL` | `30` | 单次作答分钟数 |
| `INSTALL_DEMO` | `0` | 首次启动是否灌演示数据（1 = 有示例教员/班级/周期） |

## 3. 数据持久化与备份

数据库、导出文件、日志都在命名卷 `eval_runtime`（容器内 `/var/www/html/runtime`），重建容器不丢数据。

```bash
# 备份（每天一次即可，镜像内没有 sqlite3 命令，用 PHP 的 VACUUM INTO）
docker exec teacher-eval php -r '$d=new PDO("sqlite:/var/www/html/runtime/teacher_eval.sqlite"); $d->exec("VACUUM INTO \"/tmp/backup.sqlite\"");'
docker cp teacher-eval:/tmp/backup.sqlite ./teacher_eval-$(date +%F).sqlite
# 一并保存密钥与导出文件
docker cp teacher-eval:/var/www/html/runtime/.secret ./secret-$(date +%F)

# 恢复：停容器（不要 down）→ 覆盖库文件 → 启动
docker stop teacher-eval
docker cp ./teacher_eval-2026-11-18.sqlite teacher-eval:/var/www/html/runtime/teacher_eval.sqlite
docker start teacher-eval
docker exec -u root teacher-eval chown 33:33 /var/www/html/runtime/teacher_eval.sqlite
```

## 4. 常用运维

> 从旧版本升级后首次启动，`entrypoint.sh` 会自动执行一次幂等的 `php think install`，为 `submission` 表补齐 `ip / ua / mac / mac_source / device_code / excluded` 字段（老记录这些字段为空或 0，页面显示「—」/默认计入），无需手工改库。`ALTER TABLE ADD COLUMN` 只改表结构，不重写、不删除现有数据。

> 后台「投票详情」的 MAC 来自服务端 ARP 邻居表（`/proc/net/arp`）。容器使用 `network_mode: host`，因此读到的就是宿主机 ARP 表；**只有服务器与机房电脑在同一二层网段才能拿到真实 MAC**，跨网段/代理/NAT 显示「—」。明文 IP 只存数据库、仅后台可见，导出仍用掩码。

```bash
# 改管理员密码
docker exec -it teacher-eval php think admin:password admin '新的强密码'

# 手动执行周期自动切换（建议宿主机 crontab 每 5 分钟）
docker exec -u www-data teacher-eval php think period:switch

# 查看容器内数据库
docker exec -it teacher-eval php -r '$d=new PDO("sqlite:/var/www/html/runtime/teacher_eval.sqlite");foreach($d->query("select id,name,state from period") as $r)echo json_encode($r,JSON_UNESCAPED_UNICODE),"\n";'
```

宿主机 crontab 示例：

```cron
*/5 * * * * docker exec -u www-data teacher-eval php think period:switch >> /var/log/teacher-eval-switch.log 2>&1
```

## 5. 升级（有生产数据时）

升级前务必先备份数据库和 `runtime/.secret`：

```bash
TS=$(date +%Y%m%d-%H%M%S)
mkdir -p /root/teacher-eval-backups/$TS
docker exec teacher-eval php -r '$d=new PDO("sqlite:/var/www/html/runtime/teacher_eval.sqlite"); $d->exec("VACUUM INTO \"/tmp/pre-upgrade.sqlite\"");'
docker cp teacher-eval:/tmp/pre-upgrade.sqlite /root/teacher-eval-backups/$TS/teacher_eval.sqlite
docker cp teacher-eval:/var/www/html/runtime/.secret /root/teacher-eval-backups/$TS/secret
```

确认项目根目录 `.env` 里固定了端口，避免重启后回落到 8080：

```bash
cd /opt/teacher-eval
printf 'HTTP_PORT=8082\n' > .env
```

在服务器项目目录替换代码后构建并切换：

```bash
docker compose build
docker stop teacher-eval
docker compose up -d
docker compose ps
```

- `runtime` 是独立卷，升级不会覆盖数据库。
- 启动时 `php think install` 会自动补齐新增字段；`submission.excluded` 默认 0，历史答卷保持计入，只有管理员手动点「剔除」才会变成 1。
- 旧镜像保留（如 `teacher-eval:1.0`），新代码只增加字段，回滚旧镜像仍可正常读取。
- 修改问卷模板后如未生效：

```bash
docker exec teacher-eval rm -f /var/www/html/runtime/temp/*.php
```

## 6. 常见问题

| 现象 | 处理 |
| --- | --- |
| 8080 被占用 | 改 `HTTP_PORT=8090 docker compose up -d`，entrypoint 会同步改 Apache 监听端口 |
| 学生看不到本班 | 检查该周期「名单」页里本班 IP 段是否包含机房网段 |
| 所有学生都显示成 172.x | 没使用 host 网络；`network_mode: host` 必须保留 |
| 500 / 模板编译失败 | `docker exec teacher-eval rm -f runtime/temp/*.php`；看 `docker compose logs` |
| 时区不对 | 镜像已设 `Asia/Shanghai`，检查宿主机时间 |
