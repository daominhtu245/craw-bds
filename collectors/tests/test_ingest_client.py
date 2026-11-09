import json
from datetime import UTC, datetime

import httpx
import pytest

from common.ingest_client import IngestAuthError, IngestClient
from common.schema import IngestItem
from tests.conftest import run


def items(n):
    return [
        IngestItem(
            source_type="telegram", source_identifier="g", external_id=f"c:{i}",
            url=f"https://t.me/g/{i}", text=f"Looking for flat {i}", posted_at=datetime(2026, 11, 9, tzinfo=UTC),
        )
        for i in range(n)
    ]


class Recorder:
    def __init__(self, responses):
        self.responses = list(responses)
        self.requests: list[httpx.Request] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        self.requests.append(request)
        r = self.responses.pop(0) if len(self.responses) > 1 else self.responses[0]
        if isinstance(r, Exception):
            raise r
        return r


async def no_sleep(_):
    return None


def client(recorder):
    return IngestClient(transport=httpx.MockTransport(recorder), sleep=no_sleep)


def ok(accepted, duplicates=0, rejected=None):
    return httpx.Response(200, json={"accepted": accepted, "duplicates": duplicates, "rejected": rejected or []})


def test_batches_of_50_with_bearer_token():
    rec = Recorder([ok(50), ok(50), ok(20)])

    async def go():
        async with client(rec) as c:
            return await c.send(items(120))

    totals = run(go())

    assert totals == {"accepted": 120, "duplicates": 0, "rejected": 0, "spooled": 0}
    assert [len(json.loads(r.content)["items"]) for r in rec.requests] == [50, 50, 20]
    assert rec.requests[0].headers["authorization"] == "Bearer test-token"
    assert str(rec.requests[0].url) == "http://app.test/api/ingest/items"
    assert "author_id" not in rec.requests[0].content.decode()


def test_retries_5xx_and_network_errors_then_succeeds():
    rec = Recorder([httpx.Response(502), httpx.ConnectError("down"), httpx.Response(429), ok(1)])

    async def go():
        async with client(rec) as c:
            return await c.send(items(1))

    assert run(go())["accepted"] == 1
    assert len(rec.requests) == 4


def test_spools_after_5_retries_and_flushes_later(tmp_path):
    rec = Recorder([httpx.Response(503)])

    async def fail():
        async with client(rec) as c:
            return await c.send(items(3))

    assert run(fail())["spooled"] == 3
    assert len(rec.requests) == 6  # first try + 5 retries
    spooled = list((tmp_path / "spool").glob("*.jsonl"))
    assert len(spooled) == 1
    assert len(spooled[0].read_text().splitlines()) == 3

    rec2 = Recorder([ok(3)])

    async def flush():
        async with client(rec2) as c:
            return await c.flush_spool()

    assert run(flush()) == 3
    assert not list((tmp_path / "spool").glob("*.jsonl"))


def test_401_raises_without_retry():
    rec = Recorder([httpx.Response(401, json={"message": "Unauthenticated."})])

    async def go():
        async with client(rec) as c:
            await c.send(items(1))

    with pytest.raises(IngestAuthError):
        run(go())
    assert len(rec.requests) == 1


def test_sources_and_heartbeat():
    rec = Recorder([
        httpx.Response(200, json={"data": [{"identifier": "danang_group"}]}),
        httpx.Response(200, json={"status": "ok"}),
    ])

    async def go():
        async with client(rec) as c:
            return await c.sources("telegram"), await c.heartbeat("telegram", ok=True, meta={"chats": 1})

    sources, beat = run(go())
    assert sources == [{"identifier": "danang_group"}]
    assert beat is True
    assert rec.requests[0].url.params["type"] == "telegram"
    assert json.loads(rec.requests[1].content) == {"collector": "telegram", "ok": True, "meta": {"chats": 1}}
