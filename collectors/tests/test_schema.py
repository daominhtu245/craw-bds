from datetime import datetime, timedelta, timezone

import pytest
from pydantic import ValidationError

from common.schema import IngestItem, author_key


def make(**kw):
    base = dict(
        source_type="telegram",
        source_identifier="danang_group",
        external_id="-1001:5",
        url="https://t.me/danang_group/5",
        text="Looking for a 1BR",
        posted_at=datetime(2026, 11, 9, 10, 0, tzinfo=timezone(timedelta(hours=7))),
    )
    base.update(kw)
    return IngestItem(**base)


def test_author_id_is_hashed_and_never_sent():
    item = make(author_id="987654321")
    payload = item.to_payload()

    assert "author_id" not in payload
    assert payload["author_key"] == author_key("telegram", "987654321")
    # Same formula as Laravel IngestService::authorKey().
    import hashlib
    assert payload["author_key"] == hashlib.sha256(b"telegram:987654321").hexdigest()
    assert "987654321" not in str(payload)


def test_posted_at_is_utc_iso():
    assert make().to_payload()["posted_at"] == "2026-11-09T03:00:00Z"
    naive = make(posted_at=datetime(2026, 11, 9, 3, 0))
    assert naive.to_payload()["posted_at"] == "2026-11-09T03:00:00Z"


def test_none_fields_are_omitted():
    payload = make().to_payload()
    assert "context_text" not in payload
    assert "author_key" not in payload


@pytest.mark.parametrize("field,value", [("text", ""), ("source_type", "reddit"), ("external_id", "x" * 192)])
def test_validation(field, value):
    with pytest.raises(ValidationError):
        make(**{field: value})
