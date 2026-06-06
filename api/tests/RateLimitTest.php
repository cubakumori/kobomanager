<?php

declare(strict_types=1);

/** Rate limiting (tabla login_attempts). */
final class RateLimitTest extends DbTestCase
{
    public function testUnderLimitIsAllowed(): void
    {
        $ip = '10.0.0.1';
        RateLimit::hit($ip);
        RateLimit::hit($ip);
        $this->assertFalse(RateLimit::tooMany($ip, 5, 60));
    }

    public function testReachingLimitBlocks(): void
    {
        $ip = '10.0.0.2';
        for ($i = 0; $i < 5; $i++) RateLimit::hit($ip);
        $this->assertTrue(RateLimit::tooMany($ip, 5, 60));
    }

    public function testClearResetsCount(): void
    {
        $ip = '10.0.0.3';
        for ($i = 0; $i < 5; $i++) RateLimit::hit($ip);
        RateLimit::clear($ip);
        $this->assertFalse(RateLimit::tooMany($ip, 5, 60));
    }

    public function testCountIsPerIp(): void
    {
        for ($i = 0; $i < 5; $i++) RateLimit::hit('10.0.0.4');
        $this->assertFalse(RateLimit::tooMany('10.0.0.5', 5, 60));
    }

    public function testOldAttemptsOutsideWindowDoNotCount(): void
    {
        $ip = '10.0.0.6';
        // Inserta intentos "viejos" (hace 2 min) directamente.
        for ($i = 0; $i < 5; $i++) {
            DB::run('INSERT INTO login_attempts (ip, created_at) VALUES (?, NOW() - INTERVAL 120 SECOND)', [$ip]);
        }
        $this->assertFalse(RateLimit::tooMany($ip, 5, 60)); // ventana de 60s
    }
}
