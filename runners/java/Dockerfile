# Java Runner Image for Synapticore
FROM eclipse-temurin:17-jdk-alpine

# Install system dependencies and build tools
RUN apk add --no-cache \
    git \
    maven \
    gradle

# Create non-root user
RUN adduser -D -u 1000 runner

# Set working directory
WORKDIR /workspace

# Switch to non-root user
USER runner

# Set environment
ENV MAVEN_OPTS="-Dmaven.repo.local=/tmp/.m2/repository"
ENV GRADLE_USER_HOME=/tmp/.gradle

# Default command
CMD ["/bin/sh"]