# Ransomware Watchlist

Monitors a ransomware victim feed for new entries and sends notifications to a Discord channel.

![Ransomware Watchlist](/assets/Ransome.png)

## Requirements

- PHP `>= 7.4` (recommended: PHP 8.1+)
- PHP `curl` extension enabled
- `make` (optional, recommended)

## Configuration

Recommended: set environment variable `RANSOMWARE_WEBHOOK_URL`.

```bash
export RANSOMWARE_WEBHOOK_URL="https://discord.com/api/webhooks/..."
```

Fallback: copy `../src/config/config.example.php` to `../src/config/config.php` and replace `YOUR_RANSOMWARE_WEBHOOK_URL`.

## Run

From repo root (recommended):

```bash
make run-ransomware
```

Without `make`:

```bash
php ransomware/ransomware.php
```

## Cron Example

If using env vars, define them in crontab/service (interactive shell exports are not inherited by cron).

```cron
RANSOMWARE_WEBHOOK_URL=https://discord.com/api/webhooks/...
45 * * * * cd /path/to/discord-feed-watchers && make run-ransomware >> ransomware/logs/cron.log 2>&1
```

## Expected First Run

- First run may send multiple existing ransomware victim entries.
- Next runs send only new entries.
- De-duplication state is stored in `ransomware/processed_ransomware_victims.txt`.
- A local feed cache may be written to `ransomware/recentvictims.json`.

## Troubleshooting

- `Missing webhook URL for ransomware_webhook_url`:
  - Set `RANSOMWARE_WEBHOOK_URL` or configure `../src/config/config.php`.
- `The cURL extension is not installed or enabled.`:
  - Install/enable `php-curl`.
- `Unable to fetch ransomware feed and no cache is available.`:
  - Check network access and retry; once cache exists, temporary API outages are tolerated.
