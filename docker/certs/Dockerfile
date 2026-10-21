# The stack's certificate authority — docker/certs/entrypoint.sh, and `openssl` to run it with.
#
# Alpine rather than any image already in the stack: the one job here is issuing certificates, and the
# smallest image that has a current OpenSSL is the one with the least in it besides.
FROM alpine:3.24.2@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6

RUN apk add --no-cache openssl

COPY entrypoint.sh /usr/local/bin/kern-certs

# Root, with CAP_CHOWN and nothing else (compose.yaml): each service's key is handed to the user that
# service runs as, and only root can give a file away.
ENTRYPOINT ["sh", "/usr/local/bin/kern-certs"]
