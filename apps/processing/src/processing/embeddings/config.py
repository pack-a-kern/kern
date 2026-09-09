"""Settings for the local embedding model, from `EMBEDDING_*` environment
variables (`docs/CODE-QUALITY.md` §4 — no magic numbers at a call site).

The model is deliberately **not** baked into the image: weights are fetched once
into a mounted cache volume and reused, so an unrelated code change does not
rebuild them.
"""

from typing import Literal

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class EmbeddingSettings(BaseSettings):
    """Loaded from `EMBEDDING_*`; defaults describe the model this project
    selected, so the service is usable without configuration in development."""

    model_config = SettingsConfigDict(env_prefix="EMBEDDING_", extra="forbid", frozen=True)

    model_id: str = Field(
        default="intfloat/multilingual-e5-small",
        description="Hugging Face repository holding the ONNX export. Changing "
        "this changes the vector space: every stored embedding must be "
        "recomputed, and `source_section_chunks.embedding` must match the new "
        "dimension.",
    )
    revision: str = Field(
        default="main",
        description="Repository revision to pin. `main` follows upstream; set a "
        "commit sha to make the download reproducible.",
    )
    onnx_subfolder: str = Field(
        default="onnx",
        description="Folder inside the repository holding the ONNX export and `tokenizer.json`.",
    )
    onnx_file: str = Field(
        default="model_qint8_avx512_vnni.onnx",
        description="Which export to load. The int8 build is 118 MB against the "
        "fp32 model's 470 MB and needs AVX512-VNNI, which this host has "
        "(verified in /proc/cpuinfo). On a host without it, switch to "
        "`model_O4.onnx` (235 MB) or `model.onnx` — the int8 graph would "
        "otherwise fall back to a slow emulation path.",
    )
    pooling: Literal["mean", "cls"] = Field(
        default="mean",
        description="How a token sequence becomes one vector. E5 models mean-pool "
        "over the attention mask; BGE models take the CLS token. Choosing wrong "
        "produces vectors that normalise cleanly and retrieve badly — there is no "
        "error to catch, so it is configuration, not inference.",
    )
    query_prefix: str = Field(
        default="query: ",
        description="Prepended to search queries. E5 was trained with this and "
        "loses noticeable accuracy without it. A model needing no prefixes "
        "(bge-m3) sets this and `passage_prefix` to empty strings.",
    )
    passage_prefix: str = Field(
        default="passage: ",
        description="Prepended to corpus text at indexing time. Must stay fixed "
        "for the life of an index: changing it changes every stored vector's "
        "meaning without changing the model.",
    )
    cache_dir: str = Field(
        default="/var/cache/embeddings",
        description="Where the downloaded weights live. Mounted as a volume so "
        "the ~2.3 GB download survives a container recreate.",
    )
    dimension: int = Field(
        default=384,
        gt=0,
        description="Vector width the model emits. Asserted against the model's "
        "real output on first use — a mismatch here would silently write "
        "vectors Postgres rejects, or worse, accepts at the wrong width.",
    )
    max_tokens: int = Field(
        default=512,
        gt=0,
        description="Truncation length, and E5's own trained maximum. This "
        "corpus's largest chunk measures ~400 tokens, so nothing is lost.",
    )
    batch_size: int = Field(
        default=16,
        gt=0,
        description="Texts per forward pass. Larger batches are faster per text "
        "but hold more memory; 16 keeps a CPU-only worker under control.",
    )
    intra_op_threads: int = Field(
        default=0,
        ge=0,
        description="onnxruntime threads per operator. 0 lets onnxruntime "
        "choose, which is right on a dedicated container and wrong when the "
        "host is shared — pin it then.",
    )
    warm_on_startup: bool = Field(
        default=False,
        description="Download and load the model when the service boots rather "
        "than on the first request. Off by default so an unconfigured "
        "development stack starts fast; on in any deployment that cannot "
        "afford a multi-minute first request.",
    )
