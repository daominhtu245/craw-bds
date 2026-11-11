"""Cookie import and block detection for the browser-session collectors. Values are made up."""

import json

import pytest

from common.browser_session import parse_cookie_export
from reddit.session import SITE as REDDIT
from threads.session import SITE as THREADS

COOKIE_EDITOR_JSON = json.dumps([
    {"domain": ".reddit.com", "expirationDate": 1893456000.5, "hostOnly": False, "httpOnly": True, "name": "reddit_session",
     "path": "/", "sameSite": "no_restriction", "secure": True, "session": False, "storeId": None, "value": "fake-session-value"},
    {"domain": "www.reddit.com", "hostOnly": True, "httpOnly": False, "name": "csv", "path": "/", "sameSite": None,
     "secure": True, "session": True, "value": "2"},
    {"domain": ".google.com", "expirationDate": 1893456000, "httpOnly": True, "name": "SID", "path": "/",
     "sameSite": "lax", "secure": True, "session": False, "value": "should-not-be-imported"},
])

COOKIES_TXT = "\n".join([
    "# Netscape HTTP Cookie File",
    "#HttpOnly_.threads.com\tTRUE\t/\tTRUE\t1893456000\tsessionid\tfake-threads-session",
    ".threads.com\tTRUE\t/\tTRUE\t0\tcsrftoken\tfake-csrf",
    ".instagram.com\tTRUE\t/\tTRUE\t1893456000\tsessionid\tother-site",
    "",
])


def test_cookie_editor_json_keeps_only_site_domains():
    cookies = parse_cookie_export(COOKIE_EDITOR_JSON, REDDIT.cookie_domains)

    assert [c["name"] for c in cookies] == ["reddit_session", "csv"]
    session = cookies[0]
    assert session["expires"] == 1893456000.5
    assert session["httpOnly"] is True and session["secure"] is True
    assert session["sameSite"] == "None"
    assert cookies[1]["expires"] == -1  # session cookie
    assert cookies[1]["sameSite"] == "Lax"
    assert REDDIT.is_logged_in(cookies)


def test_cookies_txt_with_httponly_prefix():
    cookies = parse_cookie_export(COOKIES_TXT, THREADS.cookie_domains)

    assert [(c["name"], c["domain"]) for c in cookies] == [("sessionid", ".threads.com"), ("csrftoken", ".threads.com")]
    assert cookies[0]["httpOnly"] is True
    assert cookies[1]["httpOnly"] is False and cookies[1]["expires"] == -1
    assert THREADS.is_logged_in(cookies)


def test_json_object_with_cookies_key_and_bom_free_input():
    raw = json.dumps({"cookies": [{"name": "reddit_session", "value": "x", "domain": "reddit.com"}]})

    cookies = parse_cookie_export(raw, REDDIT.cookie_domains)

    assert cookies[0]["path"] == "/" and cookies[0]["expires"] == -1


def test_lookalike_domain_is_not_imported():
    raw = json.dumps([{"name": "reddit_session", "value": "x", "domain": ".notreddit.com"}])

    assert parse_cookie_export(raw, REDDIT.cookie_domains) == []


def test_malformed_cookies_txt_raises():
    with pytest.raises(ValueError):
        parse_cookie_export(".reddit.com\tTRUE\t/\n", REDDIT.cookie_domains)


@pytest.mark.parametrize("site,url,expected", [
    (REDDIT, "https://www.reddit.com/r/danang/new/", None),
    (REDDIT, "https://www.reddit.com/login/?dest=x", "reddit redirected to login"),
    (THREADS, "https://www.threads.com/search?q=da%20nang", None),
    (THREADS, "https://www.threads.com/challenge/123", "threads redirected to challenge"),
])
def test_blocked_reason(site, url, expected):
    cookies = [{"name": site.login_cookies[0], "value": "x"}]

    assert site.blocked_reason(url, cookies) == expected


def test_missing_login_cookie_blocks():
    assert REDDIT.blocked_reason("https://www.reddit.com/r/danang/", []) == "not logged in (run reddit.session)"
