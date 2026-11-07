# The stack's certificate authority — docker/certs/entrypoint.sh, and `openssl` to run it with.
#
# Alpine rather than any image already in the stack: the one job here is issuing certificates, and the
# smallest image that has a current OpenSSL is the one with the least in it besides.
FROM alpine:3.24.4@sha256:93b41ef40b1677fce8fcc64ff4af09daddfacf5040afde618bc46c1e2d1cc7d4

# No package pinned past the base: alpine:3.24.4 ships the zlib (CVE-2026-85091) and musl
# (CVE-2026-107891) fixes the earlier base needed pins for. An exact pin outlives its use — Alpine's
# index drops the build it names, and the image stops building — so a fix the base already has is not
# pinned again.
RUN apk add --no-cache openssl

COPY entrypoint.sh /usr/local/bin/kern-certs

# Root, with CAP_CHOWN and nothing else (compose.yaml): each service's key is handed to the user that
# service runs as, and only root can give a file away.
ENTRYPOINT ["sh", "/usr/local/bin/kern-certs"]
