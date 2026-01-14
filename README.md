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

## Contributing

Contributions, issues, and feature requests are welcome!

## License

This project is available as open source under the terms of the [MIT License](https://opensource.org/licenses/MIT).
