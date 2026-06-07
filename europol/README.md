# Europol Watchlist

Monitors Europol's Most Wanted feed for new additions and sends notifications to a Discord channel.

![Europol Watchlist](/assets/europole.png)

## Requirements

- PHP `>= 7.4` (recommended: PHP 8.1+)
- PHP `curl` extension enabled
- `make` (optional, recommended)

## Configuration

Recommended: set environment variable `EUROPOL_WEBHOOK_URL`.

```bash
export EUROPOL_WEBHOOK_URL="https://discord.com/api/webhooks/..."
```

Fallback: copy `../src/config/config.example.php` to `../src/config/config.php` and replace `YOUR_EUROPOL_WEBHOOK_URL`.

## Run

From repo root (recommended):

```bash
make run-europol
```

Without `make`:

```bash
php europol/europol.php
```

## Cron Example

If using env vars, define them in crontab/service (interactive shell exports are not inherited by cron).

```cron
EUROPOL_WEBHOOK_URL=https://discord.com/api/webhooks/...
15 * * * * cd /path/to/discord-feed-watchers && make run-europol >> europol/logs/cron.log 2>&1
```

## Expected First Run

- First run may send multiple existing Europol entries.
- Next runs send only new entries.
- De-duplication state is stored in `europol/processed_europol_wanted.txt`.

## Troubleshooting

- `Missing webhook URL for europol_webhook_url`:
  - Set `EUROPOL_WEBHOOK_URL` or configure `../src/config/config.php`.
- `The cURL extension is not installed or enabled.`:
  - Install/enable `php-curl`.
- Write/append errors on state files:
  - Fix filesystem permissions for the runtime user.
