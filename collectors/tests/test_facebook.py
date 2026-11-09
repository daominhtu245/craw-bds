from datetime import UTC, datetime
from types import SimpleNamespace

import httpx

from common.ingest_client import IngestClient
from common.schema import author_key
from facebook.run import actor_input, map_post, run_once, since_for
from tests.conftest import run

NOW = datetime(2026, 11, 9, 12, 0, tzinfo=UTC)
GROUP = "https://www.facebook.com/groups/sample-danang-expats"

# Made-up dataset item with the field names from the actor README.
POST = {
    "facebookUrl": GROUP,
    "url": "https://www.facebook.com/groups/sample-danang-expats/permalink/1111111111/",
    "time": "2026-11-09T08:30:00.000Z",
    "user": {"id": "pfbid0SAMPLE", "name": "Sample Member"},
    "text": "Looking for a 2 bedroom apartment in Son Tra from December, 6 months, pet friendly.",
    "id": "UzpfSTEwMDA6MTExMTE=",
    "legacyId": "1111111111",
    "groupTitle": "Sample Da Nang Expats",
    "inputUrl": GROUP,
    "topComments": [],
}


def test_since_is_max_of_last_item_and_48h():
    assert since_for({"last_item_at": None}, NOW) == datetime(2026, 11, 7, 12, 0, tzinfo=UTC)
    assert since_for({"last_item_at": "2026-11-09T10:00:00.000000Z"}, NOW) == datetime(2026, 11, 9, 10, 0, tzinfo=UTC)
    assert since_for({"last_item_at": "2026-10-01T00:00:00Z"}, NOW) == datetime(2026, 11, 7, 12, 0, tzinfo=UTC)


def test_actor_input_matches_schema():
    payload = actor_input({"identifier": GROUP, "meta": {"maxItems": 30}}, datetime(2026, 11, 7, 12, 0, tzinfo=UTC))
    assert payload == {
        "startUrls": [{"url": GROUP}],
        "resultsLimit": 30,
        "viewOption": "CHRONOLOGICAL",
        "onlyPostsNewerThan": "2026-11-07T12:00:00",
    }
    assert actor_input({"identifier": GROUP}, NOW)["resultsLimit"] == 50


def test_map_post():
    payload = map_post(POST, GROUP).to_payload()
    assert payload["external_id"] == "1111111111"
    assert payload["url"] == POST["url"]
    assert payload["posted_at"] == "2026-11-09T08:30:00Z"
    assert payload["author_key"] == author_key("facebook", "pfbid0SAMPLE")
    assert payload["author_handle"] == "Sample Member"
    assert "pfbid0SAMPLE" not in str(payload)


def test_map_post_skips_incomplete():
    assert map_post({**POST, "text": ""}, GROUP) is None
    assert map_post({**POST, "legacyId": None, "id": None}, GROUP) is None
    assert map_post({**POST, "time": "not a date"}, GROUP) is None


class FakeApify:
    def __init__(self, items):
        self.items = items
        self.inputs = []

    def actor(self, actor_id):
        assert actor_id == "apify/facebook-groups-scraper"
        outer = self

        class Actor:
            def call(self, run_input, run_timeout):
                outer.inputs.append(run_input)
                return SimpleNamespace(default_dataset_id="ds1", usage_total_usd=0.12, status="SUCCEEDED")

        return Actor()

    def dataset(self, dataset_id):
        assert dataset_id == "ds1"
        return SimpleNamespace(iterate_items=lambda: iter(self.items))


def test_run_once_sends_items_and_heartbeat(monkeypatch):
    monkeypatch.setenv("APIFY_FB_ACTOR", "apify/facebook-groups-scraper")
    calls = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(request)
        if request.url.path.endswith("/collector/sources"):
            return httpx.Response(200, json={"data": [{"identifier": GROUP, "last_item_at": None, "meta": None}]})
        if request.url.path.endswith("/ingest/items"):
            return httpx.Response(200, json={"accepted": 1, "duplicates": 0, "rejected": []})
        return httpx.Response(200, json={"status": "ok"})

    async def no_sleep(_):
        return None

    apify = FakeApify([POST, {**POST, "text": ""}])

    async def go():
        async with IngestClient(transport=httpx.MockTransport(handler), sleep=no_sleep) as ingest:
            return await run_once(apify=apify, ingest=ingest, now=NOW)

    totals = run(go())
    assert totals["accepted"] == 1
    assert apify.inputs[0]["onlyPostsNewerThan"] == "2026-11-07T12:00:00"
    paths = [c.url.path for c in calls]
    assert paths == ["/api/collector/sources", "/api/ingest/items", "/api/collector/heartbeat"]
