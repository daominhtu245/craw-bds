"""Client for the Laravel ingest API (SPEC 6.1 / 7).

- Batches of at most 50 items.
- Up to 5 retries with exponential backoff on 5xx, 429 and network errors.
- When the API stays unreachable, batches are spooled to SPOOL_DIR/*.jsonl and
  re-sent later by flush_spool().
"""

from __future__ import annotations

import asyncio
import json
import logging
import time
import uuid
from collections.abc import Awaitable, Callable, Iterable, Sequence
from pathlib import Path
from typing import Any

import httpx

from common.schema import IngestItem
from common.settings import Settings

log = logging.getLogger("ingest")

MAX_BATCH = 50
MAX_RETRIES = 5

Sleep = Callable[[float], Awaitable[None]]


class IngestAuthError(RuntimeError):
    """401: wrong INGEST_TOKEN. Retrying will not help."""


class IngestClient:
    def __init__(
        self,
        settings: Settings | None = None,
        *,
        transport: httpx.AsyncBaseTransport | None = None,
        sleep: Sleep = asyncio.sleep,
    ) -> None:
        self.settings = settings or Settings()
        self._sleep = sleep
        self._http = httpx.AsyncClient(
            base_url=self.settings.ingest_url,
            headers={
                "Authorization": f"Bearer {self.settings.ingest_token}",
                "Accept": "application/json",
                "User-Agent": "ExpatLeadRadar-Collector/1.0",
            },
            timeout=httpx.Timeout(20.0),
            transport=transport,
        )

    async def __aenter__(self) -> IngestClient:
        return self

    async def __aexit__(self, *exc: object) -> None:
        await self.aclose()

    async def aclose(self) -> None:
        await self._http.aclose()

    # ------------------------------------------------------------------ items

    async def send(self, items: Iterable[IngestItem]) -> dict[str, int]:
        """Send items in batches. Returns totals; failed batches are spooled."""
        payloads = [item.to_payload() for item in items]
        totals = {"accepted": 0, "duplicates": 0, "rejected": 0, "spooled": 0}

        for start in range(0, len(payloads), MAX_BATCH):
            batch = payloads[start : start + MAX_BATCH]
            result = await self._post_batch(batch)
            if result is None:
                self._spool(batch)
                totals["spooled"] += len(batch)
                continue
            totals["accepted"] += int(result.get("accepted", 0))
            totals["duplicates"] += int(result.get("duplicates", 0))
            rejected = result.get("rejected", [])
            totals["rejected"] += len(rejected)
            for rejection in rejected:
                log.warning("item rejected", extra={"error": rejection.get("error"), "item_id": batch[rejection.get("index", 0)].get("external_id")})

        log.info("ingest done", extra={"count": len(payloads), "status": totals})
        return totals

    async def flush_spool(self) -> int:
        """Re-send spooled batches. Returns the number of items delivered."""
        delivered = 0
        for path in sorted(self._spool_dir().glob("*.jsonl")):
            batch = [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]
            if not batch:
                path.unlink(missing_ok=True)
                continue
            result = await self._post_batch(batch)
            if result is None:
                break  # API still down; keep the remaining files.
            path.unlink(missing_ok=True)
            delivered += len(batch)
        if delivered:
            log.info("spool flushed", extra={"count": delivered})
        return delivered

    # ------------------------------------------------------- other endpoints

    async def sources(self, source_type: str) -> list[dict[str, Any]]:
        response = await self._request("GET", "/collector/sources", params={"type": source_type})
        if response is None:
            raise RuntimeError("Could not fetch sources from the app")
        return list(response.json().get("data", []))

    async def heartbeat(self, collector: str, ok: bool = True, meta: dict[str, Any] | None = None) -> bool:
        body: dict[str, Any] = {"collector": collector, "ok": ok}
        if meta:
            body["meta"] = meta
        return await self._request("POST", "/collector/heartbeat", json=body, retries=1) is not None

    # --------------------------------------------------------------- helpers

    async def _post_batch(self, batch: Sequence[dict[str, Any]]) -> dict[str, Any] | None:
        response = await self._request("POST", "/ingest/items", json={"items": list(batch)})
        return None if response is None else response.json()

    async def _request(self, method: str, url: str, *, retries: int = MAX_RETRIES, **kwargs: Any) -> httpx.Response | None:
        for attempt in range(retries + 1):
            try:
                response = await self._http.request(method, url, **kwargs)
            except httpx.TransportError as e:
                log.warning("ingest api unreachable", extra={"error": type(e).__name__, "status": attempt})
            else:
                if response.status_code == 401:
                    raise IngestAuthError("Ingest API rejected INGEST_TOKEN (401)")
                if response.status_code < 400:
                    return response
                if response.status_code != 429 and response.status_code < 500:
                    log.error("ingest api client error", extra={"status": response.status_code, "error": response.text[:200]})
                    return None
                log.warning("ingest api error", extra={"status": response.status_code})

            if attempt < retries:
                await self._sleep(min(60.0, 2.0**attempt))
        return None

    def _spool_dir(self) -> Path:
        path = self.settings.spool_dir
        path.mkdir(parents=True, exist_ok=True)
        return path

    def _spool(self, batch: Sequence[dict[str, Any]]) -> None:
        path = self._spool_dir() / f"{int(time.time())}-{uuid.uuid4().hex[:8]}.jsonl"
        path.write_text("\n".join(json.dumps(item, ensure_ascii=False) for item in batch) + "\n", encoding="utf-8")
        log.warning("batch spooled", extra={"count": len(batch), "error": str(path)})
