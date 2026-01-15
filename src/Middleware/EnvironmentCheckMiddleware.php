<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Utility\EnvironmentChecker;
use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\Session;
use Cake\Utility\Security;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use function Cake\Core\env;

/**
 * Environment Check Middleware
 *
 * Validates database connection if Table classes exist.
 * Prerequisites (PHP extensions, directories, vendor) are checked in config/prerequisite.php
 */
class EnvironmentCheckMiddleware implements MiddlewareInterface
{
    /**
     * CSRF token name used in session and form
     */
    private const CSRF_TOKEN_NAME = '_setup_csrf_token';

    /**
     * Rate limit session key
     */
    private const RATE_LIMIT_KEY = '_setup_rate_limit';

    /**
     * Maximum setup attempts allowed within the time window
     */
    private const RATE_LIMIT_ATTEMPTS = 5;

    /**
     * Rate limit time window in seconds (15 minutes)
     */
    private const RATE_LIMIT_WINDOW = 900;

    /**
     * Process incoming request
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @param \Psr\Http\Server\RequestHandlerInterface $handler Handler
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        // Allow static assets first (no environment check needed)
        if (preg_match('/\.(css|js|ico|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot)$/i', $path)) {
            return $handler->handle($request);
        }

        // Run all environment checks
        $checks = $this->runEnvironmentChecks();
        $hasErrors = !empty(array_filter($checks, fn($c) => !$c['status']));

        // Handle setup routes
        if (str_starts_with($path, '/setup')) {
            // SECURITY: Block setup routes if everything is configured
            if (!$hasErrors) {
                return $this->redirectToHome();
            }

            // Handle database setup form submission with CSRF validation
            if ($path === '/setup/database' && $method === 'POST') {
                return $this->handleDatabaseSetup($request);
            }

            // Allow other setup routes to pass through
            return $handler->handle($request);
        }

        // Show environment setup page if any check failed
        if ($hasErrors) {
            return $this->renderEnvironmentSetup($request, $checks);
        }

        return $handler->handle($request);
    }

    /**
     * Generate or retrieve CSRF token from session
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return string
     */
    protected function getCsrfToken(ServerRequestInterface $request): string
    {
        $session = $this->getSession($request);

        $token = $session->read(self::CSRF_TOKEN_NAME);
        if (!$token) {
            $token = bin2hex(Security::randomBytes(16));
            $session->write(self::CSRF_TOKEN_NAME, $token);
        }

        return $token;
    }

    /**
     * Validate CSRF token from request
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return bool
     */
    protected function validateCsrfToken(ServerRequestInterface $request): bool
    {
        $session = $this->getSession($request);
        $sessionToken = $session->read(self::CSRF_TOKEN_NAME);

        if (!$sessionToken) {
            return false;
        }

        $data = (array)$request->getParsedBody();
        $formToken = $data[self::CSRF_TOKEN_NAME] ?? '';

        // Use hash_equals for timing-safe comparison
        return hash_equals($sessionToken, $formToken);
    }

    /**
     * Get session from request
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return \Cake\Http\Session
     */
    protected function getSession(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute('session');
        if (!$session) {
            $session = new Session();
            $session->start();
        }

        return $session;
    }

    /**
     * Regenerate CSRF token after successful form submission
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return void
     */
    protected function regenerateCsrfToken(ServerRequestInterface $request): void
    {
        $session = $this->getSession($request);
        $session->delete(self::CSRF_TOKEN_NAME);
    }

    /**
     * Check if rate limit has been exceeded
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return bool True if within limit, false if exceeded
     */
    protected function checkRateLimit(ServerRequestInterface $request): bool
    {
        $session = $this->getSession($request);
        $attempts = $session->read(self::RATE_LIMIT_KEY) ?? [];

        // Clean old attempts outside the time window
        $now = time();
        $attempts = array_filter($attempts, fn($timestamp) => ($now - $timestamp) < self::RATE_LIMIT_WINDOW);

        // Update cleaned attempts in session
        $session->write(self::RATE_LIMIT_KEY, array_values($attempts));

        return count($attempts) < self::RATE_LIMIT_ATTEMPTS;
    }

