# TaxOS România — python-api / python-worker image (processing plane).
#
# BLUEPRINT.md §1.2: Python is the processing plane, not the business backend.
# This image holds no Laravel DB credentials — apps/processing talks to
# Laravel only through its internal HTTP API / signed job payloads / object
# storage references (docs/AGENTS.md §6, BLUEPRINT.md §1.2).
#
# Base image and uv version pinned by exact tag and digest per
# docs/reference/verified-versions.md and docs/DEPENDENCY-POLICY.md — do not
# float either; `make pins` reports a newer build.

FROM python:3.14.8-slim-trixie@sha256:fbecd461fc72d21b2143c6eaba25143738079154a6d0ea3da3aec724f50eed4a AS base

# uv: copied as a static binary from Astral's own image (their documented
# pattern), pinned to the exact release and digest
# verified 2026-10-20 (github.com/astral-sh/uv/releases -> 0.12.24).
COPY --from=ghcr.io/astral-sh/uv:0.12.24@sha256:3af4716e991d6956a41e573eab705d0ee08500cd829ed30293eb8472f372c65a /uv /uvx /usr/local/bin/

# ---------------------------------------------------------------------------
# Stage: deps — resolve the real, locked dependency set and install the
# `processing` package itself into the same venv (uv_build backend).
# ---------------------------------------------------------------------------
FROM base AS deps
WORKDIR /app
ENV UV_PROJECT_ENVIRONMENT=/opt/venv \
    UV_PYTHON_DOWNLOADS=never \
    UV_LINK_MODE=copy
COPY apps/processing/pyproject.toml apps/processing/uv.lock ./
COPY apps/processing/src ./src
RUN uv sync --frozen --no-dev

# ---------------------------------------------------------------------------
# Stage: runtime — the actual python-api / python-worker image.
# ---------------------------------------------------------------------------
FROM python:3.14.8-slim-trixie@sha256:fbecd461fc72d21b2143c6eaba25143738079154a6d0ea3da3aec724f50eed4a AS runtime

RUN groupadd --gid 10001 appuser \
    && useradd --uid 10001 --gid appuser --no-create-home --shell /usr/sbin/nologin appuser

# The base image ships `pip` preinstalled under /usr/local (Docker's official
# python image, not a Debian apt package), including pip's own vendored
# copies of msgpack 1.1.2 (GHSA-6v7p-g79w-8964, HIGH) and setuptools 70.3.0
# (CVE-2025-47273, HIGH) — see docs/handoff/009-cve-remediation.md for the
# full trace. Neither is reachable through this project's own dependency
# graph: `apps/processing/uv.lock` resolves msgpack only via the `dev`
# dependency group (pip-audit -> cachecontrol -> msgpack, excluded from this
# image by `uv sync --frozen --no-dev` in the `deps` stage below), and a real
# `find /opt/venv -iname '*msgpack*'` against the built venv finds nothing.
# Trivy reports both versions by reading pip's own `pip/_vendor/vendor.txt`.
# This runtime image never invokes pip — `uv` resolves and installs the
# locked dependency set once, at build time, into /opt/venv, and the app
# runs entirely from that venv's own entrypoints (uvicorn). Removing pip
# (rather than trying to patch libraries vendored inside it, which isn't
# possible without an upstream pip release that vendors newer versions)
# eliminates both CVEs and shrinks the image's attack surface — the same
# "does it even need this" reasoning applied to libde265 in
# docker/nginx/Dockerfile.
RUN find /usr/local/lib/python3.14/site-packages -maxdepth 1 -iname 'pip*' -exec rm -rf {} + \
    && rm -rf /usr/local/lib/python3.14/ensurepip \
    && find /usr/local/bin -iname 'pip*' -delete

COPY --from=deps /opt/venv /opt/venv
ENV PATH="/opt/venv/bin:$PATH" \
    PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1

WORKDIR /app
COPY --chown=appuser:appuser apps/processing/src ./src

# The embedding model's weights (~2.3 GB) are NOT baked in: they would triple
# this image and be rebuilt on every unrelated change. The directory is created
# here, owned by the runtime user, purely so Docker initialises the mounted
# cache volume with that ownership — a volume mounted onto a path the image
# does not contain comes up root-owned, and `appuser` then cannot write the
# download into it.
RUN install -d -o appuser -g appuser /var/cache/embeddings

USER appuser
EXPOSE 8000

# No default CMD: python-api and python-worker are the same image with
# different `command:` overrides in compose.yaml (both share
# apps/processing/src/processing/main.py per its own docstring — "Shared by
# the python-api and python-worker containers").

# ---------------------------------------------------------------------------
# Stage: test — adds dev dependencies (pytest, ruff, pyright, pip-audit) for
# `make test`/`make lint`. Never used as a runtime image.
# ---------------------------------------------------------------------------
FROM base AS test
# pyright bundles a Node.js runtime it downloads on first run; that Node
# build needs libatomic.so.1, which python:3.14-slim-trixie does not carry.
# Confirmed necessary by a real run: pyright fails with "error while loading
# shared libraries: libatomic.so.1" without this.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libatomic1=14.2.0-19 \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
ENV UV_PROJECT_ENVIRONMENT=/opt/venv \
    UV_PYTHON_DOWNLOADS=never \
    UV_LINK_MODE=copy
COPY apps/processing/pyproject.toml apps/processing/uv.lock ./
COPY apps/processing/src ./src
COPY apps/processing/tests ./tests
RUN uv sync --frozen
ENV PATH="/opt/venv/bin:$PATH"

