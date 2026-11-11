"""Shared helpers for collectors that read with the owner's own logged-in browser profile.

Owner decisions: Facebook 2026-11-10, Reddit and Threads 2026-11-11 (personal
use, read-only). Against these sites' terms and robots.txt; the account can be
checkpointed or banned. Use a secondary account.

Rules every such collector follows:
- the owner logs in by hand (``<site>.session``) or imports cookies exported
  from their own browser (``<site>.session --import file``); nothing here types
  credentials, solves a CAPTCHA or clicks through a security check;
- reading only: no clicks, likes, comments, follows, joins or messages;
- slow, randomised delays;
- on a login page, checkpoint or CAPTCHA the round stops (``BlockedError``).
"""

from __future__ import annotations

import argparse
import asyncio
import json
import os
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any


class BlockedError(RuntimeError):
    """The site asked for login, a checkpoint or a CAPTCHA. Never bypassed: the round stops."""


@dataclass(frozen=True)
class Site:
    name: str  # "reddit", "threads"
    home: str
    login_cookies: tuple[str, ...]  # logged in if any of these has a value
    block_markers: tuple[str, ...]
    cookie_domains: tuple[str, ...]  # only cookies for these domains are imported
    profile_env: str  # e.g. REDDIT_PROFILE_DIR
    channel_env: str = ""  # e.g. REDDIT_BROWSER_CHANNEL ("msedge", "chrome"; empty = bundled Chromium)
    extra_launch: dict[str, Any] = field(default_factory=dict)

    def profile_dir(self) -> Path:
        return Path(os.environ.get(self.profile_env) or f"/data/{self.name}-profile")

    def is_logged_in(self, cookies: list[dict[str, Any]]) -> bool:
        return any(c.get("name") in self.login_cookies and c.get("value") for c in cookies)

    def blocked_reason(self, url: str, cookies: list[dict[str, Any]]) -> str | None:
        """Why the page cannot be read, or None if it looks like a normal logged-in page."""
        lowered = url.lower()
        for marker in self.block_markers:
            if marker in lowered:
                return f"{self.name} redirected to {marker.strip('/')}"
        if not self.is_logged_in(cookies):
            return f"not logged in (run {self.name}.session)"
        return None


_SAME_SITE = {"strict": "Strict", "lax": "Lax", "none": "None", "no_restriction": "None"}


def _domain_matches(domain: str, allowed: tuple[str, ...]) -> bool:
    bare = domain.lstrip(".").lower()
    return any(bare == d or bare.endswith("." + d) for d in allowed)


def parse_cookie_export(raw: str, allowed_domains: tuple[str, ...]) -> list[dict[str, Any]]:
    """Cookies exported from the owner's own browser → Playwright cookies.

    Accepts a JSON array (Cookie-Editor / EditThisCookie export) or a Netscape
    cookies.txt file. Cookies for other domains are dropped, so exporting
    "all cookies" by mistake imports only the ones this site needs.
    """
    text = raw.strip()
    rows: list[dict[str, Any]] = []
    if text.startswith("[") or text.startswith("{"):
        data = json.loads(text)
        if isinstance(data, dict):
            data = data.get("cookies", [])
        for c in data:
            expires = c.get("expirationDate", c.get("expires"))
            rows.append({
                "name": str(c["name"]),
                "value": str(c["value"]),
                "domain": str(c["domain"]),
                "path": str(c.get("path") or "/"),
                "expires": float(expires) if expires not in (None, "", -1) and not c.get("session") else -1,
                "httpOnly": bool(c.get("httpOnly", False)),
                "secure": bool(c.get("secure", False)),
                "sameSite": _SAME_SITE.get(str(c.get("sameSite") or "").lower(), "Lax"),
            })
    else:
        for line in text.splitlines():
            http_only = line.startswith("#HttpOnly_")
            if http_only:
                line = line[len("#HttpOnly_"):]
            if not line.strip() or line.startswith("#"):
                continue
            parts = line.split("\t")
            if len(parts) != 7:
                raise ValueError("cookies.txt line does not have 7 tab-separated fields")
            domain, _flag, path, secure, expires, name, value = parts
            rows.append({
                "name": name,
                "value": value,
                "domain": domain,
                "path": path or "/",
                "expires": float(expires) if expires not in ("", "0") else -1,
                "httpOnly": http_only,
                "secure": secure.upper() == "TRUE",
                "sameSite": "Lax",
            })
    return [r for r in rows if _domain_matches(r["domain"], allowed_domains)]