    /**
     * Record a failed setup attempt for rate limiting
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return void
     */
    protected function recordFailedAttempt(ServerRequestInterface $request): void
    {
        $session = $this->getSession($request);
        $attempts = $session->read(self::RATE_LIMIT_KEY) ?? [];

        // Clean old attempts and add new one
        $now = time();
        $attempts = array_filter($attempts, fn($timestamp) => ($now - $timestamp) < self::RATE_LIMIT_WINDOW);
        $attempts[] = $now;

        $session->write(self::RATE_LIMIT_KEY, array_values($attempts));
    }

    /**
     * Clear rate limit tracking (on successful setup)
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return void
     */
    protected function clearRateLimit(ServerRequestInterface $request): void
    {
        $session = $this->getSession($request);
        $session->delete(self::RATE_LIMIT_KEY);
    }

    /**
     * Get remaining attempts count
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return int
     */
    protected function getRemainingAttempts(ServerRequestInterface $request): int
    {
        $session = $this->getSession($request);
        $attempts = $session->read(self::RATE_LIMIT_KEY) ?? [];

        $now = time();
        $attempts = array_filter($attempts, fn($timestamp) => ($now - $timestamp) < self::RATE_LIMIT_WINDOW);

        return max(0, self::RATE_LIMIT_ATTEMPTS - count($attempts));
    }

    /**
     * Handle database setup form submission
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @return \Cake\Http\Response
     */
    protected function handleDatabaseSetup(ServerRequestInterface $request): Response
    {
        // Get current checks for context
        $checks = $this->runEnvironmentChecks();

        // SECURITY: Check rate limit first
        if (!$this->checkRateLimit($request)) {
            $checks['database']['error'] = 'Too many attempts. Please wait 15 minutes before trying again.';

            return $this->renderEnvironmentSetup($request, $checks);
        }

        // Validate CSRF token
        if (!$this->validateCsrfToken($request)) {
            $this->recordFailedAttempt($request);
            $remaining = $this->getRemainingAttempts($request);
            $checks['database']['error'] = "Invalid security token. Please refresh the page and try again. ({$remaining} attempts remaining)";

            return $this->renderEnvironmentSetup($request, $checks);
        }

        $data = (array)$request->getParsedBody();

        $config = [
            'driver' => $data['driver'] ?? 'mysql',
            'host' => $data['host'] ?? 'localhost',
            'port' => $data['port'] ?? '3306',
            'database' => $data['database'] ?? '',
            'username' => $data['username'] ?? '',
            'password' => $data['password'] ?? '',
        ];

        // Test connection
        $result = EnvironmentChecker::testDatabaseConnection($config);

        if (!$result['success']) {
            $this->recordFailedAttempt($request);
            $remaining = $this->getRemainingAttempts($request);
            $checks['database']['error'] = "Connection failed: {$result['error']} ({$remaining} attempts remaining)";

            return $this->renderEnvironmentSetup($request, $checks, $config);
        }

        // Update .env file
        if (!EnvironmentChecker::updateEnvFile($config)) {
            $this->recordFailedAttempt($request);
            $remaining = $this->getRemainingAttempts($request);
            $checks['database']['error'] = "Failed to update .env file. Please check file permissions. ({$remaining} attempts remaining)";

            return $this->renderEnvironmentSetup($request, $checks, $config);
        }

        // Success - clear rate limit and CSRF token
        $this->clearRateLimit($request);
        $this->regenerateCsrfToken($request);

        // Reload page to reflect .env changes
        return $this->redirectToHome();
    }

