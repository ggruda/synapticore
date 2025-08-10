# Node.js Runner Image for Synapticore
FROM node:20-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    python3 \
    make \
    g++

# Install global packages
RUN npm install -g \
    typescript \
    eslint \
    prettier \
    jest

# Create non-root user
RUN adduser -D -u 1000 runner

# Set working directory
WORKDIR /workspace

# Switch to non-root user
USER runner

# Set environment
ENV NODE_ENV=development
ENV NPM_CONFIG_CACHE=/tmp/.npm
ENV NPM_CONFIG_PREFIX=/tmp/.npm-global
ENV PATH=/tmp/.npm-global/bin:$PATH

# Default command
CMD ["/bin/sh"]