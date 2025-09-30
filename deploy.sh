#!/bin/bash

# WP-Rank PHP Deployment Script
# Automates the deployment process for WP-Rank

set -e  # Exit on any error

# Configuration
PROJECT_NAME="WP-Rank PHP"
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="$PROJECT_DIR/deploy.log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging function
log() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1" | tee -a "$LOG_FILE"
    exit 1
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1" | tee -a "$LOG_FILE"
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1" | tee -a "$LOG_FILE"
}

# Check requirements
check_requirements() {
    log "Checking system requirements..."
    
    # Check PHP version
    if ! command -v php &> /dev/null; then
        error "PHP is not installed"
    fi
    
    PHP_VERSION=$(php -r "echo PHP_VERSION;")
    if [[ $(echo "$PHP_VERSION" | cut -d. -f1,2) < "8.1" ]]; then
        error "PHP 8.1 or higher is required (found: $PHP_VERSION)"
    fi
    success "PHP $PHP_VERSION found"
    
    # Check required PHP extensions
    REQUIRED_EXTENSIONS=("pdo" "pdo_mysql" "curl" "json" "mbstring")
    for ext in "${REQUIRED_EXTENSIONS[@]}"; do
        if ! php -m | grep -q "^$ext$"; then
            error "Required PHP extension '$ext' is not installed"
        fi
    done
    success "All required PHP extensions found"
    
    # Check Composer
    if ! command -v composer &> /dev/null; then
        error "Composer is not installed"
    fi
    success "Composer found"
    
    # Check MySQL
    if ! command -v mysql &> /dev/null; then
        warning "MySQL client not found - database setup will need to be done manually"
    else
        success "MySQL client found"
    fi
}

# Install dependencies
install_dependencies() {
    log "Installing PHP dependencies..."
    
    if [[ ! -f "$PROJECT_DIR/composer.json" ]]; then
        error "composer.json not found in $PROJECT_DIR"
    fi
    
    cd "$PROJECT_DIR"
    composer install --no-dev --optimize-autoloader --no-interaction
    
    success "Dependencies installed successfully"
}

# Setup environment
setup_environment() {
    log "Setting up environment configuration..."
    
    ENV_FILE="$PROJECT_DIR/.env"
    ENV_EXAMPLE="$PROJECT_DIR/.env.example"
    
    if [[ ! -f "$ENV_EXAMPLE" ]]; then
        error ".env.example file not found"
    fi
    
    if [[ ! -f "$ENV_FILE" ]]; then
        log "Creating .env file from .env.example..."
        cp "$ENV_EXAMPLE" "$ENV_FILE"
        warning "Please edit $ENV_FILE with your configuration before continuing"
        
        # Open the file in the default editor if available
        if command -v nano &> /dev/null; then
            read -p "Would you like to edit the .env file now? (y/n): " -n 1 -r
            echo
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                nano "$ENV_FILE"
            fi
        fi
    else
        log ".env file already exists, skipping creation"
    fi
}

# Setup database
setup_database() {
    log "Setting up database..."
    
    # Source environment variables
    if [[ -f "$PROJECT_DIR/.env" ]]; then
        source "$PROJECT_DIR/.env"
    else
        error ".env file not found. Please run setup_environment first."
    fi
    
    # Check if database variables are set
    if [[ -z "$DB_DATABASE" || -z "$DB_USERNAME" || -z "$DB_PASSWORD" ]]; then
        warning "Database credentials not configured in .env file"
        return
    fi
    
    SCHEMA_FILE="$PROJECT_DIR/sql/schema.sql"
    
    if [[ ! -f "$SCHEMA_FILE" ]]; then
        error "Database schema file not found: $SCHEMA_FILE"
    fi
    
    # Test database connection
    if mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"$DB_USERNAME" -p"$DB_PASSWORD" -e "USE $DB_DATABASE;" 2>/dev/null; then
        log "Database connection successful"
        
        read -p "Database exists. Do you want to import/update the schema? (y/n): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" < "$SCHEMA_FILE"
            success "Database schema imported successfully"
        fi
    else
        warning "Could not connect to database. Please verify your credentials and create the database manually."
        log "To create the database manually:"
        log "1. mysql -u root -p"
        log "2. CREATE DATABASE $DB_DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        log "3. mysql -u$DB_USERNAME -p$DB_PASSWORD $DB_DATABASE < $SCHEMA_FILE"
    fi
}

# Set up file permissions
setup_permissions() {
    log "Setting up file permissions..."
    
    # Make worker scripts executable
    chmod +x "$PROJECT_DIR/bin/"*.php
    success "Worker scripts made executable"
    
    # Create logs directory if it doesn't exist
    mkdir -p "$PROJECT_DIR/logs"
    chmod 755 "$PROJECT_DIR/logs"
    
    # Set proper permissions for web files
    find "$PROJECT_DIR/public" -type f -exec chmod 644 {} \;
    find "$PROJECT_DIR/public" -type d -exec chmod 755 {} \;
    
    success "File permissions set correctly"
}

