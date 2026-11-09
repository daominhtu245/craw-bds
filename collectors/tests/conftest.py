from __future__ import annotations

import asyncio
from collections.abc import Coroutine
from typing import Any

import pytest


def run(coro: Coroutine[Any, Any, Any]) -> Any:
    return asyncio.run(coro)


@pytest.fixture(autouse=True)
def _env(monkeypatch: pytest.MonkeyPatch, tmp_path) -> None:
    monkeypatch.setenv("INGEST_URL", "http://app.test/api")
    monkeypatch.setenv("INGEST_TOKEN", "test-token")
    monkeypatch.setenv("SPOOL_DIR", str(tmp_path / "spool"))
    monkeypatch.setenv("TG_API_ID", "12345")
    monkeypatch.setenv("TG_API_HASH", "test-hash")
