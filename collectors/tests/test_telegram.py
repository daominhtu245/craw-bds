from datetime import UTC, datetime, timedelta
from types import SimpleNamespace

import pytest

from common.schema import author_key
from telegram.config import TelegramConfig
from telegram.listener import Listener
from telegram.mapper import build_item, internal_chat_id, message_url
from tests.conftest import run

NOW = datetime(2026, 11, 9, 3, 0, tzinfo=UTC)


def msg(**kw):
    base = dict(id=42, chat_id=-1001234567890, raw_text="Looking for 1BR in An Thuong from Dec, 6 months, budget 450$",
                date=NOW, sender_id=777, fwd_from=None, reply_to_msg_id=None, is_reply=False)
    base.update(kw)
    return SimpleNamespace(**base)


def user(**kw):
    return SimpleNamespace(**{"bot": False, "username": "alice_dn", **kw})


@pytest.mark.parametrize("chat_id,expected", [
    (-1001234567890, 1234567890),   # supergroup / channel
    (-123456, None),                # basic group: no message links
    (777, None),                    # user
])
def test_internal_chat_id(chat_id, expected):
    assert internal_chat_id(chat_id) == expected


def test_message_url():
    assert message_url(-1001234567890, 42, "danang_expats") == "https://t.me/danang_expats/42"
    assert message_url(-1001234567890, 42, None) == "https://t.me/c/1234567890/42"


def test_build_item_maps_fields():
    item = build_item(msg(), source_identifier="danang_expats", chat_username=None, sender=user())
    payload = item.to_payload()

    assert payload["external_id"] == "-1001234567890:42"
    assert payload["url"] == "https://t.me/c/1234567890/42"
    assert payload["author_key"] == author_key("telegram", "777")
    assert payload["author_handle"] == "alice_dn"
    assert payload["posted_at"] == "2026-11-09T03:00:00Z"
    assert "author_id" not in payload


def test_reply_gets_context_and_parent():
    item = build_item(msg(reply_to_msg_id=40, is_reply=True), source_identifier="g", chat_username="g",
                      sender=user(), reply_text="x" * 1500)
    assert item.parent_external_id == "-1001234567890:40"
    assert len(item.context_text) == 1000


@pytest.mark.parametrize("message,sender", [
    (msg(raw_text=""), user()),                                          # no text (photo only)
    (msg(raw_text="   "), user()),
    (msg(), user(bot=True)),                                             # bot
    (msg(fwd_from=SimpleNamespace(channel_post=123)), user()),           # forwarded channel ad
])
def test_skipped_messages(message, sender):
    assert build_item(message, source_identifier="g", chat_username=None, sender=sender) is None


def test_forward_from_a_person_is_kept():
    assert build_item(msg(fwd_from=SimpleNamespace(channel_post=None)), source_identifier="g", chat_username=None, sender=user()) is not None


class FakeMessage(SimpleNamespace):
    async def get_sender(self):
        return self._sender

    async def get_reply_message(self):
        return self._reply


def test_listener_only_collects_configured_chats_and_reads_reply():
    listener = Listener(client=SimpleNamespace(), ingest=SimpleNamespace(), config=TelegramConfig())
    listener.chats = {-1001234567890: ("danang_expats", "danang_expats")}

    reply = SimpleNamespace(raw_text="Moving to Da Nang in December, which area?")
    m = FakeMessage(**vars(msg(is_reply=True, reply_to_msg_id=40)), _sender=user(), _reply=reply)
    other = FakeMessage(**vars(msg(chat_id=-1009999999999)), _sender=user(), _reply=None)

    item = run(listener.to_item(m))
    assert item.source_identifier == "danang_expats"
    assert item.url == "https://t.me/danang_expats/42"
    assert item.context_text == reply.raw_text
    assert run(listener.to_item(other)) is None


def test_flood_wait_sleeps_requested_seconds(monkeypatch):
    from telethon.errors import FloodWaitError

    slept = []

    async def fake_sleep(s):
        slept.append(s)

    monkeypatch.setattr("telegram.listener.asyncio.sleep", fake_sleep)
    calls = {"n": 0}

    async def flaky():
        calls["n"] += 1
        if calls["n"] == 1:
            err = FloodWaitError(request=None, capture=17)
            raise err
        return "ok"

    listener = Listener(client=SimpleNamespace(), ingest=SimpleNamespace(), config=TelegramConfig())
    assert run(listener._call(flaky)) == "ok"
    assert slept == [17]


def test_backfill_stops_at_48_hours():
    old = datetime.now(UTC) - timedelta(hours=49)
    recent = datetime.now(UTC) - timedelta(hours=1)
    messages = [FakeMessage(**vars(msg(id=i, date=d)), _sender=user(), _reply=None) for i, d in [(3, recent), (2, recent), (1, old)]]

    class FakeClient:
        def iter_messages(self, chat_id, limit):
            assert limit == 200

            async def gen():
                for m in messages:
                    yield m
            return gen()

    listener = Listener(client=FakeClient(), ingest=SimpleNamespace(), config=TelegramConfig())
    listener.chats = {-1001234567890: ("g", None)}
    run(listener.backfill([-1001234567890]))
    assert listener.queue.qsize() == 2
