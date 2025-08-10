# PHP Runner Image for Synapticore
FROM php:8.3-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    oniguruma-dev \
    libxml2-dev \
    && docker-php-ext-install \
    zip \
    mbstring \
    xml \
    bcmath \
    opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create non-root user
RUN adduser -D -u 1000 runner

# Set working directory
WORKDIR /workspace

# Switch to non-root user
USER runner

# Set environment
ENV COMPOSER_HOME=/tmp/.composer
ENV COMPOSER_CACHE_DIR=/tmp/.composer/cache

# Default command
CMD ["/bin/sh"]