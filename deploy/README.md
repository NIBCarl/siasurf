# SiaSurf Production Deployment Checklist (Azure VM + CloudPanel)

## 1. Server Setup (one-time as `root`)
1. Provision an Ubuntu 24.04 LTS VM (`Standard_B2ats_v2` or `Standard_B1s` with 2 GB swap, or `Standard_B1ms`).
2. Open ports `22`, `80`, `443` (and temporarily `8443` for CloudPanel setup).
3. Add 2 GB swap, install `supervisor`, `git`, `unzip`, `nodejs` (v22 LTS), and CloudPanel (MySQL 8.4).
4. In CloudPanel:
   - Create a PHP 8.3 Laravel site for your domain (site user `siasurf`).
   - Issue a Let's Encrypt SSL certificate.
   - Create MySQL database `siasurf` and user `siasurf_user`.

## 2. First-Time App Setup (as `siasurf`)
```bash
DOMAIN="your-domain.com"
cd ~/htdocs
rm -rf "$DOMAIN"
git clone <YOUR_DEPLOY_REPO_URL> "$DOMAIN"
cd "$DOMAIN"

cp .env.production.example .env
php artisan key:generate
```
Edit `.env` (`APP_URL`, `SIASURF_PUBLIC_URL`, `DB_PASSWORD`, `REVERB_*`, `VITE_REVERB_*`, `MAIL_*`, `ADMIN_DEFAULT_PASSWORD`). Generate Reverb credentials with:
```bash
echo "REVERB_APP_ID=$(shuf -i 100000-999999 -n 1)"
echo "REVERB_APP_KEY=$(openssl rand -hex 10)"
echo "REVERB_APP_SECRET=$(openssl rand -hex 16)"
```
Then run initial install, build, migration, and seeding:
```bash
chmod +x deploy/deploy.sh
./deploy/deploy.sh --no-pull
php artisan db:seed --force
# Optional demo instructors:
# php artisan db:seed --class=InstructorSeeder --force
```

## 3. Reverse Proxy, Workers & Scheduler
1. **Nginx Reverb Proxy:** In CloudPanel -> Site -> **Vhost**, verify `root` ends in `/public;` and paste `deploy/nginx-reverb.conf` above `location / {`. Save.
2. **Supervisor (as `root`):**
   ```bash
   APP_DIR="/home/siasurf/htdocs/your-domain.com"
   sed "s|__APP_DIR__|$APP_DIR|g" "$APP_DIR/deploy/supervisor-worker.conf" > /etc/supervisor/conf.d/siasurf-worker.conf
   sed "s|__APP_DIR__|$APP_DIR|g" "$APP_DIR/deploy/supervisor-reverb.conf" > /etc/supervisor/conf.d/siasurf-reverb.conf
   supervisorctl reread && supervisorctl update && supervisorctl status
   ```
3. **Cron Scheduler (`crontab -u siasurf -e`):**
   ```cron
   * * * * * cd /home/siasurf/htdocs/your-domain.com && php artisan schedule:run >> /dev/null 2>&1
   ```

## 4. Future Updates
```bash
sudo -u siasurf /home/siasurf/htdocs/your-domain.com/deploy/deploy.sh
sudo supervisorctl restart siasurf-worker:* siasurf-reverb
```