    /**
     * Redirect to home page
     *
     * Used when setup is complete or when blocking access to setup routes.
     *
     * @return \Cake\Http\Response
     */
    protected function redirectToHome(): Response
    {
        $response = new Response();

        return $response
            ->withStatus(302)
            ->withHeader('Location', '/')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * Run all environment checks
     *
     * @return array
     */
    protected function runEnvironmentChecks(): array
    {
        $checks = [];

        // Database check
        $checks['database'] = $this->checkDatabase();

        // Redis check (only if REDIS_HOST is configured)
        $redisHost = env('REDIS_HOST');
        if (!empty($redisHost)) {
            $checks['redis'] = $this->checkRedis();
        }

        // Vite manifest check (handles dev server detection internally)
        $checks['vite'] = $this->checkViteManifest();

        return $checks;
    }

    /**
     * Check database connection
     *
     * @return array
     */
    protected function checkDatabase(): array
    {
        // Check if any Table classes exist
        $tableDir = ROOT . DS . 'src' . DS . 'Model' . DS . 'Table';
        $hasTableClasses = false;

        if (is_dir($tableDir)) {
            $files = glob($tableDir . DS . '*.php');
            $hasTableClasses = !empty($files);
        }

        $result = [
            'name' => 'database',
            'label' => 'Database Connection',
            'description' => 'MySQL/PostgreSQL database connection',
            'required' => $hasTableClasses,
            'status' => false,
            'error' => null,
            'showForm' => true,
        ];

        // Only check connection if Tables exist
        if ($hasTableClasses) {
            try {
                $connection = \Cake\Datasource\ConnectionManager::get('default');
                $connection->getDriver()->connect();
                $result['status'] = true;
            } catch (\Exception $e) {
                $result['error'] = $e->getMessage();
            }
        } else {
            // No tables, so database is not required - mark as passed
            $result['status'] = true;
        }

        return $result;
    }

    /**
     * Check Redis connection
     *
     * @return array
     */
    protected function checkRedis(): array
    {
        $result = [
            'name' => 'redis',
            'label' => 'Redis Cache',
            'description' => 'Redis server connection for caching',
            'required' => true,
            'status' => false,
            'error' => null,
            'showForm' => false,
        ];

        $redisCheck = EnvironmentChecker::testRedisConnection();
        $result['status'] = $redisCheck['success'];
        $result['error'] = $redisCheck['error'];

        if ($redisCheck['success'] && isset($redisCheck['info'])) {
            $result['description'] = sprintf(
                'Redis %s at %s:%d',
                $redisCheck['info']['redis_version'],
                $redisCheck['info']['host'],
                $redisCheck['info']['port']
            );
        } else {
            $result['note'] = 'Comment or remove REDIS_HOST from config/.env if not using Redis';
        }

        return $result;
    }

    /**
     * Check Vite manifest file exists (only when dev server is not running)
     *
     * @return array
     */
    protected function checkViteManifest(): array
    {
        $manifestPath = WWW_ROOT . 'build' . DS . '.vite' . DS . 'manifest.json';
        $hotFile = ROOT . DS . 'hot';

        $result = [
            'name' => 'vite',
            'label' => 'Vite Assets',
            'description' => 'Production build manifest',
            'required' => true,
            'status' => true,
            'error' => null,
            'showForm' => false,
        ];

        // Check if Vite dev server is running (hot file exists with valid URL)
        $devServerRunning = false;
        if (file_exists($hotFile)) {
            $devServerUrl = trim(file_get_contents($hotFile));
            if (filter_var($devServerUrl, FILTER_VALIDATE_URL)) {
                // Quick check if dev server responds
                $context = stream_context_create([
                    'http' => ['timeout' => 1, 'method' => 'HEAD'],
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);
                $headers = @get_headers($devServerUrl, false, $context);
                $devServerRunning = $headers && strpos($headers[0], '200') !== false;
            }
        }

        // If dev server is running, manifest is not required
        if ($devServerRunning) {
            $result['description'] = 'Dev server running';
            return $result;
        }

        // Dev server not running, manifest is required
        $result['status'] = file_exists($manifestPath);
        if (!$result['status']) {
            $result['error'] = 'Vite manifest not found. Run: npm run build';
        }

        return $result;
    }

    /**
     * Get existing database configuration
     *
     * @return array
     */
    protected function getExistingDbConfig(): array
    {
        $defaults = [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => '3306',
            'database' => '',
            'username' => '',
            'password' => '',
        ];

        // First try to parse DATABASE_URL from environment
        $databaseUrl = env('DATABASE_URL');
        if ($databaseUrl) {
            $parsed = parse_url($databaseUrl);
            if ($parsed) {
                $driver = 'mysql';
                $scheme = $parsed['scheme'] ?? 'mysql';
                if (str_contains($scheme, 'postgres') || $scheme === 'pgsql') {
                    $driver = 'pgsql';
                } elseif (str_contains($scheme, 'sqlite')) {
                    $driver = 'sqlite';
                }

                return [
                    'driver' => $driver,
                    'host' => $parsed['host'] ?? $defaults['host'],
                    'port' => (string)($parsed['port'] ?? ($driver === 'pgsql' ? '5432' : '3306')),
                    'database' => ltrim($parsed['path'] ?? '', '/') ?: $defaults['database'],
                    'username' => $parsed['user'] ?? $defaults['username'],
                    'password' => $parsed['pass'] ?? $defaults['password'],
                ];
            }
        }

        return $defaults;
    }

    /**
     * Render environment setup page showing all check results
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @param array $checks All environment check results
     * @param array|null $submittedConfig Submitted form values for database
     * @return \Cake\Http\Response
     */
    protected function renderEnvironmentSetup(ServerRequestInterface $request, array $checks, ?array $submittedConfig = null): Response
    {
        // Build check items HTML
        $checkItemsHtml = '';
        $dbCheck = $checks['database'] ?? null;
        $showDbForm = false;

        foreach ($checks as $key => $check) {
            $status = $check['status'] ? 'success' : 'error';
            $icon = $check['status'] ? '✓' : '✗';
            $label = htmlspecialchars($check['label'] ?? $key);
            $description = htmlspecialchars($check['description'] ?? '');
            $error = '';

            if (!$check['status'] && !empty($check['error'])) {
                $error = '<div class="check-error">' . htmlspecialchars($check['error']) . '</div>';
                if (!empty($check['note'])) {
                    $error .= '<div class="check-note"><strong>Tip:</strong> ' . htmlspecialchars($check['note']) . '</div>';
                }
            }

            if ($key === 'database' && !$check['status']) {
                $showDbForm = true;
            }

            $checkItemsHtml .= <<<HTML
                <div class="check-item {$status}">
                    <span class="status-icon">{$icon}</span>
                    <div class="check-content">
                        <strong>{$label}</strong>
                        <span class="check-description">{$description}</span>
                        {$error}
                    </div>
                </div>
HTML;
        }

        // Build database form HTML if needed
        $dbFormHtml = '';
        if ($showDbForm && $dbCheck) {
            $config = $submittedConfig ?? $this->getExistingDbConfig();
            $host = htmlspecialchars($config['host']);
            $port = htmlspecialchars($config['port']);
            $database = htmlspecialchars($config['database']);
            $username = htmlspecialchars($config['username']);
            $mysqlSelected = $config['driver'] === 'mysql' ? 'selected' : '';
            $pgsqlSelected = $config['driver'] === 'pgsql' ? 'selected' : '';
            $sqliteSelected = $config['driver'] === 'sqlite' ? 'selected' : '';
            $csrfToken = htmlspecialchars($this->getCsrfToken($request));
            $csrfTokenName = self::CSRF_TOKEN_NAME;

            $dbFormHtml = <<<HTML
            <div class="section db-form">
                <h2>Configure Database</h2>
                <form action="/setup/database" method="POST">
                    <input type="hidden" name="{$csrfTokenName}" value="{$csrfToken}">

                    <div class="form-group">
                        <label for="driver">Driver</label>
                        <select name="driver" id="driver" required>
                            <option value="mysql" {$mysqlSelected}>MySQL / MariaDB</option>
                            <option value="pgsql" {$pgsqlSelected}>PostgreSQL</option>
                            <option value="sqlite" {$sqliteSelected}>SQLite</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="host">Host</label>
                            <input type="text" name="host" id="host" value="{$host}" required>
                        </div>
                        <div class="form-group small">
                            <label for="port">Port</label>
                            <input type="text" name="port" id="port" value="{$port}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="database">Database Name</label>
                        <input type="text" name="database" id="database" value="{$database}" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" name="username" id="username" value="{$username}" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" name="password" id="password">
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn">Test & Save</button>
                    </div>
                </form>
            </div>
HTML;
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Environment Setup Required</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            max-width: 600px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            padding: 24px 32px;
            display: flex;
            align-items: center;
            gap: 16px;
            border-bottom: 1px solid #e2e8f0;
        }
        .logo {
            width: 48px;
            height: 48px;
            background: #ef4444;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }
        .logo svg { width: 24px; height: 24px; }
        .header-text h1 { font-size: 1.125rem; color: #1e293b; margin-bottom: 2px; }
        .header-text .subtitle { color: #64748b; font-size: 0.8rem; }
        .content { padding: 20px; }
        .section { margin-bottom: 20px; }
        .section h2 {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .checks { display: flex; flex-direction: column; gap: 8px; }
        .check-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .check-item.success { background: #f0fdf4; border-color: #bbf7d0; }
        .check-item.error { background: #fef2f2; border-color: #fecaca; }
        .status-icon { font-size: 1rem; line-height: 1; flex-shrink: 0; padding-top: 2px; }
        .check-item.success .status-icon { color: #10b981; }
        .check-item.error .status-icon { color: #ef4444; }
        .check-content { flex: 1; }
        .check-content strong { color: #1e293b; font-size: 0.875rem; display: block; }
        .check-description { color: #64748b; font-size: 0.75rem; }
        .check-error { color: #dc2626; font-size: 0.75rem; margin-top: 4px; font-family: monospace; word-break: break-all; }
        .check-note { color: #b45309; font-size: 0.75rem; margin-top: 8px; padding: 6px 8px; background: #fef3c7; border-radius: 4px; border-left: 3px solid #f59e0b; }
        .db-form { background: #f8fafc; border-radius: 8px; padding: 16px; border: 1px solid #e2e8f0; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: 0.75rem; font-weight: 500; color: #374151; margin-bottom: 4px; }
        .form-group input, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-row { display: flex; gap: 12px; }
        .form-row .form-group { flex: 1; }
        .form-row .form-group.small { flex: 0 0 80px; }
        .form-actions { text-align: center; margin-top: 16px; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4); }
        .footer {
            padding: 16px 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            background: #f8fafc;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 8v4M12 16h.01"/>
                </svg>
            </div>
            <div class="header-text">
                <h1>Environment Setup Required</h1>
                <p class="subtitle">Please resolve the following issues to continue.</p>
            </div>
        </div>

        <div class="content">
            <div class="section">
                <h2>Environment Checks</h2>
                <div class="checks">
                    {$checkItemsHtml}
                </div>
            </div>

            {$dbFormHtml}
        </div>

        <div class="footer">
            <button onclick="location.reload()" class="btn">Recheck Environment</button>
        </div>
    </div>

    <script>
        document.getElementById('driver')?.addEventListener('change', function() {
            const port = document.getElementById('port');
            if (!port.value || port.value === '3306' || port.value === '5432') {
                switch(this.value) {
                    case 'mysql': port.value = '3306'; break;
                    case 'pgsql': port.value = '5432'; break;
                    case 'sqlite': port.value = ''; break;
                }
            }
        });
    </script>
</body>
</html>
HTML;

        $response = new Response();

        return $response
            ->withStatus(503)
            ->withType('text/html')
            ->withStringBody($html);
    }

    /**
     * Render database setup page
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Request
     * @param array $dbCheck Database check result
     * @param array|null $submittedConfig Submitted form values (used on error to preserve user input)
     * @return \Cake\Http\Response
     */
    protected function renderDatabaseSetup(ServerRequestInterface $request, array $dbCheck, ?array $submittedConfig = null): Response
    {
        $error = $dbCheck['error'] ?? '';
        $errorHtml = $error ? '<div class="alert alert-error">' . htmlspecialchars($error) . '</div>' : '';

        // Use submitted values if available, otherwise get from .env
        $config = $submittedConfig ?? $this->getExistingDbConfig();
        $host = htmlspecialchars($config['host']);
        $port = htmlspecialchars($config['port']);
        $database = htmlspecialchars($config['database']);
        $username = htmlspecialchars($config['username']);

        // Driver select options
        $mysqlSelected = $config['driver'] === 'mysql' ? 'selected' : '';
        $pgsqlSelected = $config['driver'] === 'pgsql' ? 'selected' : '';
        $sqliteSelected = $config['driver'] === 'sqlite' ? 'selected' : '';

        // Generate CSRF token
        $csrfToken = htmlspecialchars($this->getCsrfToken($request));
        $csrfTokenName = self::CSRF_TOKEN_NAME;

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Configuration</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            max-width: 500px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            padding: 24px 32px;
            display: flex;
            align-items: center;
            gap: 16px;
            border-bottom: 1px solid #e2e8f0;
        }
        .logo {
            width: 48px;
            height: 48px;
            background: #f59e0b;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }
        .logo svg { width: 24px; height: 24px; }
        .header-text h1 { font-size: 1.125rem; color: #1e293b; margin-bottom: 2px; }
        .header-text .subtitle { color: #64748b; font-size: 0.8rem; }
        .form { padding: 20px; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 500;
            color: #374151;
            margin-bottom: 4px;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-row { display: flex; gap: 12px; }
        .form-row .form-group { flex: 1; }
        .form-row .form-group.small { flex: 0 0 90px; }
        .form-actions { margin-top: 20px; text-align: center; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            transition: all 0.2s;
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .alert {
            padding: 10px 14px;
            border-radius: 6px;
            margin: 0 20px 16px;
            font-size: 0.8rem;
        }
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <ellipse cx="12" cy="5" rx="9" ry="3"/>
                    <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
                    <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                </svg>
            </div>
            <div class="header-text">
                <h1>Database Configuration</h1>
                <p class="subtitle">Configure your database connection to continue.</p>
            </div>
        </div>

        {$errorHtml}

        <form action="/setup/database" method="POST" class="form">
            <input type="hidden" name="{$csrfTokenName}" value="{$csrfToken}">

            <div class="form-group">
                <label for="driver">Database Driver</label>
                <select name="driver" id="driver" required>
                    <option value="mysql" {$mysqlSelected}>MySQL / MariaDB</option>
                    <option value="pgsql" {$pgsqlSelected}>PostgreSQL</option>
                    <option value="sqlite" {$sqliteSelected}>SQLite</option>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="host">Host</label>
                    <input type="text" name="host" id="host" value="{$host}" required>
                </div>
                <div class="form-group small">
                    <label for="port">Port</label>
                    <input type="text" name="port" id="port" value="{$port}" required>
                </div>
            </div>

            <div class="form-group">
                <label for="database">Database Name</label>
                <input type="text" name="database" id="database" value="{$database}" required placeholder="my_database">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" value="{$username}" required placeholder="root">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" placeholder="••••••••">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                    Test & Save Configuration
                </button>
            </div>
        </form>
    </div>

    <script>
        document.getElementById('driver').addEventListener('change', function() {
            const port = document.getElementById('port');
            if (!port.value || port.value === '3306' || port.value === '5432') {
                switch(this.value) {
                    case 'mysql': port.value = '3306'; break;
                    case 'pgsql': port.value = '5432'; break;
                    case 'sqlite': port.value = ''; break;
                }
            }
        });
    </script>
</body>
</html>
HTML;

        $response = new Response();

        return $response
            ->withStatus(503)
            ->withType('text/html')
            ->withStringBody($html);
    }
}
