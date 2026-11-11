r"""Reddit browser profile: log in once, then reused by reddit.browser.

Run:  .\run-local.ps1 reddit.session                        log in by hand
      .\run-local.ps1 reddit.session --import cookies.json  or import cookies exported
                                                            from your own browser

Manual: a visible browser opens on reddit.com; you log in yourself and close
the window when your feed shows.
Import: log in on your normal Chrome, export the reddit.com cookies (e.g.
Cookie-Editor → Export → JSON, or a cookies.txt file), import, then DELETE the
export file: it gives full access to the account.

Cookies stay in REDDIT_PROFILE_DIR (default ..\data\reddit-profile, gitignored,
never commit).

Owner decision 2026-11-11: personal use, read-only. Against Reddit's terms and
robots.txt; the account can be suspended. Use a secondary account.
"""

from __future__ import annotations

from common.browser_session import Site, session_main

SITE = Site(
    name="reddit",
    home="https://www.reddit.com/",
    login_cookies=("reddit_session",),
    cookie_domains=("reddit.com",),
    block_markers=("/login", "/account/login", "/register", "/captcha", "/verify"),
    profile_env="REDDIT_PROFILE_DIR",
    channel_env="REDDIT_BROWSER_CHANNEL",
)


def main() -> None:
    session_main(SITE, "Reddit")


if __name__ == "__main__":
    main()
