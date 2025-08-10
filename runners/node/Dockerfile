# Node.js Runner Image for Synapticore - Enhanced Security
FROM node:20-alpine

# Install security tools and build dependencies
RUN apk add --no-cache \
    git \
    python3 \
    make \
    g++ \
    libcap \
    su-exec \
    && npm install -g \
    typescript \
    @types/node \
    eslint \
    prettier \
    jest \
    @jest/globals \
    ts-jest \
    ts-node \
    nodemon \
    pm2 \
    webpack \
    webpack-cli \
    @babel/core \
    @babel/preset-env \
    @babel/preset-typescript \
    && npm cache clean --force \
    && apk del make g++

# Create non-root user (node user already exists with UID 1000)
RUN addgroup -g 1001 -S runner && \
    usermod -a -G runner node

# Setup workspace and temp directories
RUN mkdir -p /workspace /tmp/runner /tmp/.npm /tmp/.cache /tmp/.config && \
    chown -R node:node /workspace /tmp/runner /tmp/.npm /tmp/.cache /tmp/.config

# Remove dangerous binaries
RUN rm -f /usr/bin/wget /usr/bin/curl /bin/nc /usr/bin/ssh /usr/bin/scp 2>/dev/null || true

# Setup npm security
RUN npm config set audit-level moderate && \
    npm config set fund false && \
    npm install -g npm-audit-resolver

# Copy security configuration
COPY --chown=node:node security.conf /etc/runner/security.conf

# Create startup script for resource limits
RUN echo '#!/bin/sh\nulimit -m 524288\nulimit -t 300\nulimit -f 10240\nulimit -n 256\nexec "$@"' > /usr/local/bin/runner-exec && \
    chmod +x /usr/local/bin/runner-exec

# Set working directory
WORKDIR /workspace

# Switch to non-root user
USER node

# Environment variables
ENV NODE_ENV=production \
    NPM_CONFIG_PREFIX=/tmp/.npm \
    NPM_CONFIG_CACHE=/tmp/.npm-cache \
    NPM_CONFIG_TMP=/tmp \
    NODE_OPTIONS="--max-old-space-size=256" \
    PATH="/tmp/.npm/bin:${PATH}" \
    TERM=xterm-256color

# Security labels
LABEL security.capabilities="none" \
      security.read_only_root="true" \
      security.no_new_privileges="true" \
      security.user="node:1000"

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD node --version || exit 1

# Default command with resource limits
ENTRYPOINT ["/usr/local/bin/runner-exec", "node"]
CMD ["--version"]