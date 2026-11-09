from datetime import UTC, datetime, timedelta
from pathlib import Path

import httpx
import pytest

from common.schema import author_key
from forum.expat_com import (
    BASE_URL,
    Crawler,
    RobotsDisallowed,
    TopicRef,
    collect,
    parse_age,
    parse_listing,
    parse_thread,
    to_items,
)

FIXTURES = Path(__file__).parent / "fixtures"
LISTING = (FIXTURES / "expat_com_listing.html").read_text(encoding="utf-8")
THREAD = (FIXTURES / "expat_com_thread.html").read_text(encoding="utf-8")
THREAD_URL = "https://www.expat.com/en/forum/asia/vietnam/da-nang/900101-sample-moving-in-january-need-a-flat.html"


@pytest.mark.parametrize("label,days", [
    ("Last post 3 days ago by X", 3),
    ("Last post yesterday by X", 1),
    ("Last post an hour ago by X", 1 / 24),
    ("Last post 2 weeks ago", 14),
    ("Last post last month by X", 30),
    ("Last post 2 years ago", 730),
    ("Last post just now", 0),
])
def test_parse_age(label, days):
    assert parse_age(label) == timedelta(days=days)


def test_parse_age_unknown():
    assert parse_age("Last post sometime") is None


def test_parse_listing_keeps_only_da_nang_threads():
    topics = parse_listing(LISTING)

    assert [t.thread_id for t in topics] == ["900101", "900102", "900103"]
    assert topics[0].url == THREAD_URL
    assert topics[0].title == "Sample: moving in January, need a flat"
    assert topics[0].age == timedelta(days=3)
    assert topics[2].age == timedelta(days=730)


def test_parse_thread_aligns_ids_with_json_ld():
    posts = parse_thread(THREAD)

    assert [p.post_id for p in posts] == ["7000101", "7000104", "7000105"]
    assert [p.is_first for p in posts] == [True, False, False]
    assert posts[0].text.startswith("Sample: moving in January, need a flat\n\nHi all")
    assert posts[0].author_id == "200"          # profile.php?id=200
    assert posts[1].author_id == "201"          # /en/profile/201-sample-helper.html
    assert posts[0].posted_at == datetime(2026, 11, 5, 8, 15, tzinfo=UTC)


def test_parse_thread_falls_back_when_cards_do_not_match():
    broken = THREAD.replace('<div id="7000105" class="card-post">', '<div class="card-post">')
    posts = parse_thread(broken)
    assert len(posts) == 3
    assert posts[1].post_id.startswith("n1-")


def test_parse_thread_without_json_ld_returns_nothing():
    assert parse_thread("<html><body><div class='card-post' id='1'></div></body></html>") == []


def test_to_items():
    topic = TopicRef("900101", THREAD_URL, "Sample", timedelta(days=3), False)
    items = [i.to_payload() for i in to_items(topic, parse_thread(THREAD))]

    assert items[0]["external_id"] == "t900101"
    assert "parent_external_id" not in items[0]
    assert items[1]["external_id"] == "p7000104"
    assert items[1]["parent_external_id"] == "t900101"
    assert items[1]["url"] == f"{THREAD_URL}#7000104"
    assert items[1]["context_text"].startswith("Sample: moving in January")
    assert items[1]["author_key"] == author_key("forum", "201")
    assert items[1]["author_handle"] == "Sample Helper"
    assert all(i["source_type"] == "forum" and i["source_identifier"] == BASE_URL for i in items)


ROBOTS_OK = "User-agent: *\nDisallow: /forum/*.html$\nDisallow: /en/login/\n"
ROBOTS_BLOCK = "User-agent: *\nDisallow: /en/forum/\n"


def site(robots: str, requests: list[str]):
    pages = {
        "https://www.expat.com/robots.txt": robots,
        BASE_URL: LISTING,
        f"{BASE_URL}2/": "<html><body></body></html>",
        THREAD_URL: THREAD,
        "https://www.expat.com/en/forum/asia/vietnam/da-nang/900102-sample-best-area-for-retirees.html": THREAD.replace("900101", "900102"),
    }

    def handler(request: httpx.Request) -> httpx.Response:
        requests.append(str(request.url))
        body = pages.get(str(request.url))
        return httpx.Response(200, text=body) if body is not None else httpx.Response(404)

    return httpx.MockTransport(handler)


def test_collect_round_respects_robots_rate_limit_and_age():
    requests: list[str] = []
    sleeps: list[float] = []
    clock = iter(range(0, 1000))  # each call advances 1 "second"
    crawler = Crawler("ExpatLeadRadar/1.0 (+mailto:ops@example.com)", transport=site(ROBOTS_OK, requests),
                      sleep=sleeps.append, clock=lambda: next(clock))

    items = collect(crawler)

    # robots, 2 listing pages, 2 recent threads (old thread and sticky topic skipped)
    assert requests[0] == "https://www.expat.com/robots.txt"
    assert requests[1:3] == [BASE_URL, f"{BASE_URL}2/"]
    assert len(requests) == 5
    assert "900103" not in " ".join(requests)
    assert len(items) == 6
    # 1 request / 5 s: every request after the first waited.
    assert len(sleeps) == 4 and all(0 < s <= 5 for s in sleeps)


def test_collect_stops_when_robots_disallows():
    requests: list[str] = []
    crawler = Crawler("ExpatLeadRadar/1.0", transport=site(ROBOTS_BLOCK, requests), sleep=lambda s: None)

    with pytest.raises(RobotsDisallowed):
        collect(crawler)
    assert requests == ["https://www.expat.com/robots.txt"]


def test_user_agent_header():
    requests: list[httpx.Request] = []

    def handler(request):
        requests.append(request)
        return httpx.Response(200, text="User-agent: *\n")

    crawler = Crawler("ExpatLeadRadar/1.0 (+mailto:ops@example.com)", transport=httpx.MockTransport(handler), sleep=lambda s: None)
    crawler.load_robots()
    assert requests[0].headers["user-agent"] == "ExpatLeadRadar/1.0 (+mailto:ops@example.com)"


def test_parse_thread_separates_quoted_text_from_reply():
    posts = parse_thread(THREAD)

    reply = posts[2]
    assert reply.text == "Thanks! We also have a small dog, so it must be pet friendly."
    assert reply.quote == "An Thuong is easy to live in without a motorbike. Prices for a 1 bedroom start around 400 USD."
    assert posts[1].quote is None
    # First post keeps paragraph breaks from the HTML.
    assert posts[0].text.endswith("budget around 500 USD.\nAny agents you trust?")


def test_reply_context_is_the_quoted_post():
    topic = TopicRef("900101", THREAD_URL, "Sample", timedelta(days=3), False)
    items = [i.to_payload() for i in to_items(topic, parse_thread(THREAD))]

    assert items[2]["text"] == "Thanks! We also have a small dog, so it must be pet friendly."
    assert items[2]["context_text"].startswith("An Thuong is easy to live in")
    assert items[1]["context_text"].startswith("Sample: moving in January")   # no quote: first post


def test_message_without_paragraph_tags_keeps_all_text():
    html = THREAD.replace(
        '<p>An Thuong is easy to live in without a motorbike. Prices for a 1 bedroom start around 400 USD.</p>',
        '<ul><li>An Thuong is easy</li></ul><p>Prices start around 400 USD.</p>',
    )
    assert parse_thread(html)[1].text == "An Thuong is easy Prices start around 400 USD."
