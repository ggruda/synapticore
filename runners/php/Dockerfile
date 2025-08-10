# PHP Runner Image for Synapticore - Enhanced Security
FROM php:8.3-cli-alpine

# Install security tools and dependencies
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    oniguruma-dev \
    libxml2-dev \
    libcap \
    su-exec \
    && docker-php-ext-install \
    zip \
    mbstring \
    xml \
    bcmath \
    opcache \
    pcntl \
    && apk del --purge autoconf g++ make

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP tools
RUN composer global require --no-interaction --prefer-dist \
    phpunit/phpunit \
    phpstan/phpstan \
    friendsofphp/php-cs-fixer \
    squizlabs/php_codesniffer \
    && composer clear-cache

# Create non-root user with specific UID/GID
RUN addgroup -g 1000 -S runner && \
    adduser -u 1000 -S runner -G runner -h /home/runner

# Setup workspace and temp directories
RUN mkdir -p /workspace /tmp/runner && \
    chown -R runner:runner /workspace /tmp/runner /home/runner

# Setup read-only root filesystem compatibility
RUN mkdir -p /tmp/.composer /tmp/.cache /tmp/.config && \
    chown -R runner:runner /tmp/.composer /tmp/.cache /tmp/.config

# Remove dangerous binaries
RUN rm -f /usr/bin/wget /usr/bin/curl /bin/nc /usr/bin/ssh /usr/bin/scp 2>/dev/null || true

# Set security capabilities
RUN setcap -r /usr/local/bin/php 2>/dev/null || true

# Copy security configuration
COPY --chown=runner:runner security.conf /etc/runner/security.conf

# Set working directory
WORKDIR /workspace

# Switch to non-root user
USER runner

# Environment variables
ENV COMPOSER_HOME=/tmp/.composer \
    COMPOSER_CACHE_DIR=/tmp/.composer/cache \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_PROCESS_TIMEOUT=300 \
    PHP_MEMORY_LIMIT=256M \
    PATH="/home/runner/.composer/vendor/bin:${PATH}"

# Security labels
LABEL security.capabilities="none" \
      security.read_only_root="true" \
      security.no_new_privileges="true" \
      security.user="runner:1000"

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD php -v || exit 1

# Default command with resource limits
ENTRYPOINT ["/usr/local/bin/php", "-d", "memory_limit=256M", "-d", "max_execution_time=300"]
CMD ["--version"]