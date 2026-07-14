<?php

use Tests\PostgresTestCase;

it('uses the dedicated PostgreSQL acceptance test case structurally', function (): void {
    expect($this)->toBeInstanceOf(PostgresTestCase::class);
});
