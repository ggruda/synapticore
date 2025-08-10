# Go Runner Image for Synapticore - Enhanced Security
FROM golang:1.21-alpine

# Install security tools and dependencies
RUN apk add --no-cache \
    git \
    gcc \
    musl-dev \
    libcap \
    su-exec \
    && go install golang.org/x/tools/cmd/goimports@latest \
    && go install github.com/golangci/golangci-lint/cmd/golangci-lint@latest \
    && go install github.com/securego/gosec/v2/cmd/gosec@latest \
    && go install golang.org/x/vuln/cmd/govulncheck@latest \
    && go install github.com/go-delve/delve/cmd/dlv@latest \
    && go install github.com/rakyll/gotest@latest \
    && go install github.com/fatih/gomodifytags@latest \
    && go install github.com/josharian/impl@latest \
    && go clean -cache -modcache \
    && apk del gcc musl-dev

# Create non-root user
RUN addgroup -g 1000 -S runner && \
    adduser -u 1000 -S runner -G runner -h /home/runner

# Setup workspace and temp directories
RUN mkdir -p /workspace /tmp/runner /tmp/.cache /tmp/.config /go/pkg /go/bin && \
    chown -R runner:runner /workspace /tmp/runner /tmp/.cache /tmp/.config /go /home/runner

# Remove dangerous binaries
RUN rm -f /usr/bin/wget /usr/bin/curl /bin/nc /usr/bin/ssh /usr/bin/scp 2>/dev/null || true

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
ENV GO111MODULE=on \
    GOPATH=/go \
    GOCACHE=/tmp/.cache/go-build \
    GOMODCACHE=/go/pkg/mod \
    GOPROXY=https://proxy.golang.org,direct \
    GOSUMDB=sum.golang.org \
    GOPRIVATE="" \
    CGO_ENABLED=0 \
    GOMAXPROCS=2 \
    PATH="/go/bin:${PATH}"

# Security labels
LABEL security.capabilities="none" \
      security.read_only_root="true" \
      security.no_new_privileges="true" \
      security.user="runner:1000"

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD go version || exit 1

# Default command with resource limits
ENTRYPOINT ["/usr/local/bin/runner-exec", "go"]
CMD ["version"]