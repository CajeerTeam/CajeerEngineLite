<?php
declare(strict_types=1);
namespace CajeerEngine\Integrations;

/** Strictly validated configuration of the real Yandex Metrica browser integration. */
final class MetricaConfig
{
    public const DEFAULTS = [
        'enabled' => false,
        'counter_id' => '',
        'page_views' => true,
        'accurate_bounce' => true,
        'clickmap' => true,
        'webvisor' => true,
        'contact_goals' => true,
        'email_goal' => 'contact_email',
        'phone_goal' => 'contact_phone',
    ];

    public static function validCounterId(string $id): bool
    {
        // Published Yandex counter IDs are positive decimal integers.
        return (bool) preg_match('/^[1-9][0-9]{0,14}$/D', $id);
    }

    public static function validGoal(string $goal): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $goal);
    }

    public static function validate(array $config): void
    {
        $expected = array_keys(self::DEFAULTS);
        $actual = array_keys($config);
        sort($expected); sort($actual);
        if ($expected !== $actual || !is_bool($config['enabled']) ||
            !is_string($config['counter_id']) ||
            ($config['counter_id'] !== '' && !self::validCounterId($config['counter_id'])) ||
            ($config['enabled'] && $config['counter_id'] === '')) {
            throw new \RuntimeException('Invalid Yandex Metrica configuration');
        }
        foreach (['page_views','accurate_bounce','clickmap','webvisor','contact_goals'] as $key) {
            if (!is_bool($config[$key])) throw new \RuntimeException('Invalid Metrica flag: '.$key);
        }
        foreach (['email_goal','phone_goal'] as $key) {
            if (!is_string($config[$key]) || ($config[$key] !== '' && !self::validGoal($config[$key])) ||
                ($config['enabled'] && $config['contact_goals'] && $config[$key] === '')) {
                throw new \RuntimeException('Invalid Metrica goal: '.$key);
            }
        }
    }

    /** @param array<string,mixed> $input */
    public static function fromPost(array $input): array
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (!array_key_exists($key,$input)) continue;
            if (!in_array($key,['counter_id','email_goal','phone_goal'],true) && $input[$key] !== '1') {
                throw new \InvalidArgumentException('Invalid Metrica flag: '.$key);
            }
        }
        foreach (['counter_id','email_goal','phone_goal'] as $key) {
            if (!is_string($input[$key] ?? null)) throw new \InvalidArgumentException('Invalid Metrica field: '.$key);
        }
        $result = [];
        foreach (self::DEFAULTS as $key => $default) {
            $result[$key] = is_bool($default) ? (($input[$key] ?? null) === '1') : trim($input[$key]);
        }
        try { self::validate($result); }
        catch (\RuntimeException $e) { throw new \InvalidArgumentException($e->getMessage(),0,$e); }
        return $result;
    }

    public static function active(array $state): bool
    {
        // A disabled consent UI may never silently opt users into analytics.
        return ($state['consent']['enabled'] ?? false) === true &&
               ($state['integrations']['metrica']['enabled'] ?? false) === true;
    }
}
