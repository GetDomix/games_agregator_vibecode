# Walkthrough: Production scheduler и заполнение цен

`backend` remains the HTTP process. `scheduler` owns Laravel `schedule:work`; `queue-worker` executes database jobs from `prices,default`. When a Steam refresh changes a game from announced to released, Plati and GGsel source states are now created as pending, so the next scheduler tick queues their existing adapters.

Verified with PostgreSQL feature tests, Compose config validation and a frontend production build. Production deployment and live smoke-check are intentionally pending user approval.
