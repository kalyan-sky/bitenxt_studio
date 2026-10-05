<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Magento;

/**
 * Who the customer is and which orders are theirs. Every answer comes from
 * Magento using the customer's own token, so a customer can only ever be
 * matched to their own orders.
 */
interface CustomerDirectory
{
    /**
     * @return array{customer_id: string, email: string, firstname: string, lastname: string}
     * @throws MagentoAuthException when the token is not valid
     */
    public function currentCustomer(string $token): array;

    /**
     * The customer's own order with this number (short numbers like "726" are
     * matched to "000000726"), or null when it is not on their account.
     *
     * @return array{number: string, status: string}|null
     */
    public function findOwnOrder(string $token, string $orderNumber): ?array;
}
