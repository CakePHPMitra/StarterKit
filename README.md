# CakePHP 5 StarterKit

[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![CakePHP 5.x](https://img.shields.io/badge/CakePHP-5.x-red.svg)](https://cakephp.org)

A ready-to-use CakePHP 5 starter kit with DDEV, Redis support, and environment validation.

---

## Features

- CakePHP 5.x skeleton
- DDEV local development environment
- Redis caching & sessions (optional, falls back to file-based)
- Environment prerequisite validation before boot
- Database connection setup wizard
- Vite integration with Hot Module Replacement (HMR)

## Requirements

- PHP 8.1+
- Required PHP extensions: intl, mbstring, openssl, pdo, json
- Composer
- Node.js & npm (if using frontend assets)
- MySQL/MariaDB or PostgreSQL
- DDEV (optional, for local development)

## Installation

### Using DDEV (Recommended)

1. Clone the repository:

   ```bash
   git clone https://github.com/CakePHPMitra/StarterKit.git my-app
   cd my-app
   ```

2. Start DDEV:

   ```bash
   ddev start
   ```

3. Install dependencies:

   ```bash
   ddev composer install
   ```

4. Launch the application:

   ```bash
   ddev launch
   ```

### Manual Installation

1. Clone the repository:

   ```bash
   git clone https://github.com/CakePHPMitra/StarterKit.git my-app
   cd my-app
   ```

2. Install PHP dependencies:

   ```bash
   composer install
   ```

3. Install Node dependencies (if applicable):

   ```bash
   npm install
   ```

4. Configure environment:

   ```bash
   cp config/.env.example config/.env
   ```

5. Set directory permissions:

   ```bash
   chmod -R 775 logs tmp
   ```

6. Start the built-in server:

   ```bash
   bin/cake server -p 8765
   ```

7. Visit `http://localhost:8765`

## Configuration

- Copy `config/.env.example` to `config/.env` and configure your environment
- Redis is automatically enabled when `REDIS_HOST` is set
- Database configuration can be done via the setup wizard or manually in `.env`

## Vite Integration

This starter kit includes [CakePhpViteHelper](https://github.com/CakePHPMitra/Vite-Plugin) for modern frontend asset bundling with Hot Module Replacement.

### Setup Vite

```bash
bin/cake vite-helper install
```

This will install Node dependencies and configure Vite for your project. The installer automatically configures Vite to work with:
- **DDEV** - Uses `DDEV_HOSTNAME` environment variable
- **Local network** - Detects Ethernet/Wi-Fi IP for LAN access
- **Localhost** - Falls back to `0.0.0.0` for local development

### DDEV Configuration

Add the following to your `.ddev/config.yaml` to expose the Vite port:

```yaml
web_extra_exposed_ports:
  - name: vite
    container_port: 5173
    http_port: 5172
    https_port: 5173
```

Then restart DDEV:

```bash
ddev restart
```

### Development

Run the Vite dev server for HMR:

```bash
# Local development
npm run dev

# With DDEV
ddev exec npm run dev
```

The Vite dev server will be accessible at:
- **Local**: `http://localhost:5173` or `http://<your-ip>:5173`
- **DDEV**: `https://your-project.ddev.site:5173`

### Production Build

Build assets for production:

```bash
npm run build
```

### Usage in Templates

Assets are loaded via the Vite helper in your layout:

```php
<?= $this->Vite->asset(['resources/js/app.js', 'resources/css/app.css']) ?>
```

Frontend assets are located in the `resources/` directory:
- `resources/js/app.js` - Main JavaScript entry
- `resources/css/app.css` - Main stylesheet

## Contributing

Contributions, issues, and feature requests are welcome!

## License

This project is available as open source under the terms of the [MIT License](https://opensource.org/licenses/MIT).
