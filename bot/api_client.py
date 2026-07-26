from __future__ import annotations

from typing import Any

import httpx


class LaravelApiError(RuntimeError):
    pass


class LaravelClient:
    def __init__(self, base_url: str, service_token: str, transport: httpx.AsyncBaseTransport | None = None) -> None:
        self.base_url = base_url.rstrip("/")
        self.service_token = service_token
        self.transport = transport

    def _headers(self) -> dict[str, str]:
        return {"Accept": "application/json", "Content-Type": "application/json", "X-Radar-Token": self.service_token}

    async def _request(self, method: str, path: str, *, params: dict[str, Any] | None = None, json: dict[str, Any] | None = None) -> dict:
        try:
            async with httpx.AsyncClient(timeout=20.0, transport=self.transport) as client:
                response = await client.request(method, f"{self.base_url}{path}", headers=self._headers(), params=params, json=json)
        except httpx.HTTPError as exc:
            raise LaravelApiError("Сервер Игроскана временно недоступен. Попробуй ещё раз.") from exc
        try:
            data = response.json() if response.content else {}
        except ValueError as exc:
            raise LaravelApiError("Сервер Игроскана вернул некорректный ответ. Попробуй ещё раз.") from exc
        if not isinstance(data, dict):
            raise LaravelApiError("Сервер Игроскана вернул некорректный ответ. Попробуй ещё раз.")
        if response.status_code >= 400:
            raise LaravelApiError(str(data.get("detail") or data.get("message") or "Не удалось выполнить запрос."))
        return data

    async def bind_telegram(self, code: str, telegram_user_id: int | str, chat_id: int | str, username: str | None) -> dict:
        return await self._request("POST", "/api/internal/telegram/bind", json={"code": code, "telegram_user_id": str(telegram_user_id), "chat_id": str(chat_id), "telegram_username": username})

    async def session(self, telegram_user_id: int, chat_id: int, username: str | None, display_name: str | None) -> dict:
        return await self._request("POST", "/api/internal/telegram/session", json={"telegram_user_id": str(telegram_user_id), "chat_id": str(chat_id), "username": username, "display_name": display_name})

    async def search(self, telegram_user_id: int, query: str) -> dict:
        return await self._request("GET", "/api/internal/telegram/search", params={"telegram_user_id": str(telegram_user_id), "q": query})

    async def card(self, telegram_user_id: int, appid: int, query: str | None = None) -> dict:
        return await self._request("GET", f"/api/internal/telegram/games/{appid}", params={"telegram_user_id": str(telegram_user_id), "q": query or ""})

    async def favorites(self, telegram_user_id: int) -> dict:
        return await self._request("GET", "/api/internal/telegram/favorites", params={"telegram_user_id": str(telegram_user_id)})

    async def save_favorite(self, telegram_user_id: int, game: dict, target: float | None, scopes: list[dict]) -> dict:
        return await self._request("PUT", "/api/internal/telegram/favorites", json={
            "telegram_user_id": str(telegram_user_id), "appid": game["appid"], "game_name": game["name"], "header_image": game.get("header_image"), "target_price_rub": target,
            "alert": {"condition_type": "target_price", "target_value": target, "scopes": scopes},
        })

    async def remove_favorite(self, telegram_user_id: int, appid: int) -> dict:
        return await self._request("DELETE", f"/api/internal/telegram/favorites/{appid}", params={"telegram_user_id": str(telegram_user_id)})

    async def alerts(self, telegram_user_id: int, status: str) -> dict:
        return await self._request("GET", "/api/internal/telegram/alerts", params={"telegram_user_id": str(telegram_user_id), "status": status})

    async def rearm(self, telegram_user_id: int, appid: int) -> dict:
        return await self._request("POST", f"/api/internal/telegram/favorites/{appid}/alert/rearm", json={"telegram_user_id": str(telegram_user_id)})

    async def image(self, url: str | None) -> bytes | None:
        if not url or not url.startswith(("https://", "http://")):
            return None
        try:
            async with httpx.AsyncClient(timeout=10.0, follow_redirects=True, transport=self.transport) as client:
                response = await client.get(url)
                response.raise_for_status()
                return response.content if len(response.content) <= 5_000_000 else None
        except httpx.HTTPError:
            return None
