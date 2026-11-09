"""Environment settings shared by collectors. Secrets only come from the environment."""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path


def _env(name: str, default: str | None = None) -> str | None:
    value = os.environ.get(name, default)
    return value if value not in ("", None) else default


@dataclass(frozen=True)
class Settings:
    ingest_url: str = field(default_factory=lambda: (_env("INGEST_URL", "http://app:8080/api") or "").rstrip("/"))
    ingest_token: str = field(default_factory=lambda: _env("INGEST_TOKEN", "") or "")
    spool_dir: Path = field(default_factory=lambda: Path(_env("SPOOL_DIR", "/data/spool") or "/data/spool"))
    user_agent: str = field(default_factory=lambda: _env("CRAWLER_USER_AGENT", "ExpatLeadRadar/1.0") or "ExpatLeadRadar/1.0")


def require(name: str) -> str:
    value = _env(name)
    if value is None:
        raise SystemExit(f"Missing required environment variable {name}")
    return value
