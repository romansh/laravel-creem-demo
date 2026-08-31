FROM haproxy:alpine

USER root

RUN apk add --no-cache inotify-tools bash coreutils shadow socat && \
    rm -rf /var/cache/apk/* && \
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
    mkdir -p /var/run/haproxy && \
    chown haproxy:haproxy /var/run/haproxy && \
    chmod 755 /var/run/haproxy

COPY entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh

USER haproxy

EXPOSE 80 443 8404

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
