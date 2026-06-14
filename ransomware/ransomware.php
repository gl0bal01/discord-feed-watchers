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

            $embed = $this->buildEmbed($victim);
            if (WatchlistRuntime::sendDiscordWebhook($this->webhookUrl, ['embeds' => [$embed]])) {
                $this->stateStore->add($checksum);
            }

            sleep(1);
        }
    }

    /**
     * Fetch the RSS feed (the legacy JSON `recentvictims` endpoint was retired
     * upstream) and map each <item> to the victim shape used downstream. Falls
     * back to the cached XML when the network call fails.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchVictims(): array
    {
        try {
            $xml = WatchlistRuntime::fetchText($this->apiUrl);
            WatchlistRuntime::writeLines($this->cacheFile, [$xml]);
        } catch (Throwable $exception) {
            if (!file_exists($this->cacheFile)) {
                throw new RuntimeException('Unable to fetch ransomware feed and no cache is available.', 0, $exception);
            }

            $cacheRaw = file_get_contents($this->cacheFile);
            if ($cacheRaw === false) {
                throw new RuntimeException('Unable to read ransomware cache file.', 0, $exception);
            }

            $xml = $cacheRaw;
        }

        return $this->parseFeed($xml);
    }

    /**
     * Parse the ransomware.live RSS feed into victim records.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseFeed(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($feed === false || !isset($feed->channel->item)) {
            throw new RuntimeException('Ransomware feed returned an unexpected (non-RSS) response format.');
        }

        $victims = [];
        foreach ($feed->channel->item as $item) {
            $title = trim((string) $item->title);

            // Title shape: "🏴‍☠️ <Group> has just published a new victim : <Victim>"
            $group = '';
            $victimName = $title;
            if (preg_match('/^\s*\S*\s*(.*?)\s+has just published a new victim\s*:\s*(.+)$/u', $title, $m) === 1) {
                $group = trim($m[1]);
                $victimName = trim($m[2]);
            }

            $screenshot = '';
            if (isset($item->enclosure)) {
                $screenshot = trim((string) $item->enclosure['url']);
            }

            $victims[] = [
                'guid' => trim((string) ($item->guid ?? '')),
                'post_title' => $victimName !== '' ? $victimName : 'Unknown Victim',
                'group_name' => $group,
                'country' => strtoupper(trim((string) ($item->category ?? ''))),
                'published' => trim((string) ($item->pubDate ?? '')),
                'description' => trim((string) ($item->description ?? '')),
                'post_url' => trim((string) ($item->link ?? '')),
                'screenshot' => $screenshot,
            ];
        }

        return $victims;
    }

    /**
     * @param array<string, mixed> $victim
     */
    private function generateChecksum(array $victim): string
    {
        // The RSS guid is a stable per-victim identifier; prefer it and fall back
        // to a composite fingerprint when absent.
        $guid = trim((string) ($victim['guid'] ?? ''));
        if ($guid !== '') {
            return hash('sha256', $guid);
        }

        $fingerprint = implode('|', [
            trim((string) ($victim['post_title'] ?? '')),
            trim((string) ($victim['published'] ?? '')),
            trim((string) ($victim['group_name'] ?? '')),
            trim((string) ($victim['post_url'] ?? '')),
        ]);

        if ($fingerprint === '|||') {
            return '';
        }

        return hash('sha256', $fingerprint);
    }

    /** Embed accent colors (highlighted country stands out in orange). */
    private const COLOR_DEFAULT = 0xE74C3C;   // red
    private const COLOR_HIGHLIGHT = 0xE67E22; // orange

    /** Host that serves victim screenshots; only its images are embedded. */
    private const SCREENSHOT_HOST = 'images.ransomware.live';

    /**
     * Build a rich Discord embed (clickable title, accent color, inline fields
     * and the victim screenshot rendered inline).
     *
     * @param array<string, mixed> $victim
     * @return array<string, mixed>
     */
    private function buildEmbed(array $victim): array
    {
        $getValue = static function (array $item, string $key): string {
            $value = $item[$key] ?? '';
            if (is_array($value)) {
                return '';
            }

            return trim((string) $value);
        };

        $title = $getValue($victim, 'post_title') ?: 'Unknown Victim';
        $group = $getValue($victim, 'group_name');
        $country = strtoupper($getValue($victim, 'country'));
        $isHighlight = ($country !== '' && $country === $this->highlightCountry);

        $embed = [
            'title' => WatchlistRuntime::truncate($title, 256),
            'color' => $isHighlight ? self::COLOR_HIGHLIGHT : self::COLOR_DEFAULT,
        ];

        // Clickable title links to the leak post when the URL is a valid HTTPS link.
        $postUrl = WatchlistRuntime::filterHttpsUrl($getValue($victim, 'post_url'));
        if ($postUrl !== '') {
            $embed['url'] = $postUrl;
        }

        $description = $getValue($victim, 'description');
        if ($description !== '') {
            $embed['description'] = WatchlistRuntime::truncate($description, WatchlistRuntime::DISCORD_SECTION_LIMIT);
        }

        $fields = [];
        $this->appendEmbedField($fields, 'Group', $group);
        $this->appendEmbedField($fields, 'Country', $country);
        $this->appendEmbedField($fields, 'Published', $getValue($victim, 'published'), false);
        if ($fields !== []) {
            $embed['fields'] = $fields;
        }

        // Inline screenshot — restricted to the aggregator's own image host.
        $screenshot = $this->safeScreenshotUrl($getValue($victim, 'screenshot'));
        if ($screenshot !== '') {
            $embed['image'] = ['url' => $screenshot];
        }

        $embed['footer'] = ['text' => 'Ransomware Notification'];

        return $embed;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    private function appendEmbedField(array &$fields, string $name, string $value, bool $inline = true): void
    {
        $value = trim($value);
        if ($value === '' || strtoupper($value) === 'N/A') {
            return;
        }

        $fields[] = [
            'name' => $name,
            'value' => WatchlistRuntime::truncate($value, 1024),
            'inline' => $inline,
        ];
    }

    /**
     * Validate a screenshot URL: HTTPS and served from the trusted image host.
     */
    private function safeScreenshotUrl(string $url): string
    {
        $url = WatchlistRuntime::filterHttpsUrl($url);
        if ($url === '') {
            return '';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return $host === self::SCREENSHOT_HOST ? $url : '';
    }
}

try {
    $webhookUrl = WatchlistRuntime::getWebhookUrl('ransomware_webhook_url', 'RANSOMWARE_WEBHOOK_URL');
    $apiUrl = 'https://api.ransomware.live/feed';
    $cacheFile = __DIR__ . '/recentfeed.xml';
    $statePath = __DIR__ . '/processed_ransomware_victims.txt';

    $notifier = new RansomwareVictimNotifier($webhookUrl, $apiUrl, $cacheFile, $statePath);
    $notifier->processVictims();
} catch (Throwable $exception) {
    error_log('[Ransomware Watchlist] ' . $exception->getMessage());
    exit(1);
}
