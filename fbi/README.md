# FBI Watchlist

Monitors the FBI's Most Wanted feed for new additions and sends notifications to a Discord channel.

![FBI Watchlist](/assets/fbi.png)

## Requirements

- PHP `>= 7.4` (recommended: PHP 8.1+)
- PHP `curl` extension enabled
- `make` (optional, recommended)

## Configuration

Recommended: set environment variable `FBI_WEBHOOK_URL`.

```bash
export FBI_WEBHOOK_URL="https://discord.com/api/webhooks/..."
```

Fallback: copy `../src/config/config.example.php` to `../src/config/config.php` and replace `YOUR_FBI_WEBHOOK_URL`.

## Run

From repo root (recommended):

```bash
make run-fbi
```

Without `make`:

```bash
php fbi/fbi.php
```

## Cron Example

If using env vars, define them in crontab/service (interactive shell exports are not inherited by cron).

```cron
FBI_WEBHOOK_URL=https://discord.com/api/webhooks/...
30 * * * * cd /path/to/discord-feed-watchers && make run-fbi >> fbi/logs/cron.log 2>&1
```

## Expected First Run

- First run may send multiple existing FBI entries.
- Next runs send only new entries.
- De-duplication state is stored in `fbi/uids`.

## Troubleshooting

- `Missing webhook URL for fbi_webhook_url`:
  - Set `FBI_WEBHOOK_URL` or configure `../src/config/config.php`.
- `The cURL extension is not installed or enabled.`:
  - Install/enable `php-curl`.
- Write/append errors on state files:
  - Fix filesystem permissions for the runtime user.
