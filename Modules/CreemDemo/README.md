# 🚀 Creem Demo Module

**Interactive demo for Creem Laravel package - Zero configuration required!**

## Quick Start

```bash
composer require romansh/creem-demo-module
php artisan serve
```

Visit: **http://localhost:8000/creem-demo**

That's it! 🎉

## ✨ Key Features

- ✅ **Zero Setup** - No .env editing needed!
- ✅ **Web Form Configuration** - Enter API keys in the browser
- ✅ **Auto-Routes** - Routes registered automatically
- ✅ **Livewire v4** - Real-time updates
- ✅ **Beautiful UI** - Modern Tailwind CSS design
- ✅ **Uses Main Package** - Depends on `romansh/laravel-creem`

## 📦 How It Works

### Installation:
```bash
composer require romansh/creem-demo-module
```

This automatically installs:
- ✅ `nwidart/laravel-modules`
- ✅ `livewire/livewire`
- ✅ `romansh/laravel-creem` ⭐ (the main package!)

### Usage:
1. `php artisan serve`
2. Open `http://localhost:8000/creem-demo`
3. **See configuration form at the top**
4. Enter your Creem API keys in the form (note: `test_mode=true`, production keys will not work)
5. Click "Save Configuration"
6. Start testing!

## 🎯 Configuration

### Through Web Form (Recommended):

Just open `/creem-demo` and fill in the form!

- Default Profile: API Key + Webhook Secret
- Profile A: API Key + Webhook Secret (optional)
- Click "Save" - stored in session

### Through .env (Optional - for pre-filling form):

```env
# These values will pre-fill the form (optional)
CREEM_API_KEY=your_test_key
CREEM_WEBHOOK_SECRET=your_secret
CREEM_PROFILE_A_KEY=another_key
CREEM_PROFILE_A_SECRET=another_secret
```

**Note:** The form is the primary way. `.env` is just for convenience.

## 📚 What's Included

- Configuration form (saves to session)
- Product management (one-time & subscriptions)
- Checkout flows with random test data
- Subscription management (cancel, pause, resume)
- Webhook event monitoring
- Dashboard with statistics

## 🎨 Routes

Auto-registered after install:

- `GET /creem-demo` - Main dashboard
- `GET /creem-demo/success` - Success page

## 🔗 Dependencies

This module uses the main Creem package:

```json
"require": {
    "romansh/laravel-creem": "^1.0"
}
```

All API calls go through the main package's services!

## 🐛 Troubleshooting

### Module not found:
```bash
composer dump-autoload
php artisan module:list
```

### Livewire not working:
```bash
php artisan livewire:discover
php artisan view:clear
```

## 📝 License

MIT
