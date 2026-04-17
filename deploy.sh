#!/bin/bash

# SiaSurf Production Deployment Script
# Usage: ./deploy.sh

set -e

echo "=========================================="
echo "SiaSurf Production Deployment"
echo "=========================================="
echo ""

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
APP_DIR="/home/forge/siasurf"
BACKUP_DIR="/home/forge/backups"
PHP_VERSION="8.2"

# Function to print status
print_status() {
    echo -e "${GREEN}[✓]${NC} $1"
}

print_error() {
    echo -e "${RED}[✗]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
}

# Check if running as correct user
if [ "$USER" != "forge" ]; then
    print_error "This script should be run as 'forge' user"
    exit 1
fi

# Navigate to app directory
cd $APP_DIR

# Get current git commit for rollback
CURRENT_COMMIT=$(git rev-parse HEAD)
echo "Current commit: $CURRENT_COMMIT"
echo ""

# Step 1: Maintenance mode
echo "Step 1: Enabling maintenance mode..."
php artisan down --message="We are performing scheduled maintenance. Please check back soon."
print_status "Maintenance mode enabled"

# Step 2: Backup database
echo ""
echo "Step 2: Creating database backup..."
BACKUP_FILE="$BACKUP_DIR/siasurf-$(date +%Y%m%d-%H%M%S).sql.gz"
mysqldump -u siasurf_user -p"$DB_PASSWORD" siasurf | gzip > $BACKUP_FILE
print_status "Database backup created: $BACKUP_FILE"

# Step 3: Pull latest code
echo ""
echo "Step 3: Pulling latest code..."
git fetch origin
git pull origin main
print_status "Code updated"

# Step 4: Install PHP dependencies
echo ""
echo "Step 4: Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction
print_status "PHP dependencies installed"

# Step 5: Install Node dependencies and build
echo ""
echo "Step 5: Building frontend assets..."
npm ci --silent
npm run build
print_status "Frontend assets built"

# Step 6: Clear caches
echo ""
echo "Step 6: Clearing caches..."
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
print_status "Caches cleared"

# Step 7: Run migrations
echo ""
echo "Step 7: Running database migrations..."
php artisan migrate --force --no-interaction
print_status "Migrations completed"

# Step 8: Optimize
echo ""
echo "Step 8: Optimizing application..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
print_status "Application optimized"

# Step 9: Clear expired cache
echo ""
echo "Step 9: Pruning stale cache tags..."
php artisan cache:prune-stale-tags
print_status "Stale cache cleared"

# Step 10: Restart services
echo ""
echo "Step 10: Restarting services..."
sudo supervisorctl restart siasurf-worker:*
sudo supervisorctl restart siasurf-reverb
print_status "Services restarted"

# Step 11: Health check
echo ""
echo "Step 11: Running health checks..."
sleep 5

# Check if application is responding
HTTP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://siasurf.com/health || echo "000")

if [ "$HTTP_STATUS" = "200" ]; then
    print_status "Health check passed (HTTP $HTTP_STATUS)"
else
    print_error "Health check failed (HTTP $HTTP_STATUS)"
    print_warning "Rolling back to previous commit..."
    
    # Rollback
    git reset --hard $CURRENT_COMMIT
    php artisan migrate --force
    php artisan cache:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    sudo supervisorctl restart all
    php artisan up
    
    print_error "Deployment failed! Rolled back to $CURRENT_COMMIT"
    exit 1
fi

# Step 12: Disable maintenance mode
echo ""
echo "Step 12: Disabling maintenance mode..."
php artisan up
print_status "Maintenance mode disabled"

# Step 13: Cleanup old backups
echo ""
echo "Step 13: Cleaning up old backups..."
find $BACKUP_DIR -name "siasurf-*.sql.gz" -mtime +7 -delete
print_status "Old backups cleaned up"

# Deployment complete
echo ""
echo "=========================================="
print_status "Deployment completed successfully!"
echo "=========================================="
echo ""
echo "New commit: $(git rev-parse HEAD)"
echo "Deployed at: $(date)"
echo ""

# Send notification (optional)
# curl -X POST "$SLACK_WEBHOOK_URL" -H 'Content-type: application/json' \
#   --data '{"text":"SiaSurf deployed successfully!"}'

exit 0