async def import_cookies(site: Site, path: Path) -> bool:
    """Load an exported cookie file into the site's profile. Returns True if it is now logged in."""
    from playwright.async_api import async_playwright

    cookies = parse_cookie_export(path.read_text(encoding="utf-8-sig"), site.cookie_domains)
    if not cookies:
        print(f"No cookies for {', '.join(site.cookie_domains)} in {path}.")
        return False
    async with async_playwright() as p:
        context = await launch(p, site, headless=True)
        try:
            await context.add_cookies(cookies)
            logged_in = site.is_logged_in(await context.cookies(site.home))
        finally:
            await context.close()
    # Names only, never values.
    print(f"Imported {len(cookies)} cookies into {site.profile_dir()}: {', '.join(sorted({c['name'] for c in cookies}))}")
    if logged_in:
        print(f"Logged in. Delete {path} now: it gives full access to the account.")
    else:
        print(f"No login cookie ({', '.join(site.login_cookies)}) found; export again while logged in.")
    return logged_in


def session_main(site: Site, label: str, argv: list[str] | None = None) -> None:
    """Entry point for <site>.session: manual login, or --import <cookie file>."""
    parser = argparse.ArgumentParser(prog=f"{site.name}.session")
    parser.add_argument("--import", dest="import_path", type=Path, help="cookie export (JSON or cookies.txt) from your own browser")
    args = parser.parse_args(argv)
    if args.import_path:
        raise SystemExit(0 if asyncio.run(import_cookies(site, args.import_path)) else 1)
    asyncio.run(manual_login(site, label))


def range_env(name: str, default: tuple[float, float]) -> tuple[float, float]:
    """'5-10' or '7' → (low, high)."""
    raw = (os.environ.get(name) or "").strip()
    if not raw:
        return default
    parts = [float(p) for p in raw.replace(" ", "").split("-", 1)]
    return (parts[0], parts[-1]) if len(parts) == 2 else (parts[0], parts[0])


def headless_env(name: str) -> bool:
    return os.environ.get(name, "1") not in ("0", "false", "False")


async def launch(playwright: Any, site: Site, *, headless: bool) -> Any:
    """Persistent context so the login survives between runs."""
    directory = site.profile_dir()
    directory.mkdir(parents=True, exist_ok=True)
    kwargs: dict[str, Any] = {
        "headless": headless,
        "locale": "en-US",
        "viewport": {"width": 1280, "height": 900},
        **site.extra_launch,
    }
    channel = os.environ.get(site.channel_env) if site.channel_env else None
    if channel:
        kwargs["channel"] = channel
    return await playwright.chromium.launch_persistent_context(str(directory), **kwargs)


async def manual_login(site: Site, label: str) -> None:
    """Open a visible browser on the site; the owner logs in, then closes the window."""
    from playwright.async_api import async_playwright

    async with async_playwright() as p:
        context = await launch(p, site, headless=False)
        page = context.pages[0] if context.pages else await context.new_page()
        await page.goto(site.home)
        print(f"Log in to {label} in the browser window yourself (2FA / security checks included).")
        print("When your feed is visible, close the browser window to save the session.")
        closed = asyncio.Event()
        context.on("close", lambda _: closed.set())
        last_ok = False
        cookie_names: list[str] = []
        while not closed.is_set():
            try:
                cookies = await context.cookies(site.home)
                cookie_names = sorted({str(c.get("name")) for c in cookies})
                last_ok = site.is_logged_in(cookies)
            except Exception:
                break
            try:
                await asyncio.wait_for(closed.wait(), timeout=2)
            except TimeoutError:
                pass
    if last_ok:
        print(f"Logged in. Session saved to {site.profile_dir()}")
    else:
        # Names only (never values) so the login cookie list can be checked.
        print(f"No login detected; run {site.name}.session again. Cookie names seen: {', '.join(cookie_names)}")
