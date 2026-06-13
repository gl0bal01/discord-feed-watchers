<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/WatchlistRuntime.php';

WatchlistRuntime::requireCurlExtension();
WatchlistRuntime::ensureSingleInstance('discord-feed-watchers-ransomware');

final class RansomwareVictimNotifier
{
    /** Country code to highlight in a distinct color. Configurable via env var. */
    private const DEFAULT_HIGHLIGHT_COUNTRY = 'FR';

    private string $webhookUrl;
    private string $apiUrl;
    private string $cacheFile;
    private LineStateStore $stateStore;
    private string $highlightCountry;

    public function __construct(string $webhookUrl, string $apiUrl, string $cacheFile, string $statePath)
    {
        $this->webhookUrl = $webhookUrl;
        $this->apiUrl = $apiUrl;
        $this->cacheFile = $cacheFile;
        $this->stateStore = new LineStateStore($statePath);
        $this->highlightCountry = strtoupper(trim((string) getenv('RANSOMWARE_HIGHLIGHT_COUNTRY')) ?: self::DEFAULT_HIGHLIGHT_COUNTRY);
    }

    public function processVictims(): void
    {
        $victims = $this->fetchVictims();
        foreach ($victims as $victim) {
            if (!is_array($victim)) {
                continue;
            }

            $checksum = $this->generateChecksum($victim);
            if ($checksum === '' || $this->stateStore->has($checksum)) {
                continue;
            }

            $content = $this->buildContentMessage($victim);
            if (WatchlistRuntime::sendDiscordWebhook($this->webhookUrl, ['content' => $content])) {
                $this->stateStore->add($checksum);
            }

            sleep(1);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchVictims(): array
    {
        try {
            $data = WatchlistRuntime::fetchJson($this->apiUrl);
            WatchlistRuntime::writeLines($this->cacheFile, [json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]']);
        } catch (Throwable $exception) {
            if (!file_exists($this->cacheFile)) {
                throw new RuntimeException('Unable to fetch ransomware feed and no cache is available.', 0, $exception);
            }

            $cacheRaw = file_get_contents($this->cacheFile);
            if ($cacheRaw === false) {
                throw new RuntimeException('Unable to read ransomware cache file.', 0, $exception);
            }

            try {
                /** @var array<int, array<string, mixed>> $data */
                $data = json_decode($cacheRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $jsonException) {
                throw new RuntimeException('Cached ransomware data is invalid JSON.', 0, $jsonException);
            }
        }

        if (!is_array($data)) {
            throw new RuntimeException('Ransomware API returned an unexpected response format.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $victim
     */
    private function generateChecksum(array $victim): string
    {
        $fingerprint = implode('|', [
            trim((string) ($victim['post_title'] ?? '')),
            trim((string) ($victim['published'] ?? '')),
            trim((string) ($victim['group_name'] ?? '')),
            trim((string) ($victim['website'] ?? '')),
        ]);

        if ($fingerprint === '|||') {
            return '';
        }

        return hash('sha256', $fingerprint);
    }

    /**
     * @param array<string, mixed> $victim
     */
    private function buildContentMessage(array $victim): string
    {
        $getValue = static function (array $item, string $key): string {
            $value = $item[$key] ?? '';
            if (is_array($value)) {
                return '';
            }

            return trim((string) $value);
        };

        $title = $getValue($victim, 'post_title') ?: 'Unknown Victim';
        $country = strtoupper($getValue($victim, 'country'));
        $highlightTag = ($country === $this->highlightCountry) ? '🟠' : '🔴';

        $lines = [];
        $lines[] = WatchlistRuntime::headerLine($highlightTag, $title);
        $lines[] = '';

        $simpleFields = [
            'website' => 'Website',
            'group_name' => 'Group',
            'country' => 'Country',
            'activity' => 'Activity',
            'discovered' => 'Discovered',
            'published' => 'Published',
        ];

        foreach ($simpleFields as $key => $label) {
            WatchlistRuntime::appendField($lines, $label, $getValue($victim, $key));
        }

        $descBlock = WatchlistRuntime::formatBlock($getValue($victim, 'description'));
        if ($descBlock !== '') {
            $lines[] = '';
            $lines[] = $descBlock;
        }

        $lines[] = '';
        WatchlistRuntime::appendLink($lines, 'Post', $getValue($victim, 'post_url'));
        WatchlistRuntime::appendLink($lines, 'Screenshot', $getValue($victim, 'screenshot'), true);

        // Infostealer data
        $infostealer = $victim['infostealer'] ?? null;
        if (is_array($infostealer)) {
            $lines[] = '';
            $lines[] = sprintf(
                '**Infostealer** — Employees: %s | Third Parties: %s | Users: %s | Updated: %s',
                (string) ($infostealer['employees'] ?? 'N/A'),
                (string) ($infostealer['thirdparties'] ?? 'N/A'),
                (string) ($infostealer['users'] ?? 'N/A'),
                (string) ($infostealer['update'] ?? 'N/A')
            );
        } elseif (is_string($infostealer) && trim($infostealer) !== '') {
            $lines[] = '';
            WatchlistRuntime::appendField($lines, 'Infostealer', $infostealer);
        }

        return WatchlistRuntime::finalizeContent($lines, 'Ransomware Notification');
    }
}

try {
    $webhookUrl = WatchlistRuntime::getWebhookUrl('ransomware_webhook_url', 'RANSOMWARE_WEBHOOK_URL');
    $apiUrl = 'https://api.ransomware.live/recentvictims';
    $cacheFile = __DIR__ . '/recentvictims.json';
    $statePath = __DIR__ . '/processed_ransomware_victims.txt';

    $notifier = new RansomwareVictimNotifier($webhookUrl, $apiUrl, $cacheFile, $statePath);
    $notifier->processVictims();
} catch (Throwable $exception) {
    error_log('[Ransomware Watchlist] ' . $exception->getMessage());
    exit(1);
}
