# Laravel Creem Demo

> Demo application showcasing [romansh/laravel-creem](https://github.com/romansh/laravel-creem) payment integration with Creem.io

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## ✨ Key Features

- ✅ **Zero Setup Configuration** - Configure API keys via web interface (no .env editing!)
- ✅ **One-Command Installation** - `composer create-project` + `composer run setup`
- ✅ **Product Management** - One-time purchases & subscriptions
- ✅ **Checkout Flows** - Complete payment integration
- ✅ **Webhook Support** - Real-time event handling (Cloudflare Tunnels or ngrok)
- ✅ **Livewire 4** - Modern reactive UI
- ✅ **Modular Architecture** - Clean, maintainable code structure

Built with Laravel 12, Livewire 4, and Tailwind CSS 4.

## Requirements

- PHP 8.2+
- Composer 2.x
- Node.js 18+ & npm
- SQLite (default) or MySQL/PostgreSQL
- Docker & Docker Compose (optional, for containerized setup)

## Quick Start

### Option 1: Composer Create Project (Recommended)

```bash
# Create new project
composer create-project romansh/laravel-creem-demo my-creem-app

# Navigate to directory
cd my-creem-app

# Run automated setup (installs dependencies, generates key, builds assets, runs migrations)
composer run setup

# Start development server
php artisan serve
```

Visit **http://localhost:8000/creem-demo** and configure your API keys via the web interface!

### Option 2: Using Docker (Includes Cloudflare Tunnel)

```bash
# Create project
composer create-project romansh/laravel-creem-demo my-creem-app
cd my-creem-app

# Copy environment file
cp .env.example .env

# Optional: Configure Cloudflare Tunnel for webhooks (see section below)
# Edit .env and add your tunnel credentials

# Start all services
docker-compose up -d
```

Visit **http://localhost** and configure API keys at `/creem-demo`

### Option 3: Manual Installation (Git Clone)

```bash
# Clone repository
git clone https://github.com/romansh/laravel-creem-demo.git
cd laravel-creem-demo

# Run automated setup
composer run setup

# Start development server
php artisan serve
```

Visit **http://localhost:8000/creem-demo**
## Development Workflow

### Using Docker Compose (Recommended)

Starts all services including Cloudflare Tunnel:

```bash
# Start all services (Laravel Octane + Traefik + Cloudflare Tunnel)
docker-compose up -d

# View logs
docker-compose logs -f

# Run artisan commands
docker-compose exec laravel.test php artisan migrate
docker-compose exec laravel.test php artisan tinker

# Stop services
docker-compose down
```

**Services included:**
- Laravel Octane (Swoole) - High performance app server
- Traefik - Reverse proxy
- Cloudflare Tunnel - Secure public access (if configured)

Access: http://localhost (via Traefik) or your Cloudflare domain

### Using PHP Artisan Serve

Traditional Laravel development server:

```bash
# Install dependencies
composer install
npm install

# Build assets
npm run build  # Production build
# OR
npm run dev    # Development with HMR

# Start server
php artisan serve

# ⚠️ Important: Use localhost, not IP addresses (e.g., --host=0.0.0.0)
# API requests may fail with IP-based URLs due to CORS/security restrictions


# Optional: Watch logs
php artisan pail
```

Access: http://localhost:8000

## Configuration

### 🎯 Creem API Keys - Web Interface (No .env editing!)

**The easiest way to get started:**

1. Start the application (any method above)
2. Visit `/` 
3. **You'll see a configuration form at the top of the page**
4. Get your API credentials from [Creem.io Dashboard](https://www.creem.io/dashboard/developers):
   - **API Key** - Copy from Settings → Developers
   - **Webhook Secret** - Copy from Settings → Webhooks
5. **Enter credentials in the web form** and click "Save Configuration"
6. Start testing immediately! 🎉

**Important Notes:**
- ✅ API keys are stored in session (no database pollution)
- ✅ Demo uses test mode (`test_mode=true`)canvas - use **test API keys**, not production keys
- ✅ Optional: Configure additional profiles (Profile A, Profile B) for multi-account testing
- ✅ Changes take effect immediately - no server restart needed

### Alternative: Pre-fill via .env (Optional)

You can pre-populate the web form by adding to `.env` (form values override these):

```env
# Creem API Configuration (optional - web form is recommended)
CREEM_API_KEY=your_test_api_key
CREEM_WEBHOOK_SECRET=your_webhook_secret

# Optional: Additional test profiles
CREEM_PROFILE_A_KEY=another_test_key
CREEM_PROFILE_A_SECRET=another_secret
```

### 🔌 Webhook Configuration (Local Development)

To receive real-time webhook events from Creem.io on your local machine, you need a public URL. Choose one:

#### Option 1: Cloudflare Tunnel (Recommended - Auto-configured with Docker)

**When using Docker Compose, Cloudflare Tunnel service is already included!**

##### Step 1: Get Cloudflare Tunnel Credentials

1. Go to [Cloudflare Zero Trust Dashboard](https://one.dash.cloudflare.com/)
2. Navigate to **Networks → Tunnels** (or **Access → Tunnels**)
3. Click **Create a tunnel**
4. Choose tunnel type:
   - **Named tunnel** (recommended for production) - Persistent, custom domain
   - **Quick tunnel** (for testing) - Temporary *.trycloudflare.com domain
5. Follow the setup wizard:
   - Give your tunnel a name (e.g., "creem-demo-local")
   - On the installation page, select **Docker** as connector
6. **Copy the tunnel token** from the docker run command:
   ```bash
   # Example command shown by Cloudflare:
   docker run cloudflare/cloudflared:latest tunnel --no-autoupdate run --token eyJhIjoiXXX...
   
   # Copy this part: eyJhIjoiXXX... (your token)
   ```
7. Configure tunnel route:
   - **Public hostname**: Choose subdomain (e.g., `creem-demo.yourdomain.com`) or use *.trycloudflare.com
   - **Service type**: HTTP
   - **URL**: `laravel.test:80` (this matches Docker service name)
8. Save your **Tunnel Domain** (e.g., `creem-demo.yourdomain.com`)

##### Step 2: Add to `.env`

```env
CLOUDFLARED_TUNNEL_TOKEN=eyJhIjoiXXXyourTokenHereXXX
CLOUDFLARED_TUNNEL_DOMAIN=creem-demo.yourdomain.com
```

##### Step 3: Restart Docker

```bash
docker-compose down
docker-compose up -d

# Check tunnel status
docker-compose logs cloudflared
```

You should see: `"Connection established"` or `"Registered tunnel connection"`

##### Step 4: Configure Webhook in Creem.io

1. Go to [Creem.io Webhook Settings](https://www.creem.io/dashboard/webhooks)
2. Set webhook URL:
   ```
   https://creem-demo.yourdomain.com/creem/webhook
   ```
3. Save and test webhook delivery

#### Option 2: ngrok (For `php artisan serve`)

If not using Docker, use ngrok for quick public tunneling:

1. **Install ngrok:**
   ```bash
   # Download from https://ngrok.com/download
   # Or via package manager:
   brew install ngrok/ngrok/ngrok  # macOS
   snap install ngrok              # Linux
   choco install ngrok             # Windows
   ```

2. **Start your Laravel app:**
   ```bash
   php artisan serve
   ```

3. **In another terminal, start ngrok:**
   ```bash
   ngrok http 8000
   ```

4. **Copy the forwarding URL** from ngrok output:
   ```
   Forwarding  https://abc123.ngrok.io -> http://localhost:8000
   ```

5. **Configure webhook in Creem.io:**
   - Webhook URL: `https://abc123.ngrok.io/creem/webhook`

**Note:** Free ngrok URLs change on each restart. For persistent URLs, upgrade to ngrok paid plan.
### Database Configuration

Default: SQLite (`database/database.sqlite`) - created automatically by `composer run setup`

For MySQL/PostgreSQL, update `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=creem_demo
DB_USERNAME=root
DB_PASSWORD=
```

Then run migrations:
```bash
php artisan migrate
# Or with Docker:
docker-compose exec laravel.test php artisan migrate
```

## Development Workflow

### Method 1: Using Docker Compose (Production-like Environment)

Docker Compose provides a complete environment with Octane, Traefik, and optional Cloudflare Tunnel:

```bash
# Start all services
docker-compose up -d

# View logs
docker-compose logs -f

# Run artisan commands
docker-compose exec laravel.test php artisan migrate
docker-compose exec laravel.test php artisan tinker

# Stop services
docker-compose down
```

**Services included:**
- ✅ **Laravel Octane (Swoole)** - High-performance PHP server
- ✅ **Traefik** - Reverse proxy and load balancer
- ✅ **Cloudflare Tunnel** - Secure public access (if configured in .env)

**Access:**
- Local: http://localhost (via Traefik)
- Public: https://your-tunnel-domain.com (if Cloudflare Tunnel configured)

**When to use Docker:**
- ✅ Production-like environment testing
- ✅ Need webhook support without ngrok
- ✅ Testing with Octane performance
- ✅ Deploying to server

### Method 2: Using PHP Artisan Serve (Traditional Development)

Standard Laravel development server - simple and fast for local development:

```bash
# Start development server
php artisan serve

# In another terminal: Watch and build assets with HMR
npm run dev

# Optional: Run queue worker (if testing background jobs)
php artisan queue:work

# Optional: Watch logs in real-time
php artisan pail
```

**Or use the combined dev command (runs all services in parallel):**

```bash
composer run dev
```

This starts:
- Laravel development server (port 8000)
- Queue worker
- Log viewer (Laravel Pail)
- Vite dev server with Hot Module Replacement

**Access:**
- Local: http://localhost:8000

**When to use artisan serve:**
- ✅ Quick local development
- ✅ Simple debugging
- ✅ Frontend development with HMR
- ✅ Don't need webhooks (or okay with ngrok)

### Webhook Support Summary

| Method | Webhook Solution | Public URL | Setup Complexity |
|--------|-----------------|------------|------------------|
| **Docker** | Cloudflare Tunnel (built-in) | ✅ Persistent custom domain | Medium (one-time setup) |
| **artisan serve** | ngrok | ⚠️ Temporary URL (changes each restart) | Easy (install + run) |

**Recommendation:** Use Docker if you need reliable webhook testing. Use artisan serve for quick UI/feature development.

## Architecture

### Module Structure

This demo uses [nwidart/laravel-modules](https://github.com/nwidart/laravel-modules) for clean, modular architecture:

```
Modules/
└── CreemDemo/
    ├── app/
    │   ├── Http/Controllers/     # Route controllers
    │   ├── Livewire/             # Livewire v4 components
    │   └── Models/               # Domain models
    ├── config/
    │   └── config.php            # Module configuration
    ├── database/
    │   ├── migrations/           # Database migrations
    │   └── seeders/              # Test data seeders
    ├── resources/
    │   ├── views/                # Blade templates
    │   └── assets/               # CSS/JS assets
    ├── routes/
    │   ├── web.php               # Web routes
    │   └── api.php               # API routes (if needed)
    ├── tests/                    # Module tests
    └── composer.json             # Module dependencies
```

### Routes

Auto-registered after installation:

| Method | URI | Description |
|--------|-----|-------------|
| GET | `/creem-demo` | Main dashboard with config form |
| GET | `/creem-demo/success` | Payment success page |
| POST | `/creem/webhook` | Webhook endpoint for Creem.io |

### Features Demonstrated

- ✅ **Zero-config Setup** - Configure via web UI, no .env editing
- ✅ **Product Management** - Create/manage products & subscriptions
- ✅ **Checkout Flows** - Complete payment integration with randomized test data
- ✅ **Subscription Management** - Cancel, pause, resume subscriptions
- ✅ **Webhook Monitoring** - Real-time event tracking and visualization
- ✅ **Dashboard & Statistics** - Visual overview of payments and activity
- ✅ **Multi-profile Support** - Test with multiple API key sets simultaneously
- ✅ **Session-based Storage** - Clean testing without database pollution
- ✅ **Modular Code** - Easy to extract and reuse in your own projects

## Testing

Run the included test suite:

```bash
# Run all tests
composer run test

# Or directly with PHPUnit
vendor/bin/phpunit

# With coverage (if xdebug enabled)
vendor/bin/phpunit --coverage-html coverage
```

## Troubleshooting

### Composer create-project fails

**Issue:** Package not found on Packagist

**Solution:** If not yet published to Packagist, clone manually:
```bash
git clone https://github.com/romansh/laravel-creem-demo.git
cd laravel-creem-demo
composer run setup
```

### Module Not Found

**Issue:** `Module [CreemDemo] not found`

**Solution:**
```bash
composer dump-autoload
php artisan module:list
php artisan module:discover
```

### Livewire Components Not Working

**Issue:** Livewire components not rendering or updating

**Solution:**
```bash
php artisan livewire:discover
php artisan view:clear
php artisan config:clear
php artisan optimize:clear
```

### Cloudflare Tunnel Not Connecting (Docker)

**Issue:** Tunnel shows as disconnected in dashboard

**Solutions:**

1. **Verify token is correct:**
   ```bash
   # Check .env file
   cat .env | grep CLOUDFLARED_TUNNEL_TOKEN
   ```

2. **Check tunnel status:**
   ```bash
   docker-compose logs cloudflared
   ```
   Look for: `"Connection established"` or `"Registered tunnel connection"`

3. **Verify tunnel configuration in Cloudflare dashboard:**
   - Service type: HTTP
   - URL: `laravel.test:80` (or `http://laravel.test:80`)
   - Domain matches `CLOUDFLARED_TUNNEL_DOMAIN` in .env

4. **Restart tunnel service:**
   ```bash
   docker-compose restart cloudflared
   docker-compose logs -f cloudflared
   ```

### Webhooks Not Received

**Issue:** Creem.io webhooks not triggering events in the app

**Checklist:**

1. **Verify webhook URL in Creem.io:**
   - Docker: `https://your-tunnel-domain.com/creem/webhook`
   - Artisan serve + ngrok: `https://abc123.ngrok.io/creem/webhook`

2. **Test tunnel/ngrok is working:**
   ```bash
   # Docker: Check cloudflared logs
   docker-compose logs cloudflared
   
   # ngrok: Check status
   curl http://localhost:4040/api/tunnels
   # Or visit: http://localhost:4040
   ```

3. **Test webhook endpoint manually:**
   ```bash
   # Docker
   curl -X POST https://your-tunnel-domain.com/creem/webhook \
     -H "Content-Type: application/json" \
     -d '{"event": "test", "data": {}}'
   
   # Local
   curl -X POST http://localhost:8000/creem/webhook \
     -H "Content-Type: application/json" \
     -d '{"event": "test", "data": {}}'
   ```

4. **Check Laravel logs:**
   ```bash
   # Docker
   docker-compose logs -f laravel.test
   
   # Local
   tail -f storage/logs/laravel.log
   # Or
   php artisan pail
   ```

### API Errors / Invalid Keys

**Issue:** API calls return 401/403 errors

**Solutions:**

1. **Verify using test mode keys:**
   - Demo app uses `test_mode=true`
   - Production API keys will NOT work
   - Get test keys from [Creem.io Dashboard](https://www.creem.io/dashboard/developers)

2. **Check API key is saved:**
   - Clear browser cache and reload `/creem-demo`
   - Re-enter API credentials in web form
   - Check browser dev tools → Application → Session Storage

3. **Test API connection:**
   ```bash
   # Via artisan tinker
   php artisan tinker
   >>> use Romansh\LaravelCreem\Facades\Creem;
   >>> Creem::products()->all();
   ```

### API/CORS Errors with Custom Host

**Issue:** API requests fail when using `php artisan serve --host=0.0.0.0` or IP addresses

**Solution:**
Always use `localhost` for local development:
```bash
# ✅ Correct
php artisan serve
# Access: http://localhost:8000

# ❌ Avoid
php artisan serve --host=0.0.0.0
php artisan serve --host=192.168.1.100
```

**Reason:** API providers may reject requests from IP-based URLs due to CORS policies and security restrictions. Use `localhost` to ensure proper API communication.

### Docker Build Issues

**Issue:** Docker images fail to build

**Solution:**
```bash
# Clean rebuild
docker-compose down -v
docker-compose build --no-cache
docker-compose up -d
```

### Permission Errors (Linux/macOS)

**Issue:** Permission denied errors on storage/ or bootstrap/cache/

**Solution:**
```bash
# Fix permissions
chmod -R 775 storage bootstrap/cache
chown -R $USER:www-data storage bootstrap/cache

# Or with Docker
docker-compose exec laravel.test chmod -R 775 storage bootstrap/cache
```

## What's Next?

After getting the demo running:

1. **Explore the code** - Check `Modules/CreemDemo/` for implementation examples
2. **Test workflows** - Try creating products, checkouts, and subscriptions
3. **Monitor webhooks** - Watch real-time events in the dashboard
4. **Integrate into your app** - Extract and adapt code for your project
5. **Read the docs** - Learn more about the main package

## Documentation & Links

- 📦 [Laravel Creem Package](https://github.com/romansh/laravel-creem) - Main package repository
- 📚 [Creem.io API Docs](https://docs.creem.io) - Official API documentation
- 🧩 [Laravel Modules](https://nwidart.com/laravel-modules) - Modular architecture docs
- 🔥 [Livewire](https://livewire.laravel.com) - Reactive components
- 🌐 [Cloudflare Tunnels](https://developers.cloudflare.com/cloudflare-one/connections/connect-apps/) - Tunnel documentation

## Support & Contributing

- 🐛 **Report issues:** [GitHub Issues](https://github.com/romansh/laravel-creem-demo/issues)
- 💬 **Questions:** shalabanov@gmail.com
- 🤝 **Contributions welcome** via Pull Requests
- ⭐ **Star the repo** if you find it helpful!

## License

MIT License. See [LICENSE](LICENSE) for details.

## Credits

Created by [Roman Shalabanov](https://github.com/romansh)

**Powered by:**
- [Laravel 12](https://laravel.com) - PHP framework
- [Livewire 4](https://livewire.laravel.com) - Reactive components
- [Tailwind CSS 4](https://tailwindcss.com) - Utility-first CSS
- [Laravel Octane](https://laravel.com/docs/octane) - High-performance server
- [Creem.io](https://creem.io) - Payment provider
