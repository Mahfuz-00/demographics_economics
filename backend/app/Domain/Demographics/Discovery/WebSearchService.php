<?php

namespace App\Domain\Demographics\Discovery;

use App\Support\HttpClient;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Web search used only when a scrape turns up nothing for a country.
 *
 * It returns candidate sources; it never parses them into data. That separation is
 * the point: "search the internet for it" is a discovery step, and treating whatever
 * a search engine surfaces as an authoritative figure would quietly undo the whole
 * source-ranking model.
 *
 * The default provider is DuckDuckGo's HTML endpoint because it needs no API key.
 * Any provider returning the same shape can be swapped in.
 */
class WebSearchService
{
    /**
     * @return array<int, array{title: string, url: string, snippet: string, host: string}>
     */
    public function search(string $query, ?int $limit = null): array
    {
        $resultLimit = $limit ?? (int) config('demographics.discovery.results_per_query', 6);

        return match ((string) config('demographics.discovery.search_provider', 'duckduckgo')) {
            'duckduckgo' => $this->duckDuckGo($query, $resultLimit),
            default => [],
        };
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: string, host: string}>
     */
    private function duckDuckGo(string $query, int $limit): array
    {
        $response = HttpClient::make([
            'User-Agent' => (string) config('demographics.discovery.user_agent', 'DemographicsEconomy/1.0'),
        ])->get('https://html.duckduckgo.com/html/', ['q' => $query]);

        if ($response->failed()) {
            return [];
        }

        $crawler = new Crawler($response->body());
        $results = [];

        $crawler->filter('.result')->each(function (Crawler $node) use (&$results): void {
            $link = $node->filter('.result__a')->first();

            if ($link->count() === 0) {
                return;
            }

            $url = $this->resolveUrl((string) $link->attr('href'));
            $title = trim($link->text(''));

            if ($url === null || $title === '') {
                return;
            }

            $snippet = $node->filter('.result__snippet')->count() > 0
                ? trim($node->filter('.result__snippet')->first()->text(''))
                : '';

            $results[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => $snippet,
                'host' => (string) parse_url($url, PHP_URL_HOST),
            ];
        });

        return array_slice($results, 0, $limit);
    }

    /**
     * DuckDuckGo wraps results in a redirect; unwrap to the destination URL.
     */
    private function resolveUrl(string $href): ?string
    {
        if ($href === '') {
            return null;
        }

        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        if (str_contains($href, 'duckduckgo.com/l/')) {
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

            if (isset($query['uddg'])) {
                return (string) $query['uddg'];
            }
        }

        return str_starts_with($href, 'http') ? $href : null;
    }
}
