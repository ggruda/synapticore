# Python Runner Image for Synapticore - Enhanced Security
FROM python:3.11-alpine

# Install security tools and build dependencies
RUN apk add --no-cache \
    git \
    gcc \
    musl-dev \
    libffi-dev \
    openssl-dev \
    libcap \
    su-exec \
    && pip install --no-cache-dir --upgrade pip setuptools wheel \
    && pip install --no-cache-dir \
    pytest \
    pytest-cov \
    pytest-asyncio \
    pytest-mock \
    black \
    flake8 \
    mypy \
    pylint \
    autopep8 \
    isort \
    bandit \
    safety \
    poetry \
    pipenv \
    virtualenv \
    requests \
    numpy \
    pandas \
    && apk del gcc musl-dev libffi-dev openssl-dev

# Create non-root user
RUN addgroup -g 1000 -S runner && \
    adduser -u 1000 -S runner -G runner -h /home/runner

# Setup workspace and temp directories
RUN mkdir -p /workspace /tmp/runner /tmp/.cache /tmp/.config /tmp/.local && \
    chown -R runner:runner /workspace /tmp/runner /tmp/.cache /tmp/.config /tmp/.local /home/runner

# Remove dangerous binaries
RUN rm -f /usr/bin/wget /usr/bin/curl /bin/nc /usr/bin/ssh /usr/bin/scp 2>/dev/null || true

# Setup Python security
RUN echo "import sys\nsys.dont_write_bytecode = True" > /etc/pythonstartup.py

# Copy security configuration
COPY --chown=runner:runner security.conf /etc/runner/security.conf

# Create startup script for resource limits
RUN echo '#!/bin/sh\nulimit -m 524288\nulimit -t 300\nulimit -f 10240\nulimit -n 256\nexec "$@"' > /usr/local/bin/runner-exec && \
    chmod +x /usr/local/bin/runner-exec

# Set working directory
WORKDIR /workspace

# Switch to non-root user
USER runner

# Environment variables
ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONSTARTUP=/etc/pythonstartup.py \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PIP_DEFAULT_TIMEOUT=100 \
    PIP_INDEX_URL=https://pypi.org/simple \
    POETRY_HOME=/tmp/.poetry \
    POETRY_CACHE_DIR=/tmp/.cache/pypoetry \
    PIPENV_VENV_IN_PROJECT=1 \
    PATH="/home/runner/.local/bin:${PATH}"

# Security labels
LABEL security.capabilities="none" \
      security.read_only_root="true" \
      security.no_new_privileges="true" \
      security.user="runner:1000"

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD python --version || exit 1

# Default command with resource limits
ENTRYPOINT ["/usr/local/bin/runner-exec", "python"]
CMD ["--version"]