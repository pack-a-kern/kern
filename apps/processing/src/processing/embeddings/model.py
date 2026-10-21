"""Loading and running the configured embedding model under onnxruntime.

**Why ONNX and not sentence-transformers**: the torch stack adds roughly a
gigabyte to a deliberately slim, hardened runtime image, and this service needs
exactly one forward pass — no training, no autograd. `intfloat` publish official
ONNX exports, including an int8 build that is 118 MB against the fp32 model's
470 MB, so onnxruntime + tokenizers covers it with a fraction of the footprint.

Two model-specific behaviours are configuration rather than assumptions, because
both fail *silently* when wrong — the vectors come out well-formed and normalise
cleanly, they simply retrieve badly:

**Pooling.** E5 mean-pools over the attention mask; BGE takes the CLS token.
`EMBEDDING_POOLING` selects.

**Prefixes.** E5 was trained with `query: ` and `passage: ` markers and loses
accuracy without them; bge-m3 takes raw text. `EMBEDDING_QUERY_PREFIX` and
`EMBEDDING_PASSAGE_PREFIX` select, and are set empty for a model that wants
none.
"""

import threading
from collections.abc import Mapping, Sequence
from pathlib import Path
from typing import Final, Protocol, cast

import huggingface_hub
import numpy as np
import numpy.typing as npt
import onnxruntime as ort
from tokenizers import Tokenizer

from processing.embeddings.config import EmbeddingSettings

_TOKENIZER_FILE: Final = "tokenizer.json"

# What this module uses of the three libraries, and nothing more.
#
# onnxruntime ships no types, and some of tokenizers' and huggingface_hub's are incomplete, so under
# strict checking every value they return was `Unknown` — and so was everything computed from it,
# which is to say the whole of this file. Each library enters through one `cast` to these, below;
# past that line the file is checked like any other, and a library that changes one of these
# signatures fails the tests that load the real model rather than going unnoticed.


class _ModelInput(Protocol):
    @property
    def name(self) -> str: ...


class _Session(Protocol):
    def get_inputs(self) -> Sequence[_ModelInput]: ...

    def run(
        self, output_names: None, input_feed: Mapping[str, npt.NDArray[np.int64]]
    ) -> Sequence[npt.ArrayLike]: ...


class _SessionOptions(Protocol):
    intra_op_num_threads: int
    graph_optimization_level: object


class _OptimizationLevels(Protocol):
    @property
    def ORT_ENABLE_ALL(self) -> object: ...


class _OnnxRuntime(Protocol):
    @property
    def GraphOptimizationLevel(self) -> _OptimizationLevels: ...

    def SessionOptions(self) -> _SessionOptions: ...

    def InferenceSession(
        self, path: str, *, sess_options: _SessionOptions, providers: list[str]
    ) -> _Session: ...


class _Encoding(Protocol):
    @property
    def ids(self) -> list[int]: ...

    @property
    def attention_mask(self) -> list[int]: ...

    @property
    def type_ids(self) -> list[int]: ...


class _Tokenizer(Protocol):
    def enable_truncation(self, max_length: int) -> None: ...

    def enable_padding(self) -> None: ...

    def encode_batch(self, inputs: list[str]) -> list[_Encoding]: ...


class _Download(Protocol):
    def __call__(
        self, *, repo_id: str, revision: str, cache_dir: str, allow_patterns: list[str]
    ) -> str: ...


_onnxruntime = cast("_OnnxRuntime", ort)

# The one exception, and only to this rule: `snapshot_download`'s own signature carries unknown
# parts (an untyped `user_agent` dict, a tqdm class), so even naming it is `partially unknown`.
# This line is where it gets a type; nothing after it is unchecked.
_download = cast("_Download", huggingface_hub.snapshot_download)  # pyright: ignore[reportUnknownMemberType]


class EmbeddingModelError(RuntimeError):
    """The model could not be loaded or produced an unusable result."""


