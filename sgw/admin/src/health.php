<?php
$healthy = is_readable('/var/www/html/index.php')
    && is_writable('/etc/nginx/subscribe')
    && is_writable('/var/log/subscribe');

http_response_code($healthy ? 200 : 503);
header('Content-Type: text/plain; charset=utf-8');
echo $healthy ? "ok\n" : "unhealthy\n";
