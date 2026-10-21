<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * One place where outbound HTTP policy is decided: timeouts, retries and — on
 * Windows PHP builds that ship without a CA bundle — the certificate authority
 * file, so TLS verification stays on instead of being disabled to make things work.
 */
class HttpClient
{
    /**
     * @param  array<string, string>  $headers
     */
    public static function make(array $headers = []): PendingRequest
    {
        $config = config('demographics.ingestion.http');

        $request = Http::timeout($config['timeout'])
            ->connectTimeout($config['connect_timeout'])
            ->retry($config['retries'], $config['retry_delay_ms'], throw: false)
            ->acceptJson();

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        $bundle = self::caBundle();

        if ($bundle !== null) {
            $request = $request->withOptions(['verify' => $bundle]);
        }

        return $request;
    }

    /**
     * Locates a usable CA bundle. Configuration wins; otherwise the usual locations
     * are probed, including the bundle that ships with Git for Windows.
     */
    public static function caBundle(): ?string
    {
        $candidates = [
            config('demographics.ingestion.http.ca_bundle'),
            env('DEMOGRAPHICS_CA_BUNDLE'),
            'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt',
            'C:\\Program Files\\Git\\usr\\ssl\\certs\\ca-bundle.crt',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/usr/local/etc/openssl/cert.pem',
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
