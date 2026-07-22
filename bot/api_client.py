from __future__ import annotations

import httpx


class LaravelClient:
    def __init__(self, base_url: str, service_token: str) -> None:
        self.base_url = base_url.rstrip("/")
        self.service_token = service_token

    def _headers(self) -> dict[str, str]:
        return {
            "Accept": "application/json",
            "Content-Type": "application/json",
            "X-Radar-Token": self.service_token,
        }

    async def bind_telegram(self, code: str, chat_id: int | str, username: str | None) -> dict:
        async with httpx.AsyncClient(timeout=30.0) as client:
            r = await client.post(
                f"{self.base_url}/api/internal/telegram/bind",
                headers=self._headers(),
                json={
                    "code": code,
                    "chat_id": str(chat_id),
                    "telegram_username": username,
                },
            )
            data = r.json() if r.content else {}
            if r.status_code >= 400:
                detail = data.get("detail") or data.get("message") or r.text
                raise RuntimeError(str(detail))
            return data

    async def run_radar_scan(self) -> dict:
        async with httpx.AsyncClient(timeout=600.0) as client:
            r = await client.post(
                f"{self.base_url}/api/internal/radar/run",
                headers=self._headers(),
            )
            data = r.json() if r.content else {}
            if r.status_code >= 400:
                detail = data.get("detail") or data.get("message") or r.text
                raise RuntimeError(str(detail))
            return data
