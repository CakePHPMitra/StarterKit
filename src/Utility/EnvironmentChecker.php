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
