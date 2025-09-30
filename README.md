# WP-Rank PHP

[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-orange.svg)](https://mysql.com)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

A WordPress site performance ranking system that analyzes sites based on their PageSpeed Insights scores and estimated plugin counts to determine "efficiency" rankings.

## 🚀 Features

- **📊 Performance Analysis**: Uses Google PageSpeed Insights API for real performance data
- **🔧 Plugin Detection**: Estimates plugin count through frontend asset analysis  
- **🏆 Efficiency Rankings**: Ranks sites by performance-to-plugin ratio
- **🌐 REST API**: Complete API for accessing rankings and site data
- **💻 Web Interface**: Responsive frontend for browsing rankings
- **🛡️ Anti-Spam Protection**: Rate limiting and duplicate submission detection
- **📈 Real-time Statistics**: Live submission and ranking statistics

## 🛠️ Tech Stack

- **Backend**: PHP 8.0+, MySQL 8.0+
- **Frontend**: HTML, CSS, JavaScript
- **Dependencies**: Composer packages (Guzzle, PHPDotEnv, UUID)
- **APIs**: Google PageSpeed Insights

## Architecture

```
WP-Rank-PHP/
├── src/                    # Core PHP classes
│   ├── Config.php         # Configuration management
│   ├── Database.php       # Database connection
│   ├── Utils/             # Utility classes
│   └── Services/          # Business logic services
├── public/                # Web-accessible files
│   ├── index.php         # API router
│   ├── leaderboard.html  # Main rankings page
│   ├── site.html         # Site detail page
│   └── methodology.html  # Documentation
├── bin/                   # Command-line scripts
│   ├── crawl_worker.php  # Continuous crawling worker
│   ├── discover_daily.php # Daily site discovery
│   └── recompute_ranks.php # Rankings recalculation
├── sql/                   # Database schema
└── docs/                  # Documentation
```

## Requirements

- **PHP 8.1+** with extensions:
  - PDO with MySQL driver
  - cURL
  - JSON
  - mbstring
- **MySQL 8.0+** or MariaDB 10.4+
- **Composer** for dependency management
- **Google PageSpeed Insights API key**

## Installation

### 1. Clone and Setup

```bash
git clone <repository-url> wp-rank-php
cd wp-rank-php
composer install
```

### 2. Database Setup

Create a MySQL database and import the schema:

```bash
mysql -u root -p -e "CREATE DATABASE wp_rank CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p wp_rank < sql/schema.sql
```

### 3. Environment Configuration

Copy the environment template and configure:

```bash
cp .env.example .env
```

Edit `.env` with your settings:

```env
# Database Configuration
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=wp_rank
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Google PageSpeed Insights API
PSI_API_KEY=your_google_api_key

# Admin Configuration
ADMIN_TOKEN=your_secure_admin_token

# Application Settings
APP_ENV=production
APP_DEBUG=false
```

### 4. Get Google PageSpeed Insights API Key

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing one
3. Enable the PageSpeed Insights API
4. Create credentials (API Key)
5. Add the key to your `.env` file

### 5. Web Server Configuration

#### Apache

Create a virtual host or configure your document root to point to the `public/` directory:

```apache
<VirtualHost *:80>
    ServerName wp-rank.local
    DocumentRoot /path/to/wp-rank-php/public
    
    <Directory /path/to/wp-rank-php/public>
        AllowOverride All
        Require all granted
        
        # Enable clean URLs
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^api/(.*)$ index.php [QSA,L]
    </Directory>
</VirtualHost>
```

#### Nginx

```nginx
server {
    listen 80;
    server_name wp-rank.local;
    root /path/to/wp-rank-php/public;
    index index.php index.html;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location /api/ {
        try_files $uri /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## Usage

### Web Interface

1. **Main Rankings**: Visit `/leaderboard.html` to see the WordPress site rankings
2. **Site Details**: Click any site to view detailed metrics and history
3. **Submit Sites**: Use the submission form to add new sites for analysis
4. **Methodology**: Read `/methodology.html` for details on how rankings work

### API Endpoints

#### Public Endpoints

```bash
# Get leaderboard (paginated)
GET /api/leaderboard?page=1&limit=50&min_plugins=0&max_plugins=100

# Get site details
GET /api/sites/example.com

# Get global statistics
GET /api/stats

# Submit site for analysis
POST /api/sites/submit
Content-Type: application/json
{"domain": "example.com"}
```

#### Admin Endpoints

```bash
# Force crawl a site (requires admin token)
POST /api/admin/crawl
Authorization: Bearer your_admin_token
{"domain": "example.com"}

# Get queue status
GET /api/admin/queue
Authorization: Bearer your_admin_token
```

### Command Line Tools

#### Start the Crawl Worker

```bash
# Run continuously (recommended for production)
php bin/crawl_worker.php --verbose

# Process limited batches
php bin/crawl_worker.php --batch-size=5 --max-retries=2
```

#### Daily Site Discovery

```bash
# Discover new sites (run via cron)
php bin/discover_daily.php --verbose --max-sites=100 --cleanup
```

#### Recompute Rankings

```bash
# Recalculate all rankings (run daily after crawling)
php bin/recompute_ranks.php --verbose --batch-size=1000
```

## Automation Setup

### Cron Jobs

Add these cron jobs for automated operation:

```bash
# Site discovery (daily at 2 AM)
0 2 * * * /usr/bin/php /path/to/wp-rank-php/bin/discover_daily.php --max-sites=100 --cleanup

# Recompute rankings (daily at 6 AM)
0 6 * * * /usr/bin/php /path/to/wp-rank-php/bin/recompute_ranks.php --batch-size=1000

# Clean up logs (weekly)
0 0 * * 0 find /path/to/wp-rank-php/logs -name "*.log" -mtime +7 -delete
```

### Systemd Service (Crawl Worker)

Create `/etc/systemd/system/wp-rank-crawler.service`:

```ini
[Unit]
Description=WP-Rank Crawl Worker
After=network.target mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/path/to/wp-rank-php
ExecStart=/usr/bin/php bin/crawl_worker.php --verbose
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable wp-rank-crawler
sudo systemctl start wp-rank-crawler
```

## Development

### Running Tests

```bash
# Install development dependencies
composer install --dev

# Run PHPUnit tests
./vendor/bin/phpunit

# Run code style checks
./vendor/bin/phpcs src/
```

### Database Migrations

When updating the schema, create migration files in `sql/migrations/`:

```bash
# Apply migrations
php bin/migrate.php --up

# Rollback migrations
php bin/migrate.php --down
```

### Local Development

```bash
# Start PHP development server
php -S localhost:8000 -t public/

# Watch for file changes (if using nodemon)
nodemon --exec "php -S localhost:8000 -t public/" --watch src/ --ext php
```

## Performance Optimization

### Database Optimization

1. **Indexes**: Ensure proper indexes on frequently queried columns
2. **Partitioning**: Consider partitioning large tables by date
3. **Connection Pooling**: Use connection pooling in production

### Application Optimization

1. **Caching**: Implement Redis/Memcached for API responses
2. **Rate Limiting**: Add rate limiting to prevent API abuse
3. **CDN**: Use CDN for static assets and API responses

### Monitoring

1. **Application Logs**: Monitor logs for errors and performance issues
2. **Database Metrics**: Track query performance and slow queries
3. **API Metrics**: Monitor API response times and error rates

## Security Considerations

1. **API Keys**: Keep PageSpeed Insights API key secure
2. **Admin Token**: Use strong admin tokens and rotate regularly
3. **Input Validation**: All user inputs are validated and sanitized
4. **SQL Injection**: All queries use prepared statements
5. **Rate Limiting**: Implement rate limiting on public endpoints

## Troubleshooting

### Common Issues

1. **PageSpeed API Limits**: Google has daily quotas - monitor usage
2. **Memory Issues**: Large crawls may need increased memory limits
3. **Database Locks**: Heavy concurrent access may cause locks
4. **Network Timeouts**: External site analysis may timeout

### Debug Mode

Enable debug mode in `.env`:

```env
APP_DEBUG=true
```

This will provide detailed error messages and query logging.

### Logs

Check application logs for errors:

```bash
tail -f logs/app.log
tail -f logs/crawler.log
tail -f logs/api.log
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests for new functionality
5. Run the test suite
6. Submit a pull request

## License

This project is open source and available under the MIT License.

## Support

For issues and questions:

1. Check the troubleshooting section
2. Search existing GitHub issues
3. Create a new issue with detailed information
4. Include logs and configuration (without sensitive data)

## Changelog

### Version 1.0.0

- Initial PHP implementation
- Complete API with ranking endpoints
- Responsive web interface
- Automated crawling and discovery
- Google PageSpeed Insights integration
- Plugin detection system
- Command-line tools for automation