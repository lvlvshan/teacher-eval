#!/bin/sh
# 容器首次启动：生成 .env、初始化数据库、修正 runtime 权限
set -e

cd /var/www/html

# ---------- 1. Apache 监听端口（默认 8080） ----------
HTTP_PORT="${HTTP_PORT:-8080}"
sed -i "s/^Listen .*/Listen ${HTTP_PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${HTTP_PORT}>/" /etc/apache2/sites-available/000-default.conf

# ---------- 2. runtime 目录 ----------
mkdir -p runtime/temp runtime/log runtime/cache runtime/session runtime/export

# ---------- 3. 哈希密钥（EVAL.SECRET） ----------
# 优先环境变量；否则复用 runtime/.secret；首次启动随机生成并持久化
if [ -z "${EVAL_SECRET:-}" ]; then
    if [ -f runtime/.secret ]; then
        EVAL_SECRET="$(cat runtime/.secret)"
    else
        EVAL_SECRET="$(php -r 'echo bin2hex(random_bytes(24));')"
    fi
fi
printf '%s' "$EVAL_SECRET" > runtime/.secret
chmod 600 runtime/.secret 2>/dev/null || true

# ---------- 4. 生成 .env ----------
cat > .env <<EOF
APP_DEBUG = ${APP_DEBUG:-false}
APP_TRACE = false

[APP]
DEFAULT_TIMEZONE = Asia/Shanghai
TRUST_PROXY = false
IP_WHITELIST = ${IP_WHITELIST:-true}

[DATABASE]
TYPE = sqlite
PREFIX =

[LANG]
default_lang = zh-cn

[EVAL]
SECRET = ${EVAL_SECRET}
SESSION_TTL = ${SESSION_TTL:-30}
EOF

# ---------- 5. 建库 / 老库补列 ----------
# install 是幂等的（CREATE TABLE IF NOT EXISTS + 缺列自动 ALTER），每次启动执行以应用结构升级。
# INSTALL_DEMO=1 只在首次建库时灌演示数据。
if [ ! -s runtime/teacher_eval.sqlite ] && [ "${INSTALL_DEMO:-0}" = "1" ]; then
    php think install --demo
else
    php think install
fi

# ---------- 6. 权限 ----------
chown -R www-data:www-data runtime

exec "$@"
