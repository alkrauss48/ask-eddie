---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Nothing works the queue in production: dispatch with afterResponse()
Production sets QUEUE_CONNECTION=database but no pod runs `queue:work`, so a normally dispatched job sits in `jobs` forever. Background work (e.g. RefreshHouse, from POST /api/house/refresh) is dispatched with `->afterResponse()`. It runs in the same FrankenPHP thread after the response is flushed. Such a job must call set_time_limit(0), because php.ini's max_execution_time=120 still applies after the response, and it holds 1 of 4 threads, so make it ShouldBeUnique. If a queue worker is ever added, switch the job back to a plain dispatch.
