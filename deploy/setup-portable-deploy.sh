#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

TEMPLATE_DIR="${SCRIPT_DIR}/docker-compose"
TEMPLATE_PROD="${TEMPLATE_DIR}/docker-compose.prod.example.yml"
TEMPLATE_HAPROXY="${TEMPLATE_DIR}/docker-compose.haproxy.prod.example.yml"
TEMPLATE_ENVOY="${SCRIPT_DIR}/Envoy.blade.php"

OUTPUT_PROD="${TEMPLATE_DIR}/docker-compose.prod.yml"
OUTPUT_HAPROXY="${TEMPLATE_DIR}/docker-compose.haproxy.prod.yml"
OUTPUT_ENVOY="${PROJECT_ROOT}/Envoy.blade.php"

OPTIONAL_SERVICES=(postgres queue seaweedfs chromium)
SELECTED_SERVICES=()
NON_INTERACTIVE=false
SERVICE_LIST_ARG=""

log() {
  printf '[%s] %s\n' "$(date +'%Y-%m-%d %H:%M:%S')" "$1"
}

die() {
  printf 'ERROR: %s\n' "$1" >&2
  exit 1
}

has_selected() {
  local target="$1"
  local item
  for item in "${SELECTED_SERVICES[@]}"; do
    if [[ "$item" == "$target" ]]; then
      return 0
    fi
  done
  return 1
}

yq_docker() {
  docker run --rm -v "${PROJECT_ROOT}":/workdir -w /workdir mikefarah/yq "$@"
}

select_services_whiptail() {
  local selected
  selected=$(whiptail \
    --title "Production service selection" \
    --checklist "Select optional production services (Space to toggle, Enter to confirm).\ntraefik + cert-manager + app are always enabled." \
    22 90 12 \
    "postgres" "PostgreSQL service" ON \
    "queue" "Queue blue/green template" ON \
    "seaweedfs" "SeaweedFS blue/green template" ON \
    "chromium" "Chromium automation service" ON \
    3>&1 1>&2 2>&3) || die "Selection cancelled"

  mapfile -t SELECTED_SERVICES < <(printf '%s\n' "$selected" | tr -d '"' | tr ' ' '\n' | sed '/^$/d')
}

select_services_fallback() {
  local service
  local answer

  printf 'Whiptail is not installed, switching to prompt mode.\n' >&2
  printf 'Mandatory services: traefik, cert-manager, app\n' >&2

  for service in "${OPTIONAL_SERVICES[@]}"; do
    read -r -p "Enable optional service '${service}'? [Y/n]: " answer
    answer="${answer:-Y}"
    case "$answer" in
      Y|y|yes|YES)
        SELECTED_SERVICES+=("$service")
        ;;
      *)
        ;;
    esac
  done
}

select_services_from_arg() {
  local raw="$1"
  local normalized
  local requested

  normalized="$(printf '%s' "$raw" | tr ',' ' ')"
  mapfile -t requested < <(printf '%s\n' "$normalized" | tr ' ' '\n' | sed '/^$/d')

  SELECTED_SERVICES=()
  local item
  for item in "${requested[@]}"; do
    case "$item" in
      postgres|queue|seaweedfs|chromium)
        SELECTED_SERVICES+=("$item")
        ;;
      *)
        die "Unknown service in --services: $item"
        ;;
    esac
  done
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --non-interactive)
        NON_INTERACTIVE=true
        shift
        ;;
      --services)
        [[ $# -ge 2 ]] || die "--services requires a value"
        SERVICE_LIST_ARG="$2"
        shift 2
        ;;
      -h|--help)
        cat <<'EOF'
Usage:
  bash deploy/setup-portable-deploy.sh [--non-interactive] [--services "postgres queue seaweedfs chromium"]

Notes:
  - Mandatory services (always enabled): traefik, cert-manager, app
  - Templates are read from deploy/docker-compose/*.example.yml
  - Generated files are written to deploy/docker-compose/*.yml
  - If --non-interactive is set without --services, all optional services are enabled.
EOF
        exit 0
        ;;
      *)
        die "Unknown argument: $1"
        ;;
    esac
  done
}

ensure_envoy_installed() {
  if ! command -v composer >/dev/null 2>&1; then
    die "composer is not installed"
  fi

  if composer --working-dir="${PROJECT_ROOT}" show laravel/envoy >/dev/null 2>&1; then
    log "laravel/envoy already installed"
  else
    log "Installing laravel/envoy in project"
    composer --working-dir="${PROJECT_ROOT}" require --dev laravel/envoy --no-interaction
  fi
}

apply_service_selection() {
  local rel_prod="$(realpath --relative-to="${PROJECT_ROOT}" "${OUTPUT_PROD}")"

  if ! has_selected "postgres"; then
    log "Disabling postgres service"
    yq_docker eval -i 'del(.services.postgres)' "${rel_prod}"
    yq_docker eval -i 'del(.volumes.postgres_data)' "${rel_prod}"
  fi

  if ! has_selected "queue"; then
    log "Disabling queue template"
    yq_docker eval -i 'del(."x-blue-green-service-templates".queue)' "${rel_prod}"
    yq_docker eval -i 'del(.volumes.queue_logs)' "${rel_prod}"
  fi

  if ! has_selected "seaweedfs"; then
    log "Disabling seaweedfs template"
    yq_docker eval -i 'del(."x-blue-green-service-templates".seaweedfs)' "${rel_prod}"
    yq_docker eval -i 'del(.volumes."seaweedfs-data")' "${rel_prod}"
  fi

  if ! has_selected "chromium"; then
    log "Disabling chromium service"
    yq_docker eval -i 'del(.services.chromium)' "${rel_prod}"
  fi
}

main() {
  parse_args "$@"

  [[ -f "${TEMPLATE_PROD}" ]] || die "Template not found: ${TEMPLATE_PROD}"
  [[ -f "${TEMPLATE_HAPROXY}" ]] || die "Template not found: ${TEMPLATE_HAPROXY}"
  [[ -f "${TEMPLATE_ENVOY}" ]] || die "Template not found: ${TEMPLATE_ENVOY}"
  [[ -d "${TEMPLATE_DIR}/docker" ]] || die "Docker template directory not found: ${TEMPLATE_DIR}/docker"

  log "Portable deploy setup started"
  log "Mandatory services: traefik, cert-manager, app"

  ensure_envoy_installed

  if [[ -n "$SERVICE_LIST_ARG" ]]; then
    select_services_from_arg "$SERVICE_LIST_ARG"
  elif [[ "$NON_INTERACTIVE" == true ]]; then
    SELECTED_SERVICES=("${OPTIONAL_SERVICES[@]}")
  elif command -v whiptail >/dev/null 2>&1; then
    select_services_whiptail
  else
    select_services_fallback
  fi

  cp "${TEMPLATE_PROD}" "${OUTPUT_PROD}"
  cp "${TEMPLATE_HAPROXY}" "${OUTPUT_HAPROXY}"
  ln -sfn "deploy/Envoy.blade.php" "${OUTPUT_ENVOY}"

  apply_service_selection

  log "Generated ${OUTPUT_PROD}"
  log "Generated ${OUTPUT_HAPROXY}"
  log "Linked ${OUTPUT_ENVOY} -> deploy/Envoy.blade.php"
  log "Docker contexts are read directly from ${TEMPLATE_DIR}/docker"
  log "Done"
}

main "$@"
