from aiogram.fsm.state import State, StatesGroup


class WatchSetup(StatesGroup):
    choosing_scopes = State()
    entering_target = State()
