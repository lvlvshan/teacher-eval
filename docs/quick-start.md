# 快速使用说明

面向管理员的最短上手流程。详细口径见 [user-manual.md](user-manual.md)，部署与备份见 [deploy-docker.md](deploy-docker.md)。

## 1. 打开系统

| 入口 | 地址 |
| --- | --- |
| 学生端 | `http://<服务器IP>:<端口>/` |
| 管理后台 | `http://<服务器IP>:<端口>/admin` |

示例（假设服务器 IP 为 `192.168.1.10`、端口 `8080`）：

- 学生端：`http://192.168.1.10:8080/`
- 后台：`http://192.168.1.10:8080/admin`
- 初始账号：`admin` / `admin123`

> 首次登录后先改密码：
> `docker exec -it teacher-eval php think admin:password admin 你的新密码`

## 2. 管理员四步开通一次测评

**第 1 步：准备基础数据（已有可跳过）**

后台依次进入「教员」「班级」「课程」，把本次要评的教员、班级、课程录入（可直接 Excel 导入教员）。

**第 2 步：新建周期**

进入「周期」→ 填写年月/名称 → 选择「教员 + 班级 + 课程」→ 填写该班级的**机房 IP 段**（CIDR，如 `192.168.5.0/24`，多个用逗号或换行分隔）→ 点击「创建并开始测评」。

- 保存后立即开始，学生即可作答。
- 开始时间默认是当前时刻，截止时间默认是当月最后一天 23:59。
- 一个网段可以绑多个班级；同一班级也可以填多个网段。

> **班级和 IP 在哪里绑定？** 系统没有独立的「班级-IP」菜单，绑定是在**周期**里做的：
> 1. 创建周期时，表单下方就有「本班 IP 段」输入框（和教员、班级、课程一起填）；
> 2. 建好后，到「周期」列表点该周期行的「名单」按钮（`/admin/periods/{周期id}/targets`），页面中部有「本班 IP 段」卡片，每个班级一行输入框，填好后点「保存 IP 段」。
>
> 注意：「班级」菜单里只维护班级名称/年级/状态，**不填 IP**。另外，「名单」页里某个班级只有在已经添加了「教员+班级+课程」测评对象后才会出现；如果看不到该班级输入框，请先用「添加单条」添加一条对象。已归档的周期不能改，需先在「周期」页解冻。

**第 3 步（可选）：细项调整**

- 「名单」：调整各班 IP 段，设置 `必评 / 选评 / 不参评`。
- 「场次」：限定机房作答时段；不排场次则整个周期都能答。
- 「问卷」：修改题目、维度、开放题（默认 4 维度 20 题 + 2 道开放题）。

**第 4 步：学生作答**

学生用机房浏览器打开学生端网址，无需登录/扫码：

- IP 命中 1 个班级 → 自动进入；
- 命中多个班级 → 首页选择本班；
- 未命中 → 提示联系管理员检查 IP 段。

一台设备对同一份问卷只能提交一次；单次作答 30 分钟，倒计时结束自动提交（仍计入）。

## 3. 查看结果与导出

| 菜单 | 用途 |
| --- | --- |
| 看板 | 计入提交数、异常提示数、各教员均分 |
| 明细 | 逐条提交记录（网段、耗时、状态）；异常默认计入，确认是脏数据时可「剔除」 |
| 投票详情 | **按设备（≈一名学生）查看**：明文 IP、MAC、UA、提交份数、逐题得分、开放题原文、同设备其他提交 |
| 导出 | 单教员年度表 xlsx、全部教员 ZIP、年度汇总包 |

关于「投票详情」的 MAC：浏览器无法读取 MAC，系统由服务器读取 ARP 邻居表获取，**仅当服务器与机房电脑在同一二层网段时有效**；跨网段/代理/NAT 显示「—」属正常。明文 IP/MAC 只在后台可见，导出仍使用掩码。

周期截止后进入「汇总」查看结果，月末可「归档」，归档后严格只读（超级管理员可填原因解冻）。

## 4. 日常运维

> 服务器项目目录 `/opt/teacher-eval/.env` 里固定 `HTTP_PORT=8082`。否则执行 `docker compose up -d` 时端口会回落到默认 8080，与现有 nginx 冲突。

```bash
# 改管理员密码
docker exec -it teacher-eval php think admin:password admin 新密码

# 周期状态自动切换（服务器 crontab 已配置每 5 分钟执行一次）
docker exec -u www-data teacher-eval php think period:switch

# 备份数据库（不停机）
docker exec teacher-eval php -r '$d=new PDO("sqlite:/var/www/html/runtime/teacher_eval.sqlite"); $d->exec("VACUUM INTO \"/var/www/html/runtime/backup.sqlite\"");'
docker cp teacher-eval:/var/www/html/runtime/backup.sqlite ./teacher_eval-$(date +%F).sqlite
```

> 数据在命名卷 `teacher-eval_eval_runtime` 里，重建容器不丢。**不要执行 `docker compose down -v`**，那会删除卷。备份时建议把 `runtime/.secret` 一起保存，否则换卷后设备指纹哈希会失效。

### 清空测试答卷（保留周期、名单、IP、问卷）

后台没有一键清空按钮，用下面命令即可（执行前会自动备份到 `/root/teacher-eval-backups/`）：

```bash
TS=$(date +%Y%m%d-%H%M%S)
mkdir -p /root/teacher-eval-backups
docker exec teacher-eval php -r "\$d=new PDO('sqlite:/var/www/html/runtime/teacher_eval.sqlite'); \$d->exec('VACUUM INTO \"/tmp/bk-$TS.sqlite\"');"
docker cp "teacher-eval:/tmp/bk-$TS.sqlite" "/root/teacher-eval-backups/teacher_eval-$TS.sqlite"

docker exec teacher-eval php -r '
$d = new PDO("sqlite:/var/www/html/runtime/teacher_eval.sqlite");
$d->beginTransaction();
$d->exec("DELETE FROM answer");
$d->exec("DELETE FROM submission");
$d->exec("DELETE FROM eval_session");
$d->exec("DELETE FROM sqlite_sequence WHERE name IN (\"answer\",\"submission\",\"eval_session\")");
$d->commit();
'
docker exec teacher-eval sh -c 'rm -f /var/www/html/runtime/export/*'
```

只想清审计日志时：`DELETE FROM audit_log;`。想连周期/问卷一起重来，改用 `docker compose down` 后删除卷（会丢全部数据，谨慎）。

## 5. 常见问题

| 现象 | 处理 |
| --- | --- |
| 学生看不到本班 | 检查该周期「名单」里本班 IP 段是否包含机房网段 |
| 找不到绑定班级和 IP 的界面 | 不在「班级」菜单，而在「周期」→ 创建表单的「本班 IP 段」，或该周期「名单」页的「本班 IP 段」卡片 |
| 学生都显示成网关 IP | 确认容器使用 `network_mode: host`，否则拿不到真实 IP |
| 投票详情里 MAC 为空 | 服务器与客户端不在同一二层网段，正常现象 |
| 异常提示很多 | 异常默认已计入统计，仅作提示；确认是脏数据时到「明细」或「投票详情」剔除 |
| 8082 打不开 | 该端口被占用时改 `HTTP_PORT=其他端口 docker compose up -d --build` |
