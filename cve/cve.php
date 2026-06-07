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

            $content = $this->buildContentMessage($vulnerability);
            if (WatchlistRuntime::sendDiscordWebhook($this->webhookUrl, ['content' => $content])) {
                $this->stateStore->add($cveId);
            }

            sleep(1);
        }
    }

    /**
     * @param array<string, mixed> $vulnerability
     */
    private function buildContentMessage(array $vulnerability): string
    {
        $cveId = trim((string) ($vulnerability['cveID'] ?? ''));
        $name = trim((string) ($vulnerability['vulnerabilityName'] ?? 'Unknown Vulnerability'));
        $shortDesc = trim((string) ($vulnerability['shortDescription'] ?? ''));

        $nvd = $vulnerability['nvdData'] ?? [];
        $nvdData = (is_array($nvd) && isset($nvd[0]) && is_array($nvd[0])) ? $nvd[0] : null;

        // Severity emoji
        $severityTag = '';
        if ($nvdData !== null) {
            $severity = strtoupper((string) ($nvdData['baseSeverity'] ?? ''));
            $severityTag = ['CRITICAL' => '🔴', 'HIGH' => '🟠', 'MEDIUM' => '🟡', 'LOW' => '🟢'][$severity] ?? '';
        }

        $lines = [];
        $lines[] = "{$severityTag} **{$name}**";
        $lines[] = "**CVE**: {$cveId}";

        // NVD link
        if ($cveId !== '') {
            $lines[] = "<https://nvd.nist.gov/vuln/detail/{$cveId}>";
        }

        if ($shortDesc !== '') {
            $lines[] = '';
            $lines[] = WatchlistRuntime::truncate($shortDesc, 500);
        }

        $lines[] = '';

        $simpleFields = [
            'dateAdded' => 'Date Added',
            'dueDate' => 'Due Date',
            'vendorProject' => 'Vendor/Project',
            'requiredAction' => 'Required Action',
        ];

        foreach ($simpleFields as $key => $label) {
            $value = trim((string) ($vulnerability[$key] ?? ''));
            if ($value !== '') {
                $lines[] = "**{$label}**: {$value}";
            }
        }

        // NVD details
        if ($nvdData !== null) {
            $lines[] = "**Score**: " . $this->formatNumberField($nvdData['baseScore'] ?? null) . " (" . trim((string) ($nvdData['baseSeverity'] ?? '')) . ")";
            $lines[] = "**Vector**: " . trim((string) ($nvdData['attackVector'] ?? '')) . " | **Complexity**: " . trim((string) ($nvdData['attackComplexity'] ?? ''));
        }

        // GitHub PoCs as clickable links
        $githubPocs = $vulnerability['githubPocs'] ?? null;
        if (is_array($githubPocs) && $githubPocs !== []) {
            $lines[] = '';
            $lines[] = '**PoCs**:';
            foreach (array_slice($githubPocs, 0, 5) as $poc) {
                $poc = trim((string) $poc);
                if ($poc !== '') {
                    $lines[] = "- <{$poc}>";
                }
            }
        }

        $notes = trim((string) ($vulnerability['notes'] ?? ''));
        if ($notes !== '') {
            $lines[] = '';
            $lines[] = "**Notes**: {$notes}";
        }

        $lines[] = '';
        $lines[] = '_Vulnerability Notification — ' . gmdate('Y-m-d H:i:s') . ' UTC_';

        return WatchlistRuntime::truncate(implode("\n", $lines), WatchlistRuntime::DISCORD_CONTENT_LIMIT);
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
