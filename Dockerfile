FROM php:8.2-apache

WORKDIR /var/www/html

# 1. 创建模板目录
RUN mkdir -p /templates

# 2. 创建默认配置文件模板
RUN echo '<?php' > /templates/config.php.example && \
    echo '// 默认配置模板' >> /templates/config.php.example && \
    echo '// 将此文件复制为 config.php 并修改' >> /templates/config.php.example && \
    echo 'return [' >> /templates/config.php.example && \
    echo '    "emby" => ["host" => "http://127.0.0.1:8096", "api_key" => "your-api-key"],' >> /templates/config.php.example && \
    echo '    "user" => ["invite_file" => "/data/invite_codes.json"],' >> /templates/config.php.example && \
    echo '    "cleanup" => ["log_file" => "/logs/inactive_cleanup_log.txt"]' >> /templates/config.php.example && \
    echo '];' >> /templates/config.php.example

# 3. 复制应用代码
COPY *.php ./
COPY templates/ ./templates/

# 4. 安装cron
RUN apt-get update && apt-get install -y cron && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# 5. 创建cron任务
RUN echo "# Emby Panel 定时清理任务" > /etc/cron.d/emby && \
    echo "# 每天凌晨3点执行" >> /etc/cron.d/emby && \
    echo "0 3 * * * www-data cd /var/www/html && /usr/local/bin/php cron_cleanup.php >> /logs/cron_cleanup.log 2>&1" >> /etc/cron.d/emby && \
    echo "" >> /etc/cron.d/emby && \
    chmod 0644 /etc/cron.d/emby

# 6. 启用Apache模块
RUN a2enmod rewrite

