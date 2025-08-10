# Java Runner Image for Synapticore - Enhanced Security
FROM eclipse-temurin:17-jdk-alpine

# Install security tools and build tools
RUN apk add --no-cache \
    git \
    maven \
    gradle \
    libcap \
    su-exec \
    && rm -rf /var/cache/apk/*

# Install additional Java tools
RUN mkdir -p /opt/tools && \
    cd /opt/tools && \
    # Download SpotBugs
    wget -q https://github.com/spotbugs/spotbugs/releases/download/4.7.3/spotbugs-4.7.3.zip && \
    unzip -q spotbugs-4.7.3.zip && \
    rm spotbugs-4.7.3.zip && \
    # Download Checkstyle
    wget -q https://github.com/checkstyle/checkstyle/releases/download/checkstyle-10.12.0/checkstyle-10.12.0-all.jar && \
    # Download PMD
    wget -q https://github.com/pmd/pmd/releases/download/pmd_releases%2F6.55.0/pmd-bin-6.55.0.zip && \
    unzip -q pmd-bin-6.55.0.zip && \
    rm pmd-bin-6.55.0.zip

# Create non-root user
RUN addgroup -g 1000 -S runner && \
    adduser -u 1000 -S runner -G runner -h /home/runner

# Setup workspace and temp directories
RUN mkdir -p /workspace /tmp/runner /tmp/.m2 /tmp/.gradle /tmp/.cache && \
    chown -R runner:runner /workspace /tmp/runner /tmp/.m2 /tmp/.gradle /tmp/.cache /home/runner /opt/tools

# Remove dangerous binaries
RUN rm -f /usr/bin/wget /usr/bin/curl /bin/nc /usr/bin/ssh /usr/bin/scp 2>/dev/null || true

# Setup Maven and Gradle security
COPY --chown=runner:runner maven-settings.xml /tmp/.m2/settings.xml
RUN echo "systemProp.http.proxyHost=\nsystemProp.http.nonProxyHosts=localhost|127.0.0.1" > /tmp/.gradle/gradle.properties && \
    chown runner:runner /tmp/.gradle/gradle.properties

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
ENV JAVA_HOME=/opt/java/openjdk \
    MAVEN_HOME=/usr/share/java/maven-3 \
    GRADLE_HOME=/usr/share/java/gradle \
    M2_HOME=/usr/share/java/maven-3 \
    MAVEN_OPTS="-Xmx256m -XX:MaxMetaspaceSize=128m" \
    GRADLE_OPTS="-Xmx256m -XX:MaxMetaspaceSize=128m -Dorg.gradle.daemon=false" \
    MAVEN_USER_HOME=/tmp/.m2 \
    GRADLE_USER_HOME=/tmp/.gradle \
    _JAVA_OPTIONS="-Xmx256m -XX:MaxMetaspaceSize=128m -Djava.security.egd=file:/dev/./urandom" \
    PATH="/opt/tools/spotbugs-4.7.3/bin:/opt/tools/pmd-bin-6.55.0/bin:${MAVEN_HOME}/bin:${GRADLE_HOME}/bin:${PATH}"

# Security labels
LABEL security.capabilities="none" \
      security.read_only_root="true" \
      security.no_new_privileges="true" \
      security.user="runner:1000"

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD java -version || exit 1

# Default command with resource limits
ENTRYPOINT ["/usr/local/bin/runner-exec", "java"]
CMD ["-version"]