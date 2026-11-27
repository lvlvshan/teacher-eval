# 学员满意度测评系统 — 生产镜像（Apache + PHP 8.1 + SQLite）
FROM php:8.1-apache

ENV TZ=Asia/Shanghai \
    APACHE_DOCUMENT_ROOT=/var/www/html/public

# 使用国内镜像站加速 apt（Debian 源）
RUN for f in /etc/apt/sources.list /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources; do \
      if [ -f "$f" ]; then \
        sed -i \
          -e 's|https\?://deb.debian.org/debian|https://mirrors.aliyun.com/debian|g' \
          -e 's|https\?://security.debian.org/debian-security|https://mirrors.aliyun.com/debian-security|g' "$f"; \
      fi; \
    done

# 运行依赖：SQLite、mbstring、zip（PhpSpreadsheet）、gd、curl、opcache
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libzip-dev \
      libsqlite3-dev \
      libpng-dev \
      libjpeg62-turbo-dev \
      libfreetype6-dev \
      libonig-dev \
      libcurl4-openssl-dev \
      curl \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j2 pdo_sqlite mbstring zip gd curl opcache \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Apache 监听 8080（配合 host 网络，保留学生真实 IP）
RUN sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-eval.ini
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
 && chmod +x /usr/local/bin/entrypoint.sh

# 项目代码（vendor 已随仓库提供，无需 composer install）
COPY . /var/www/html

RUN mkdir -p runtime/temp runtime/log runtime/cache runtime/session runtime/export \
 && chown -R www-data:www-data runtime

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
