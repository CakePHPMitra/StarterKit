<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Utility\EnvironmentChecker;
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

        // Allow static assets first (no database check needed)
        if (preg_match('/\.(css|js|ico|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot)$/i', $path)) {
            return $handler->handle($request);
        }

        // Check database status once for all route decisions
        $dbCheck = $this->checkDatabase();
        $dbConfigured = !$dbCheck['required'] || $dbCheck['status'];

        // Handle setup routes
        if (str_starts_with($path, '/setup')) {
            // SECURITY: Block setup routes if database is already configured
            if ($dbConfigured) {
                return $this->redirectToHome();
            }

            // Handle database setup form submission with CSRF validation
            if ($path === '/setup/database' && $method === 'POST') {
                return $this->handleDatabaseSetup($request);
            }

            // Allow other setup routes to pass through
            return $handler->handle($request);
        }

        // Show database setup if required and failing
        if ($dbCheck['required'] && !$dbCheck['status']) {
            return $this->renderDatabaseSetup($request, $dbCheck);
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
        // SECURITY: Check rate limit first
        if (!$this->checkRateLimit($request)) {
            $dbCheck = [
                'required' => true,
                'status' => false,
                'error' => 'Too many attempts. Please wait 15 minutes before trying again.',
            ];

            return $this->renderDatabaseSetup($request, $dbCheck);
        }

        // Validate CSRF token
        if (!$this->validateCsrfToken($request)) {
            $this->recordFailedAttempt($request);
            $remaining = $this->getRemainingAttempts($request);
            $dbCheck = [
                'required' => true,
                'status' => false,
                'error' => "Invalid security token. Please refresh the page and try again. ({$remaining} attempts remaining)",
            ];

            return $this->renderDatabaseSetup($request, $dbCheck);
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
            // Show form again with error and submitted values
            $dbCheck = [
                'required' => true,
                'status' => false,
                'error' => "Connection failed: {$result['error']} ({$remaining} attempts remaining)",
            ];

            return $this->renderDatabaseSetup($request, $dbCheck, $config);
        }

        // Update .env file
        if (!EnvironmentChecker::updateEnvFile($config)) {
            $this->recordFailedAttempt($request);
            $remaining = $this->getRemainingAttempts($request);
            $dbCheck = [
                'required' => true,
                'status' => false,
                'error' => "Failed to update .env file. Please check file permissions. ({$remaining} attempts remaining)",
            ];

            return $this->renderDatabaseSetup($request, $dbCheck, $config);
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
            'description' => 'Database connection',
            'required' => $hasTableClasses,
            'status' => false,
            'error' => null,
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
