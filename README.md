# Laravel Creem Demo

> Demo application showcasing [romansh/laravel-creem](https://github.com/romansh/laravel-creem) payment integration with Creem.io

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## ✨ Key Features

- ✅ **Zero Setup Configuration** - Configure API keys via web interface!
- ✅ **Product Management** - One-time purchases & subscriptions
- ✅ **Checkout Flows** - Complete payment integration
- ✅ **Webhook Support** - Real-time event handling (with Cloudflare Tunnels or ngrok)
- ✅ **Livewire 4** - Modern reactive UI
- ✅ **Modular Architecture** - Clean, maintainable code

Built with Laravel 12, Livewire 4, and Tailwind CSS 4.

## Requirements

- PHP 8.2+
- Composer 2.x
- Node.js 18+ & npm
- SQLite (default) or MySQL/PostgreSQL
- Docker & Docker Compose (optional, for containerized setup)

## Quick Start

### Option 1: Using Docker (Recommended - Includes Cloudflare Tunnel)

```bash
# Clone repository
git clone https://github.com/romansh/laravel-creem-demo.git
cd laravel-creem-demo

# Copy environment file
cp .env.example .env

# Configure Cloudflare Tunnel (optional - see Webhook Configuration below)
# Edit .env and add:
# CLOUDFLARED_TUNNEL_TOKEN=your_token
# CLOUDFLARED_TUNNEL_DOMAIN=your-tunnel.trycloudflare.com

# Start all services
docker-compose up -d

# Run migrations
docker-compose exec laravel.test php artisan migrate
```

Visit http://localhost and configure API keys via web interface at `/creem-demo`

### Option 2: Local Development (PHP Artisan Serve)

```bash
# Clone repository
git clone https://github.com/romansh/laravel-creem-demo.git
cd laravel-creem-demo

# Install dependencies
composer install
npm install

# Setup environment
cp .env.example .env
php artisan key:generate

# Run migrations
php artisan migrate

# Build assets
npm run build

# Start development server
php artisan serve
```

Visit http://localhost:8000/creem-demo
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

# Optional: Run queue worker in another terminal
php artisan queue:work

# Optional: Watch logs
php artisan pail
```

Access: http://localhost:8000

## Configuration

### Creem API Keys - Web Interface (Recommended)

**No .env editing required!** Configure everything through the web interface:

1. Start the application (Docker or `php artisan serve`)
2. Visit `/creem-demo` 
3. **Enter your Creem API credentials in the configuration form:**
   - API Key (from [Creem.io Dashboard](https://creem.io/dashboard/settings))
   - Webhook Secret (from Creem.io Webhook settings)
   - Optional: Configure additional profiles (Profile A, Profile B)
4. Click "Save Configuration"
5. Start testing immediately!

**Note:** API keys are stored in session. For test mode, use test API keys (production keys won't work with `test_mode=true`).

### Alternative: Pre-fill via .env (Optional)

You can pre-populate the web form by adding to `.env`:

```env
# Creem API Configuration (optional - form values override these)
CREEM_API_KEY=your_test_api_key
CREEM_WEBHOOK_SECRET=your_webhook_secret

# Optional: Additional profiles
CREEM_PROFILE_A_KEY=another_test_key
CREEM_PROFILE_A_SECRET=another_secret
```

### Webhook Configuration (Local Development)

To receive webhook events from Creem.io on your local machine, use one of these methods:

#### Option 1: Cloudflare Tunnel (Recommended - Auto-configured with Docker)

When using Docker Compose, Cloudflare Tunnel is already configured:

1. **Get Cloudflare Tunnel credentials:**
   - Go to [Cloudflare Zero Trust Dashboard](https://one.dash.cloudflare.com/)
   - Navigate to **Access → Tunnels**
   - Click **Create a tunnel**
   - Choose **Cloudflared** and follow setup
   - Copy the **Tunnel Token** (looks like: `eyJhIjoiXXX...`)
   - Note your **Tunnel Domain** (e.g., `your-app.trycloudflare.com` or custom domain)

2. **Add to `.env`:**
   ```env
   CLOUDFLARED_TUNNEL_TOKEN=eyJhIjoiXXXyourTokenHereXXX
   CLOUDFLARED_TUNNEL_DOMAIN=your-tunnel-name.your-domain.com
   ```

3. **Restart Docker:**
   ```bash
   docker-compose down
   docker-compose up -d
   ```

4. **Configure webhook in Creem.io:**
   - Webhook URL: `https://your-tunnel-name.your-domain.com/creem/webhook`

#### Option 2: ngrok (For php artisan serve)

If using `php artisan serve` instead of Docker:

1. **Install ngrok:**
   ```bash
   # Download from https://ngrok.com/download
   # Or via package manager
   brew install ngrok  # macOS
   ```

2. **Start ngrok tunnel:**
   ```bash
   ngrok http 8000
   ```

3. **Copy the forwarding URL** (e.g., `https://abc123.ngrok.io`)

4. **Configure webhook in Creem.io:**
   - Webhook URL: `https://abc123.ngrok.io/creem/webhook`

### Database

Default: SQLite (`database/database.sqlite`)

For MySQL/PostgreSQL, update `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=creem_demo
DB_USERNAME=root
DB_PASSWORD=
```

## Module Structure

This demo uses [nwidart/laravel-modules](https://github.com/nwidart/laravel-modules):

```
Modules/
└── CreemDemo/
    ├── app/              # Controllers, Models, Livewire components
    ├── config/           # Module configuration
    ├── database/         # Migrations, seeders
    ├── resources/        # Views, assets
    ├── routes/           # Web & API routes
    └── tests/            # Module tests
```

## Features Demonstrated

- ✅ **Web-based Configuration** - No .env editing, configure via UI
- ✅ **Product Management** - Create/manage one-time products & subscriptions
- ✅ **Checkout Flows** - Complete payment integration with test data
- ✅ **Subscription Management** - Cancel, pause, resume subscriptions
- ✅ **Webhook Monitoring** - Real-time event tracking and display
- ✅ **Dashboard & Statistics** - Visual overview of payments and subscriptions
- ✅ **Multi-profile Support** - Test with multiple API key sets
- ✅ **Session-based Storage** - No database pollution during testing

## Routes

Auto-registered routes after installation:

- `GET /creem-demo` - Main dashboard with configuration form
- `GET /creem-demo/success` - Payment success page
- `POST /creem/webhook` - Webhook endpoint (configured in Creem.io)


## Docker Deployment

The application includes Docker Compose configuration with:

- **Laravel Octane (Swoole)** - High-performance PHP server
- **Traefik** - Reverse proxy and load balancer  
- **Cloudflare Tunnel** - Secure public access without port forwarding

### Environment Variables for Docker

```env
# App
APP_ENV=production
APP_DEBUG=false
APP_URL=http://localhost

# Traefik
TRAEFIK_HOST=localhost

# Cloudflare Tunnel (optional)
CLOUDFLARED_TUNNEL_TOKEN=your_tunnel_token
CLOUDFLARED_TUNNEL_DOMAIN=your-app.trycloudflare.com

# Vite
VITE_PORT=5173
```

### Docker Commands

```bash
# Start services
docker-compose up -d

# View logs
docker-compose logs -f laravel.test

# Execute commands
docker-compose exec laravel.test php artisan migrate
docker-compose exec laravel.test php artisan optimize

# Rebuild images
docker-compose build --no-cache

# Stop services
docker-compose down
```

## Troubleshooting

### Module Not Found

```bash
composer dump-autoload
php artisan module:list
```

### Livewire Not Working

```bash
php artisan livewire:discover
php artisan view:clear
php artisan config:clear
```

### Cloudflare Tunnel Not Connecting

1. Verify `CLOUDFLARED_TUNNEL_TOKEN` is correct
2. Check tunnel status in Cloudflare dashboard
3. Ensure tunnel domain matches `CLOUDFLARED_TUNNEL_DOMAIN`
4. View logs: `docker-compose logs cloudflared`

### Webhooks Not Received

1. **Check webhook URL configuration in Creem.io:**
   - Docker: `https://your-tunnel-domain.com/creem/webhook`
   - Artisan serve + ngrok: `https://abc123.ngrok.io/creem/webhook`

2. **Verify tunnel/ngrok is running:**
   ```bash
   # Docker: Check cloudflared service
   docker-compose logs cloudflared
   
   # Artisan: Check ngrok
   curl http://localhost:4040/api/tunnels
   ```

3. **Test webhook endpoint manually:**
   ```bash
   curl -X POST http://localhost:8000/creem/webhook \
     -H "Content-Type: application/json" \
     -d '{"event": "test"}'
   ```

### API Errors

- Ensure you're using **test mode API keys** (production keys won't work with `test_mode=true`)
- Check API key is correctly saved via web form
- View session data: Check browser dev tools → Application → Session Storage

## Documentation

- [Laravel Creem Package](https://github.com/romansh/laravel-creem)
- [Creem.io API Docs](https://docs.creem.io)
- [Laravel Modules Docs](https://nwidart.com/laravel-modules)

## Support & Contributing

- Report issues: [GitHub Issues](https://github.com/romansh/laravel-creem-demo/issues)
- Contributions welcome via Pull Requests
- Questions: shalabanov@gmail.com

## License

MIT License. See [LICENSE](LICENSE) for details.

## Credits

Created by [Roman Shalabanov](https://github.com/romansh)

Powered by:
- [Laravel](https://laravel.com)
- [Livewire](https://livewire.laravel.com)
- [Tailwind CSS](https://tailwindcss.com)
- [Creem.io](https://creem.io)
