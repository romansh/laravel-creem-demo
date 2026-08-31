# Use selenium/standalone-chromium as the base image since it includes Chromium pre-installed
# This image is based on Ubuntu 24.04 and provides a ready-to-use Chromium environment
FROM selenium/standalone-chromium:latest

# Switch to root user to install additional packages
# The default user (seluser) lacks permissions for apt-get
USER root

# Clean up apt cache and lists to avoid stale data issues, then update package lists and install packages
# Install socat (for port redirection), net-tools (for netstat), and iproute2 (for ss)
RUN apt-get clean && \
    rm -rf /var/lib/apt/lists/* && \
    apt-get update && \
    apt-get install -y socat net-tools iproute2

# Copy the wrap.sh script into the container
# This script will control how Chromium is launched
COPY wrap.sh /wrap.sh

# Make the wrap.sh script executable
RUN chmod +x /wrap.sh

# Switch back to seluser (uid 1200) to run Chromium
# This maintains compatibility with the base image's file permissions
USER seluser

# Expose port 9222 to indicate that the container listens on this port
# This is metadata and does not automatically publish the port to the host
EXPOSE 9222

# Set wrap.sh as the entrypoint to run when the container starts
# This overrides any default entrypoint from the base image
ENTRYPOINT ["/wrap.sh"]