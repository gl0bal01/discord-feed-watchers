# Daily Fun

Sends a daily random joke, fact, and quote-style message to a Discord channel.

![Fun](/assets/fun.png)

## Requirements

- PHP `>= 7.4` (recommended: PHP 8.1+)
- PHP `curl` extension enabled
- `make` (optional, recommended)

## Configuration

Recommended: set environment variable `FUN_WEBHOOK_URL`.

```bash
export FUN_WEBHOOK_URL="https://discord.com/api/webhooks/..."
```

Fallback: copy `../src/config/config.example.php` to `../src/config/config.php` and replace `YOUR_FUN_WEBHOOK_URL`.

## Run

From repo root (recommended):

```bash
make run-fun
```

Without `make`:

```bash
php fun/fun.php
```

## Cron Example

If using env vars, define them in crontab/service (interactive shell exports are not inherited by cron).

```cron
FUN_WEBHOOK_URL=https://discord.com/api/webhooks/...
0 9 * * * cd /path/to/discord-feed-watchers && make run-fun >> fun/logs/cron.log 2>&1
```

## Expected First Run

- Sends one daily message payload if at least one upstream fun API returns content.
- If all upstream APIs fail in one run, the script exits with an error.

## Troubleshooting

- `Missing webhook URL for fun_webhook_url`:
  - Set `FUN_WEBHOOK_URL` or configure `../src/config/config.php`.
- `The cURL extension is not installed or enabled.`:
  - Install/enable `php-curl`.
- `No content could be fetched from upstream fun APIs.`:
  - Retry later; one or more external APIs may be temporarily unavailable.