# Create systemd service
create_systemd_service() {
    log "Creating systemd service for crawl worker..."
    
    SERVICE_FILE="/etc/systemd/system/wp-rank-crawler.service"
    
    if [[ -f "$SERVICE_FILE" ]]; then
        warning "Systemd service already exists"
        return
    fi
    
    # Check if we have sudo access
    if ! sudo -n true 2>/dev/null; then
        warning "Sudo access required to create systemd service. Skipping..."
        return
    fi
    
    # Get the web server user
    WEB_USER="www-data"
    if ! id "$WEB_USER" &>/dev/null; then
        WEB_USER="apache"
        if ! id "$WEB_USER" &>/dev/null; then
            WEB_USER="nginx"
            if ! id "$WEB_USER" &>/dev/null; then
                WEB_USER="$(whoami)"
            fi
        fi
    fi
    
    cat > /tmp/wp-rank-crawler.service << EOF
[Unit]
Description=WP-Rank Crawl Worker
After=network.target mysql.service

[Service]
Type=simple
User=$WEB_USER
Group=$WEB_USER
WorkingDirectory=$PROJECT_DIR
ExecStart=/usr/bin/php bin/crawl_worker.php --verbose
Restart=always
RestartSec=10
StandardOutput=append:$PROJECT_DIR/logs/crawler.log
StandardError=append:$PROJECT_DIR/logs/crawler.log

[Install]
WantedBy=multi-user.target
EOF
    
    sudo mv /tmp/wp-rank-crawler.service "$SERVICE_FILE"
    sudo systemctl daemon-reload
    
    success "Systemd service created. To start it:"
    log "  sudo systemctl enable wp-rank-crawler"
    log "  sudo systemctl start wp-rank-crawler"
}

# Setup cron jobs
setup_cron() {
    log "Setting up cron jobs..."
    
    CRON_FILE="/tmp/wp-rank-cron"
    
    cat > "$CRON_FILE" << EOF
# WP-Rank automated tasks
# Site discovery (daily at 2 AM)
0 2 * * * /usr/bin/php $PROJECT_DIR/bin/discover_daily.php --max-sites=100 --cleanup >> $PROJECT_DIR/logs/discovery.log 2>&1

# Recompute rankings (daily at 6 AM)
0 6 * * * /usr/bin/php $PROJECT_DIR/bin/recompute_ranks.php --batch-size=1000 >> $PROJECT_DIR/logs/rankings.log 2>&1

# Clean up old logs (weekly on Sunday)
0 0 * * 0 find $PROJECT_DIR/logs -name "*.log" -mtime +7 -delete

EOF
    
    log "Suggested cron jobs written to $CRON_FILE"
    log "To install them, run: crontab $CRON_FILE"
    
    read -p "Would you like to install the cron jobs now? (y/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        crontab "$CRON_FILE"
        success "Cron jobs installed successfully"
    else
        log "Cron jobs not installed. You can install them later with: crontab $CRON_FILE"
    fi
    
    rm -f "$CRON_FILE"
}

# Test installation
test_installation() {
    log "Testing installation..."
    
    # Test database connection
    cd "$PROJECT_DIR"
    if php -r "
        require_once 'vendor/autoload.php';
        try {
            \$db = WPRank\Database::getInstance();
            echo 'Database connection successful\n';
        } catch (Exception \$e) {
            echo 'Database connection failed: ' . \$e->getMessage() . '\n';
            exit(1);
        }
    "; then
        success "Database connection test passed"
    else
        error "Database connection test failed"
    fi
    
    # Test worker script
    if php bin/crawl_worker.php --help > /dev/null; then
        success "Crawl worker script test passed"
    else
        error "Crawl worker script test failed"
    fi
    
    success "All tests passed!"
}

# Web server configuration hints
show_webserver_config() {
    log "Web server configuration:"
    log ""
    log "For Apache, add this virtual host:"
    log "----------------------------------------"
    cat << EOF
<VirtualHost *:80>
    ServerName wp-rank.local
    DocumentRoot $PROJECT_DIR/public
    
    <Directory $PROJECT_DIR/public>
        AllowOverride All
        Require all granted
        
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^api/(.*)$ index.php [QSA,L]
    </Directory>
</VirtualHost>
EOF
    log "----------------------------------------"
    log ""
    log "For Nginx, add this server block:"
    log "----------------------------------------"
    cat << EOF
server {
    listen 80;
    server_name wp-rank.local;
    root $PROJECT_DIR/public;
    index index.php index.html;
    
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    
    location /api/ {
        try_files \$uri /index.php?\$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
}
EOF
    log "----------------------------------------"
}

# Main deployment function
main() {
    log "Starting deployment of $PROJECT_NAME..."
    log "Project directory: $PROJECT_DIR"
    log ""
    
    check_requirements
    install_dependencies
    setup_environment
    setup_database
    setup_permissions
    
    if command -v systemctl &> /dev/null; then
        create_systemd_service
    else
        warning "systemctl not found, skipping systemd service creation"
    fi
    
    setup_cron
    test_installation
    
    log ""
    success "Deployment completed successfully!"
    log ""
    log "Next steps:"
    log "1. Configure your web server (see configuration below)"
    log "2. Get a Google PageSpeed Insights API key and add it to .env"
    log "3. Start the crawl worker: sudo systemctl start wp-rank-crawler"
    log "4. Submit some WordPress sites via the web interface"
    log "5. Monitor logs in $PROJECT_DIR/logs/"
    log ""
    
    show_webserver_config
}

# Show help
show_help() {
    echo "WP-Rank PHP Deployment Script"
    echo ""
    echo "Usage: $0 [options]"
    echo ""
    echo "Options:"
    echo "  --help              Show this help message"
    echo "  --check-only        Only check requirements, don't deploy"
    echo "  --no-cron           Skip cron job setup"
    echo "  --no-systemd        Skip systemd service creation"
    echo ""
    echo "Examples:"
    echo "  $0                  Full deployment"
    echo "  $0 --check-only     Check requirements only"
    echo ""
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --help)
            show_help
            exit 0
            ;;
        --check-only)
            check_requirements
            exit 0
            ;;
        *)
            error "Unknown option: $1"
            ;;
    esac
    shift
done

# Run main deployment
main