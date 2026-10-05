<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Magento;

/** CustomerDirectory over Magento GraphQL, using only customer-scoped queries. */
final class MagentoCustomerDirectory implements CustomerDirectory
{
    private const CUSTOMER = 'query { customer { id email firstname lastname } }';
    private const ORDER = 'query ($number: String!) { customer { orders(filter: { number: { eq: $number } }, pageSize: 1) '
        . '{ items { number status } } } }';

    public function __construct(private readonly GraphQLClient $client)
    {
    }

    public function currentCustomer(string $token): array
    {
        $customer = $this->client->query(self::CUSTOMER, [], $token)['customer'] ?? null;
        if (!is_array($customer) || ($customer['email'] ?? '') === '') {
            throw new MagentoAuthException('customer query returned no customer');
        }

        return [
            // Some Magento versions hide the numeric id; the email is then the stable key.
            'customer_id' => (string) ($customer['id'] ?? $customer['email']),
            'email' => (string) $customer['email'],
            'firstname' => (string) ($customer['firstname'] ?? ''),
            'lastname' => (string) ($customer['lastname'] ?? ''),
        ];
    }

    public function findOwnOrder(string $token, string $orderNumber): ?array
    {
        $candidates = [$orderNumber];
        if (ctype_digit($orderNumber) && strlen($orderNumber) < 9) {
            $candidates[] = str_pad($orderNumber, 9, '0', STR_PAD_LEFT);
        }
        foreach ($candidates as $number) {
            $data = $this->client->query(self::ORDER, ['number' => $number], $token);
            $order = $data['customer']['orders']['items'][0] ?? null;
            if (is_array($order) && isset($order['number'])) {
                return ['number' => (string) $order['number'], 'status' => (string) ($order['status'] ?? '')];
            }
        }

        return null;
    }
}
