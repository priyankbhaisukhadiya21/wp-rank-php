<?php
/**
 * Main API router for WP-Rank
 * 
 * Handles all API endpoints including leaderboard, site details, submissions,
 * and admin functions. Also serves static frontend files.
 */

require __DIR__ . '/../vendor/autoload.php';

use WPRank\Config;
use WPRank\Database;
use WPRank\Services\RankingService;
use WPRank\Services\SubmissionService;
use WPRank\Services\Crawler;

// Load configuration
$config = Config::load();

// Enable error reporting in debug mode
if (Config::isDebug()) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Get request information
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$queryParams = $_GET;

// CORS headers
$corsOrigins = Config::get('api.cors_origins', ['http://localhost:8000']);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $corsOrigins)) {
    header("Access-Control-Allow-Origin: {$origin}");
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
header('Access-Control-Allow-Credentials: true');

// Handle OPTIONS requests (preflight)
if ($requestMethod === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * Send JSON response with proper headers
 */
function sendJson($data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Send error response
 */
function sendError(string $message, int $statusCode = 400): void {
    sendJson([
        'error' => $message,
        'timestamp' => date('c')
    ], $statusCode);
}

/**
 * Get request body as JSON
 */
function getJsonBody(): array {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    return $data ?? [];
}

/**
 * Validate admin token
 */
function validateAdminToken(): bool {
    $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    $expectedToken = Config::get('api.admin_token', '');
    return !empty($expectedToken) && hash_equals($expectedToken, $token);
}

// Route handling
try {
    if ($requestUri === '/' && $requestMethod === 'GET') {
        readfile(__DIR__ . '/leaderboard.html');
        exit();
    }
    
    if ($requestUri === '/site' && $requestMethod === 'GET') {
        readfile(__DIR__ . '/site.html');
        exit();
    }
    
    if ($requestUri === '/methodology' && $requestMethod === 'GET') {
        readfile(__DIR__ . '/methodology.html');
        exit();
    }
    
    // API Routes
    
    // Health check endpoint
    if ($requestUri === '/api/health' && $requestMethod === 'GET') {
        $dbHealthy = Database::testConnection();
        
        sendJson([
            'status' => $dbHealthy ? 'healthy' : 'unhealthy',
            'database' => $dbHealthy ? 'connected' : 'disconnected',
            'timestamp' => date('c'),
            'version' => '1.0.0'
        ], $dbHealthy ? 200 : 503);
    }
    
    // Leaderboard endpoint
    if ($requestUri === '/api/leaderboard' && $requestMethod === 'GET') {
        $rankingService = new RankingService();
        $leaderboard = $rankingService->getLeaderboard($queryParams);
        sendJson($leaderboard);
    }
    
    // Site details endpoint
    if (preg_match('#^/api/sites/([^/]+)$#', $requestUri, $matches) && $requestMethod === 'GET') {
        $domain = $matches[1];
        
        // Get site information
        $siteStmt = Database::execute(
            "SELECT * FROM sites WHERE domain = ?",
            [$domain]
        );
        
        $site = $siteStmt->fetch();
        
        if (!$site) {
            sendError('Site not found', 404);
        }
        
        // Get current ranking
        $rankStmt = Database::execute(
            "SELECT * FROM ranks WHERE site_id = ?",
            [$site['id']]
        );
        
        $rank = $rankStmt->fetch();
        
        // Get metrics history (last 10 crawls)
        $historyStmt = Database::execute(
            "SELECT created_at, psi_score, plugin_est_count, lcp_ms, cls, tbt_ms
             FROM site_metrics 
             WHERE site_id = ? 
             ORDER BY created_at DESC 
             LIMIT 10",
            [$site['id']]
        );
        
        $history = $historyStmt->fetchAll();
        
        // Get latest evidence
        $evidenceStmt = Database::execute(
            "SELECT evidence FROM site_metrics 
             WHERE site_id = ? 
             ORDER BY created_at DESC 
             LIMIT 1",
            [$site['id']]
        );
        
        $evidenceRow = $evidenceStmt->fetch();
        $evidence = $evidenceRow ? json_decode($evidenceRow['evidence'], true) : [];
        
        sendJson([
            'site' => $site,
            'rank' => $rank,
            'history' => $history,
            'evidence' => $evidence ?: []
        ]);
    }
    
    // Site submission endpoint
    if ($requestUri === '/api/sites/submit' && $requestMethod === 'POST') {
        $body = getJsonBody();
        $url = $body['url'] ?? '';
        
        if (empty($url)) {
            sendError('URL is required');
        }
        
        $submissionService = new SubmissionService();
        $userIP = $_SERVER['REMOTE_ADDR'] ?? null;
        
        $result = $submissionService->submitDomain($url, $userIP);
        
        if ($result['success']) {
            sendJson([
                'status' => 'queued',
                'domain' => $result['domain'],
                'message' => $result['message'],
                'submission_id' => $result['submission_id']
            ], 202);
        } else {
            sendError($result['message'], 400);
        }
    }
    
    // Statistics endpoint
    if ($requestUri === '/api/stats' && $requestMethod === 'GET') {
        $rankingService = new RankingService();
        $submissionService = new SubmissionService();
        
        $rankingStats = $rankingService->getRankingStats();
        $submissionStats = $submissionService->getSubmissionStats();
        
        sendJson([
            'rankings' => $rankingStats,
            'submissions' => $submissionStats,
            'generated_at' => date('c')
        ]);
    }
    
    // Admin endpoints (require authentication)
    
    // Recompute rankings
    if ($requestUri === '/api/admin/recompute' && $requestMethod === 'POST') {
        if (!validateAdminToken()) {
            sendError('Unauthorized', 401);
        }
        
        $rankingService = new RankingService();
        $result = $rankingService->recomputeRankings();
        
        sendJson($result);
    }
    
    // Trigger crawl
    if ($requestUri === '/api/admin/crawl' && $requestMethod === 'POST') {
        if (!validateAdminToken()) {
            sendError('Unauthorized', 401);
        }
        
        $body = getJsonBody();
        $limit = (int)($body['limit'] ?? 20);
        
        $crawler = new Crawler();
        $result = $crawler->crawlFromQueue($limit);
        
        sendJson($result);
    }
    
    // Queue statistics
    if ($requestUri === '/api/admin/queue' && $requestMethod === 'GET') {
        if (!validateAdminToken()) {
            sendError('Unauthorized', 401);
        }
        
        $crawler = new Crawler();
        $stats = $crawler->getQueueStats();
        
        sendJson($stats);
    }
    
    // System monitoring endpoint
    if ($requestUri === '/api/admin/monitor' && $requestMethod === 'GET') {
        if (!validateAdminToken()) {
            sendError('Unauthorized', 401);
        }
        
        require_once __DIR__ . '/../src/Services/MonitoringService.php';
        $monitoring = new WPRank\Services\MonitoringService();
        
        $status = $monitoring->getSystemStatus();
        $alerts = $monitoring->getAlerts();
        
        sendJson([
            'system' => $status,
            'alerts' => $alerts
        ]);
    }
    
    // Manual domain addition
    if ($requestUri === '/api/admin/add-domain' && $requestMethod === 'POST') {
        if (!validateAdminToken()) {
            sendError('Unauthorized', 401);
        }
        
        $body = getJsonBody();
        $domain = $body['domain'] ?? '';
        
        if (empty($domain)) {
            sendError('Domain is required');
        }
        
        $submissionService = new SubmissionService();
        $result = $submissionService->submitDomain($domain, null, ['priority' => 10]);
        
        sendJson($result);
    }
    
    // 404 - Not Found
    sendError('Endpoint not found', 404);
    
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    if (Config::isDebug()) {
        sendError("Internal error: " . $e->getMessage(), 500);
    } else {
        sendError('Internal server error', 500);
    }
}