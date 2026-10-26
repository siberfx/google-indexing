<?php

declare(strict_types=1);

namespace Siberfx\GoogleIndexing\Sitemap;

use Illuminate\Support\Facades\Http;
use Siberfx\GoogleIndexing\Data\SitemapEntry;
use Siberfx\GoogleIndexing\Exceptions\GoogleIndexingException;
use SimpleXMLElement;

/**
 * Reads sitemap.xml, sitemap indexes (recursively), *.xml.gz files and local paths.
 */
final class SitemapReader
{
    public function __construct(
        private readonly int $maxDepth = 3,
        private readonly int $maxUrls = 50000,
        private readonly int $timeout = 15,
        private readonly string $userAgent = 'siberfx-google-indexing',
    ) {}

    /**
     * @return list<SitemapEntry> de-duplicated by location
     */
    public function read(string $location): array
    {
        $entries = [];
        $seen = [];

        $this->collect($location, 0, $entries, $seen);

        return array_values($entries);
    }

    /**
     * @param  array<string, SitemapEntry>  $entries
     * @param  array<string, true>  $seen  sitemap files already visited (loop guard)
     */
    private function collect(string $location, int $depth, array &$entries, array &$seen): void
    {
        if (isset($seen[$location]) || count($entries) >= $this->maxUrls) {
            return;
        }

        $seen[$location] = true;
        $xml = $this->parse($location, $this->fetch($location));

        // Sitemap index → follow children.
        if ($xml->getName() === 'sitemapindex') {
            if ($depth >= $this->maxDepth) {
                return;
            }

            foreach ($xml->xpath('//*[local-name()="sitemap"]/*[local-name()="loc"]') ?: [] as $loc) {
                $this->collect(trim((string) $loc), $depth + 1, $entries, $seen);
            }

            return;
        }

        foreach ($xml->xpath('//*[local-name()="url"]') ?: [] as $url) {
            if (count($entries) >= $this->maxUrls) {
                return;
            }

            $loc = trim((string) ($url->xpath('*[local-name()="loc"]')[0] ?? ''));

            if ($loc === '' || isset($entries[$loc])) {
                continue;
            }

            $lastmod = (string) ($url->xpath('*[local-name()="lastmod"]')[0] ?? '');
            $entries[$loc] = SitemapEntry::make($loc, $lastmod);
        }
    }

    private function fetch(string $location): string
    {
        if (! preg_match('#^https?://#i', $location)) {
            $body = is_file($location) ? file_get_contents($location) : false;

            if ($body === false) {
                throw GoogleIndexingException::sitemapUnreadable($location, 'file not found');
            }

            return $this->decompress($body);
        }

        $response = Http::timeout($this->timeout)
            ->withUserAgent($this->userAgent)
            ->accept('application/xml,text/xml,*/*')
            ->get($location);

        if ($response->failed()) {
            throw GoogleIndexingException::sitemapUnreadable($location, "HTTP {$response->status()}");
        }

        return $this->decompress($response->body());
    }

    private function decompress(string $body): string
    {
        // gzip magic number (some servers send *.xml.gz without Content-Encoding)
        if (str_starts_with($body, "\x1f\x8b")) {
            $decoded = gzdecode($body);
            $body = $decoded === false ? $body : $decoded;
        }

        return $body;
    }

    private function parse(string $location, string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw GoogleIndexingException::sitemapUnreadable($location, 'invalid XML');
        }

        return $xml;
    }
}
