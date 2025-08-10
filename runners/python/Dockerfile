# Python Runner Image for Synapticore
FROM python:3.11-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    gcc \
    musl-dev \
    libffi-dev \
    openssl-dev

# Install common Python packages
RUN pip install --no-cache-dir \
    pytest \
    black \
    flake8 \
    mypy \
    poetry

# Create non-root user
RUN adduser -D -u 1000 runner

# Set working directory
WORKDIR /workspace

# Switch to non-root user
USER runner

# Set environment
ENV PYTHONUNBUFFERED=1
ENV PIP_CACHE_DIR=/tmp/.pip
ENV POETRY_HOME=/tmp/.poetry
ENV PATH=/tmp/.poetry/bin:$PATH

# Default command
CMD ["/bin/sh"]