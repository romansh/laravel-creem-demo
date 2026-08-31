FROM chrislusf/seaweedfs:latest

# Switch to root to install packages and adjust entrypoint
USER root

# Install envsubst (provided by gettext)
RUN apk add --no-cache gettext curl

# Preserve original SeaweedFS entrypoint
RUN mv /entrypoint.sh /entrypoint.original.sh

# Copy our wrapper entrypoint
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

USER seaweed

# Use our wrapper as the new entrypoint
ENTRYPOINT ["/entrypoint.sh"]

