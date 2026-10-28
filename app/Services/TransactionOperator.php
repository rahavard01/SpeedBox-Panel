<?php

namespace App\Services;

final class TransactionOperator
{
    /** Match the billing operator precedence without treating missing identity as a system action. */
    public static function displayRole(mixed $role, array $meta = []): int|string
    {
        $source = trim((string) ($meta['operator_source'] ?? ''));
        if ($source !== '') return $source;
        if (is_int($role)) return $role;
        $role = trim((string) ($role ?? ''));
        return $role !== '' ? $role : 'unknown';
    }
}
