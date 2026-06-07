<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/WatchlistRuntime.php';

WatchlistRuntime::requireCurlExtension();
WatchlistRuntime::ensureSingleInstance('discord-feed-watchers-fun');

final class DailyFunNotifier
{
    private const CONTENT_LIMIT = 2000;

    private string $webhookUrl;

    public function __construct(string $webhookUrl)
    {
        $this->webhookUrl = $webhookUrl;
    }

    public function sendDailyMessage(): void
    {
        $sections = [];

        $fact = $this->fetchRandomFact();
        if ($fact !== '') {
            $sections[] = '**Random Fact:** ' . $fact;
        }

        $joke = $this->fetchJokeOfTheDay();
        if ($joke !== '') {
            $sections[] = "**Joke of the Day:**\n" . $joke;
        }

        $dadJoke = $this->fetchDadJoke();
        if ($dadJoke !== '') {
            $sections[] = '**Dad Joke of the Day:** ' . $dadJoke;
        }

        if ($sections === []) {
            throw new RuntimeException('No content could be fetched from upstream fun APIs.');
        }

        $content = "**Your Daily Dose of Fun:**\n\n" . implode("\n\n", $sections);
        $payload = ['content' => WatchlistRuntime::truncate($content, self::CONTENT_LIMIT)];

        if (!WatchlistRuntime::sendDiscordWebhook($this->webhookUrl, $payload)) {
            throw new RuntimeException('Failed to send daily fun webhook.');
        }
    }

    private function fetchRandomFact(): string
    {
        try {
            $data = WatchlistRuntime::fetchJson('https://uselessfacts.jsph.pl/api/v2/facts/random');
            return trim((string) ($data['text'] ?? ''));
        } catch (Throwable $exception) {
            error_log('[Fun Watchlist] Random fact fetch failed: ' . $exception->getMessage());
            return '';
        }
    }

    private function fetchJokeOfTheDay(): string
    {
        try {
            $data = WatchlistRuntime::fetchJson('https://v2.jokeapi.dev/joke/Any?blacklistFlags=nsfw,religious,political,racist,sexist,explicit');
        } catch (Throwable $exception) {
            error_log('[Fun Watchlist] Joke fetch failed: ' . $exception->getMessage());
            return '';
        }

        $type = trim((string) ($data['type'] ?? ''));
        if ($type === 'single') {
            return trim((string) ($data['joke'] ?? ''));
        }

        if ($type === 'twopart') {
            $setup = trim((string) ($data['setup'] ?? ''));
            $delivery = trim((string) ($data['delivery'] ?? ''));
            return trim($setup . "\n" . $delivery);
        }

        return '';
    }

    private function fetchDadJoke(): string
    {
        try {
            $data = WatchlistRuntime::fetchJson('https://icanhazdadjoke.com/');
            return trim((string) ($data['joke'] ?? ''));
        } catch (Throwable $exception) {
            error_log('[Fun Watchlist] Dad joke fetch failed: ' . $exception->getMessage());
            return '';
        }
    }
}

try {
    $webhookUrl = WatchlistRuntime::getWebhookUrl('fun_webhook_url', 'FUN_WEBHOOK_URL');
    $notifier = new DailyFunNotifier($webhookUrl);
    $notifier->sendDailyMessage();
} catch (Throwable $exception) {
    error_log('[Fun Watchlist] ' . $exception->getMessage());
    exit(1);
}
