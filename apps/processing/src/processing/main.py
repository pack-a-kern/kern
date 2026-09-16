"""FastAPI entry point for the processing plane (`BLUEPRINT.md` §1.2, §3.1).

Shared by the `python-api` and `python-worker` containers. Holds no Laravel
database credentials (§3.3) and no fiscal decision logic — this service
reads, classifies and extracts; it never decides a tax number
(`docs/AGENTS.md` §0).
"""

from fastapi import FastAPI

from processing.documents.http import router as documents_router
from processing.embeddings.http import router as embeddings_router
from processing.error_handling import register_error_handlers
from processing.logging_config import configure_logging
from processing.middleware import CorrelationIdMiddleware
from processing.statements.http import router as statements_router

configure_logging()

app = FastAPI(title="TaxOS processing plane")
app.add_middleware(CorrelationIdMiddleware)
register_error_handlers(app)
app.include_router(documents_router)
app.include_router(embeddings_router)
app.include_router(statements_router)


@app.get("/health")
async def health() -> dict[str, str]:
    """Liveness only. No dependency checks — this service has none yet."""
    return {"status": "ok", "service": "processing"}
