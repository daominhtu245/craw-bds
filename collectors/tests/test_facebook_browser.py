from datetime import UTC, datetime
from pathlib import Path

import pytest

from common.schema import author_key
from facebook import browser
from facebook.extract import find_stories, html_documents, items_from_documents, parse_documents, post_url
from facebook.session import blocked_reason, is_logged_in

FIXTURES = Path(__file__).parent / "fixtures"
GROUP = "https://www.facebook.com/groups/sample-danang-expats"
SINCE = datetime(2026, 11, 7, 12, 0, tzinfo=UTC)
LOGGED_IN = [{"name": "c_user", "value": "100000000000099"}, {"name": "xs", "value": "secret"}]


def feed_documents():
    return parse_documents((FIXTURES / "facebook_graphql_feed.txt").read_text(encoding="utf-8"))


def test_parse_documents_splits_lines_and_skips_garbage():
    assert len(feed_documents()) == 4
    assert parse_documents('for (;;);{"a": 1}') == [{"a": 1}]
    assert parse_documents("") == []


def test_find_stories_merges_nested_story_and_skips_shared_and_comments():
    stories = find_stories(feed_documents())
    assert set(stories) == {"2222222222", "3333333333", "4444444444", "5555555555"}
    first = stories["2222222222"]
    assert first.text.startswith("Hi all, looking for a 1BR near My Khe")
    assert "Shared listing" not in first.text
    assert first.creation_time == 1794211200
    assert first.author_name == "Sample Renter"
    assert first.url == "https://www.facebook.com/groups/sample-danang-expats/posts/2222222222/"
    assert "9999999999" not in stories


def test_items_filter_old_and_textless_posts():
    items = items_from_documents(feed_documents(), GROUP, SINCE)
    assert [i.external_id for i in items] == ["2222222222", "3333333333"]

    payload = items[0].to_payload()
    assert payload["source_type"] == "facebook"
    assert payload["source_identifier"] == GROUP
    assert payload["posted_at"] == "2026-11-09T08:00:00Z"
    assert payload["author_key"] == author_key("facebook", "100000000000001")
    assert "100000000000001" not in str(payload)

    russian = items[1].to_payload()
    assert russian["url"] == post_url(GROUP, "3333333333") == f"{GROUP}/posts/3333333333/"
    assert russian["text"].startswith("Ищем дом")


def test_html_documents_reads_embedded_json():
    line = (FIXTURES / "facebook_graphql_feed.txt").read_text(encoding="utf-8").splitlines()[1]
    html = (
        "<html><body>"
        '<script type="application/json">{"no": "stories here"}</script>'
        f'<script type="application/json" data-sjs>{line}</script>'
        '<script type="application/json">{broken post_id</script>'
        "</body></html>"
    )
    stories = find_stories(html_documents(html))
    assert list(stories) == ["3333333333"]


def test_blocked_reason():
    assert blocked_reason(f"{GROUP}/?sorting_setting=CHRONOLOGICAL", LOGGED_IN) is None
    assert "checkpoint" in blocked_reason("https://www.facebook.com/checkpoint/1501092823525282/", LOGGED_IN)
    assert "login" in blocked_reason("https://www.facebook.com/login/?next=x", LOGGED_IN)
    assert "not logged in" in blocked_reason(GROUP, [{"name": "datr", "value": "x"}])
    assert is_logged_in(LOGGED_IN)
    assert not is_logged_in([{"name": "c_user", "value": ""}])


def test_feed_url_and_delay_ranges(monkeypatch: pytest.MonkeyPatch):
    assert browser.feed_url(GROUP) == f"{GROUP}/?sorting_setting=CHRONOLOGICAL"
    assert browser.feed_url(f"{GROUP}/?ref=share") == f"{GROUP}/?sorting_setting=CHRONOLOGICAL"
    monkeypatch.setenv("FB_SCROLL_DELAY", "3-4")
    assert browser._range("FB_SCROLL_DELAY", (5, 10)) == (3.0, 4.0)
    monkeypatch.setenv("FB_SCROLL_DELAY", "7")
    assert browser._range("FB_SCROLL_DELAY", (5, 10)) == (7.0, 7.0)
    monkeypatch.delenv("FB_SCROLL_DELAY")
    assert browser._range("FB_SCROLL_DELAY", (5, 10)) == (5, 10)


def test_module_has_no_write_actions():
    """Read-only guard: the browser collector must never click, type or submit."""
    source = (Path(browser.__file__)).read_text(encoding="utf-8")
    for forbidden in (".click(", ".fill(", ".type(", ".press(", "keyboard.", ".check(", "set_input_files"):
        assert forbidden not in source


# ------------------------------------------------------------ fake browser


class FakeResponse:
    def __init__(self, url: str, body: str, method: str = "POST") -> None:
        self.url = url
        self.request = type("Req", (), {"method": method})()
        self._body = body

    async def text(self) -> str:
        return self._body


class FakeMouse:
    def __init__(self, page: "FakePage") -> None:
        self.page = page

    async def wheel(self, dx: int, dy: int) -> None:
        self.page.scrolls += 1
        for response in self.page.on_scroll.pop(0) if self.page.on_scroll else []:
            self.page.handler(response)


class FakePage:
    def __init__(self, final_url: str, html: str, on_scroll: list[list[FakeResponse]]) -> None:
        self.url = ""
        self.final_url = final_url
        self.html = html
        self.on_scroll = on_scroll
        self.scrolls = 0
        self.closed = False
        self.handler = lambda r: None
        self.mouse = FakeMouse(self)

    def on(self, event: str, handler) -> None:
        assert event == "response"
        self.handler = handler

    async def goto(self, url: str, **kwargs) -> None:
        self.requested = url
        self.url = self.final_url

    async def wait_for_timeout(self, ms: float) -> None:
        return None

    async def content(self) -> str:
        return self.html

    async def close(self) -> None:
        self.closed = True


class FakeContext:
    def __init__(self, page: FakePage, cookies=LOGGED_IN) -> None:
        self.page = page
        self._cookies = cookies

    async def new_page(self) -> FakePage:
        return self.page

    async def cookies(self, url: str):
        return self._cookies


def test_scrape_group_reads_html_and_graphql_responses():
    from tests.conftest import run

    lines = (FIXTURES / "facebook_graphql_feed.txt").read_text(encoding="utf-8").splitlines()
    html = f'<script type="application/json">{lines[0]}</script>'
    page = FakePage(
        f"{GROUP}/?sorting_setting=CHRONOLOGICAL",
        html,
        on_scroll=[
            [FakeResponse("https://www.facebook.com/api/graphql/", "\n".join(lines[1:]))],
            [FakeResponse("https://www.facebook.com/ajax/bz", "ignored")],
        ],
    )
    source = {"identifier": GROUP, "last_item_at": None, "meta": {"scrolls": 2}}
    items = run(browser.scrape_group(FakeContext(page), source, datetime(2026, 11, 9, 12, 0, tzinfo=UTC)))

    assert page.requested == f"{GROUP}/?sorting_setting=CHRONOLOGICAL"
    assert page.scrolls == 2
    assert page.closed
    assert [i.external_id for i in items] == ["2222222222", "3333333333"]


def test_scrape_group_stops_on_checkpoint():
    from facebook.session import BlockedError
    from tests.conftest import run

    page = FakePage("https://www.facebook.com/checkpoint/828281030927956/", "", on_scroll=[])
    with pytest.raises(BlockedError):
        run(browser.scrape_group(FakeContext(page), {"identifier": GROUP}, datetime(2026, 11, 9, tzinfo=UTC)))
    assert page.scrolls == 0
    assert page.closed
