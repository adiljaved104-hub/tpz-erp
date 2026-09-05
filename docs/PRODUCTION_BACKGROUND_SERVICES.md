# Production Background Services

This runbook describes the approved Laravel database queue and scheduler processes for TPZ ERP. It is a deployment template, not an installed operating-system service.

## Architecture

- Production queue driver: `database` on MySQL/InnoDB.
- Queues: `notifications` first, then `default`.
- Authentication OTP email is synchronous and never depends on a queue worker.
- Operational notification email is queued after the surrounding database transaction commits.
- Laravel's scheduler evaluates Task deadlines every 30 minutes, Warranty SLAs hourly, and Hikvision attendance at the configured interval. Hikvision performs no device request while either enablement flag is false.
- Scheduler and overlap locks use the configured cache. Every scheduler node must use the same production database/cache before multiple nodes are allowed to run cron.

## Primary Linux recommendation: Supervisor

Run at least one worker with the application release user and directory:

```ini
[program:tpz-erp-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/tpz-erp/artisan queue:work database --queue=notifications,default --sleep=3 --tries=3 --backoff=30 --timeout=60 --max-time=3600
directory=/var/www/tpz-erp
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=90
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/tpz-erp/storage/logs/queue-worker.log
```

The database queue `retry_after` must remain greater than the worker timeout. The approved defaults are 90 seconds and 60 seconds respectively. Operational mail notifications define their own bounded retry delays of 30, 120, and 300 seconds.

The historical `emails` queue is no longer used. Before production cutover, inspect its count and age without displaying payloads. Do not blindly process or expose legacy payloads because older releases may have placed authentication OTP notifications there. Normal production workers intentionally consume only `notifications,default`.

After changing Supervisor configuration:

```sh
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status tpz-erp-queue:*
```

Systemd is also viable, but one process manager should be chosen and documented for the actual server; do not operate duplicate Supervisor and systemd workers.

## Scheduler

Install one system cron entry for the application user:

```cron
* * * * * cd /var/www/tpz-erp && php artisan schedule:run >> /dev/null 2>&1
```

`schedule:run` records a cache-backed heartbeat every minute. Evaluators also record their last successful completion. `php artisan erp:production-check` reads those timestamps without changing them.

The schedules use bounded `withoutOverlapping()` locks. `onOneServer()` is intentionally not enabled until the production topology and shared cache are confirmed. With more than one scheduler node, all nodes must share the same reliable cache/lock store; otherwise run cron on one designated node only.

## Operations and failure handling

Inspect the queue without exposing payloads in routine reports:

```sh
php artisan queue:failed
php artisan queue:retry <id-or-uuid>
php artisan queue:forget <id-or-uuid>
```

`php artisan queue:flush` permanently removes every failed-job record. Use it only after explicit review and approval; never automate it.

Useful checks:

```sh
php artisan schedule:list
php artisan erp:production-check
sudo supervisorctl status tpz-erp-queue:*
tail -f storage/logs/queue-worker.log
tail -f storage/logs/laravel.log
```

The notification delivery ledger prevents an evaluator from enqueueing the same logical email repeatedly. Queue delivery is retry-safe at the application level, although SMTP itself is an at-least-once transport and an ambiguous network disconnect can never provide absolute exactly-once delivery.

## Safe deployment sequence

1. Confirm a current backup and a tested rollback plan.
2. Enable maintenance mode when the release requires it: `php artisan down`.
3. Deploy code and install production dependencies.
4. Run approved migrations: `php artisan migrate --force`.
5. Build/refresh approved configuration, route, and view caches.
6. Signal workers to finish their current job and reload code: `php artisan queue:restart`.
7. Disable maintenance mode: `php artisan up`.
8. Verify Supervisor, `schedule:list`, failed jobs, queue backlog, and `erp:production-check`.

Do not terminate a worker in the middle of an inventory or notification transaction. `queue:restart` is the preferred graceful mechanism.

## Local development

The ERP boots and authentication works without permanent background processes. Start these only when testing operational email or recurring evaluations:

```sh
php artisan queue:work --queue=notifications,default --sleep=1 --tries=3 --timeout=60
php artisan schedule:work
```

Login OTP, two-factor OTP, password-reset OTP, and login-email-change OTP remain synchronous. A queue worker outage must not prevent authentication.

Hikvision must remain disabled in local configuration unless an explicitly approved, isolated device test is being performed.
