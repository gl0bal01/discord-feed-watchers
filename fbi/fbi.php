<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/WatchlistRuntime.php';

WatchlistRuntime::requireCurlExtension();
WatchlistRuntime::ensureSingleInstance('discord-feed-watchers-fbi');

final class FBIWantedNotifier
{

    private string $webhookUrl;
    private LineStateStore $stateStore;
    private string $apiUrl;

    public function __construct(string $webhookUrl, string $statePath, string $apiUrl)
    {
        $this->webhookUrl = $webhookUrl;
        $this->stateStore = new LineStateStore($statePath);
        $this->apiUrl = $apiUrl;
    }

    public function process(): void
    {
        $data = WatchlistRuntime::fetchJson($this->apiUrl);
        $items = $data['items'] ?? null;

        if (!is_array($items)) {
            throw new RuntimeException('FBI API returned an unexpected response format.');
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $uid = trim((string) ($item['uid'] ?? ''));
            if ($uid === '' || $this->stateStore->has($uid)) {
                continue;
            }

            $payload = [
                'content' => $this->buildContentMessage($item),
            ];

            if (WatchlistRuntime::sendDiscordWebhook($this->webhookUrl, $payload)) {
                $this->stateStore->add($uid);
            }

            sleep(1);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function buildContentMessage(array $item): string
    {
        $title = $this->valueAsString($item['title'] ?? '') ?: 'Unknown';
        $lines = [
            '==============================',
            '# ' . WatchlistRuntime::escapeMarkdown($title),
            '==============================',
            '',
        ];

        $fieldsToCheck = [
            'publication' => 'Publication Date',
            'status' => 'Status',
            'sex' => 'Sex',
            'race_raw' => 'Race',
            'nationality' => 'Nationality',
            'place_of_birth' => 'Place of Birth',
            'person_classification' => 'Person Classification',
            'poster_classification' => 'Poster Classification',
        ];

        foreach ($fieldsToCheck as $key => $label) {
            WatchlistRuntime::appendField($lines, $label, $this->valueAsString($item[$key] ?? ''));
        }

        WatchlistRuntime::appendField($lines, 'Date(s) of Birth Used', $this->valueAsString($item['dates_of_birth_used'] ?? []));

        $age = $this->calculateAge($item);
        if ($age !== null) {
            $lines[] = '**Age**: ' . (string) $age;
        }

        $optionalFields = [
            'age_range' => 'Age Range',
            'hair_raw' => 'Hair',
            'eyes' => 'Eyes',
            'scars_and_marks' => 'Scars and Marks',
            'ncic' => 'NCIC',
        ];

        foreach ($optionalFields as $key => $label) {
            WatchlistRuntime::appendField($lines, $label, $this->valueAsString($item[$key] ?? ''));
        }

        $heightMin = $this->valueAsString($item['height_min'] ?? '');
        $heightMax = $this->valueAsString($item['height_max'] ?? '');
        if ($heightMin !== '' && $heightMax !== '') {
            $lines[] = sprintf('**Height**: %s - %s inches', $heightMin, $heightMax);
        }

        $weightMin = $this->valueAsString($item['weight_min'] ?? '');
        $weightMax = $this->valueAsString($item['weight_max'] ?? '');
        if ($weightMin !== '' && $weightMax !== '') {
            $lines[] = sprintf('**Weight**: %s - %s pounds', $weightMin, $weightMax);
        }

        $arrayFields = [
            'occupations' => 'Occupations',
            'possible_countries' => 'Possible Countries',
            'possible_states' => 'Possible States',
            'locations' => 'Locations',
            'field_offices' => 'Field Offices',
            'subjects' => 'Subjects',
            'aliases' => 'Aliases',
        ];

        foreach ($arrayFields as $key => $label) {
            WatchlistRuntime::appendField($lines, $label, $this->valueAsString($item[$key] ?? []));
        }

        $rewardText = $this->valueAsString($item['reward_text'] ?? '');
        if ($rewardText !== '') {
            $lines[] = '';
            WatchlistRuntime::appendField($lines, 'Reward', $rewardText, WatchlistRuntime::DISCORD_SECTION_LIMIT);
        } elseif (is_numeric($item['reward_min'] ?? null) && (float) $item['reward_min'] > 0) {
            $lines[] = '**Reward**: $' . number_format((float) $item['reward_min'], 2, '.', ',');
        }

        foreach (['description', 'caution', 'remarks', 'details', 'warning_message', 'publication_remarks'] as $key) {
            $block = WatchlistRuntime::formatBlock($this->valueAsString($item[$key] ?? ''));
            if ($block === '') {
                continue;
            }

            $lines[] = '';
            $lines[] = sprintf('**%s**: %s', ucwords(str_replace('_', ' ', $key)), $block);
        }

        WatchlistRuntime::appendLink($lines, 'More Info', $this->valueAsString($item['url'] ?? ''));

        // Detail page link from the FBI feed's path (distinct from `url`).
        $path = trim((string) ($item['path'] ?? ''));
        if ($path !== '') {
            $fullPath = 'https://www.fbi.gov' . (str_starts_with($path, '/') ? $path : '/' . $path);
            WatchlistRuntime::appendLink($lines, 'All Details', $fullPath);
        }

        $files = $item['files'] ?? [];
        if (is_array($files)) {
            $fileUrls = [];
            foreach ($files as $file) {
                if (is_array($file)) {
                    $fileUrls[] = $file['url'] ?? '';
                }
            }

            WatchlistRuntime::appendLinkList($lines, 'File(s)', $fileUrls);
        }

        return WatchlistRuntime::finalizeContent($lines, 'FBI Wanted Notification');
    }

    /**
     * @param array<string, mixed> $item
     */
    private function calculateAge(array $item): ?int
    {
        $dates = $item['dates_of_birth_used'] ?? [];
        if (!is_array($dates) || $dates === []) {
            return null;
        }

        $candidate = trim((string) $dates[0]);
        if ($candidate === '') {
            return null;
        }

        try {
            $birthDate = new DateTimeImmutable($candidate);
            $today = new DateTimeImmutable('now');
            if ($birthDate > $today) {
                return null;
            }

            return $today->diff($birthDate)->y;
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * @param mixed $value
     */
    private function valueAsString($value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $element) {
                $text = trim(strip_tags((string) $element));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return implode(', ', $parts);
        }

        return trim(strip_tags((string) $value));
    }
}

try {
    $webhookUrl = WatchlistRuntime::getWebhookUrl('fbi_webhook_url', 'FBI_WEBHOOK_URL');
    // BUG-5 fix: increased pageSize from 15 to 50 to avoid missing new entries.
    // Consistent state file naming (was 'uids', now 'processed_fbi_uids.txt').
    $statePath = __DIR__ . '/processed_fbi_uids.txt';
    $apiUrl = 'https://api.fbi.gov/@wanted?pageSize=50&page=1&sort_order=desc&sort_on=publication';

    $notifier = new FBIWantedNotifier($webhookUrl, $statePath, $apiUrl);
    $notifier->process();
} catch (Throwable $exception) {
    error_log('[FBI Watchlist] ' . $exception->getMessage());
    exit(1);
}
