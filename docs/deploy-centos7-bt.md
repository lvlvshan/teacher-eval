# 部署到 CentOS 7 + 宝塔面板

目标：一台校园内网服务器，学生用机房电脑通过浏览器访问，后台由教务人员在办公电脑上操作。

## 1. 环境准备

- CentOS 7.6 / 7.9（64 位）
- 宝塔面板 7.x / 8.x
- PHP **8.1**（7.4 也可以；不要用 5.x）
- Nginx 1.20+
- 不需要 MySQL（默认 SQLite）

宝塔「软件商店」安装 Nginx、PHP 8.1 后，在 PHP 设置里确认这些扩展已启用：

```
pdo_sqlite  sqlite3  mbstring  zip  fileinfo  gd  curl  openssl
```

扩展装好后在「PHP 设置 → 配置修改」里把 `memory_limit` 调到 `256M`，`max_execution_time` 调到 `120`（导出 Excel 需要）。

## 2. 上传代码

把整个项目上传到：

```
/www/wwwroot/teacher-eval
```

不要上传本地 `runtime/` 下的演示数据库和缓存（可以传空目录）。如果使用 Git：

```bash
cd /www/wwwroot
git clone <仓库地址> teacher-eval
cd teacher-eval
composer install --no-dev --optimize-autoloader
```

服务器上没装 Composer 时，可以直接把本地带 `vendor/` 的目录打包上传（`vendor` 必须一起传）。

## 3. 建站

宝塔「网站」→「添加站点」：

| 项 | 值 |
| --- | --- |
| 域名 | 例如 `eval.school.local`（内网 DNS 或直接用 IP） |
| 根目录 | `/www/wwwroot/teacher-eval/public` |
| PHP 版本 | 8.1 |
| 数据库 | 不创建 |

> 根目录必须指到 `public`，不要指到项目根，否则 `.env`、数据库文件、源码都能被直接下载。

## 4. 伪静态 / Nginx 配置

宝塔站点「设置」→「伪静态」，填 ThinkPHP 规则：

```nginx
location / {
    if (!-e $request_filename){
        rewrite ^(.*)$ /index.php?s=$1 last;
    }
}
```

再在「配置文件」里补几条安全限制（放在 `server { }` 内、`include enable-php-81.conf;` 之前或之后都行）：

```nginx
# 这些目录不允许外部访问
location ~ ^/(runtime|vendor|app|config|route|view|extend)/ {
    deny all;
    return 404;
}

# 隐藏文件
location ~ /\.(env|git|htaccess) {
    deny all;
    return 404;
}

# 静态资源缓存
location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
    expires 7d;
    access_log off;
}
```

改完重载 Nginx：

```bash
nginx -t && nginx -s reload
```

### 如果前面还有 CDN / 反向代理

程序只信任 `REMOTE_ADDR`，不解析客户端可伪造的 `X-Forwarded-For`。若前面确实有代理，需要让 Nginx 用 realip 模块把真实地址写进 `REMOTE_ADDR`：

```nginx
set_real_ip_from 10.0.0.0/8;        # 代理服务器地址段
real_ip_header X-Forwarded-For;
real_ip_recursive on;
```

没有代理时**不要**加这段，否则学生可以伪造 IP 绕过白名单。

## 5. 目录权限

```bash
cd /www/wwwroot/teacher-eval
chown -R www:www .
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R 775 runtime
```

`runtime/` 必须可写，SQLite 库、模板缓存、导出临时文件都在里面。

如果宝塔开了 `open_basedir`，确认包含项目目录（宝塔默认按站点根目录限制，通常没问题；若报 `open_basedir restriction`，在 PHP 设置里把 `/www/wwwroot/teacher-eval/` 加进去）。

## 6. 初始化

```bash
cd /www/wwwroot/teacher-eval
/www/server/php/81/bin/php think install
```

输出应类似：

```
[ok] 表结构已创建
[ok] 创建管理员 admin / admin123
[ok] 初始化 IP 规则 127.0.0.1/32
初始化完成。后台账号 admin / admin123
```

> 生产环境不要加 `--demo`。`--demo` 只用于本地演示，会写入假数据。

数据库文件生成在 `runtime/teacher_eval.sqlite`。

## 7. 首次登录与安全设置

1. 立刻改密码：

   ```bash
   /www/server/php/81/bin/php think admin:password admin '换成强密码'
   ```

   不给第二个参数会随机生成一个并打印出来。

2. 访问 `http://服务器IP/admin` 登录，先维护教员/班级/课程，再新建测评周期：

   - 选择「教员 + 班级 + 课程」；
   - 填写**本班 IP 段**（如机房网段 `192.168.10.0/24`，多个用逗号分隔）；
   - 点「创建并开始测评」。

   学生打开 `http://服务器IP/` 时，系统按其 IP 匹配这些网段并自动进入本班。IP 段可随后在「名单」页调整；填错网段会导致学生看不到该班调研。

