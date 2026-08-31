#!/bin/sh
set -e

# Path for runtime-generated S3 config
S3_CONFIG_PATH="/tmp/s3.json"
S3_TEMPLATE_PATH="/config/s3.json.template"

# Generate S3 config from template if present
if [ -f "$S3_TEMPLATE_PATH" ]; then
  echo "Generating S3 config at $S3_CONFIG_PATH"
  envsubst < "$S3_TEMPLATE_PATH" > "$S3_CONFIG_PATH"
fi

# Delegate execution to original SeaweedFS entrypoint
exec /entrypoint.original.sh "$@"

