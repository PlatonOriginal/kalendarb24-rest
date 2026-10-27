<?php
declare(strict_types=1);

namespace Kb24Rest\Bitrix24;

final class CurlTransport implements HttpTransport
{
    public function post(string $url, array $params): array
    {
        return $this->send($url, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
        ]);
    }

    public function get(string $url, array $query): array
    {
        return $this->send($url . '?' . http_build_query($query), []);
    }

    private function send(string $url, array $options): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        if ($raw === false)
        {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RestException('http_error', $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $body = json_decode((string)$raw, true);

        return ['status' => $status, 'body' => is_array($body) ? $body : []];
    }
}
