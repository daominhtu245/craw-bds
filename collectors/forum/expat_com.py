"""Expat.com forum, Da Nang section (SPEC 6.4).

Selectors were verified against the live site on 2026-11-09:

Listing page (https://www.expat.com/en/forum/asia/vietnam/da-nang/, page N: .../da-nang/N/)
  div.card-topic
    i.card-topic--details--title--icon.pined   → sticky topic (Vietnam-wide, skipped)
    a.card-topic--details--title--link[href]   → thread URL ".../da-nang/{thread_id}-{slug}.html"
    a.card-topic--details--last-post           → "Last post 3 days ago by …" (relative only)

Thread page
  <script type="application/ld+json"> DiscussionForumPosting
      headline, text, datePublished (ISO), author{name,url}, comment[]{text, datePublished, author}
  div.card-post[id]                            → post ids, same order as [main post, *comments]
  a.card-post__username                        → used to check that both lists line up

robots.txt (checked on every run): the listing and thread URLs under /en/forum/ are allowed.
If that ever changes the crawler stops and logs an error.

Run:  python -m forum.expat_com          (loops every FORUM_POLL_MINUTES)
      python -m forum.expat_com --once
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import re
import time
import urllib.robotparser
from collections.abc import Callable
from dataclasses import dataclass
from datetime import UTC, datetime, timedelta

import httpx
from selectolax.lexbor import LexborHTMLParser as HTMLParser

from common import logging as jsonlog
from common.logging import excerpt
from common.schema import IngestItem
from common.settings import Settings

log = logging.getLogger("forum")

BASE_URL = "https://www.expat.com/en/forum/asia/vietnam/da-nang/"
SOURCE_IDENTIFIER = BASE_URL
THREAD_URL_RE = re.compile(r"/da-nang/(\d+)-[^/]*\.html$")
PROFILE_ID_RE = re.compile(r"/profile/(\d+)-|[?&]id=(\d+)")
AGE_RE = re.compile(r"(an?|\d+)\s+(second|minute|hour|day|week|month|year)s?\s+ago")
UNIT = {"second": 1 / 86400, "minute": 1 / 1440, "hour": 1 / 24, "day": 1, "week": 7, "month": 30, "year": 365}


@dataclass(frozen=True)
class TopicRef:
    thread_id: str
    url: str
    title: str
    age: timedelta | None
    sticky: bool


@dataclass(frozen=True)
class ForumPost:
    post_id: str
    author_name: str | None
    author_id: str | None
    text: str
    posted_at: datetime
    is_first: bool


# ---------------------------------------------------------------- parsing


def parse_age(label: str) -> timedelta | None:
    """'Last post 3 days ago by X' → 3 days. Returns None when it cannot be parsed."""
    text = label.lower()
    if "just now" in text or "today" in text:
        return timedelta(0)
    if "yesterday" in text:
        return timedelta(days=1)
    match = re.search(r"last (week|month|year)", text)
    if match:
        return timedelta(days=UNIT[match.group(1)])
    match = AGE_RE.search(text)
    if match:
        amount = 1 if match.group(1) in ("a", "an") else int(match.group(1))
        return timedelta(days=amount * UNIT[match.group(2)])
    return None


def parse_listing(html: str) -> list[TopicRef]:
    tree = HTMLParser(html)
    topics: list[TopicRef] = []

    for card in tree.css("div.card-topic"):
        link = card.css_first("a.card-topic--details--title--link")
        if link is None:
            continue
        url = (link.attributes.get("href") or "").split("#")[0]
        match = THREAD_URL_RE.search(url)
        if match is None:
            continue  # Vietnam-wide threads (not under /da-nang/) are out of scope.
        last = card.css_first("a.card-topic--details--last-post")
        topics.append(TopicRef(
            thread_id=match.group(1),
            url=url,
            title=link.text(strip=True),
            age=parse_age(last.text(strip=True)) if last is not None else None,
            sticky=card.css_first("i.card-topic--details--title--icon.pined") is not None,
        ))
    return topics


def _author_id(url: str | None) -> str | None:
    match = PROFILE_ID_RE.search(url or "")
    return (match.group(1) or match.group(2)) if match else None


def parse_thread(html: str) -> list[ForumPost]:
    tree = HTMLParser(html)
    posting = None
    for script in tree.css('script[type="application/ld+json"]'):
        try:
            data = json.loads(script.text())
        except ValueError:
            continue
        if isinstance(data, dict) and data.get("@type") == "DiscussionForumPosting":
            posting = data
            break
    if posting is None:
        return []

    entries = [posting, *posting.get("comment", [])]
    cards = tree.css("div.card-post[id]")
    ids = [card.attributes.get("id") for card in cards]
    names = [(card.css_first("a.card-post__username").text(strip=True) if card.css_first("a.card-post__username") else None) for card in cards]
    aligned = len(ids) == len(entries) and all(
        (names[i] or "") == ((entry.get("author") or {}).get("name") or "") for i, entry in enumerate(entries)
    )
    if not aligned:
        log.warning("post ids do not line up with JSON-LD; using fallback ids", extra={"count": len(entries)})

    posts: list[ForumPost] = []
    for i, entry in enumerate(entries):
        text = (entry.get("text") or "").strip()
        if i == 0 and posting.get("headline"):
            text = f"{posting['headline'].strip()}\n\n{text}"
        if not text:
            continue
        posted_raw = entry.get("datePublished")
        try:
            posted_at = datetime.fromisoformat(posted_raw).astimezone(UTC) if posted_raw else datetime.now(UTC)
        except ValueError:
            posted_at = datetime.now(UTC)
        author = entry.get("author") or {}
        post_id = ids[i] if aligned and ids[i] else f"n{i}-{posted_raw}"
        posts.append(ForumPost(
            post_id=str(post_id),
            author_name=author.get("name"),
            author_id=_author_id(author.get("url")),
            text=text,
            posted_at=posted_at,
            is_first=i == 0,
        ))
    return posts


def to_items(topic: TopicRef, posts: list[ForumPost]) -> list[IngestItem]:
    """First post → external_id "t{thread}"; replies → "p{post}" with parent "t{thread}"."""
    first = next((p for p in posts if p.is_first), None)
    items = []
    for post in posts:
        items.append(IngestItem(
            source_type="forum",
            source_identifier=SOURCE_IDENTIFIER,
            external_id=f"t{topic.thread_id}" if post.is_first else f"p{post.post_id}",
            parent_external_id=None if post.is_first else f"t{topic.thread_id}",
            url=topic.url if post.is_first else f"{topic.url}#{post.post_id}",
            author_id=post.author_id,
            author_handle=post.author_name,
            text=post.text,
            context_text=None if post.is_first or first is None else first.text[:1000],
            posted_at=post.posted_at,
        ))
    return items


# --------------------------------------------------------------- crawling


class RobotsDisallowed(RuntimeError):
    pass


class Crawler:
    """Polite fetcher: robots.txt, clear User-Agent, at most 1 request every `delay` seconds."""

    def __init__(
        self,
        user_agent: str,
        *,
        delay: float = 5.0,
        transport: httpx.BaseTransport | None = None,
        sleep: Callable[[float], None] = time.sleep,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        self.user_agent = user_agent
        self.delay = delay
        self._sleep = sleep
        self._clock = clock
        self._last: float | None = None
        self._http = httpx.Client(headers={"User-Agent": user_agent}, timeout=30.0, follow_redirects=True, transport=transport)
        self._robots: urllib.robotparser.RobotFileParser | None = None

    def close(self) -> None:
        self._http.close()

    def load_robots(self) -> None:
        robots = urllib.robotparser.RobotFileParser()
        robots.parse(self._get("https://www.expat.com/robots.txt", check_robots=False).splitlines())
        self._robots = robots

    def allowed(self, url: str) -> bool:
        return self._robots is None or self._robots.can_fetch(self.user_agent, url)

    def get(self, url: str) -> str:
        return self._get(url, check_robots=True)

    def _get(self, url: str, *, check_robots: bool) -> str:
        if check_robots and not self.allowed(url):
            raise RobotsDisallowed(url)
        if self._last is not None:
            wait = self.delay - (self._clock() - self._last)
            if wait > 0:
                self._sleep(wait)
        try:
            response = self._http.get(url)
        finally:
            self._last = self._clock()
        response.raise_for_status()
        return response.text


def collect(crawler: Crawler, *, pages: int = 2, max_age_days: int = 7) -> list[IngestItem]:
    """One crawl round (SPEC 6.4): 2 listing pages, then threads updated in the last 7 days."""
    crawler.load_robots()
    if not crawler.allowed(BASE_URL):
        raise RobotsDisallowed(BASE_URL)

    topics: dict[str, TopicRef] = {}
    for page in range(1, pages + 1):
        url = BASE_URL if page == 1 else f"{BASE_URL}{page}/"
        for topic in parse_listing(crawler.get(url)):
            if topic.sticky or topic.age is None or topic.age > timedelta(days=max_age_days):
                continue
            topics.setdefault(topic.thread_id, topic)

    items: list[IngestItem] = []
    for topic in topics.values():
        if not crawler.allowed(topic.url):
            log.warning("thread disallowed by robots.txt, skipped", extra={"source": topic.url})
            continue
        posts = parse_thread(crawler.get(topic.url))
        thread_items = to_items(topic, posts)
        items.extend(thread_items)
        log.info("thread collected", extra={"item_id": topic.thread_id, "count": len(thread_items), "excerpt": excerpt(topic.title)})
    return items


def run_once() -> dict[str, int]:
    import asyncio

    from common.ingest_client import IngestClient

    settings = Settings()
    crawler = Crawler(settings.user_agent)
    try:
        items = collect(crawler, max_age_days=int(os.environ.get("FORUM_MAX_AGE_DAYS", "7")))
    finally:
        crawler.close()

    async def send() -> dict[str, int]:
        async with IngestClient(settings) as ingest:
            await ingest.flush_spool()
            totals = await ingest.send(items)
            await ingest.heartbeat("forum", ok=True, meta={"items": len(items)})
            return totals

    return asyncio.run(send())


def main() -> None:
    jsonlog.setup()
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()
    interval = int(os.environ.get("FORUM_POLL_MINUTES", "60")) * 60

    while True:
        try:
            totals = run_once()
            log.info("forum round done", extra={"status": totals})
        except RobotsDisallowed as e:
            log.error("robots.txt disallows crawling; stopping", extra={"source": str(e)})
            raise SystemExit(2) from e
        except Exception:
            log.exception("forum round failed")
        if args.once:
            return
        time.sleep(interval)


if __name__ == "__main__":
    main()
