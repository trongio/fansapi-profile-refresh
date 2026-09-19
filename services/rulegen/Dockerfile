# Internal signing-rules extractor. No app source, secrets, database or Redis
# access; see docker-compose.yml for the runtime hardening.
FROM node:22-bookworm-slim

ARG CURL_IMPERSONATE_VERSION=v2.2.3
ARG CURL_IMPERSONATE_SHA256=ca7c8aae49e33260d3a2400db16c7737aaf04692d606a843502056b42990f989

# curl-impersonate presents a real browser TLS/HTTP2 fingerprint for the
# homepage fetch. It does not solve challenges; a challenge is reported.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl \
    && curl -fsSL -o /tmp/ci.tar.gz \
        "https://github.com/lexiforest/curl-impersonate/releases/download/${CURL_IMPERSONATE_VERSION}/curl-impersonate-${CURL_IMPERSONATE_VERSION}.x86_64-linux-gnu.tar.gz" \
    && echo "${CURL_IMPERSONATE_SHA256}  /tmp/ci.tar.gz" | sha256sum -c - \
    && mkdir -p /opt/curl-impersonate \
    && tar -xzf /tmp/ci.tar.gz -C /opt/curl-impersonate \
    && rm /tmp/ci.tar.gz \
    && apt-get purge -y curl && apt-get autoremove -y \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json ./
COPY contract ./contract
COPY src ./src
COPY NOTICE ./

USER 10001:10001
ENV NODE_ENV=production PORT=8080
EXPOSE 8080
CMD ["node", "src/server.js"]
