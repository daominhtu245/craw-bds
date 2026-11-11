r"""Threads browser profile: log in once, then reused by threads.browser.

Run:  .\run-local.ps1 threads.session                        log in by hand
      .\run-local.ps1 threads.session --import cookies.json  or import cookies exported
                                                             from your own browser

Manual: a visible browser opens on threads.com; you log in yourself (Instagram
account) and close the window when your feed shows.
Import: log in on your normal Chrome, export the threads.com cookies (e.g.
Cookie-Editor → Export → JSON, or a cookies.txt file), import, then DELETE the
export file: it gives full access to the account.

Cookies stay in THREADS_PROFILE_DIR (default ..\data\threads-profile,
gitignored, never commit).

Owner decision 2026-11-11: personal use, read-only. Against Threads' terms and
robots.txt; the account can be checkpointed. Use a secondary account.
"""

from __future__ import annotations

from common.browser_session import Site, session_main

SITE = Site(
    name="threads",
    home="https://www.threads.com/",
    login_cookies=("sessionid",),
    cookie_domains=("threads.com", "threads.net"),
    block_markers=("/login", "/accounts/login", "/challenge", "/checkpoint", "/suspended"),
    profile_env="THREADS_PROFILE_DIR",
    channel_env="THREADS_BROWSER_CHANNEL",
)


def main() -> None:
    session_main(SITE, "Threads")


if __name__ == "__main__":
    main()
