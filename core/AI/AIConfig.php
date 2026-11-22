<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

/** Persist only nonsecret settings. Credentials never enter site.json or backups. */
final class AIConfig
{
    public const DEFAULTS = [
        'enabled' => false,
        'model' => 'gpt-5-mini',
        'max_input_chars' => 3000,
        'max_output_tokens' => 768,
        'daily_requests' => 25,
        'daily_tokens' => 100000,
    ];

    public static function validate(array $config): void
    {
        if (array_keys($config) !== array_keys(self::DEFAULTS) || !is_bool($config['enabled']) ||
            !is_string($config['model']) || !preg_match('/^[a-z][a-z0-9._-]{1,79}$/D', $config['model']) ||
            !str_starts_with($config['model'], 'gpt-') ||
            !self::between($config['max_input_chars'], 100, 16000) ||
            !self::between($config['max_output_tokens'], 64, 4096) ||
            !self::between($config['daily_requests'], 1, 500) ||
            !self::between($config['daily_tokens'], 5000, 2000000)) {
            throw new \RuntimeException('Invalid OpenAI configuration');
        }
    }

    private static function between(mixed $value, int $min, int $max): bool
    {
        return is_int($value) && $value >= $min && $value <= $max;
    }

    public static function fromPost(array $post): array
    {
        $config = ['enabled'=>($post['ai_enabled']??null)==='1'];
        if (isset($post['ai_enabled']) && $post['ai_enabled']!=='1') {
            throw new \InvalidArgumentException('Invalid OpenAI enable flag');
        }
        $model = $post['ai_model']??null;
        if (!is_string($model)) throw new \InvalidArgumentException('Model must be a string');
        $config['model']=trim($model);
        foreach (['max_input_chars','max_output_tokens','daily_requests','daily_tokens'] as $key) {
            $raw=$post['ai_'.$key]??null;
            if (!is_string($raw) || !preg_match('/^[0-9]{1,9}$/D',$raw)) {
                throw new \InvalidArgumentException('Invalid AI limit: '.$key);
            }
            $config[$key]=(int)$raw;
        }
        try { self::validate($config); }
        catch (\RuntimeException $e) { throw new \InvalidArgumentException($e->getMessage(),0,$e); }
        return $config;
    }
}
