"""Facebook group posts via Apify (SPEC 6.3).

Actor: APIFY_FB_ACTOR (verified against `apify/facebook-groups-scraper`, 2026-11-09).

Input used (from the actor's input schema):
    startUrls          [{"url": "<group url>"}]        public groups only
    resultsLimit       int                              posts to scrape
    viewOption         "CHRONOLOGICAL"                  newest posts first
    onlyPostsNewerThan "YYYY-MM-DDTHH:MM:SS"            UTC

Output fields mapped (from the actor README):
    legacyId / id → external_id, url, time → posted_at, text,
    user.id → author_key (hashed here, never sent), user.name → author_handle

One actor run per source so `resultsLimit` and the date filter are per group.
Comments are Phase 7 (separate actor).

Run:  python -m facebook.run          (loops every FB_POLL_MINUTES)
      python -m facebook.run --once
"""

from __future__ import annotations

import argparse
import asyncio
import logging
import os
import time
from datetime import UTC, datetime, timedelta
from typing import Any

from common import logging as jsonlog
from common.ingest_client import IngestClient
from common.schema import IngestItem
from common.settings import require

log = logging.getLogger("facebook")

DEFAULT_MAX_ITEMS = 50
LOOKBACK = timedelta(hours=48)


def since_for(source: dict[str, Any], now: datetime) -> datetime:
    """max(last_item_at, now - 48h) (SPEC 6.3)."""
    floor = now - LOOKBACK
    raw = source.get("last_item_at")
    if not raw:
        return floor
    try:
        last = datetime.fromisoformat(str(raw).replace("Z", "+00:00"))
    except ValueError:
        return floor
    if last.tzinfo is None:
        last = last.replace(tzinfo=UTC)
    return max(last.astimezone(UTC), floor)


def actor_input(source: dict[str, Any], since: datetime) -> dict[str, Any]:
    meta = source.get("meta") or {}
    return {
        "startUrls": [{"url": source["identifier"]}],
        "resultsLimit": int(meta.get("maxItems", DEFAULT_MAX_ITEMS)),
        "viewOption": "CHRONOLOGICAL",
        "onlyPostsNewerThan": since.astimezone(UTC).strftime("%Y-%m-%dT%H:%M:%S"),
    }


def map_post(item: dict[str, Any], source_identifier: str) -> IngestItem | None:
    text = (item.get("text") or "").strip()
    post_id = item.get("legacyId") or item.get("id")
    url = item.get("url") or item.get("facebookUrl")
    posted = item.get("time")
    if not text or not post_id or not url or not posted:
        return None
    try:
        posted_at = datetime.fromisoformat(str(posted).replace("Z", "+00:00"))
    except ValueError:
        return None
    user = item.get("user") or {}
    return IngestItem(
        source_type="facebook",
        source_identifier=source_identifier,
        external_id=str(post_id),
        url=str(url),
        author_id=str(user["id"]) if user.get("id") else None,
        author_handle=user.get("name"),
        text=text,
        posted_at=posted_at,
    )


def scrape_source(apify: Any, actor_id: str, source: dict[str, Any], now: datetime) -> list[IngestItem]:
    run_input = actor_input(source, since_for(source, now))
    run = apify.actor(actor_id).call(run_input=run_input, run_timeout=timedelta(minutes=30))
    if run is None:
        log.error("apify run did not finish", extra={"source": source["identifier"]})
        return []

    log.info("apify run finished", extra={
        "source": source["identifier"],
        "status": str(getattr(run, "status", "")),
        "cost_usd": getattr(run, "usage_total_usd", None),
    })

    items = []
    for raw in apify.dataset(run.default_dataset_id).iterate_items():
        item = map_post(raw, source["identifier"])
        if item is not None:
            items.append(item)
    return items


async def run_once(apify: Any | None = None, ingest: IngestClient | None = None, now: datetime | None = None) -> dict[str, int]:
    actor_id = require("APIFY_FB_ACTOR")
    if apify is None:
        from apify_client import ApifyClient

        apify = ApifyClient(require("APIFY_TOKEN"))
    now = now or datetime.now(UTC)
    owns_ingest = ingest is None
    ingest = ingest or IngestClient()

    try:
        await ingest.flush_spool()
        totals = {"accepted": 0, "duplicates": 0, "rejected": 0, "spooled": 0}
        sources = await ingest.sources("facebook")
        for source in sources:
            try:
                items = await asyncio.to_thread(scrape_source, apify, actor_id, source, now)
            except Exception:
                log.exception("facebook source failed", extra={"source": source.get("identifier")})
                continue
            result = await ingest.send(items)
            for key in totals:
                totals[key] += result.get(key, 0)
        await ingest.heartbeat("facebook", ok=True, meta={"sources": len(sources)})
        return totals
    finally:
        if owns_ingest:
            await ingest.aclose()


def main() -> None:
    jsonlog.setup()
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()
    interval = int(os.environ.get("FB_POLL_MINUTES", "60")) * 60

    while True:
        try:
            log.info("facebook round done", extra={"status": asyncio.run(run_once())})
        except Exception:
            log.exception("facebook round failed")
        if args.once:
            return
        time.sleep(interval)


if __name__ == "__main__":
    main()
