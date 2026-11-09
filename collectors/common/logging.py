"""JSON logging (SPEC 17). Never log full message text: use excerpt()."""

from __future__ import annotations

import json
import logging
import sys
from datetime import UTC, datetime

_EXTRA_FIELDS = ("collector", "source", "item_id", "job", "count", "seconds", "error", "status", "excerpt", "cost_usd")


class JsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        payload = {
            "ts": datetime.now(UTC).strftime("%Y-%m-%dT%H:%M:%S.%fZ"),
            "level": record.levelname.lower(),
            "logger": record.name,
            "message": record.getMessage(),
        }
        for key in _EXTRA_FIELDS:
            if hasattr(record, key):
                payload[key] = getattr(record, key)
        if record.exc_info:
            payload["exception"] = self.formatException(record.exc_info)
        return json.dumps(payload, ensure_ascii=False, default=str)


def setup(level: str = "INFO") -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(JsonFormatter())
    root = logging.getLogger()
    root.handlers[:] = [handler]
    root.setLevel(level)
    # Telethon is chatty at INFO.
    logging.getLogger("telethon").setLevel(logging.WARNING)
    logging.getLogger("httpx").setLevel(logging.WARNING)


def excerpt(text: str | None, length: int = 80) -> str:
    flat = " ".join((text or "").split())
    return flat if len(flat) <= length else flat[: length - 1] + "…"
