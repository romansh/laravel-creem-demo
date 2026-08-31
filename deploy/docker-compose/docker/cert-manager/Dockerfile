FROM alpine:latest

RUN apk add --no-cache jq openssl inotify-tools shadow busybox && \
    if ! getent group sslcerts >/dev/null; then \
        addgroup -g 1001 sslcerts; \
    else \
        if [ "$(getent group sslcerts | cut -d: -f3)" != "1001" ]; then \
            echo "Error: Group sslcerts has wrong GID" >&2; \
            exit 1; \
        fi; \
    fi && \
    if ! id haproxy >/dev/null 2>&1; then \
        adduser -S -u 100 haproxy -G sslcerts; \
    else \
        usermod -aG sslcerts haproxy; \
    fi && \
    mkdir -p /certs /var/log && \
    chown root:sslcerts /certs /var/log && \
    chmod 750 /certs && \
    chmod 750 /var/log

COPY ./cert-manager.sh /app/cert-manager.sh

RUN chmod +x /app/cert-manager.sh && \
    sed -i 's/\r$//' /app/cert-manager.sh

ENTRYPOINT ["/app/cert-manager.sh"]
