"""Reddit collector stub (SPEC 6.5).

Disabled on purpose: Reddit requires a commercial API agreement for this use.
Only the interface exists so the collector can be added later without
changing the ingest contract.
"""

from __future__ import annotations

from typing import Protocol

from common.schema import IngestItem


class RedditCollector(Protocol):
    def collect(self, subreddits: list[str]) -> list[IngestItem]:
        """Return new posts/comments mentioning housing in Da Nang."""
        ...


class DisabledRedditCollector:
    def collect(self, subreddits: list[str]) -> list[IngestItem]:
        raise NotImplementedError("Reddit collection is disabled until a commercial API agreement exists (SPEC 6.5).")
