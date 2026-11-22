<?php
declare(strict_types=1);
namespace CajeerEngine\Consent;

/** Interprets a visitor's consent statement; never an authentication mechanism. */
final class ConsentCookie
{
    public const NAME = 'cajeer_lite_consent';
    private const BITS = ['analytics' => 1, 'marketing' => 2, 'recordings' => 4];

    /** @return array{necessary:true,analytics:bool,marketing:bool,recordings:bool}|null */
    public static function read(mixed $value, array $policy, ?int $now = null): ?array
    {
        if (!$policy['enabled'] || !is_string($value) || strlen($value) > 80 ||
            !preg_match('/^v1\.([1-9][0-9]{0,8})\.([1-9][0-9]{9,10})\.([0-7])$/D', $value, $match)) return null;
        $now ??= time();
        $time = (int)$match[2];
        if ((int)$match[1] !== $policy['revision'] || $time > $now + 60 ||
            $time + $policy['days'] * 86400 <= $now) return null;
        $flags = (int)$match[3];
        return ['necessary' => true,
            'analytics' => (bool)($flags & self::BITS['analytics']),
            'marketing' => (bool)($flags & self::BITS['marketing']),
            'recordings' => (bool)($flags & self::BITS['recordings'])];
    }

    public static function mask(array $consent): int
    {
        $result = 0;
        foreach (self::BITS as $key => $bit) if ($consent[$key] ?? false) $result |= $bit;
        return $result;
    }
}
