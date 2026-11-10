"""Facebook browser profile: manual login once, then reused by facebook.browser.

Run:  .\\run-local.ps1 facebook.session

A visible browser opens on facebook.com. You log in yourself (password, 2FA,
any security check). Nothing here types credentials, solves a CAPTCHA or
clicks for you. Close the browser window when the feed shows; the cookies stay
in FB_PROFILE_DIR (default ..\\data\\facebook-profile, gitignored, never commit).

Owner decision 2026-11-10: personal, non-commercial use, read-only. This is
against Facebook's terms and robots.txt; the account can be checkpointed or
banned. Use a secondary account.
"""

from __future__ import annotations

import asyncio
import os
from pathlib import Path
from typing import Any

HOME = "https://www.facebook.com/"
LOGIN_COOKIE = "c_user"
BLOCK_MARKERS = ("/checkpoint", "/login", "two_step_verification", "/captcha", "/recover")


class BlockedError(RuntimeError):
    """Facebook asked for login, a checkpoint or a CAPTCHA. Never bypassed: the round stops."""


def profile_dir() -> Path:
    return Path(os.environ.get("FB_PROFILE_DIR") or "/data/facebook-profile")


def is_logged_in(cookies: list[dict[str, Any]]) -> bool:
    return any(c.get("name") == LOGIN_COOKIE and c.get("value") for c in cookies)


def blocked_reason(url: str, cookies: list[dict[str, Any]]) -> str | None:
    """Why the page cannot be read, or None if it looks like a normal logged-in page."""
    lowered = url.lower()
    for marker in BLOCK_MARKERS:
        if marker in lowered:
            return f"facebook redirected to {marker.strip('/')}"
    if not is_logged_in(cookies):
        return "not logged in (run facebook.session)"
    return None


async def launch(playwright: Any, *, headless: bool) -> Any:
    """Persistent context so the login survives between runs."""
    directory = profile_dir()
    directory.mkdir(parents=True, exist_ok=True)
    kwargs: dict[str, Any] = {
        "headless": headless,
        "locale": "en-US",
        "viewport": {"width": 1280, "height": 900},
    }
    channel = os.environ.get("FB_BROWSER_CHANNEL")  # e.g. "msedge" or "chrome"; empty = bundled Chromium
    if channel:
        kwargs["channel"] = channel
    return await playwright.chromium.launch_persistent_context(str(directory), **kwargs)


async def main() -> None:
    from playwright.async_api import async_playwright

    async with async_playwright() as p:
        context = await launch(p, headless=False)
        page = context.pages[0] if context.pages else await context.new_page()
        await page.goto(HOME)
        print("Log in to Facebook in the browser window yourself (2FA / security checks included).")
        print("When your feed is visible, close the browser window to save the session.")
        closed = asyncio.Event()
        context.on("close", lambda _: closed.set())
        last_ok = False
        while not closed.is_set():
            try:
                last_ok = is_logged_in(await context.cookies(HOME))
            except Exception:
                break
            try:
                await asyncio.wait_for(closed.wait(), timeout=2)
            except TimeoutError:
                pass
    if last_ok:
        print(f"Logged in. Session saved to {profile_dir()}")
    else:
        print("No login detected; run facebook.session again.")


if __name__ == "__main__":
    asyncio.run(main())
