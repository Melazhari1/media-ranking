<?php

namespace App\Http;

interface HttpClient
{
    /**
     * GET a URL and decode the JSON body.
     *
     * @param array<string,string> $headers
     * @throws HttpException on transport errors or non-2xx responses
     */
    public function getJson(string $url, array $headers = []): array;

    /**
     * GET raw bytes (used for poster downloads).
     *
     * @return array{body:string, content_type:string}
     * @throws HttpException
     */
    public function getBytes(string $url, int $maxBytes): array;
}
