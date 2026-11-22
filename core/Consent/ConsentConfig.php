<?php
declare(strict_types=1);
namespace CajeerEngine\Consent;

use CajeerEngine\Public\PublicPath;

/** Editable, versioned policy. Changing it invalidates previously granted consent. */
final class ConsentConfig
{
    public const DEFAULTS = [
        'enabled' => true,
        'revision' => 1,
        'days' => 180,
        'title' => 'Настройки конфиденциальности',
        'description' => 'Мы используем необходимые cookie для работы сайта. Аналитика, маркетинг и запись сессий включаются только с вашего разрешения. Вы можете изменить или отозвать согласие в любое время.',
        'privacy_url' => '',
    ];

    public static function validate(array $policy): void
    {
        $keys = array_keys($policy);
        $expected = array_keys(self::DEFAULTS);
        sort($keys);
        sort($expected);
        if ($keys !== $expected || !is_bool($policy['enabled']) ||
            !is_int($policy['revision']) || $policy['revision'] < 1 || $policy['revision'] >= 1_000_000_000 ||
            !is_int($policy['days']) || $policy['days'] < 1 || $policy['days'] > 365) {
            throw new \RuntimeException('Invalid consent policy');
        }
        foreach (['title' => 120, 'description' => 1000, 'privacy_url' => 1024] as $key => $max) {
            $value = $policy[$key];
            if (!is_string($value) || strlen($value) > $max || !preg_match('//u', $value) || str_contains($value, "\0")) {
                throw new \RuntimeException('Invalid consent field: '.$key);
            }
        }
        if (trim($policy['title']) === '' || trim($policy['description']) === '') {
            throw new \RuntimeException('Consent title and description are required');
        }
        $url = $policy['privacy_url'];
        if ($url !== '') {
            try { if (!str_starts_with($url, '/') || PublicPath::content($url) !== $url) throw new \RuntimeException('Invalid privacy URL'); }
            catch (\InvalidArgumentException $e) { throw new \RuntimeException('Invalid privacy URL', 0, $e); }
        }
    }

    /** @param array<string,mixed> $input */
    public static function fromPost(array $input, array $current): array
    {
        self::validate($current);
        if (!is_string($input['enabled'] ?? null) && isset($input['enabled'])) throw new \InvalidArgumentException('Invalid consent flag');
        if (isset($input['enabled']) && $input['enabled'] !== '1') throw new \InvalidArgumentException('Invalid consent flag');
        $days = $input['days'] ?? null;
        if (!is_string($days) || !preg_match('/^[1-9][0-9]{0,2}$/D', $days)) {
            throw new \InvalidArgumentException('Invalid consent duration');
        }
        $data = [
            'enabled' => ($input['enabled'] ?? null) === '1',
            'revision' => $current['revision'],
            'days' => (int)$days,
            'title' => self::string($input, 'title', 120, true),
            'description' => self::string($input, 'description', 1000, true),
            'privacy_url' => self::string($input, 'privacy_url', 1024, false),
        ];
        if ($data['privacy_url'] !== '') {
            try {
                if (!str_starts_with($data['privacy_url'], '/') ||
                    PublicPath::content($data['privacy_url']) !== $data['privacy_url']) {
                    throw new \InvalidArgumentException('Invalid privacy URL');
                }
            }
            catch (\InvalidArgumentException $e) { throw new \InvalidArgumentException('Invalid privacy URL', 0, $e); }
        }
        foreach ($data as $key => $value) {
            if ($key !== 'revision' && $current[$key] !== $value) {
                if ($current['revision'] >= 999_999_999) throw new \InvalidArgumentException('Consent revision limit exceeded');
                ++$data['revision'];
                break;
            }
        }
        self::validate($data);
        return $data;
    }

    private static function string(array $data, string $key, int $max, bool $required): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || strlen($value) > $max || !preg_match('//u', $value) || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Invalid consent field: '.$key);
        }
        $value = trim($value);
        if ($required && $value === '') throw new \InvalidArgumentException('Required consent field: '.$key);
        return $value;
    }
}
