<?php
/**
 * Prerequisite Check
 *
 * Validates environment requirements before CakePHP loads.
 * This file is required BEFORE vendor/autoload.php
 */

/**
 * Required PHP extensions
 */
$requiredExtensions = [
    'intl' => 'Internationalization extension for locale support',
    'mbstring' => 'Multibyte string functions',
    'openssl' => 'OpenSSL for encryption',
    'pdo' => 'PDO for database connectivity',
    'json' => 'JSON encoding/decoding',
];

/**
 * Required writable directories (relative to ROOT)
 */
$writableDirectories = [
    'logs' => 'Log files directory',
    'tmp' => 'Temporary files directory',
    'tmp/cache' => 'Cache directory',
    'tmp/sessions' => 'Sessions directory',
    'tmp/tests' => 'Test cache directory',
];

// Use project root path (don't define ROOT constant as paths.php will do that)
$projectRoot = dirname(__DIR__);

$errors = [];
$checks = [
    'php_extensions' => [],
    'directories' => [],
    'vendor' => null,
    'node' => null,
];

// Check PHP extensions
foreach ($requiredExtensions as $extension => $description) {
    $loaded = extension_loaded($extension);
    $checks['php_extensions'][$extension] = [
        'name' => $extension,
        'description' => $description,
        'status' => $loaded,
    ];
    if (!$loaded) {
        $errors[] = "PHP extension '{$extension}' is not loaded";
    }
}

// Check directories
foreach ($writableDirectories as $dir => $description) {
    $path = $projectRoot . DIRECTORY_SEPARATOR . $dir;
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);

    $checks['directories'][$dir] = [
        'path' => $dir,
        'description' => $description,
        'exists' => $exists,
        'writable' => $writable,
        'status' => $exists && $writable,
    ];

    if (!$exists) {
        $errors[] = "Directory '{$dir}' does not exist";
    } elseif (!$writable) {
        $errors[] = "Directory '{$dir}' is not writable";
    }
}

// Check vendor directory
$vendorPath = $projectRoot . DIRECTORY_SEPARATOR . 'vendor';
$autoloadPath = $vendorPath . DIRECTORY_SEPARATOR . 'autoload.php';
$vendorExists = is_dir($vendorPath) && file_exists($autoloadPath);

$checks['vendor'] = [
    'path' => 'vendor',
    'description' => 'Composer dependencies',
    'exists' => is_dir($vendorPath),
    'autoload' => file_exists($autoloadPath),
    'status' => $vendorExists,
];

if (!$vendorExists) {
    $errors[] = 'Vendor directory or autoload.php not found. Run: composer install';
}

// Check node_modules directory (only if package.json exists)
$packageJsonPath = $projectRoot . DIRECTORY_SEPARATOR . 'package.json';
if (file_exists($packageJsonPath)) {
    $nodeModulesPath = $projectRoot . DIRECTORY_SEPARATOR . 'node_modules';
    $nodeModulesExists = is_dir($nodeModulesPath);

    $checks['node'] = [
        'path' => 'node_modules',
        'description' => 'Node.js dependencies',
        'package_json' => true,
        'exists' => $nodeModulesExists,
        'status' => $nodeModulesExists,
    ];

    if (!$nodeModulesExists) {
        $errors[] = 'Node modules not found. Run: npm install';
    }
}

// Check Redis extension (only if REDIS_HOST is configured)
$redisHost = getenv('REDIS_HOST') ?: ($_ENV['REDIS_HOST'] ?? ($_SERVER['REDIS_HOST'] ?? null));
if (!empty($redisHost)) {
    $redisExtLoaded = extension_loaded('redis');
    $checks['redis'] = [
        'name' => 'redis',
        'description' => 'Redis extension for caching (required when REDIS_HOST is set)',
        'host' => $redisHost,
        'extension_loaded' => $redisExtLoaded,
        'status' => $redisExtLoaded,
    ];

    if (!$redisExtLoaded) {
        $errors[] = "PHP 'redis' extension is not loaded but REDIS_HOST is configured. Either install the redis extension or remove REDIS_HOST to use file-based caching.";
    }
}

// If there are errors, render the checklist page
if (!empty($errors)) {
    http_response_code(503);
    renderPrerequisiteChecklist($checks);
    exit;
}

/**
 * Render prerequisite checklist HTML
 */
