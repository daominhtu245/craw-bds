"""Interactive first login (SPEC 6.2).

Run:  docker compose run --rm -it collector-telegram python -m telegram.login

You type the phone number and the OTP yourself; nothing here is automated.
The session file is written to TG_SESSION_PATH (a volume, never committed).
"""

from __future__ import annotations

import asyncio

from telethon import TelegramClient

from telegram.config import TelegramConfig


async def main() -> None:
    config = TelegramConfig()
    client = TelegramClient(config.session_path, config.api_id, config.api_hash)
    # start() prompts for phone, code and 2FA password on the terminal.
    await client.start()
    me = await client.get_me()
    print(f"Logged in as id={me.id} username=@{me.username or '-'}. Session saved to {config.session_path}")
    print("Join the groups you want to monitor by hand in the Telegram app, then add them as sources on the dashboard.")
    await client.disconnect()


if __name__ == "__main__":
    asyncio.run(main())
