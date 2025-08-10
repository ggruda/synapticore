# Go Runner Image for Synapticore
FROM golang:1.21-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    gcc \
    musl-dev

# Install Go tools
RUN go install golang.org/x/tools/cmd/goimports@latest && \
    go install github.com/golangci/golangci-lint/cmd/golangci-lint@latest

# Create non-root user
RUN adduser -D -u 1000 runner

# Set working directory
WORKDIR /workspace

# Switch to non-root user
USER runner

# Set environment
ENV GOCACHE=/tmp/.cache/go-build
ENV GOPATH=/tmp/go
ENV PATH=/tmp/go/bin:$PATH

# Default command
CMD ["/bin/sh"]