"""Telegram collector settings, from the environment."""

from __future__ import annotations

import os
from dataclasses import dataclass, field

from common.settings import require


@dataclass(frozen=True)
class TelegramConfig:
    api_id: int = field(default_factory=lambda: int(require("TG_API_ID")))
    api_hash: str = field(default_factory=lambda: require("TG_API_HASH"))
    session_path: str = field(default_factory=lambda: os.environ.get("TG_SESSION_PATH", "/data/telegram.session"))
    sources_refresh_seconds: int = 600       # SPEC 6.2: refresh group list every 10 minutes
    heartbeat_seconds: int = 300             # SPEC 6.2: heartbeat every 5 minutes
    backfill_limit: int = 200                # SPEC 6.2: up to 200 messages ...
    backfill_hours: int = 48                 # ... from the last 48 hours
    flush_seconds: float = 2.0               # realtime items are sent in small batches
    context_max_chars: int = 1000
