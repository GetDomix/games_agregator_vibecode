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