function renderPrerequisiteChecklist(array $checks): void
{
    $phpExtensions = renderPhpExtensions($checks['php_extensions']);
    $directories = renderDirectories($checks['directories']);
    $vendor = renderVendor($checks['vendor']);
    $node = isset($checks['node']) ? renderNode($checks['node']) : null;
    $redis = isset($checks['redis']) ? renderRedis($checks['redis']) : null;

    echo <<<HTML
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
            max-width: 900px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            padding: 24px 32px;
            display: flex;
            align-items: center;
            gap: 20px;
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
        .header-text h1 { font-size: 1.25rem; color: #1e293b; margin-bottom: 4px; }
        .header-text .subtitle { color: #64748b; font-size: 0.875rem; }
        .checklist { padding: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .section { background: #f8fafc; border-radius: 12px; padding: 16px; border: 1px solid #e2e8f0; }
        .section h2 {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .items { display: flex; flex-direction: column; gap: 6px; }
        .item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            border-radius: 6px;
            background: white;
            border: 1px solid #e2e8f0;
        }
        .item.success { background: #f0fdf4; border-color: #bbf7d0; }
        .item.error { background: #fef2f2; border-color: #fecaca; }
        .status-icon { font-size: 1rem; line-height: 1; flex-shrink: 0; }
        .item.success .status-icon { color: #10b981; }
        .item.error .status-icon { color: #ef4444; }
        .item-content { flex: 1; min-width: 0; }
        .item-content strong { color: #1e293b; font-size: 0.8rem; font-weight: 600; }
        .item-content .description { color: #64748b; font-size: 0.7rem; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .footer {
            padding: 16px 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            background: #f8fafc;
        }
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
        @media (max-width: 640px) {
            .header { flex-direction: column; text-align: center; padding: 20px; }
            .checklist { grid-template-columns: 1fr; }
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
                <p class="subtitle">Please resolve the following issues before the application can start.</p>
            </div>
        </div>

        <div class="checklist">
            <div class="section">
                <h2><span>📦</span> PHP Extensions</h2>
                <div class="items">{$phpExtensions}</div>
            </div>

            <div class="section">
                <h2><span>📁</span> Directory Permissions</h2>
                <div class="items">{$directories}</div>
            </div>

            <div class="section">
                <h2><span>🎼</span> Composer Dependencies</h2>
                <div class="items">{$vendor}</div>
            </div>
HTML;

    // Only show Node section if package.json exists
    if ($node !== null) {
        echo <<<HTML

            <div class="section">
                <h2><span>📦</span> Node Dependencies</h2>
                <div class="items">{$node}</div>
            </div>
HTML;
    }

    // Only show Redis section if REDIS_HOST is configured
    if ($redis !== null) {
        echo <<<HTML

            <div class="section">
                <h2><span>🔴</span> Redis Cache</h2>
                <div class="items">{$redis}</div>
            </div>
HTML;
    }

    echo <<<HTML
        </div>

        <div class="footer">
            <button onclick="location.reload()" class="btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14">
                    <path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                </svg>
                Recheck Environment
            </button>
        </div>
    </div>
</body>
</html>
HTML;
}

function renderPhpExtensions(array $extensions): string
{
    $html = '';
    foreach ($extensions as $ext) {
        $status = $ext['status'] ? 'success' : 'error';
        $icon = $ext['status'] ? '✓' : '✗';
        $html .= "<div class=\"item {$status}\"><span class=\"status-icon\">{$icon}</span><div class=\"item-content\"><strong>{$ext['name']}</strong><span class=\"description\">{$ext['description']}</span></div></div>";
    }
    return $html;
}

function renderDirectories(array $directories): string
{
    $html = '';
    foreach ($directories as $dir) {
        $status = $dir['status'] ? 'success' : 'error';
        $icon = $dir['status'] ? '✓' : '✗';
        $issue = '';
        if (!$dir['exists']) {
            $issue = ' (does not exist)';
        } elseif (!$dir['writable']) {
            $issue = ' (not writable)';
        }
        $html .= "<div class=\"item {$status}\"><span class=\"status-icon\">{$icon}</span><div class=\"item-content\"><strong>{$dir['path']}/</strong>{$issue}<span class=\"description\">{$dir['description']}</span></div></div>";
    }
    return $html;
}

function renderVendor(array $vendor): string
{
    $status = $vendor['status'] ? 'success' : 'error';
    $icon = $vendor['status'] ? '✓' : '✗';
    $message = $vendor['status'] ? 'Installed' : 'Run: composer install';
    return "<div class=\"item {$status}\"><span class=\"status-icon\">{$icon}</span><div class=\"item-content\"><strong>vendor/</strong><span class=\"description\">{$message}</span></div></div>";
}

function renderNode(array $node): string
{
    $status = $node['status'] ? 'success' : 'error';
    $icon = $node['status'] ? '✓' : '✗';
    $message = $node['status'] ? 'Installed' : 'Run: npm install';
    return "<div class=\"item {$status}\"><span class=\"status-icon\">{$icon}</span><div class=\"item-content\"><strong>node_modules/</strong><span class=\"description\">{$message}</span></div></div>";
}

function renderRedis(array $redis): string
{
    $status = $redis['status'] ? 'success' : 'error';
    $icon = $redis['status'] ? '✓' : '✗';
    $host = htmlspecialchars($redis['host']);
    $message = $redis['status']
        ? "Extension loaded, host: {$host}"
        : "Install redis extension or unset REDIS_HOST";
    return "<div class=\"item {$status}\"><span class=\"status-icon\">{$icon}</span><div class=\"item-content\"><strong>redis extension</strong><span class=\"description\">{$message}</span></div></div>";
}
