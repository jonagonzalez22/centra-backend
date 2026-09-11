<?php

test('cash session status column supports pending reconciliation', function () {
    $migration = file_get_contents(
        __DIR__.'/../../../database/migrations/2026_09_10_000002_expand_cash_session_status_length.php'
    );

    expect(strlen('pending_reconciliation'))->toBeGreaterThan(20)
        ->and($migration)->toContain("string('status', 32)");
});
