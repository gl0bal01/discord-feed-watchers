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
                'embeds' => [$this->buildEmbed($item)],
            ];

            if (WatchlistRuntime::sendDiscordWebhook($this->webhookUrl, $payload)) {
                $this->stateStore->add($uid);
            }

            sleep(1);
        }
    }

    /** Host (and subdomains) allowed to supply poster/mugshot images. */
    private const IMAGE_HOST_SUFFIX = 'fbi.gov';

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function buildEmbed(array $item): array
    {
        $title = $this->valueAsString($item['title'] ?? '') ?: 'Unknown';

        $embed = [
            'title' => WatchlistRuntime::truncate($title, 256),
            'color' => WatchlistRuntime::EMBED_COLOR_DEFAULT,
        ];

        // Clickable title → the wanted person's page.
        $titleUrl = WatchlistRuntime::filterHttpsUrl($this->valueAsString($item['url'] ?? ''));
        if ($titleUrl !== '') {
            $embed['url'] = $titleUrl;
        }

        // Lead narrative becomes the embed description.
        $description = WatchlistRuntime::formatBlock($this->valueAsString($item['description'] ?? ''));
        if ($description !== '') {
            $embed['description'] = $description;
        }

        // Poster image (right-side thumbnail), restricted to the FBI's own host.
        $imageUrl = $this->safeImageUrl($item);
        if ($imageUrl !== '') {
            $embed['thumbnail'] = ['url' => $imageUrl];
        }

        $fields = [];

        $scalarFields = [
            'publication' => 'Publication Date',
            'status' => 'Status',
            'sex' => 'Sex',
            'race_raw' => 'Race',
            'nationality' => 'Nationality',
            'place_of_birth' => 'Place of Birth',
            'person_classification' => 'Person Classification',
            'poster_classification' => 'Poster Classification',
        ];

        foreach ($scalarFields as $key => $label) {
            WatchlistRuntime::embedField($fields, $label, $this->valueAsString($item[$key] ?? ''));
        }

        WatchlistRuntime::embedField($fields, 'Date(s) of Birth Used', $this->valueAsString($item['dates_of_birth_used'] ?? []));

        $age = $this->calculateAge($item);
        if ($age !== null) {
            WatchlistRuntime::embedField($fields, 'Age', (string) $age);
        }

        $optionalFields = [
            'age_range' => 'Age Range',
            'hair_raw' => 'Hair',
            'eyes' => 'Eyes',
            'scars_and_marks' => 'Scars and Marks',
            'ncic' => 'NCIC',
        ];

        foreach ($optionalFields as $key => $label) {
            WatchlistRuntime::embedField($fields, $label, $this->valueAsString($item[$key] ?? ''));
        }

        $heightMin = $this->valueAsString($item['height_min'] ?? '');
        $heightMax = $this->valueAsString($item['height_max'] ?? '');
        if ($heightMin !== '' && $heightMax !== '') {
            WatchlistRuntime::embedField($fields, 'Height', sprintf('%s - %s inches', $heightMin, $heightMax));
        }

        $weightMin = $this->valueAsString($item['weight_min'] ?? '');
        $weightMax = $this->valueAsString($item['weight_max'] ?? '');
        if ($weightMin !== '' && $weightMax !== '') {
            WatchlistRuntime::embedField($fields, 'Weight', sprintf('%s - %s pounds', $weightMin, $weightMax));
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
            WatchlistRuntime::embedField($fields, $label, $this->valueAsString($item[$key] ?? []));
        }

        $rewardText = $this->valueAsString($item['reward_text'] ?? '');
        if ($rewardText !== '') {
            WatchlistRuntime::embedField($fields, 'Reward', $rewardText, false, WatchlistRuntime::DISCORD_SECTION_LIMIT);
        } elseif (is_numeric($item['reward_min'] ?? null) && (float) $item['reward_min'] > 0) {
            WatchlistRuntime::embedField($fields, 'Reward', '$' . number_format((float) $item['reward_min'], 2, '.', ','));
        }

        // Remaining narrative blocks (description already used above).
        foreach (['caution', 'remarks', 'details', 'warning_message', 'publication_remarks'] as $key) {
            $block = WatchlistRuntime::formatBlock($this->valueAsString($item[$key] ?? ''));
            if ($block === '') {
                continue;
            }

            WatchlistRuntime::embedField($fields, ucwords(str_replace('_', ' ', $key)), $block, false, WatchlistRuntime::DISCORD_SECTION_LIMIT);
        }

        // Detail page link from the FBI feed's path (distinct from `url`).
        $path = trim((string) ($item['path'] ?? ''));
        if ($path !== '') {
            $fullPath = 'https://www.fbi.gov' . (str_starts_with($path, '/') ? $path : '/' . $path);
            WatchlistRuntime::embedLinkField($fields, 'All Details', $fullPath);
        }

        $files = $item['files'] ?? [];
        if (is_array($files)) {
            $fileUrls = [];
            foreach ($files as $file) {
                if (is_array($file)) {
                    $fileUrls[] = $file['url'] ?? '';
                }
            }

            WatchlistRuntime::embedLinkListField($fields, 'File(s)', $fileUrls);
        }

        // Discord allows at most 25 fields per embed.
        if (count($fields) > 25) {
            $fields = array_slice($fields, 0, 25);
        }

        if ($fields !== []) {
            $embed['fields'] = $fields;
        }

        return WatchlistRuntime::finalizeEmbed($embed, 'FBI Wanted Notification');
    }

    /**
     * Extract a usable poster image URL from the feed, accepting only HTTPS URLs
     * served from the FBI's own domain.
     *
     * @param array<string, mixed> $item
     */
    private function safeImageUrl(array $item): string
    {
        $images = $item['images'] ?? [];
        if (!is_array($images)) {
            return '';
        }

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            foreach (['large', 'original', 'thumb'] as $sizeKey) {
                $url = WatchlistRuntime::filterHttpsUrl((string) ($image[$sizeKey] ?? ''));
                if ($url === '') {
                    continue;
                }

                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if ($host === self::IMAGE_HOST_SUFFIX || str_ends_with($host, '.' . self::IMAGE_HOST_SUFFIX)) {
                    return $url;
                }
            }
        }

        return '';
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
