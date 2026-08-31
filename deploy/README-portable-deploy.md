# Portable Deploy Kit

This directory is intended to be copied between projects with minimal edits.

## Structure

- `deploy/docker-compose/docker/` - shared Docker build contexts and runtime files for production
- `deploy/docker-compose/docker-compose.prod.example.yml` - production compose template
- `deploy/docker-compose/docker-compose.haproxy.prod.example.yml` - reverse-proxy compose template
- `deploy/docker-compose/docker-compose.prod.yml` - generated production compose (created by setup script)
- `deploy/docker-compose/docker-compose.haproxy.prod.yml` - generated reverse-proxy compose (created by setup script)
- `deploy/Envoy.blade.php` - Envoy template copied to project root by setup script
- `deploy/setup-portable-deploy.sh` - interactive setup script for a project

## Quick Start

Run from project root:

```bash
bash deploy/setup-portable-deploy.sh
```

What the script does:

1. Installs `laravel/envoy` via Composer if missing.
2. Lets you choose optional production services interactively.
3. Keeps `traefik`, `cert-manager`, and `app` always enabled.
4. Generates `deploy/docker-compose/docker-compose.prod.yml` and `deploy/docker-compose/docker-compose.haproxy.prod.yml` from `*.example.yml`.
5. Copies `deploy/Envoy.blade.php` to root `Envoy.blade.php`.

## Notes

- Root `docker-compose.yml`, `docker-compose.prod.yml`, and `docker-compose.haproxy.prod.yml` are configured to build directly from `deploy/docker-compose/docker/...`.
- Envoy deployment tasks read generated production compose files from `deploy/docker-compose/`.
