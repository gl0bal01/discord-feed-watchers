<?php
declare(strict_types=1);

final class WatchlistRuntime
{
    private const USER_AGENT = 'discord-feed-watchers/2.0 (+https://github.com/gl0bal01/discord-feed-watchers)';
    private const DEFAULT_CONNECT_TIMEOUT_SECONDS = 10;
    private const DEFAULT_TIMEOUT_SECONDS = 20;
    private const DEFAULT_MAX_ATTEMPTS = 3;

    /** Discord hard limit on the `content` field of a webhook message. */
    public const DISCORD_CONTENT_LIMIT = 2000;

    /** @var resource[] */
    private static array $heldLocks = [];

    /** @var array<string, mixed>|null */
    private static ?array $configCache = null;

    public static function requireCurlExtension(): void
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The cURL extension is not installed or enabled.');
        }
    }

    public static function ensureSingleInstance(string $lockName): void
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $lockName);
        if ($safeName === null || $safeName === '') {
            throw new RuntimeException('Invalid lock name.');
        }

        $lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $safeName . '.lock';
        $handle = @fopen($lockPath, 'c');
        if ($handle === false) {
            throw new RuntimeException('Unable to create or open lock file: ' . $lockPath);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fwrite(STDERR, sprintf("[%s] Another instance is already running for lock '%s'.\n", date('c'), $lockName));
            exit(0);
        }

        self::$heldLocks[] = $handle;
    }

    public static function getWebhookUrl(string $configKey, string $envKey): string
    {
        $fromEnv = trim((string) getenv($envKey));
        $candidate = $fromEnv;

        if ($candidate === '') {
            $config = self::loadConfig();
            $candidate = trim((string) ($config[$configKey] ?? ''));
        }

        if ($candidate === '') {
            throw new RuntimeException(sprintf(
                'Missing webhook URL for %s. Set environment variable %s or define %s in src/config/config.php.',
                $configKey,
                $envKey,
                $configKey
            ));
        }

        self::assertDiscordWebhookUrl($candidate, $configKey);
        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fetchJson(string $url): array
    {
        self::assertHttpsUrl($url, 'fetch URL');
        $response = self::httpRequest('GET', $url, ['Accept: application/json']);

        try {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Invalid JSON received from %s: %s', $url, $exception->getMessage()),
                0,
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Unexpected JSON structure received from %s.', $url));
        }

        return $decoded;
    }

    public static function downloadFile(string $url, string $destinationPath): void
    {
        self::assertHttpsUrl($url, 'download URL');
        $response = self::httpRequest('GET', $url, ['Accept: application/json']);

        $bytesWritten = @file_put_contents($destinationPath, $response['body'], LOCK_EX);
        if (!is_int($bytesWritten) || $bytesWritten < 0) {
            throw new RuntimeException('Failed to write downloaded data to ' . $destinationPath);
        }
    }

    public static function sendDiscordWebhook(string $webhookUrl, array $payload): bool
    {
        self::assertDiscordWebhookUrl($webhookUrl, 'webhook URL');

        // Always suppress pings from external feed content.
        $payload['allowed_mentions'] = ['parse' => []];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Failed to encode webhook payload: ' . json_last_error_msg());
        }

        try {
            $response = self::httpRequest(
                'POST',
                $webhookUrl,
                ['Content-Type: application/json'],
                $body,
                15,
                5
            );
        } catch (RuntimeException $exception) {
            error_log('Webhook send error: ' . $exception->getMessage());
            return false;
        }

        return $response['status'] === 200 || $response['status'] === 204;
    }

    public static function truncate(string $value, int $maxLength): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return 'N/A';
        }

        if (self::stringLength($normalized) <= $maxLength) {
            return $normalized;
        }

        $suffix = ' ...[truncated]';
        $sliceLength = max(0, $maxLength - self::stringLength($suffix));
        return self::stringSubstr($normalized, 0, $sliceLength) . $suffix;
    }

    /**
     * Validate that a URL uses HTTPS. Returns the URL if valid, empty string otherwise.
     */
    public static function filterHttpsUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            return '';
        }

        return $url;
    }

    /**
     * @param string[] $lines
     */
    public static function writeLines(string $path, array $lines): void
    {
        $payload = implode("\n", $lines);
        if ($payload !== '') {
            $payload .= "\n";
        }

        $bytesWritten = @file_put_contents($path, $payload, LOCK_EX);
        if (!is_int($bytesWritten) || $bytesWritten < 0) {
            throw new RuntimeException('Failed to write file: ' . $path);
        }
    }

    /**
     * @return string[]
     */
    public static function readLines(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $raw = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($raw === false) {
            throw new RuntimeException('Failed to read file: ' . $path);
        }

        return array_values(array_filter(array_map('trim', $raw), static function (string $line): bool {
            return $line !== '';
        }));
    }

    public static function appendLine(string $path, string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        $bytesWritten = @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        if (!is_int($bytesWritten) || $bytesWritten < 0) {
            throw new RuntimeException('Failed to append file: ' . $path);
        }
    }

    /**
     * @param string[] $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private static function httpRequest(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        int $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS
    ): array {
        self::assertHttpsUrl($url, 'request URL');

        $attempt = 0;
        $lastError = 'Unknown network error.';
        while ($attempt < max(1, $maxAttempts)) {
            $attempt++;
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Failed to initialize HTTP request handle.');
            }

            $options = [
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => self::USER_AGENT,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => $headers,
            ];

            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }

            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            }

            if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
            }

            // Capture response headers for Retry-After parsing.
            $responseHeaders = [];
            $options[CURLOPT_HEADERFUNCTION] = static function ($curl, string $header) use (&$responseHeaders): int {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            };

            if (!curl_setopt_array($ch, $options)) {
                curl_close($ch);
                throw new RuntimeException('Failed to configure HTTP request options.');
            }

            $responseBody = curl_exec($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($responseBody === false) {
                $lastError = 'HTTP request failed: ' . $curlError;
                if ($attempt < $maxAttempts) {
                    sleep((int) min(4, pow(2, $attempt - 1)));
                    continue;
                }
                break;
            }

            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'status' => $statusCode,
                    'body' => (string) $responseBody,
                    'headers' => $responseHeaders,
                ];
            }

            $lastError = sprintf(
                'Unexpected HTTP status %d for %s. Body: %s',
                $statusCode,
                $url,
                self::bodySnippet((string) $responseBody)
            );

            $retryable = $statusCode === 429 || $statusCode >= 500;
            if ($retryable && $attempt < $maxAttempts) {
                // Respect Discord's Retry-After header if present.
                if ($statusCode === 429 && isset($responseHeaders['retry-after'])) {
                    $retryAfter = (float) $responseHeaders['retry-after'];
                    $sleepSeconds = max(1, (int) ceil($retryAfter));
                } else {
                    $sleepSeconds = (int) min(4, pow(2, $attempt - 1));
                }
                sleep($sleepSeconds);
                continue;
            }

            break;
        }

        throw new RuntimeException($lastError);
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadConfig(): array
    {
        if (self::$configCache !== null) {
            return self::$configCache;
        }

        $configPath = __DIR__ . '/../config/config.php';
        if (!file_exists($configPath)) {
            self::$configCache = [];
            return self::$configCache;
        }

        $config = require $configPath;
        if (!is_array($config)) {
            throw new RuntimeException('src/config/config.php must return an array.');
        }

        self::$configCache = $config;
        return self::$configCache;
    }

    private static function assertHttpsUrl(string $url, string $context): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException(sprintf('Invalid %s: %s', $context, $url));
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            throw new RuntimeException(sprintf('%s must use HTTPS: %s', ucfirst($context), $url));
        }
    }

    private static function assertDiscordWebhookUrl(string $url, string $context): void
    {
        self::assertHttpsUrl($url, $context);

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        $allowedHosts = ['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com'];

        if (!in_array($host, $allowedHosts, true)) {
            throw new RuntimeException(sprintf('Webhook host is not trusted for %s: %s', $context, $host));
        }

        if (!preg_match('#^/api/webhooks/\d+/[A-Za-z0-9._-]+$#', $path)) {
            throw new RuntimeException(sprintf('Webhook path is invalid for %s.', $context));
        }
    }

    private static function stringLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value);
        }

        return strlen($value);
    }

    private static function stringSubstr(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, $start, $length);
        }

        return substr($value, $start, $length);
    }

    private static function bodySnippet(string $body): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($body));
        if ($normalized === null || $normalized === '') {
            return '[empty]';
        }

        return self::truncate($normalized, 220);
    }
}

final class LineStateStore
{
    private string $path;

    /** @var array<string, bool> */
    private array $index = [];

    /** Maximum number of entries to retain. Older entries are pruned. */
    private const MAX_ENTRIES = 5000;

    public function __construct(string $path)
    {
        $this->path = $path;
        foreach (WatchlistRuntime::readLines($path) as $line) {
            $this->index[$line] = true;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->index[$key]);
    }

    public function add(string $key): void
    {
        $key = trim($key);
        if ($key === '' || isset($this->index[$key])) {
            return;
        }

        WatchlistRuntime::appendLine($this->path, $key);
        $this->index[$key] = true;
        $this->maybePrune();
    }

    /**
     * Prune the state file when it exceeds MAX_ENTRIES.
     * Keeps the most recent entries (last MAX_ENTRIES lines).
     */
    private function maybePrune(): void
    {
        if (count($this->index) <= self::MAX_ENTRIES) {
            return;
        }

        $lines = WatchlistRuntime::readLines($this->path);
        $keepCount = (int) floor(self::MAX_ENTRIES * 0.8);
        $kept = array_slice($lines, -$keepCount);

        WatchlistRuntime::writeLines($this->path, $kept);

        // Rebuild index from kept entries only.
        $this->index = [];
        foreach ($kept as $line) {
            $this->index[$line] = true;
        }
    }
}
