from __future__ import annotations

from collections.abc import Generator
from pathlib import Path

from sqlalchemy import create_engine, event, text
from sqlalchemy.orm import DeclarativeBase, Session, sessionmaker

from app.config import get_settings


class Base(DeclarativeBase):
    pass


def _make_engine():
    from sqlalchemy.pool import StaticPool

    settings = get_settings()
    url = settings.database_url
    connect_args: dict = {}
    engine_kwargs: dict = {"pool_pre_ping": True}

    if url.startswith("sqlite"):
        connect_args["check_same_thread"] = False
        # In-memory SQLite must share one connection across threads/sessions
        if url in ("sqlite://", "sqlite:///:memory:"):
            engine_kwargs["poolclass"] = StaticPool
        elif ":///" in url:
            raw_path = url.split(":///", 1)[1]
            if raw_path and raw_path != ":memory:" and not raw_path.startswith("file:"):
                Path(raw_path).expanduser().resolve().parent.mkdir(parents=True, exist_ok=True)

    engine = create_engine(url, connect_args=connect_args, **engine_kwargs)

    if url.startswith("sqlite"):

        @event.listens_for(engine, "connect")
        def _sqlite_pragma(dbapi_conn, _):
            cursor = dbapi_conn.cursor()
            try:
                cursor.execute("PRAGMA journal_mode=WAL")
            except Exception:
                pass
            cursor.execute("PRAGMA foreign_keys=ON")
            cursor.close()

    return engine


engine = _make_engine()
SessionLocal = sessionmaker(bind=engine, autocommit=False, autoflush=False)


def get_db() -> Generator[Session, None, None]:
    db = SessionLocal()
    try:
        yield db
    finally:
        db.close()


def init_db() -> None:
    # Import models so metadata is populated
    from app import db_models  # noqa: F401

    Base.metadata.create_all(bind=engine)


def check_db() -> bool:
    try:
        with engine.connect() as conn:
            conn.execute(text("SELECT 1"))
        return True
    except Exception:
        return False
