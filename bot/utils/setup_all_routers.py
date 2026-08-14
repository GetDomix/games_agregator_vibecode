import importlib.util
import sys
from pathlib import Path

from aiogram import Dispatcher, Router


def setup_all_routers(dispatcher: Dispatcher) -> None:
    handlers_dir = Path(__file__).parent.parent / "handlers"
    for file_path in handlers_dir.rglob("*.py"):
        if file_path.name in {"__init__.py", "states.py"}:
            continue
        module_name = ".".join(file_path.relative_to(handlers_dir.parent).with_suffix("").parts)
        spec = importlib.util.spec_from_file_location(module_name, file_path)
        if spec is None or spec.loader is None:
            continue
        module = importlib.util.module_from_spec(spec)
        sys.modules[module_name] = module
        spec.loader.exec_module(module)
        if hasattr(module, "router") and isinstance(module.router, Router):
            dispatcher.include_router(module.router)
