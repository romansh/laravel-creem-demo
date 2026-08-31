#!/usr/bin/env bash

set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  compose-stack.sh render-blue-green <source> <output> <color>
  compose-stack.sh list-blue-green <source> <color>
  compose-stack.sh list-required-secrets <compose>
  compose-stack.sh has-service <compose> <service>
  compose-stack.sh has-secret <compose> <secret>
EOF
}

yq_docker() {
  docker run --rm -v "${PWD}":/workdir -w /workdir mikefarah/yq "$@"
}

list_template_services() {
  local source_file="$1"

  yq_docker eval '."x-blue-green-service-templates" // {} | keys | .[]' "$source_file" 2>/dev/null || true
}

base_has_service() {
  local compose_file="$1"
  local service_name="$2"

  yq_docker eval -e ".services | has(\"${service_name}\")" "$compose_file" >/dev/null 2>&1
}

render_blue_green() {
  local source_file="$1"
  local output_file="$2"
  local deployment_color="$3"
  local tmp_root=".compose-stack.tmp"
  local services=()
  local service_name
  local source_json
  local rendered_json
  local rendered_yaml

  mkdir -p "$tmp_root"
  while IFS= read -r service_name; do
    [[ -n "$service_name" ]] || continue
    services+=("$service_name")
  done < <(list_template_services "$source_file")

  if [[ ${#services[@]} -eq 0 ]]; then
    echo "No blue-green service templates found in ${source_file}" >&2
    rmdir "$tmp_root" 2>/dev/null || true
    return 1
  fi

  source_json=$(mktemp -p "$tmp_root" source.XXXXXX.json)
  rendered_json=$(mktemp -p "$tmp_root" rendered.XXXXXX.json)
  rendered_yaml=$(mktemp -p "$tmp_root" rendered.XXXXXX.yml)

  yq_docker eval -o=json '.' "$source_file" > "$source_json"

  jq --arg color "$deployment_color" '
    def drop_env_keys($keys_to_drop):
      if has("environment") and (.environment | type) == "object" then
        .environment |= with_entries(
          .key as $env_key
          | select(($keys_to_drop | index($env_key)) | not)
        )
      else
        .
      end;
    def drop_secret_names($secret_names):
      if has("secrets") then
        .secrets |= map(
          . as $secret_entry
          | if type == "object" then
            select(($secret_names | index(($secret_entry.source // ""))) | not)
          else
            select(($secret_names | index($secret_entry)) | not)
          end
        )
      else
        .
      end;
    def prune_optional_integrations($base_services):
      .
      | if (($base_services | has("postgres")) | not) then
          drop_secret_names(["db_password"])
          | drop_env_keys(["DB_HOST", "DB_PORT", "DB_USERNAME", "DB_PASSWORD"])
        else
          .
        end
      | if (($base_services | has("seaweedfs")) | not) then
          drop_env_keys(["SEAWEEDFS_URL", "SEAWEEDFS_ENDPOINT", "SEAWEEDFS_BUCKET"])
        else
          .
        end;
    def rewrite_depends_on($keys; $color; $base_services):
      if has("depends_on") then
        .depends_on |= (
          if type == "array" then
            map(
              . as $dependency
              | if ($keys | index($dependency)) != null then
                $dependency + "-" + $color
              elif (($base_services | has($dependency)) | not) then
                empty
              else
                $dependency
              end
            )
          elif type == "object" then
            with_entries(
              .key as $dependency
              | if ($keys | index($dependency)) != null then
                .key = ($dependency + "-" + $color)
              elif (($base_services | has($dependency)) | not) then
                empty
              else
                .
              end
            )
          else
            .
          end
        )
      else
        .
      end;
    . as $root
    | ($root["x-blue-green-service-templates"] // {}) as $templates
    | ($templates | keys) as $keys
    | .services += (
      reduce $keys[] as $name (
        {};
        . + {
          (($name + "-" + $color)): (
            $templates[$name]
            | rewrite_depends_on($keys; $color; $root.services)
            | prune_optional_integrations($root.services)
          )
        }
      )
    )
    | del(.["x-blue-green-service-templates"])
  ' "$source_json" > "$rendered_json"

  yq_docker eval -p=json -o=yaml -P '.' "$rendered_json" > "$rendered_yaml"

  export DEPLOYMENT_COLOR="$deployment_color"
  envsubst < "$rendered_yaml" > "$output_file"

  rm -f "$source_json" "$rendered_json" "$rendered_yaml"
  rmdir "$tmp_root" 2>/dev/null || true
}

list_blue_green() {
  local source_file="$1"
  local deployment_color="$2"
  local service_name

  while IFS= read -r service_name; do
    [[ -n "$service_name" ]] || continue
    printf '%s-%s\n' "$service_name" "$deployment_color"
  done < <(list_template_services "$source_file")
}

list_required_secrets() {
  local compose_file="$1"
  local tmp_root=".compose-stack.tmp"
  local compose_json

  mkdir -p "$tmp_root"
  compose_json=$(mktemp -p "$tmp_root" secrets.XXXXXX.json)
  yq_docker eval -o=json '.' "$compose_file" > "$compose_json"

  jq -r '
    [
      (.services // {})
      | to_entries[]
      | (.value.secrets // [])[]?
      | if type == "object" then (.source // empty) else . end
      | select(type == "string" and . != "")
    ]
    | unique
    | .[]
  ' "$compose_json" 2>/dev/null || true

  rm -f "$compose_json"
  rmdir "$tmp_root" 2>/dev/null || true
}

has_service() {
  local compose_file="$1"
  local service_name="$2"

  if base_has_service "$compose_file" "$service_name"; then
    printf 'true\n'
  else
    printf 'false\n'
    return 1
  fi
}

has_secret() {
  local compose_file="$1"
  local secret_name="$2"

  if list_required_secrets "$compose_file" | grep -qx "$secret_name"; then
    printf 'true\n'
  else
    printf 'false\n'
    return 1
  fi
}

main() {
  local command="${1:-}"

  case "$command" in
    render-blue-green)
      [[ $# -eq 4 ]] || {
        usage >&2
        exit 1
      }
      render_blue_green "$2" "$3" "$4"
      ;;
    list-blue-green)
      [[ $# -eq 3 ]] || {
        usage >&2
        exit 1
      }
      list_blue_green "$2" "$3"
      ;;
    list-required-secrets)
      [[ $# -eq 2 ]] || {
        usage >&2
        exit 1
      }
      list_required_secrets "$2"
      ;;
    has-service)
      [[ $# -eq 3 ]] || {
        usage >&2
        exit 1
      }
      has_service "$2" "$3"
      ;;
    has-secret)
      [[ $# -eq 3 ]] || {
        usage >&2
        exit 1
      }
      has_secret "$2" "$3"
      ;;
    *)
      usage >&2
      exit 1
      ;;
  esac
}

main "$@"