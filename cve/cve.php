<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/WatchlistRuntime.php';

WatchlistRuntime::requireCurlExtension();
WatchlistRuntime::ensureSingleInstance('discord-feed-watchers-cve');

final class DiscordVulnerabilityNotifier
{
    private string $webhookUrl;
    private string $jsonUrl;
    private LineStateStore $stateStore;

    public function __construct(string $webhookUrl, string $jsonUrl, string $statePath)
    {
        $this->webhookUrl = $webhookUrl;
        $this->jsonUrl = $jsonUrl;
        $this->stateStore = new LineStateStore($statePath);
    }

    public function processVulnerabilities(): void
    {
        $data = WatchlistRuntime::fetchJson($this->jsonUrl);
        $vulnerabilities = $data['vulnerabilities'] ?? null;

        if (!is_array($vulnerabilities)) {
            throw new RuntimeException('CVE feed returned an unexpected response format.');
        }

        foreach ($vulnerabilities as $vulnerability) {
            if (!is_array($vulnerability)) {
                continue;
            }

            $cveId = trim((string) ($vulnerability['cveID'] ?? ''));
            if ($cveId === '' || $this->stateStore->has($cveId)) {
                continue;
            }

            $embed = $this->buildEmbed($vulnerability, $cveId);
            if (WatchlistRuntime::sendDiscordWebhook($this->webhookUrl, ['embeds' => [$embed]])) {
                $this->stateStore->add($cveId);
            }

            sleep(1);
        }
    }

    /**
     * @param array<string, mixed> $vulnerability
     * @return array<string, mixed>
     */
    private function buildEmbed(array $vulnerability, string $cveId): array
    {
        $name = trim((string) ($vulnerability['vulnerabilityName'] ?? 'Unknown Vulnerability'));

        $nvd = $vulnerability['nvdData'] ?? [];
        $nvdData = (is_array($nvd) && isset($nvd[0]) && is_array($nvd[0])) ? $nvd[0] : null;
        $severity = $nvdData !== null ? trim((string) ($nvdData['baseSeverity'] ?? '')) : '';

        $embed = [
            'title' => WatchlistRuntime::truncate($name, 256),
            'color' => WatchlistRuntime::severityColor($severity),
        ];

        // Clickable title → NVD detail page.
        if ($cveId !== '' && !str_contains($cveId, '>')) {
            $embed['url'] = "https://nvd.nist.gov/vuln/detail/{$cveId}";
        }

        $shortDescription = trim((string) ($vulnerability['shortDescription'] ?? ''));
        if ($shortDescription !== '') {
            $embed['description'] = WatchlistRuntime::truncate($shortDescription, WatchlistRuntime::DISCORD_SECTION_LIMIT);
        }

        $fields = [];
        WatchlistRuntime::embedField($fields, 'CVE ID', $cveId);
        WatchlistRuntime::embedField($fields, 'Date Added', (string) ($vulnerability['dateAdded'] ?? ''));
        WatchlistRuntime::embedField($fields, 'Due Date', (string) ($vulnerability['dueDate'] ?? ''));
        WatchlistRuntime::embedField($fields, 'Vendor/Project', (string) ($vulnerability['vendorProject'] ?? ''), false);
        WatchlistRuntime::embedField($fields, 'Required Action', (string) ($vulnerability['requiredAction'] ?? ''), false);

        // NVD details (controlled enum values, not free text).
        if ($nvdData !== null) {
            $baseScore = $this->formatNumberField($nvdData['baseScore'] ?? null);
            $scoreLabel = $severity !== '' ? $baseScore . ' (' . $severity . ')' : $baseScore;
            WatchlistRuntime::embedField($fields, 'Base Score', $scoreLabel);
            WatchlistRuntime::embedField($fields, 'Exploitability Score', $this->formatNumberField($nvdData['exploitabilityScore'] ?? null));
            WatchlistRuntime::embedField($fields, 'Attack Vector', trim((string) ($nvdData['attackVector'] ?? '')));
            WatchlistRuntime::embedField($fields, 'Attack Complexity', trim((string) ($nvdData['attackComplexity'] ?? '')));
        }

        $githubPocs = $vulnerability['githubPocs'] ?? null;
        if (is_array($githubPocs)) {
            WatchlistRuntime::embedLinkListField($fields, 'GitHub PoCs', $githubPocs);
        }

        WatchlistRuntime::embedField($fields, 'Notes', (string) ($vulnerability['notes'] ?? ''), false, WatchlistRuntime::DISCORD_SECTION_LIMIT);

        if ($fields !== []) {
            $embed['fields'] = $fields;
        }

        return WatchlistRuntime::finalizeEmbed($embed, 'Vulnerability Notification');
    }

    /**
     * @param mixed $value
     */
    private function formatNumberField($value): string
    {
        if (!is_numeric($value)) {
            return 'N/A';
        }

        return number_format((float) $value, 2, '.', '');
    }
}

try {
    $webhookUrl = WatchlistRuntime::getWebhookUrl('cve_webhook_url', 'CVE_WEBHOOK_URL');
    // Data source is configurable via CVE_FEED_URL. The default is a community
    // proxy that enriches the CISA KEV catalog with NVD scores and PoC links.
    // For an authoritative, un-enriched feed, point CVE_FEED_URL at CISA directly:
    //   https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json
    $jsonUrl = trim((string) getenv('CVE_FEED_URL')) ?: 'https://kevin.gtfkd.com/kev?per_page=100';
    $statePath = __DIR__ . '/processed_cves.txt';

    $notifier = new DiscordVulnerabilityNotifier($webhookUrl, $jsonUrl, $statePath);
    $notifier->processVulnerabilities();
} catch (Throwable $exception) {
    error_log('[CVE Watchlist] ' . $exception->getMessage());
    exit(1);
}