class EmbeddingModel:
    """A lazily loaded, thread-safe wrapper around one ONNX session.

    Loading is guarded by a lock because uvicorn serves concurrently: two
    simultaneous first requests would otherwise both download ~2.3 GB and both
    build a session, doubling memory for no benefit.
    """

    def __init__(self, settings: EmbeddingSettings) -> None:
        self._settings = settings
        self._lock = threading.Lock()
        self._session: _Session | None = None
        self._tokenizer: _Tokenizer | None = None

    @property
    def identifier(self) -> str:
        """Stored per row as `source_section_chunks.embedding_model`, so a
        future reader can tell which vector space a row belongs to."""
        return f"{self._settings.model_id}@{self._settings.revision}"

    def load(self) -> None:
        """Download (once) and build the session. Safe to call repeatedly."""
        if self._session is not None:
            return

        with self._lock:
            if self._session is not None:
                return

            try:
                local = (
                    Path(
                        _download(
                            repo_id=self._settings.model_id,
                            revision=self._settings.revision,
                            cache_dir=self._settings.cache_dir,
                            # Only the one export this service loads, plus the
                            # tokenizer files. The repository holds several builds
                            # (fp32, O4, int8); fetching all of them would download
                            # 824 MB to use 118 MB of it.
                            allow_patterns=[
                                f"{self._settings.onnx_subfolder}/{self._settings.onnx_file}",
                                f"{self._settings.onnx_subfolder}/{self._settings.onnx_file}_data",
                                f"{self._settings.onnx_subfolder}/*.json",
                                f"{self._settings.onnx_subfolder}/*.model",
                            ],
                        )
                    )
                    / self._settings.onnx_subfolder
                )
            except Exception as cause:
                raise EmbeddingModelError(f"model download failed: {cause}") from cause

            options = _onnxruntime.SessionOptions()
            options.intra_op_num_threads = self._settings.intra_op_threads
            options.graph_optimization_level = _onnxruntime.GraphOptimizationLevel.ORT_ENABLE_ALL

            try:
                session = _onnxruntime.InferenceSession(
                    str(local / self._settings.onnx_file),
                    sess_options=options,
                    providers=["CPUExecutionProvider"],
                )
                tokenizer = cast("_Tokenizer", Tokenizer.from_file(str(local / _TOKENIZER_FILE)))
            except Exception as cause:
                raise EmbeddingModelError(f"model load failed: {cause}") from cause

            tokenizer.enable_truncation(max_length=self._settings.max_tokens)
            tokenizer.enable_padding()

            self._session = session
            self._tokenizer = tokenizer

    def embed(self, texts: list[str], *, is_query: bool) -> list[list[float]]:
        """Embed texts in declaration order, batched.

        `is_query` selects the training-time prefix. It is a required keyword
        rather than a defaulted flag on purpose: silently embedding a search
        query as a passage is exactly the mistake this parameter exists to
        prevent, and a default would let a caller make it without deciding.

        Returns L2-normalised vectors, so cosine similarity and inner product
        agree — which is what lets Postgres compare them with `<=>` without
        re-normalising on read.
        """
        self.load()
        prefix = self._settings.query_prefix if is_query else self._settings.passage_prefix
        texts = [f"{prefix}{text}" for text in texts]
        session, tokenizer = self._session, self._tokenizer

        if session is None or tokenizer is None:  # pragma: no cover - load() raises first
            raise EmbeddingModelError("model is not loaded")

        vectors: list[list[float]] = []

        for start in range(0, len(texts), self._settings.batch_size):
            batch = texts[start : start + self._settings.batch_size]
            encoded = tokenizer.encode_batch(batch)

            inputs = {
                "input_ids": np.array([e.ids for e in encoded], dtype=np.int64),
                "attention_mask": np.array([e.attention_mask for e in encoded], dtype=np.int64),
                # XLM-RoBERTa uses a single segment, so these are all zeros — but the
                # exported graph still declares the input and onnxruntime refuses to
                # run without it. Taken from the tokenizer rather than zeroed by hand
                # so a model that does use segments keeps working.
                "token_type_ids": np.array([e.type_ids for e in encoded], dtype=np.int64),
            }
            # Exports differ in which inputs they declare, and passing one the graph
            # does not expect is as fatal as omitting one it does. Send exactly the
            # intersection.
            expected = {i.name for i in session.get_inputs()}
            inputs = {name: value for name, value in inputs.items() if name in expected}

            missing = expected - set(inputs)
            if missing:
                raise EmbeddingModelError(
                    f"the model requires inputs this service does not build: {sorted(missing)}"
                )

            outputs = session.run(None, inputs)
            hidden = np.asarray(outputs[0])

            if hidden.ndim != 3:  # pragma: no cover - guards a wrong export
                raise EmbeddingModelError(
                    f"expected (batch, tokens, hidden) from the model, got shape {hidden.shape}"
                )

            if self._settings.pooling == "cls":
                pooled = hidden[:, 0, :]
            else:
                # Mean over real tokens only. Averaging padding as well would make
                # a vector depend on its batch-mates' lengths — the same text would
                # embed differently depending on what it was batched with.
                mask = np.asarray(inputs["attention_mask"])[:, :, None].astype(hidden.dtype)
                pooled = (hidden * mask).sum(axis=1) / np.clip(mask.sum(axis=1), 1e-9, None)
            norms = np.linalg.norm(pooled, axis=1, keepdims=True)
            # A zero-length vector cannot be normalised; it also cannot be a
            # meaningful embedding, so fail loudly rather than emit NaNs that
            # would poison every comparison it takes part in.
            if not np.all(np.isfinite(norms)) or np.any(norms == 0):
                raise EmbeddingModelError("model produced a zero or non-finite vector")

            normalised = pooled / norms

            if normalised.shape[1] != self._settings.dimension:
                raise EmbeddingModelError(
                    f"model emits {normalised.shape[1]} dimensions, "
                    f"configuration expects {self._settings.dimension}"
                )

            vectors.extend(normalised.astype(np.float32).tolist())

        return vectors
