"""Normalised item sent to POST /api/ingest/items (SPEC 6.1)."""

from __future__ import annotations

import hashlib
from datetime import UTC, datetime
from typing import Any, Literal

from pydantic import BaseModel, Field, field_validator

SourceType = Literal["telegram", "facebook", "forum"]


def author_key(source_type: str, author_id: str | int) -> str:
    """sha256("{source_type}:{author_id}"). Must match IngestService::authorKey() in Laravel."""
    return hashlib.sha256(f"{source_type}:{author_id}".encode()).hexdigest()


class IngestItem(BaseModel):
    source_type: SourceType
    source_identifier: str = Field(min_length=1, max_length=255)
    external_id: str = Field(min_length=1, max_length=191)
    parent_external_id: str | None = Field(default=None, max_length=191)
    url: str = Field(min_length=1, max_length=1024)
    # Raw platform author ID. Only used to compute author_key; NEVER sent to the server.
    author_id: str | None = Field(default=None, exclude=True)
    author_key: str | None = None
    author_handle: str | None = Field(default=None, max_length=191)
    text: str = Field(min_length=1)
    context_text: str | None = None
    posted_at: datetime

    @field_validator("posted_at")
    @classmethod
    def _utc(cls, value: datetime) -> datetime:
        if value.tzinfo is None:
            return value.replace(tzinfo=UTC)
        return value.astimezone(UTC)

    def model_post_init(self, __context: Any) -> None:
        if self.author_key is None and self.author_id is not None:
            self.author_key = author_key(self.source_type, self.author_id)
        # Drop the raw ID from memory once hashed.
        self.author_id = None

    def to_payload(self) -> dict[str, Any]:
        """JSON-ready dict without author_id; posted_at as ISO 8601 UTC."""
        data = self.model_dump(exclude={"author_id"}, exclude_none=True)
        data["posted_at"] = self.posted_at.strftime("%Y-%m-%dT%H:%M:%SZ")
        return data
