# CVE Watchlist

Monitors a CVE feed for new vulnerabilities and sends notifications to a Discord channel.

![CVE Watchlist](/assets/cve2.png)

## Requirements

- PHP `>= 7.4` (recommended: PHP 8.1+)
- PHP `curl` extension enabled
- `make` (optional, recommended)

## Configuration

Recommended: set environment variable `CVE_WEBHOOK_URL`.

```bash
export CVE_WEBHOOK_URL="https://discord.com/api/webhooks/..."
```

Fallback: copy `../src/config/config.example.php` to `../src/config/config.php` and replace `YOUR_CVE_WEBHOOK_URL`.

## Run

From repo root (recommended):

```bash
make run-cve
```

Without `make`:

```bash
php cve/cve.php
```

## Cron Example

If using env vars, define them in crontab/service (interactive shell exports are not inherited by cron).

```cron
CVE_WEBHOOK_URL=https://discord.com/api/webhooks/...
0 * * * * cd /path/to/discord-feed-watchers && make run-cve >> cve/logs/cron.log 2>&1
```

## Expected First Run

- First run may send multiple existing CVEs.
- Next runs send only new entries.
- De-duplication state is stored in `cve/processed_cves.txt`.

## Troubleshooting

- `Missing webhook URL for cve_webhook_url`:
  - Set `CVE_WEBHOOK_URL` or configure `../src/config/config.php`.
- `The cURL extension is not installed or enabled.`:
  - Install/enable `php-curl`.
- Write/append errors on state files:
  - Fix filesystem permissions for the runtime user.
