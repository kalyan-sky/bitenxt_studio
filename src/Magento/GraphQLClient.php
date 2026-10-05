<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Magento;

/** Minimal Magento GraphQL client. Queries are fixed in code; only variables change. */
class GraphQLClient
{
    public function __construct(
        private readonly string $url,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed> the `data` object
     */
    public function query(string $query, array $variables, string $token): array
    {
        if ($this->url === '') {
            throw new MagentoException('MAGENTO_GRAPHQL_URL is not set');
        }
        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'variables' => $variables === [] ? new \stdClass() : $variables]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new MagentoException('request failed: ' . $error);
        }
        if ($status === 401 || $status === 403) {
            throw new MagentoAuthException('token rejected (HTTP ' . $status . ')');
        }
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            throw new MagentoException('invalid response (HTTP ' . $status . ')');
        }
        foreach ($body['errors'] ?? [] as $err) {
            $category = $err['extensions']['category'] ?? '';
            if ($category === 'graphql-authorization' || $category === 'graphql-authentication') {
                throw new MagentoAuthException((string) ($err['message'] ?? 'not authorized'));
            }
        }
        if (!isset($body['data']) || !is_array($body['data'])) {
            throw new MagentoException('GraphQL error: ' . (string) ($body['errors'][0]['message'] ?? 'no data'));
        }

        return $body['data'];
    }
}
