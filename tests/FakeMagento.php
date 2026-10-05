<?php

declare(strict_types=1);

namespace Bitenxt\Studio\Tests;

use Bitenxt\Studio\Magento\CustomerDirectory;
use Bitenxt\Studio\Magento\MagentoAuthException;

/** Two clinics, each with their own orders, keyed by token. */
final class FakeMagento implements CustomerDirectory
{
    public const CLINIC_A = 'token-clinic-a-0001';
    public const CLINIC_B = 'token-clinic-b-0002';

    private array $customers = [
        self::CLINIC_A => ['customer_id' => '11', 'email' => 'a@clinic.test', 'firstname' => 'Asha', 'lastname' => 'Rao'],
        self::CLINIC_B => ['customer_id' => '22', 'email' => 'b@clinic.test', 'firstname' => 'Ben', 'lastname' => 'Lee'],
    ];
    private array $orders = [
        self::CLINIC_A => ['000000101', '000000102'],
        self::CLINIC_B => ['000000201'],
    ];

    public function currentCustomer(string $token): array
    {
        return $this->customers[$token] ?? throw new MagentoAuthException('bad token');
    }

    public function findOwnOrder(string $token, string $orderNumber): ?array
    {
        $this->currentCustomer($token);
        $padded = ctype_digit($orderNumber) ? str_pad($orderNumber, 9, '0', STR_PAD_LEFT) : $orderNumber;
        foreach ($this->orders[$token] ?? [] as $number) {
            if ($number === $orderNumber || $number === $padded) {
                return ['number' => $number, 'status' => 'processing'];
            }
        }

        return null;
    }
}
