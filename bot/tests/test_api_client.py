import json
import unittest

import httpx

from api_client import LaravelApiError, LaravelClient


class LaravelClientTest(unittest.IsolatedAsyncioTestCase):
    async def test_search_sends_service_and_telegram_identity(self):
        async def handler(request: httpx.Request):
            self.assertEqual(request.headers["X-Radar-Token"], "secret")
            self.assertEqual(request.url.params["telegram_user_id"], "12")
            self.assertEqual(request.url.params["q"], "Hades")
            return httpx.Response(200, json={"candidates": []})

        client = LaravelClient("https://api.test", "secret", httpx.MockTransport(handler))
        self.assertEqual(await client.search(12, "Hades"), {"candidates": []})

    async def test_api_error_uses_backend_detail(self):
        client = LaravelClient("https://api.test", "secret", httpx.MockTransport(lambda _: httpx.Response(422, json={"detail": "Некорректная цель"})))
        with self.assertRaisesRegex(LaravelApiError, "Некорректная цель"):
            await client.alerts(12, "wrong")

    async def test_bind_sends_private_user_identity(self):
        async def handler(request: httpx.Request):
            payload = json.loads(request.content)
            self.assertEqual(payload["telegram_user_id"], "12")
            self.assertEqual(payload["chat_id"], "12")
            return httpx.Response(200, json={"ok": True})

        client = LaravelClient("https://api.test", "secret", httpx.MockTransport(handler))
        self.assertEqual(await client.bind_telegram("SAFE", 12, 12, "player"), {"ok": True})
