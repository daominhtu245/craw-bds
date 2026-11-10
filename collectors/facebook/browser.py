"""Facebook group posts read with your own logged-in browser profile (no Apify).

Run:  .\\run-local.ps1 facebook.session          (once: log in by hand)
      .\\run-local.ps1 facebook.browser --once   (one round)
      .\\run-local.ps1 facebook.browser          (loops every FB_POLL_MINUTES)

Per source (dashboard → Nguồn, type facebook, identifier = group URL the
account can see) one round:

1. opens ``<group url>?sorting_setting=CHRONOLOGICAL``;
2. scrolls FB_SCROLLS times (default 3), waiting FB_SCROLL_DELAY seconds
   (default 5–10, random) between scrolls;
3. reads the posts from the page JSON and the GraphQL responses it loaded
   (facebook.extract), keeps posts newer than max(last_item_at, now − 48h);
4. waits FB_GROUP_DELAY seconds (default 30–60) before the next group.

Read-only: it never clicks, types, likes, comments or joins. If Facebook shows
a login page, checkpoint or CAPTCHA the round stops (exit code 2) and a failed
heartbeat is sent; log in again by hand with facebook.session.
"""

from __future__ import annotations

import argparse
import asyncio
import logging
import os
import random
import time
from datetime import UTC, datetime
from typing import Any

from common import logging as jsonlog
from common.ingest_client import IngestClient
from common.schema import IngestItem
from facebook.extract import find_stories, html_documents, items_from_documents, parse_documents
from facebook.run import since_for
from facebook.session import HOME, BlockedError, blocked_reason, launch

log = logging.getLogger("facebook")


def _range(name: str, default: tuple[float, float]) -> tuple[float, float]:
    """'5-10' or '7' → (low, high)."""
    raw = (os.environ.get(name) or "").strip()
    if not raw:
        return default
    parts = [float(p) for p in raw.replace(" ", "").split("-", 1)]
    return (parts[0], parts[-1]) if len(parts) == 2 else (parts[0], parts[0])


def feed_url(group_url: str) -> str:
    base = group_url.split("?")[0].rstrip("/")
    return f"{base}/?sorting_setting=CHRONOLOGICAL"


async def scrape_group(context: Any, source: dict[str, Any], now: datetime) -> list[IngestItem]:
    group_url = source["identifier"]
    scrolls = int((source.get("meta") or {}).get("scrolls", os.environ.get("FB_SCROLLS", "3")))
    scroll_delay = _range("FB_SCROLL_DELAY", (5.0, 10.0))

    page = await context.new_page()
    bodies: list[asyncio.Task[str]] = []

    def on_response(response: Any) -> None:
        if "/api/graphql" in response.url and response.request.method == "POST":
            bodies.append(asyncio.ensure_future(response.text()))

    page.on("response", on_response)
    try:
        await page.goto(feed_url(group_url), wait_until="domcontentloaded", timeout=60_000)
        reason = blocked_reason(page.url, await context.cookies(HOME))
        if reason:
            raise BlockedError(reason)

        await page.wait_for_timeout(random.uniform(*scroll_delay) * 1000)
        documents = html_documents(await page.content())
        for _ in range(max(0, scrolls)):
            await page.mouse.wheel(0, random.randint(1800, 2800))
            await page.wait_for_timeout(random.uniform(*scroll_delay) * 1000)
            reason = blocked_reason(page.url, await context.cookies(HOME))
            if reason:
                raise BlockedError(reason)

        for result in await asyncio.gather(*bodies, return_exceptions=True):
            if isinstance(result, str):
                documents.extend(parse_documents(result))
    finally:
        await page.close()

    items = items_from_documents(documents, group_url, since_for(source, now))
    log.info("facebook group read", extra={
        "source": group_url,
        "count": len(items),
        "status": {"documents": len(documents), "graphql_responses": len(bodies), "stories": len(find_stories(documents))},
    })
    return items


async def run_once(ingest: IngestClient | None = None, now: datetime | None = None) -> dict[str, int]:
    from playwright.async_api import async_playwright

    now = now or datetime.now(UTC)
    owns_ingest = ingest is None
    ingest = ingest or IngestClient()
    headless = os.environ.get("FB_HEADLESS", "1") not in ("0", "false", "False")
    group_delay = _range("FB_GROUP_DELAY", (30.0, 60.0))

    try:
        await ingest.flush_spool()
        totals = {"accepted": 0, "duplicates": 0, "rejected": 0, "spooled": 0}
        sources = await ingest.sources("facebook")
        async with async_playwright() as p:
            context = await launch(p, headless=headless)
            try:
                for index, source in enumerate(sources):
                    if index:
                        await asyncio.sleep(random.uniform(*group_delay))
                    try:
                        items = await scrape_group(context, source, now)
                    except BlockedError:
                        raise
                    except Exception:
                        log.exception("facebook source failed", extra={"source": source.get("identifier")})
                        continue
                    result = await ingest.send(items)
                    for key in totals:
                        totals[key] += result.get(key, 0)
            finally:
                await context.close()
        await ingest.heartbeat("facebook", ok=True, meta={"sources": len(sources), "mode": "browser"})
        return totals
    except BlockedError as e:
        await ingest.heartbeat("facebook", ok=False, meta={"error": str(e), "mode": "browser"})
        raise
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
        except BlockedError as e:
            log.error("facebook blocked the session; stopping. Log in again with facebook.session", extra={"error": str(e)})
            raise SystemExit(2) from e
        except Exception:
            log.exception("facebook round failed")
        if args.once:
            return
        time.sleep(interval)


if __name__ == "__main__":
    main()
