<?php

namespace App\Http;

final class CurlHttpClient implements HttpClient
{
    public function __construct(private int $timeout = 15)
    {
    }

    public function getJson(string $url, array $headers = []): array
    {
        [$body, $status] = $this->request($url, $headers + ['Accept' => 'application/json']);
        if ($status < 200 || $status >= 300) {
            throw new HttpException("HTTP $status from " . parse_url($url, PHP_URL_HOST), $status);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new HttpException('Invalid JSON from ' . parse_url($url, PHP_URL_HOST), $status);
        }
        return $data;
    }

    public function getBytes(string $url, int $maxBytes): array
    {
        $contentType = '';
        [$body, $status] = $this->request($url, [], $maxBytes, $contentType);
        if ($status < 200 || $status >= 300) {
            throw new HttpException("HTTP $status downloading image", $status);
        }
        return ['body' => $body, 'content_type' => $contentType];
    }

    /** @return array{0:string,1:int} */
    private function request(string $url, array $headers, int $maxBytes = 5_000_000, string &$contentType = ''): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new HttpException('Only http(s) URLs are allowed');
        }
        $h = curl_init($url);
        $buffer = '';
        curl_setopt_array($h, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => 'MMR/2.0 (personal media list)',
            CURLOPT_HTTPHEADER     => array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer, $maxBytes) {
                $buffer .= $chunk;
                return strlen($buffer) > $maxBytes ? 0 : strlen($chunk); // abort oversized bodies
            },
        ]);
        $ok = curl_exec($h);
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($h, CURLINFO_CONTENT_TYPE);
        $error = curl_error($h);
        curl_close($h);

        if ($ok === false) {
            throw new HttpException(strlen($buffer) > $maxBytes ? 'Response too large' : "Request failed: $error");
        }
        return [$buffer, $status];
    }
}
