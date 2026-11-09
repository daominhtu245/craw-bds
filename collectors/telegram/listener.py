"""Realtime Telegram listener (SPEC 6.2). READ-ONLY.

- Sources come from GET /api/collector/sources?type=telegram, refreshed every 10 minutes.
- Only messages from those chats are processed (chat ids are resolved once per refresh).
- On start and for every newly added source: backfill up to 200 messages from the last 48 hours.
- FloodWaitError: sleep exactly the requested time.
- Heartbeat every 5 minutes.

Run:  python -m telegram.listener
"""

from __future__ import annotations

import asyncio
import logging
from datetime import UTC, datetime, timedelta
from typing import Any

from telethon import TelegramClient, events, utils
from telethon.errors import FloodWaitError

from common import logging as jsonlog
from common.ingest_client import IngestClient
from common.logging import excerpt
from common.schema import IngestItem
from telegram.config import TelegramConfig
from telegram.mapper import build_item, internal_chat_id

log = logging.getLogger("telegram")


class Listener:
    def __init__(self, client: TelegramClient, ingest: IngestClient, config: TelegramConfig) -> None:
        self.client = client
        self.ingest = ingest
        self.config = config
        # marked chat id -> (source identifier, public username or None)
        self.chats: dict[int, tuple[str, str | None]] = {}
        self.queue: asyncio.Queue[IngestItem] = asyncio.Queue()

    # ------------------------------------------------------------- sources

    async def refresh_sources(self) -> list[int]:
        """Resolve active sources to chat ids. Returns ids that are new since the last refresh."""
        sources = await self.ingest.sources("telegram")
        resolved: dict[int, tuple[str, str | None]] = {}

        for source in sources:
            identifier = str(source["identifier"])
            try:
                entity = await self._call(self.client.get_entity, int(identifier) if identifier.lstrip("-").isdigit() else identifier)
            except (ValueError, TypeError) as e:
                log.warning("cannot resolve source; is the account a member?", extra={"source": identifier, "error": str(e)})
                continue
            chat_id = utils.get_peer_id(entity)
            if internal_chat_id(chat_id) is None and not getattr(entity, "username", None):
                log.warning("basic group without message links; upgrade it to a supergroup", extra={"source": identifier})
            resolved[chat_id] = (identifier, getattr(entity, "username", None))

        new_ids = [cid for cid in resolved if cid not in self.chats]
        self.chats = resolved
        log.info("sources refreshed", extra={"count": len(resolved)})
        return new_ids

    # ------------------------------------------------------------ messages

    async def on_message(self, event: events.NewMessage.Event) -> None:
        item = await self.to_item(event.message)
        if item is not None:
            await self.queue.put(item)

    async def to_item(self, message: Any) -> IngestItem | None:
        source = self.chats.get(int(message.chat_id))
        if source is None:
            return None
        identifier, username = source

        sender = await self._call(message.get_sender)
        reply_text = None
        if getattr(message, "is_reply", False):
            reply = await self._call(message.get_reply_message)
            reply_text = getattr(reply, "raw_text", None) if reply is not None else None

        item = build_item(
            message,
            source_identifier=identifier,
            chat_username=username,
            sender=sender,
            reply_text=reply_text,
            context_max_chars=self.config.context_max_chars,
        )
        if item is not None:
            log.info("message collected", extra={"source": identifier, "item_id": item.external_id, "excerpt": excerpt(item.text)})
        return item

    async def backfill(self, chat_ids: list[int]) -> None:
        since = datetime.now(UTC) - timedelta(hours=self.config.backfill_hours)
        for chat_id in chat_ids:
            count = 0
            async for message in self.client.iter_messages(chat_id, limit=self.config.backfill_limit):
                if message.date is not None and message.date < since:
                    break
                item = await self.to_item(message)
                if item is not None:
                    await self.queue.put(item)
                    count += 1
            log.info("backfill done", extra={"source": self.chats[chat_id][0], "count": count})

    # ---------------------------------------------------------------- loops

    async def flush_loop(self) -> None:
        while True:
            await asyncio.sleep(self.config.flush_seconds)
            batch: list[IngestItem] = []
            while not self.queue.empty() and len(batch) < 50:
                batch.append(self.queue.get_nowait())
            if batch:
                await self.ingest.send(batch)
            else:
                await self.ingest.flush_spool()

    async def refresh_loop(self) -> None:
        while True:
            await asyncio.sleep(self.config.sources_refresh_seconds)
            try:
                new_ids = await self.refresh_sources()
                if new_ids:
                    await self.backfill(new_ids)
            except Exception:
                log.exception("source refresh failed")

    async def heartbeat_loop(self) -> None:
        while True:
            ok = self.client.is_connected()
            await self.ingest.heartbeat("telegram", ok=ok, meta={"chats": len(self.chats)})
            await asyncio.sleep(self.config.heartbeat_seconds)

    async def _call(self, fn: Any, *args: Any) -> Any:
        """Call a Telethon coroutine, sleeping exactly as long as Telegram asks on FloodWaitError."""
        while True:
            try:
                return await fn(*args)
            except FloodWaitError as e:
                log.warning("flood wait", extra={"seconds": e.seconds})
                await asyncio.sleep(e.seconds)


async def main() -> None:
    jsonlog.setup()
    config = TelegramConfig()
    client = TelegramClient(config.session_path, config.api_id, config.api_hash)
    # Let FloodWaitError reach our handler instead of sleeping silently inside Telethon.
    client.flood_sleep_threshold = 0

    await client.connect()
    if not await client.is_user_authorized():
        raise SystemExit("Telegram session is not authorised. Run: python -m telegram.login")
    # Read-only: loads the chats the account already joined so numeric ids resolve.
    await client.get_dialogs()

    async with IngestClient() as ingest:
        listener = Listener(client, ingest, config)
        initial = await listener.refresh_sources()

        # No `chats=` filter here: the allowed set changes every 10 minutes, so
        # the handler checks `listener.chats` itself (same effect as SPEC 6.2).
        client.add_event_handler(listener.on_message, events.NewMessage())

        tasks = [
            asyncio.create_task(listener.flush_loop()),
            asyncio.create_task(listener.refresh_loop()),
            asyncio.create_task(listener.heartbeat_loop()),
        ]
        await listener.backfill(initial)
        log.info("listening", extra={"count": len(listener.chats)})
        try:
            await client.run_until_disconnected()
        finally:
            for task in tasks:
                task.cancel()


if __name__ == "__main__":
    asyncio.run(main())
