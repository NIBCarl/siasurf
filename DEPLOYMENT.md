# SiaSurf Deployment Guide

## Prerequisites

### Server Requirements
- PHP 8.2+
- MySQL 8.0+ or PostgreSQL 14+
- Redis 6.0+
- Node.js 18+
- Composer 2.x
- Nginx or Apache
- SSL Certificate (Let's Encrypt)

### Required PHP Extensions
- bcmath
- ctype
- curl
- dom
- fileinfo
- gd
- iconv
- intl
- json
- libxml
- mbstring
- openssl
- pdo
- pdo_mysql or pdo_pgsql
- phar
- posix
- redis
- simplexml
- tokenizer
- xml
- xmlwriter
- zip

## Production Environment Setup

### 1. Server Provisioning

Using Laravel Forge + DigitalOcean (recommended):

```bash
# Create droplet on DigitalOcean
# Ubuntu 22.04 LTS
# 2GB RAM / 1 CPU (minimum)
# 25GB SSD

# Laravel Forge will handle:
# - PHP installation
# - Nginx configuration
# - SSL certificates
# - Database setup
# - Redis setup
# - Supervisor for queues
```

### 2. Environment Configuration

```bash
# Clone repository
git clone https://github.com/NIBCarl/siasurf.git
cd siasurf

# Install dependencies
composer install --no-dev --optimize-autoloader
npm ci
npm run build

# Set permissions
chmod -R 755 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### 3. Environment Variables

```env
APP_NAME=SiaSurf
APP_ENV=production
APP_KEY=base64:GENERATE_WITH_php_artisan_key:generate
APP_DEBUG=false
APP_URL=https://siasurf.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=siasurf
DB_USERNAME=siasurf_user
DB_PASSWORD=STRONG_PASSWORD

# Redis (for cache, sessions, queues)
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Session & Cache
SESSION_DRIVER=redis
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis

# Broadcasting (Laravel Reverb)
REVERB_APP_KEY=GENERATE_WITH_php_artisan_reverb:generate
REVERB_APP_SECRET=GENERATE_SECRET
REVERB_APP_ID=GENERATE_ID
REVERB_HOST=reverb.siasurf.com
REVERB_PORT=443
REVERB_SCHEME=https

# Mail (Mailgun recommended)
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=mg.siasurf.com
MAILGUN_SECRET=KEY_FROM_MAILGUN
MAIL_FROM_ADDRESS=noreply@siasurf.com
MAIL_FROM_NAME="SiaSurf"

# File Storage (AWS S3)
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=siasurf-production
AWS_URL=https://cdn.siasurf.com

# Payments (PayMongo)
PAYMONGO_PUBLIC_KEY=pk_live_...
PAYMONGO_SECRET_KEY=sk_live_...
PAYMONGO_WEBHOOK_SECRET=whsec_...

# Security
APP_FORCE_HTTPS=true
```

### 4. Database Setup

```bash
# Create database
mysql -u root -p
CREATE DATABASE siasurf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'siasurf_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON siasurf.* TO 'siasurf_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# Run migrations
php artisan migrate --force

# Seed initial data
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan db:seed --class=SurfSpotSeeder
php artisan db:seed --class=AdminSeeder
```

### 5. Optimization Commands

```bash
# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache

# Optimize autoloader
composer dump-autoload --optimize

# Warm cache
php artisan cache:warm
```

### 6. Queue Workers

Create Supervisor configuration:

```ini
; /etc/supervisor/conf.d/siasurf-worker.conf
[program:siasurf-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /home/forge/siasurf/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=forge
numprocs=2
redirect_stderr=true
stdout_logfile=/home/forge/siasurf/storage/logs/worker.log
stopwaitsecs=3600
```

```bash
# Start workers
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start siasurf-worker:*
```

### 7. Laravel Reverb WebSocket Server

```ini
; /etc/supervisor/conf.d/siasurf-reverb.conf
[program:siasurf-reverb]
process_name=%(program_name)s
command=php /home/forge/siasurf/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=forge
redirect_stderr=true
stdout_logfile=/home/forge/siasurf/storage/logs/reverb.log
```

### 8. Scheduler

```bash
# Add to crontab
* * * * * cd /home/forge/siasurf && php artisan schedule:run >> /dev/null 2>&1
```

### 9. Nginx Configuration

```nginx
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name siasurf.com;
    root /home/forge/siasurf/public;

    # SSL
    ssl_certificate /etc/nginx/ssl/siasurf.com.crt;
    ssl_certificate_key /etc/nginx/ssl/siasurf.com.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # WebSocket proxy for Reverb
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

# HTTP to HTTPS redirect
server {
    listen 80;
    listen [::]:80;
    server_name siasurf.com;
    return 301 https://$server_name$request_uri;
}
```

### 10. Deployment Script

```bash
#!/bin/bash
# deploy.sh

set -e

echo "Starting deployment..."

# Pull latest code
git pull origin main

# Install dependencies
composer install --no-dev --optimize-autoloader
npm ci
npm run build

# Clear caches
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Run migrations
php artisan migrate --force

# Recreate caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Restart services
sudo supervisorctl restart siasurf-worker:*
sudo supervisorctl restart siasurf-reverb

# Clear expired cache
php artisan cache:prune-stale-tags

echo "Deployment completed!"
```

## Post-Deployment Checklist

### Immediate Checks
- [ ] Application loads without errors
- [ ] Login/registration works
- [ ] SSL certificate valid
- [ ] Database connections working
- [ ] Redis connections working
- [ ] File uploads working
- [ ] Email sending working

### Feature Verification
- [ ] Booking creation flow
- [ ] Payment processing (test transaction)
- [ ] Waiver signing
- [ ] QR code generation
- [ ] Real-time notifications
- [ ] Admin dashboard accessible

### Performance Checks
- [ ] Page load times < 3 seconds
- [ ] Database query optimization
- [ ] Cache warming completed
- [ ] CDN configured (if using)

### Security Checks
- [ ] Security headers present
- [ ] Debug mode disabled
- [ ] Error messages not exposing sensitive data
- [ ] Rate limiting active
- [ ] Admin routes protected

## Rollback Plan

If deployment fails:

```bash
# 1. Restore previous release
cd /home/forge/siasurf
git reset --hard PREVIOUS_COMMIT

# 2. Restore database
mysql -u siasurf_user -p siasurf < backup.sql

# 3. Clear and recache
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 4. Restart services
sudo supervisorctl restart all
```

## Monitoring

### Health Check Endpoint
```php
// routes/web.php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'database' => DB::connection()->getPdo() ? 'connected' : 'error',
        'redis' => Redis::connection()->ping() ? 'connected' : 'error',
    ]);
});
```

### Log Rotation
```bash
# /etc/logrotate.d/siasurf
/home/forge/siasurf/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    create 644 forge forge
}
```

## Backup Strategy

### Database
```bash
# Daily automated backup
0 2 * * * mysqldump -u siasurf_user -p'PASSWORD' siasurf | gzip > /backups/siasurf-$(date +\%Y\%m\%d).sql.gz
```

### Files
```bash
# Weekly full backup
0 3 * * 0 tar -czf /backups/siasurf-files-$(date +\%Y\%m\%d).tar.gz /home/forge/siasurf/storage/app
```

### S3 Backup (Optional)
```bash
# Sync to S3
aws s3 sync /backups/ s3://siasurf-backups/production/
```

## SSL Certificate Renewal

```bash
# Let's Encrypt auto-renewal (handled by Forge)
# Manual renewal if needed:
certbot renew
```

## Support Contacts

- **Server Issues**: Laravel Forge Support
- **Application Issues**: development@siasurf.com
- **Emergency**: +63 XXX XXX XXXX
