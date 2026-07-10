<?php

namespace App\Services;

use App\Models\CashSession;
use Illuminate\Support\Facades\DB;

class CashSessionService
{
    public function open(string $storeId, string $userId, float $openingAmount): CashSession
    {
        $existing = CashSession::forStore($storeId)
            ->current($userId)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            throw new \RuntimeException(
                'El usuario ya tiene una sesión de caja abierta en esta tienda.'
            );
        }

        return DB::transaction(function () use ($storeId, $userId, $openingAmount) {
            return CashSession::create([
                'store_id' => $storeId,
                'user_id' => $userId,
                'status' => 'open',
                'opening_amount' => $openingAmount,
                'expected_amount' => 0,
                'opened_at' => now(),
            ]);
        });
    }

    public function close(CashSession $session, float $realAmount, ?string $notes = null): CashSession
    {
        if ($session->status !== 'open') {
            throw new \RuntimeException(
                'La sesión de caja ya está cerrada.'
            );
        }

        $session->update([
            'status' => 'closed',
            'real_amount' => $realAmount,
            'notes' => $notes,
            'closed_at' => now(),
        ]);

        return $session->fresh();
    }
}
