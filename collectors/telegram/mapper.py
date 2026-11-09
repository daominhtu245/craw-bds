"""Pure mapping from Telethon messages to IngestItem (SPEC 6.2).

Works on duck-typed objects so tests can use simple fakes.
"""

from __future__ import annotations

from datetime import UTC, datetime
from typing import Any

from common.schema import IngestItem


def internal_chat_id(chat_id: int) -> int | None:
    """Marked supergroup/channel id -100XXXXXXXXXX → XXXXXXXXXX (telethon.utils.resolve_id).

    Basic groups (negative ids without the -100 prefix) and users have no
    t.me/c/ message links, so None is returned for them.
    """
    if chat_id < 0 and -chat_id > 1_000_000_000_000:
        return -chat_id - 1_000_000_000_000
    return None


def message_url(chat_id: int, message_id: int, username: str | None) -> str:
    """https://t.me/{username}/{id} for public chats, https://t.me/c/{internal}/{id} otherwise."""
    if username:
        return f"https://t.me/{username}/{message_id}"
    internal = internal_chat_id(chat_id)
    if internal is None:
        # Basic groups have no message links; point at the chat id so the item is still traceable.
        internal = abs(chat_id)
    return f"https://t.me/c/{internal}/{message_id}"


def should_skip(message: Any, sender: Any | None) -> str | None:
    """Return a reason to skip the message, or None to keep it."""
    if not (getattr(message, "raw_text", None) or "").strip():
        return "no_text"
    if sender is not None and getattr(sender, "bot", False):
        return "bot"
    fwd = getattr(message, "fwd_from", None)
    if fwd is not None and getattr(fwd, "channel_post", None):
        return "channel_forward"
    return None


def build_item(
    message: Any,
    *,
    source_identifier: str,
    chat_username: str | None,
    sender: Any | None,
    reply_text: str | None = None,
    context_max_chars: int = 1000,
) -> IngestItem | None:
    if should_skip(message, sender) is not None:
        return None

    chat_id = int(message.chat_id)
    posted_at = message.date if isinstance(message.date, datetime) else datetime.now(UTC)
    sender_id = getattr(message, "sender_id", None)
    reply_to = getattr(message, "reply_to_msg_id", None)
    context = (reply_text or "").strip()[:context_max_chars] or None

    return IngestItem(
        source_type="telegram",
        source_identifier=source_identifier,
        external_id=f"{chat_id}:{message.id}",
        parent_external_id=f"{chat_id}:{reply_to}" if reply_to else None,
        url=message_url(chat_id, int(message.id), chat_username),
        author_id=str(sender_id) if sender_id is not None else None,
        author_handle=getattr(sender, "username", None) if sender is not None else None,
        text=message.raw_text,
        context_text=context,
        posted_at=posted_at,
    )
