<?php
declare(strict_types=1);

namespace App\Utility;

use Cake\Datasource\ConnectionManager;

/**
 * Environment Checker Utility
 *
 * Provides database connection testing and .env file updates.
 */
class EnvironmentChecker
{
    /**
     * Test database connection with provided credentials
     *
     * @param array $config Database configuration
     * @return array ['success' => bool, 'error' => string|null]
     */
    public static function testDatabaseConnection(array $config): array
    {
        try {
            $driver = $config['driver'] ?? 'mysql';

            if ($driver === 'sqlite') {
                $dsn = sprintf('sqlite:%s', $config['database'] ?? '');
            } else {
                $dsn = sprintf(
                    '%s:host=%s;port=%s;dbname=%s',
                    $driver,
                    $config['host'] ?? 'localhost',
                    $config['port'] ?? '3306',
                    $config['database'] ?? ''
                );
            }

            $pdo = new \PDO(
                $dsn,
                $config['username'] ?? '',
                $config['password'] ?? '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );

            return ['success' => true, 'error' => null];
        } catch (\PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Test Redis connection with provided configuration
     *
     * @param array $config Redis configuration (host, port, timeout)
     * @return array ['success' => bool, 'error' => string|null, 'info' => array|null]
     */
    public static function testRedisConnection(array $config = []): array
    {
        // Check if redis extension is loaded
        if (!extension_loaded('redis')) {
            return [
                'success' => false,
                'error' => 'Redis PHP extension is not installed',
                'info' => null,
            ];
        }

        $host = $config['host'] ?? (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int)($config['port'] ?? (getenv('REDIS_PORT') ?: 6379));
        $timeout = (float)($config['timeout'] ?? 2.0);

        try {
            $redis = new \Redis();
            $connected = $redis->connect($host, $port, $timeout);

            if (!$connected) {
                return [
                    'success' => false,
                    'error' => "Failed to connect to Redis at {$host}:{$port}",
                    'info' => null,
                ];
            }

            // Get server info
            $info = $redis->info('server');
            $redis->close();

            return [
                'success' => true,
                'error' => null,
                'info' => [
                    'host' => $host,
                    'port' => $port,
                    'redis_version' => $info['redis_version'] ?? 'unknown',
                ],
            ];
        } catch (\RedisException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'info' => null,
            ];
        }
    }

    /**
     * Update .env file with database configuration
     *
     * SECURITY NOTES:
     * - .env files store credentials in plaintext by design
     * - File permissions are set to 0600 (owner read/write only)
     * - Ensure .env is in .gitignore (never commit to version control)
     * - For production, consider using system environment variables instead
     *
     * @param array $config Database configuration
     * @return bool
     */
    public static function updateEnvFile(array $config): bool
    {
        $envFile = ROOT . DS . 'config' . DS . '.env';
        $isNewFile = false;

        if (!file_exists($envFile)) {
            $exampleFile = ROOT . DS . 'config' . DS . '.env.example';
            if (file_exists($exampleFile)) {
                copy($exampleFile, $envFile);
                $isNewFile = true;
            } else {
                return false;
            }
        }

        $content = file_get_contents($envFile);

        // Build database URL
        $driver = $config['driver'] ?? 'mysql';
        $host = $config['host'] ?? 'localhost';
        $port = $config['port'] ?? '3306';
        $database = $config['database'] ?? '';
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';

        $dbUrl = sprintf(
            '%s://%s:%s@%s:%s/%s',
            $driver,
            urlencode($username),
            urlencode($password),
            $host,
            $port,
            $database
        );

        // Update or add DATABASE_URL (handles both "export DATABASE_URL=" and "DATABASE_URL=")
        if (preg_match('/^(export\s+)?DATABASE_URL\s*=/m', $content)) {
            $content = preg_replace(
                '/^(export\s+)?DATABASE_URL\s*=.*$/m',
                '$1DATABASE_URL="' . $dbUrl . '"',
                $content
            );
        } else {
            $content .= "\nexport DATABASE_URL=\"{$dbUrl}\"\n";
        }

        $result = file_put_contents($envFile, $content);

        if ($result !== false) {
            // SECURITY: Set restrictive file permissions (owner read/write only)
            // This prevents other users on the system from reading credentials
            @chmod($envFile, 0600);
        }

        return $result !== false;
    }
}
