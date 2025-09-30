#!/bin/bash

# WP-Rank Automation Setup Script
# 
# This script helps set up the automated processes for WP-Rank
# Run with: bash setup_automation.sh

set -e

echo "WP-Rank Automation Setup"
echo "========================"
echo

# Get current directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

echo "Project directory: $PROJECT_DIR"
echo

# Check if running as root (needed for systemd service)
if [[ $EUID -eq 0 ]]; then
    IS_ROOT=true
    echo "Running as root - can install systemd service"
else
    IS_ROOT=false
    echo "Running as regular user - will skip systemd service installation"
fi

echo

# Create logs directory
echo "Creating logs directory..."
mkdir -p "$PROJECT_DIR/logs"
chmod 755 "$PROJECT_DIR/logs"

# Make sure web server can write to logs
if command -v www-data &> /dev/null; then
    chown -R www-data:www-data "$PROJECT_DIR/logs" 2>/dev/null || true
fi

echo "✓ Logs directory created"

# Make scripts executable
echo "Making scripts executable..."
chmod +x "$PROJECT_DIR/bin"/*.php
echo "✓ Scripts are now executable"

# Apply database updates
echo "Applying database updates..."
if [ -f "$PROJECT_DIR/.env" ]; then
    source "$PROJECT_DIR/.env"
    
    if [ -n "$DB_HOST" ] && [ -n "$DB_DATABASE" ] && [ -n "$DB_USERNAME" ]; then
        mysql -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" < "$PROJECT_DIR/sql/automation_updates.sql"
        echo "✓ Database updates applied"
    else
        echo "⚠ Warning: Database credentials not found in .env - please apply sql/automation_updates.sql manually"
    fi
else
    echo "⚠ Warning: .env file not found - please apply sql/automation_updates.sql manually"
fi

# Install systemd service (if root)
if [ "$IS_ROOT" = true ]; then
    echo "Installing systemd service..."
    
    # Update the service file with correct paths
    sed "s|/path/to/WP-Rank-PHP|$PROJECT_DIR|g" "$PROJECT_DIR/config/wp-rank-crawler.service" > /etc/systemd/system/wp-rank-crawler.service
    
    systemctl daemon-reload
    systemctl enable wp-rank-crawler.service
    
    echo "✓ Systemd service installed and enabled"
    echo "  Start with: sudo systemctl start wp-rank-crawler"
    echo "  Check status: sudo systemctl status wp-rank-crawler"
else
    echo "⚠ Skipping systemd service installation (requires root)"
fi

# Set up cron jobs
echo "Setting up cron jobs..."

CRON_FILE="/tmp/wp-rank-cron"
cat > "$CRON_FILE" << EOF
# WP-Rank Automation Cron Jobs
# Daily site discovery at 2:00 AM
0 2 * * * cd $PROJECT_DIR && php bin/discover_daily.php --max-sites=100 --cleanup >> logs/cron.log 2>&1

# Ranking computation at 6:00 AM
0 6 * * * cd $PROJECT_DIR && php bin/recompute_ranks.php --batch-size=1000 >> logs/cron.log 2>&1

# Queue cleanup every 4 hours
0 */4 * * * cd $PROJECT_DIR && php bin/cleanup_queue.php >> logs/cron.log 2>&1

# Log rotation weekly
0 0 * * 0 find $PROJECT_DIR/logs -name "*.log" -mtime +7 -delete

# Health check every 15 minutes during business hours
*/15 8-18 * * 1-5 cd $PROJECT_DIR && php bin/health_check.php --quiet >> logs/health.log 2>&1
EOF

echo
echo "Cron jobs to install:"
echo "===================="
cat "$CRON_FILE"
echo
echo "Would you like to install these cron jobs? (y/n)"
read -r INSTALL_CRON

if [ "$INSTALL_CRON" = "y" ] || [ "$INSTALL_CRON" = "Y" ]; then
    crontab -l 2>/dev/null | cat - "$CRON_FILE" | crontab -
    echo "✓ Cron jobs installed"
else
    echo "⚠ Cron jobs not installed - you can install them later with:"
    echo "  crontab -e"
    echo "  Then add the contents of: $CRON_FILE"
fi

rm "$CRON_FILE"

# Test configuration
echo
echo "Testing configuration..."

# Test database connection
echo -n "Database connection: "
if php -r "
require_once '$PROJECT_DIR/src/Config.php';
require_once '$PROJECT_DIR/src/Database.php';
try {
    \$db = new WPRank\\Database();
    \$stmt = \$db->prepare('SELECT 1');
    \$stmt->execute();
    echo 'OK';
} catch (Exception \$e) {
    echo 'FAILED: ' . \$e->getMessage();
    exit(1);
}
"; then
    echo " ✓"
else
    echo " ✗"
    exit 1
fi

# Test PageSpeed API key
echo -n "PageSpeed API key: "
if php -r "
require_once '$PROJECT_DIR/src/Config.php';
\$config = new WPRank\\Config();
\$apiKey = \$config->get('PSI_API_KEY');
if (\$apiKey) {
    echo 'Configured';
} else {
    echo 'NOT SET';
    exit(1);
}
"; then
    echo " ✓"
else
    echo " ⚠ Warning: PSI_API_KEY not set in .env"
fi

echo
echo "Setup Complete!"
echo "==============="
echo
echo "Next steps:"
echo "1. Start the crawl worker:"
if [ "$IS_ROOT" = true ]; then
    echo "   sudo systemctl start wp-rank-crawler"
else
    echo "   php bin/crawl_worker.php --verbose &"
fi
echo
echo "2. Run initial discovery:"
echo "   php bin/discover_daily.php --verbose --max-sites=50"
echo
echo "3. Monitor the system:"
echo "   php bin/health_check.php"
echo "   tail -f logs/queue.log"
echo
echo "4. Check the web interface:"
echo "   Visit your domain/leaderboard.html"
echo
echo "Log files location: $PROJECT_DIR/logs/"
echo "Configuration files: $PROJECT_DIR/config/"
echo
echo "For troubleshooting, check the README.md file."