# 7. 创建初始化脚本 - 修复版
RUN echo '#!/bin/bash' > /init.sh && \
    echo '# 初始化脚本 - 在容器启动时运行' >> /init.sh && \
    echo '' >> /init.sh && \
    echo 'echo "正在初始化 Emby Panel..."' >> /init.sh && \
    echo '' >> /init.sh && \
    echo '# 1. 确保数据目录存在' >> /init.sh && \
    echo 'mkdir -p /data /logs' >> /init.sh && \
    echo '' >> /init.sh && \
    echo '# 2. 【关键修复】设置目录权限给www-data' >> /init.sh && \
    echo '# 容器以root启动，可以修改挂载目录权限' >> /init.sh && \
    echo 'WEB_USER="www-data"' >> /init.sh && \
    echo 'WEB_GROUP="www-data"' >> /init.sh && \
    echo '' >> /init.sh && \
    echo '# 先尝试设置所有者' >> /init.sh && \
    echo 'chown -R $WEB_USER:$WEB_GROUP /data /logs 2>/dev/null || true' >> /init.sh && \
    echo '' >> /init.sh && \
    echo '# 确保目录可写（NAS兼容）' >> /init.sh && \
    echo 'chmod 777 /data /logs 2>/dev/null || true' >> /init.sh && \
    echo 'echo "✅ 已设置/data和/logs权限为www-data可写"' >> /init.sh && \
    echo '' >> /init.sh && \
    echo '# 3. 创建初始文件（如果不存在）' >> /init.sh && \
    echo 'if [ ! -f /data/invite_codes.json ]; then' >> /init.sh && \
    echo '    echo "创建邀请码文件..."' >> /init.sh && \
    echo '    echo "[]" > /data/invite_codes.json' >> /init.sh && \
    echo '    chown $WEB_USER:$WEB_GROUP /data/invite_codes.json 2>/dev/null || true' >> /init.sh && \
    echo '    chmod 644 /data/invite_codes.json' >> /init.sh && \
    echo 'fi' >> /init.sh && \
    echo '' >> /init.sh && \
    echo 'if [ ! -f /data/registration_status.json ]; then' >> /init.sh && \
    echo '    echo "创建注册状态文件..."' >> /init.sh && \
    echo '    echo "{\"mode\":\"invite\",\"open_registration\":{\"enabled\":false,\"max_users\":100,\"current_count\":0,\"account_valid_days\":30,\"is_permanent\":false,\"enabled_at\":null,\"closed_at\":null,\"auto_close_on_full\":true}}" > /data/registration_status.json' >> /init.sh && \
    echo '    chown $WEB_USER:$WEB_GROUP /data/registration_status.json 2>/dev/null || true' >> /init.sh && \
    echo '    chmod 644 /data/registration_status.json' >> /init.sh && \
    echo 'fi' >> /init.sh && \
    echo '' >> /init.sh && \
    echo 'if [ ! -f /data/emby_users.json ]; then' >> /init.sh && \
    echo '    echo "创建用户记录文件..."' >> /init.sh && \
    echo '    echo "{}" > /data/emby_users.json' >> /init.sh && \
    echo '    chown $WEB_USER:$WEB_GROUP /data/emby_users.json 2>/dev/null || true' >> /init.sh && \
    echo '    chmod 644 /data/emby_users.json' >> /init.sh && \
    echo 'fi' >> /init.sh && \
    echo '' >> /init.sh && \
    echo '# 4. 创建日志文件' >> /init.sh && \
    echo 'touch /logs/cleanup_log.txt' >> /init.sh && \
    echo 'touch /logs/inactive_cleanup_log.txt' >> /init.sh && \
    echo 'touch /logs/error.log' >> /init.sh && \
    echo 'touch /logs/cron_cleanup.log' >> /init.sh && \
    echo 'touch /logs/expiry_cleanup_log.txt' >> /init.sh && \
    echo 'chown $WEB_USER:$WEB_GROUP /logs/*.log /logs/*.txt 2>/dev/null || true' >> /init.sh && \
    echo 'chmod 644 /logs/*.log /logs/*.txt 2>/dev/null || true' >> /init.sh && \
    echo '' >> /init.sh && \
    echo '# 5. 设置Web目录权限' >> /init.sh && \
    echo 'chown -R $WEB_USER:$WEB_GROUP /var/www/html 2>/dev/null || true' >> /init.sh && \
    echo 'chmod -R 755 /var/www/html' >> /init.sh && \
    echo '' >> /init.sh && \
    echo '# 6. 如果 config.php 不存在，从模板创建' >> /init.sh && \
    echo 'if [ ! -f /var/www/html/config.php ]; then' >> /init.sh && \
    echo '    echo "⚠️  警告: config.php 不存在"' >> /init.sh && \
    echo '    echo "从模板创建..."' >> /init.sh && \
    echo '    cp /templates/config.php.example /var/www/html/config.php' >> /init.sh && \
    echo '    chown $WEB_USER:$WEB_GROUP /var/www/html/config.php' >> /init.sh && \
    echo '    chmod 644 /var/www/html/config.php' >> /init.sh && \
    echo 'fi' >> /init.sh && \
    echo '' >> /init.sh && \
    echo '# 7. 设置时区' >> /init.sh && \
    echo 'if [ ! -z "$TZ" ]; then' >> /init.sh && \
    echo '    echo "设置时区: $TZ"' >> /init.sh && \
    echo '    ln -sf /usr/share/zoneinfo/$TZ /etc/localtime' >> /init.sh && \
    echo '    echo "$TZ" > /etc/timezone' >> /init.sh && \
    echo 'fi' >> /init.sh && \
    echo '' >> /init.sh && \
    echo '# 8. 启动cron服务' >> /init.sh && \
    echo 'echo "启动cron服务..."' >> /init.sh && \
    echo 'cron' >> /init.sh && \
    echo 'sleep 2' >> /init.sh && \
    echo 'if pgrep cron > /dev/null; then' >> /init.sh && \
    echo '    echo "✅ cron服务启动成功"' >> /init.sh && \
    echo 'else' >> /init.sh && \
    echo '    echo "❌ cron服务启动失败，尝试重新启动..."' >> /init.sh && \
    echo '    cron' >> /init.sh && \
    echo 'fi' >> /init.sh && \
    echo '' >> /init.sh && \
    echo 'echo "初始化完成！"' >> /init.sh && \
    chmod +x /init.sh

# 8. 创建启动脚本 - 简化版（不使用gosu）
RUN echo '#!/bin/bash' > /start.sh && \
    echo 'set -e' >> /start.sh && \
    echo '' >> /start.sh && \
    echo '# 运行初始化脚本' >> /start.sh && \
    echo '/init.sh' >> /start.sh && \
    echo '' >> /start.sh && \
    echo '# 显示启动信息' >> /start.sh && \
    echo 'echo "=== Emby Panel 启动信息 ==="' >> /start.sh && \
    echo 'echo "容器内用户: $(whoami)"' >> /start.sh && \
    echo 'echo "Apache运行用户: www-data（通过Apache配置）"' >> /start.sh && \
    echo 'echo "数据目录权限: $(ls -ld /data)"' >> /start.sh && \
    echo 'echo "日志目录权限: $(ls -ld /logs)"' >> /start.sh && \
    echo 'echo "=========================="' >> /start.sh && \
    echo '' >> /start.sh && \
    echo '# 启动Apache（Apache会自动降权到www-data运行）' >> /start.sh && \
    echo 'exec apache2-foreground "$@"' >> /start.sh && \
    chmod +x /start.sh

# 9. 设置Apache运行用户
RUN echo 'export APACHE_RUN_USER=www-data' >> /etc/apache2/envvars && \
    echo 'export APACHE_RUN_GROUP=www-data' >> /etc/apache2/envvars && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80

# 容器以root启动（为了运行init.sh修复权限）
CMD ["/start.sh"]
