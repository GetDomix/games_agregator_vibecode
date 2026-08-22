"""Soft-premium daily search quotas (guest by IP, free users by user id)."""

from __future__ import annotations

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.config import Settings
from app.db_models import DailySearchQuota, User, utcnow
from app.schemas import SearchQuotaInfo


def _today_utc() -> str:
    return utcnow().strftime("%Y-%m-%d")


def quota_key_for(user: User | None, client_ip: str) -> tuple[str, bool, int]:
    """Return (key, is_guest, limit)."""
    if user is not None:
        return f"user:{user.id}", False, 0  # limit filled by caller via settings
    return f"ip:{client_ip or 'unknown'}", True, 0


def get_quota_info(
    db: Session,
    *,
    user: User | None,
    client_ip: str,
    settings: Settings,
) -> SearchQuotaInfo:
    is_guest = user is None
    limit = settings.guest_searches_per_day if is_guest else settings.free_searches_per_day
    key = f"ip:{client_ip or 'unknown'}" if is_guest else f"user:{user.id}"
    day = _today_utc()
    row = db.scalar(
        select(DailySearchQuota).where(DailySearchQuota.quota_key == key, DailySearchQuota.day == day)
    )
    used = int(row.count) if row else 0
    remaining = max(0, limit - used)
    return SearchQuotaInfo(
        limit=limit,
        used=used,
        remaining=remaining,
        is_guest=is_guest,
        reset_hint="обновится завтра (UTC)",
    )


def check_and_consume_search(
    db: Session,
    *,
    user: User | None,
    client_ip: str,
    settings: Settings,
) -> SearchQuotaInfo:
    """Increment daily counter if under limit. Raises ValueError with RU message if exhausted."""
    info = get_quota_info(db, user=user, client_ip=client_ip, settings=settings)
    if info.remaining <= 0:
        if info.is_guest:
            raise ValueError(
                f"Лимит гостя: {info.limit} поисков/день. "
                "Зарегистрируйся — до 15/день и избранное с алертами."
            )
        raise ValueError(
            f"Дневной лимит: {info.limit} поисков. "
            "Premium скоро снимет ограничение — пока зайди завтра или следи за избранным."
        )

    is_guest = user is None
    key = f"ip:{client_ip or 'unknown'}" if is_guest else f"user:{user.id}"
    day = _today_utc()
    row = db.scalar(
        select(DailySearchQuota).where(DailySearchQuota.quota_key == key, DailySearchQuota.day == day)
    )
    if row is None:
        row = DailySearchQuota(quota_key=key, day=day, count=1)
        db.add(row)
    else:
        row.count = int(row.count) + 1
        row.updated_at = utcnow()
    db.commit()

    used = int(row.count)
    return SearchQuotaInfo(
        limit=info.limit,
        used=used,
        remaining=max(0, info.limit - used),
        is_guest=is_guest,
        reset_hint=info.reset_hint,
    )
