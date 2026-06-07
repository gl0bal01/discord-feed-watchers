<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/WatchlistRuntime.php';

WatchlistRuntime::requireCurlExtension();
WatchlistRuntime::ensureSingleInstance('discord-feed-watchers-europol');

final class EuropolMostWantedNotifier
{
    private string $webhookUrl;
    private LineStateStore $stateStore;
    private string $jsonFile;

    public function __construct(string $webhookUrl, string $statePath, string $jsonFile)
    {
        $this->webhookUrl = $webhookUrl;
        $this->stateStore = new LineStateStore($statePath);
        $this->jsonFile = $jsonFile;
    }

    public function processWantedPersons(): void
    {
        $this->refreshDataset();

        $file = new SplFileObject($this->jsonFile, 'r');
        try {
            while (!$file->eof()) {
                $line = trim((string) $file->fgets());
                if ($line === '') {
                    continue;
                }

                try {
                    /** @var array<string, mixed> $entity */
                    $entity = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    error_log('[Europol Watchlist] Invalid JSON line skipped: ' . $exception->getMessage());
                    continue;
                }

                if (($entity['schema'] ?? '') !== 'Person') {
                    continue;
                }

                $personId = trim((string) ($entity['id'] ?? ''));
                if ($personId === '' || $this->stateStore->has($personId)) {
                    continue;
                }

                $content = $this->buildContentMessage($entity);
                if (WatchlistRuntime::sendDiscordWebhook($this->webhookUrl, ['content' => $content])) {
                    $this->stateStore->add($personId);
                }

                sleep(1);
            }
        } finally {
            // Release file handle explicitly (BUG-7 fix).
            $file = null;
        }
    }

    private function refreshDataset(): void
    {
        $lastException = null;
        for ($daysAgo = 0; $daysAgo <= 2; $daysAgo++) {
            $dateStamp = gmdate('Ymd', time() - ($daysAgo * 86400));
            $url = sprintf(
                'https://data.opensanctions.org/datasets/%s/eu_europol_wanted/entities.ftm.json',
                $dateStamp
            );

            try {
                WatchlistRuntime::downloadFile($url, $this->jsonFile);
                return;
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        throw new RuntimeException('Unable to refresh Europol dataset.', 0, $lastException);
    }

    /**
     * @param array<string, mixed> $entity
     */
    private function buildContentMessage(array $entity): string
    {
        $properties = $entity['properties'] ?? [];
        if (!is_array($properties)) {
            $properties = [];
        }

        $getPropertyValues = static function (array $properties, string $key): array {
            $values = $properties[$key] ?? [];
            if (!is_array($values)) {
                return [];
            }

            return array_values(array_filter(array_map('strval', $values), static function (string $value): bool {
                return trim($value) !== '';
            }));
        };

        $names = $getPropertyValues($properties, 'name');
        $notes = $getPropertyValues($properties, 'notes');
        $sourceUrls = $getPropertyValues($properties, 'sourceUrl');

        $lines = [];
        $lines[] = '🔴 **' . (implode(', ', $names) ?: 'Unknown Person') . '**';
        $lines[] = '';

        // Key details
        $detailFields = [
            'birthDate' => 'Birth Date',
            'nationality' => 'Nationality',
            'ethnicity' => 'Ethnicity',
            'height' => 'Height',
            'eyeColor' => 'Eye Color',
        ];

        foreach ($detailFields as $key => $label) {
            $values = $getPropertyValues($properties, $key);
            if ($values !== []) {
                $display = implode(', ', $values);
                $lines[] = "**{$label}**: " . ($key === 'nationality' ? strtoupper($display) : $display);
            }
        }

        $appearance = $getPropertyValues($properties, 'appearance');
        if ($appearance !== []) {
            $lines[] = "**Appearance**: " . implode(', ', $appearance);
        }

        if ($notes !== []) {
            $lines[] = '';
            $lines[] = '**Notes**: ' . implode("\n", $notes);
        }

        // Source URLs as clickable links
        if ($sourceUrls !== []) {
            $lines[] = '';
            $lines[] = '**Links**:';
            foreach (array_slice($sourceUrls, 0, 5) as $url) {
                $lines[] = "- <{$url}>";
            }
        }

        $lines[] = '';
        $lines[] = '_Europol Notification — ' . gmdate('Y-m-d H:i:s') . ' UTC_';

        return WatchlistRuntime::truncate(implode("\n", $lines), WatchlistRuntime::DISCORD_CONTENT_LIMIT);
    }
}

try {
    $webhookUrl = WatchlistRuntime::getWebhookUrl('europol_webhook_url', 'EUROPOL_WEBHOOK_URL');
    $statePath = __DIR__ . '/processed_europol_wanted.txt';
    $jsonFile = __DIR__ . '/entities.ftm.json';

    $notifier = new EuropolMostWantedNotifier($webhookUrl, $statePath, $jsonFile);
    $notifier->processWantedPersons();
} catch (Throwable $exception) {
    error_log('[Europol Watchlist] ' . $exception->getMessage());
    exit(1);
}
