"""Pull group posts out of the JSON a logged-in Facebook page loads.

Facebook's web app (Comet) ships feed data in two places:

* ``<script type="application/json">`` blocks in the first HTML response;
* ``/api/graphql/`` responses fetched while the feed scrolls. One response can
  hold several JSON documents separated by newlines, sometimes prefixed with
  ``for (;;);``.

Field paths inside those documents change often, so instead of hard-coding one
path we walk every document and pick up objects with ``__typename == "Story"``
and a ``post_id``. For each story we look for, in order of preference:

* text:          ``comet_sections.content.story.message.text`` → ``message.text``
* creation time: the first integer ``creation_time`` inside the story
* author:        the first entry of ``actors`` (``id`` is hashed, never sent)
* url:           a ``url``/``wwwURL`` containing ``/posts/`` or ``/permalink/``

Nested shared stories (``attached_story``) and comments are skipped so their
text is not mixed into the parent post.

[VERIFY] These paths are written from public knowledge of Comet payloads and
tested against self-written fixtures only. Check them against a real logged-in
session (the round log prints documents / stories / items counts per group)
before relying on the output.
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any, Iterator

from selectolax.lexbor import LexborHTMLParser as HTMLParser

from common.schema import IngestItem

SKIP_KEYS = {"attached_story", "comments", "comment_list_renderer", "feedback", "top_level_comments"}
POST_URL = re.compile(r"/(posts|permalink)/")


@dataclass
class Story:
    post_id: str
    text: str | None = None
    creation_time: int | None = None
    author_id: str | None = None
    author_name: str | None = None
    url: str | None = None

    def merge(self, other: "Story") -> None:
        self.text = self.text or other.text
        self.creation_time = self.creation_time or other.creation_time
        self.author_id = self.author_id or other.author_id
        self.author_name = self.author_name or other.author_name
        self.url = self.url or other.url


# ------------------------------------------------------------------ documents


def parse_documents(body: str) -> list[Any]:
    """Split a GraphQL response body into JSON documents; bad lines are ignored."""
    body = body.strip()
    if body.startswith("for (;;);"):
        body = body[len("for (;;);"):]
    try:
        return [json.loads(body)]
    except ValueError:
        pass
    docs = []
    for line in body.splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            docs.append(json.loads(line))
        except ValueError:
            continue
    return docs


def html_documents(html: str) -> list[Any]:
    """JSON blobs embedded in the page as <script type="application/json">."""
    docs = []
    for node in HTMLParser(html).css('script[type="application/json"]'):
        text = node.text(deep=True)
        if "post_id" not in text:
            continue
        try:
            docs.append(json.loads(text))
        except ValueError:
            continue
    return docs


# --------------------------------------------------------------------- walker


def _walk(obj: Any, skip: set[str] = frozenset()) -> Iterator[dict[str, Any]]:
    stack = [obj]
    while stack:
        cur = stack.pop()
        if isinstance(cur, dict):
            yield cur
            stack.extend(v for k, v in cur.items() if k not in skip)
        elif isinstance(cur, list):
            stack.extend(cur)


def _is_story(node: dict[str, Any]) -> bool:
    return node.get("__typename") == "Story" and node.get("post_id") not in (None, "")


def _dig(node: Any, *path: str) -> Any:
    for key in path:
        if not isinstance(node, dict):
            return None
        node = node.get(key)
    return node


def _text(story: dict[str, Any]) -> str | None:
    for path in (("comet_sections", "content", "story", "message", "text"), ("message", "text")):
        value = _dig(story, *path)
        if isinstance(value, str) and value.strip():
            return value.strip()
    for node in _walk(story, SKIP_KEYS):
        message = node.get("message")
        if isinstance(message, dict) and isinstance(message.get("text"), str) and message["text"].strip():
            return message["text"].strip()
    return None


def _creation_time(story: dict[str, Any]) -> int | None:
    for node in _walk(story, SKIP_KEYS):
        value = node.get("creation_time")
        if isinstance(value, int) and value > 0:
            return value
    return None


def _author(story: dict[str, Any]) -> tuple[str | None, str | None]:
    for node in _walk(story, SKIP_KEYS):
        actors = node.get("actors")
        if isinstance(actors, list) and actors and isinstance(actors[0], dict):
            actor = actors[0]
            actor_id = actor.get("id")
            return (str(actor_id) if actor_id else None, actor.get("name"))
    return (None, None)


def _url(story: dict[str, Any]) -> str | None:
    for node in _walk(story, SKIP_KEYS):
        for key in ("url", "wwwURL", "permalink_url"):
            value = node.get(key)
            if isinstance(value, str) and value.startswith("https://") and POST_URL.search(value):
                return value
    return None


def find_stories(documents: list[Any]) -> dict[str, Story]:
    """All stories in the documents, keyed by post_id; duplicates are merged."""
    found: dict[str, Story] = {}
    for doc in documents:
        for node in _walk(doc, {"attached_story"}):
            if not _is_story(node):
                continue
            author_id, author_name = _author(node)
            story = Story(
                post_id=str(node["post_id"]),
                text=_text(node),
                creation_time=_creation_time(node),
                author_id=author_id,
                author_name=author_name,
                url=_url(node),
            )
            if story.post_id in found:
                found[story.post_id].merge(story)
            else:
                found[story.post_id] = story
    return found


# --------------------------------------------------------------------- mapper


def post_url(group_url: str, post_id: str) -> str:
    return f"{group_url.split('?')[0].rstrip('/')}/posts/{post_id}/"


def to_item(story: Story, group_url: str, since: datetime | None = None) -> IngestItem | None:
    """IngestItem for one story, or None if it lacks text/time or is older than `since`."""
    if not story.text or not story.creation_time:
        return None
    posted_at = datetime.fromtimestamp(story.creation_time, tz=UTC)
    if since is not None and posted_at < since:
        return None
    return IngestItem(
        source_type="facebook",
        source_identifier=group_url,
        external_id=story.post_id,
        url=story.url or post_url(group_url, story.post_id),
        author_id=story.author_id,
        author_handle=story.author_name,
        text=story.text,
        posted_at=posted_at,
    )


def items_from_documents(documents: list[Any], group_url: str, since: datetime | None = None) -> list[IngestItem]:
    items = []
    for story in find_stories(documents).values():
        item = to_item(story, group_url, since)
        if item is not None:
            items.append(item)
    items.sort(key=lambda i: i.posted_at)
    return items
