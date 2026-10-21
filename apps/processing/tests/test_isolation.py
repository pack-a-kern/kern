"""`processing.isolation` on its own: the two ways a worker can fail to answer."""

import time
from multiprocessing.connection import Connection

import pytest

from processing.isolation import IsolatedTimeoutError, IsolatedWorkerDiedError, run_isolated


def _answer(value: int, conn: Connection) -> None:
    conn.send(("ok", value))
    conn.close()


def _hang(conn: Connection) -> None:
    time.sleep(60)
    conn.close()


def _die(conn: Connection) -> None:
    conn.close()


def test_returns_the_one_message_the_worker_sends() -> None:
    assert run_isolated(_answer, (7,), timeout_seconds=10) == ("ok", 7)


def test_kills_a_worker_that_does_not_answer_in_time() -> None:
    started = time.perf_counter()

    with pytest.raises(IsolatedTimeoutError):
        run_isolated(_hang, (), timeout_seconds=2)

    assert time.perf_counter() - started < 15


def test_reports_a_worker_that_ends_without_answering() -> None:
    with pytest.raises(IsolatedWorkerDiedError, match="terminated unexpectedly"):
        run_isolated(_die, (), timeout_seconds=10)