3. 后台没有 IP 白名单，建议单独限制（二选一）：

   **方案 A（推荐）：后台单独端口**

   在站点配置里再加一个 server 块，只监听内网端口 `8080`，用防火墙把 8080 限制给办公网：

   ```nginx
   server {
       listen 8080;
       server_name _;
       root /www/wwwroot/teacher-eval/public;
       index index.php;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }
       location ~ \.php$ {
           include enable-php-81.conf;
       }
   }
   ```

   ```bash
   firewall-cmd --permanent --add-rich-rule='rule family="ipv4" source address="10.1.0.0/16" port port="8080" protocol="tcp" accept'
   firewall-cmd --reload
   ```

   学生端继续用 80 端口，后台走 `http://服务器IP:8080/admin`。

   **方案 B：按来源 IP 拒绝 /admin**

   用 `geo` 生成标记，再在 `location ^~ /admin` 里 `return 403`（`if` 里只用 `return` 是安全的）：

   ```nginx
   geo $admin_allowed {
       default 0;
       10.1.0.0/16     1;
       192.168.10.0/24 1;
   }

   server {
       # ...原有配置...

       location ^~ /admin {
           if ($admin_allowed = 0) {
               return 403;
           }
           try_files $uri $uri/ /index.php?$query_string;
       }
   }
   ```

   注意不要把 `allow/deny` 写在带 `try_files` 的 location 里——内部重写到 `index.php` 后会重新匹配 location，访问控制可能失效。

4. 生成新的 `EVAL.SECRET` 写进 `.env`（上线前定好，之后不要改）：

   ```bash
   php -r "echo bin2hex(random_bytes(16));"
   ```

5. `.env` 里 `APP_DEBUG = false`。

6. 建议开 HTTPS（宝塔「SSL」→ Let's Encrypt 或上传校内证书）。

## 8. 计划任务（自动开闭周期）

宝塔「计划任务」→「添加任务」：

| 项 | 值 |
| --- | --- |
| 任务类型 | Shell 脚本 |
| 执行周期 | 每 5 分钟 |
| 脚本内容 | 见下 |

```bash
cd /www/wwwroot/teacher-eval && /www/server/php/81/bin/php think period:switch >> runtime/period_switch.log 2>&1
```

作用：把到点的 `pending` 周期置为 `open`，把过期的 `open` 周期置为 `closed`。归档仍需人工在后台点。

> 后台手动改状态也可以，计划任务是兜底，避免忘记开关。

## 9. 备份

数据只有两个地方：

- `runtime/teacher_eval.sqlite`（含 `-wal`、`-shm`）
- `runtime/export/` 导出的 Excel（可选，能重新生成）

最稳妥的备份方式（需要 `sqlite3` 命令，没有就 `yum install -y sqlite`）：

```bash
cd /www/wwwroot/teacher-eval
mkdir -p /www/backup/teacher-eval
sqlite3 runtime/teacher_eval.sqlite ".backup '/www/backup/teacher-eval/eval-$(date +%F).sqlite'"
```

宝塔「计划任务」加一条每天执行的 Shell 任务即可。恢复时停一次 PHP-FPM、替换库文件、再启动。

## 10. 上线前检查清单

- [ ] 站点根目录是 `public`
- [ ] 伪静态已配，学生端 `/`、后台 `/admin` 都能打开
- [ ] `runtime/` 可写，`/runtime/` 外部不可访问（直接访问应 404/403）
- [ ] `APP_DEBUG = false`
- [ ] 管理员密码已改
- [ ] `EVAL.SECRET` 已换成随机值
- [ ] 创建测评时已为每个班级填写机房 IP 段（如 `192.168.10.0/24`）
- [ ] 计划任务 `period:switch` 已生效（看 `runtime/period_switch.log`）
- [ ] 在本月周期里建好名单、场次、问卷，状态设为「进行中」
- [ ] 机房电脑实测：能进列表、能提交、提交后列表变「已提交」
- [ ] 后台能导出 xlsx，Excel 打开正常
- [ ] 备份任务已配

## 11. 常见问题

| 现象 | 原因 / 处理 |
| --- | --- |
| 学生端 404 | 伪静态没配；或 Nginx 把 `/c/` 当静态路径处理（检查 `location /` 的 `try_files`/rewrite） |
| `no such table: admin` | 没执行 `php think install`，或库文件路径不对（看 `runtime/teacher_eval.sqlite` 是否存在），或 `runtime` 不可写 |
| 500，提示模板编译失败 | 清 `runtime/temp/*.php` 后重试；确认 `runtime` 属主是 `www` |
| 所有学生都提示「当前网络不在允许范围」 | IP 规则里只有 `127.0.0.1`，补校园网 CIDR；或前面有代理但没配 realip（记到的是代理 IP） |
| 后台登录后又跳回登录页 | PHP session 目录不可写（默认 `runtime/session`），或 `config/session.php` 里 `expire` 被改小 |
| 导出的 Excel 打不开/为空 | PHP `memory_limit` 太小（建议 256M）、`max_execution_time` 太短（建议 120）；年度数据多时先在后台按单个教员导出 |
| 导出时提示 `ZipArchive` 不存在 | 没装 `zip` 扩展 |
| 学生提交后提示「会话已失效」 | `config/session.php` 的 `expire` 被调小过。当前为 7200 秒（2 小时），大于单次作答上限 30 分钟 + 宽限，请保持 |
| 学生提交时提示「database is locked」 | 同一秒并发写太多。SQLite 已开 WAL，40 人机房一般没问题；如遇到，把场次拆成两批，或在 Nginx/PHP 层限制并发 |
| 时间差 8 小时 | `.env` 的 `DEFAULT_TIMEZONE` 与实际不符，或 PHP `date.timezone` 未设；CentOS 上 `timedatectl set-timezone Asia/Shanghai` |
| 归档后想改名单 | 超管在「周期」页解冻，必须填原因；解冻会保留原快照与审计日志 |

## 12. 升级

```bash
cd /www/wwwroot/teacher-eval
# 备份 runtime 后再替换代码
composer install --no-dev --optimize-autoloader
rm -rf runtime/temp/*.php
chown -R www:www .
```

`.env` 与 `runtime/` 不要被覆盖。若改了 `EVAL.SECRET` 或问卷模板结构，先确认对历史数据的影响。
