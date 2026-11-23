<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Update\{OperationFiles,OperationLock};

/** Operator-set estimates in US cents; these are NOT provider billing limits or a price feed. */
final class CostPolicy
{
    public const DEFAULTS = [
        'enabled' => false,
        'daily_cents' => 500,
        'monthly_cents' => 5000,
        'input_cents_per_million' => 0,
        'output_cents_per_million' => 0,
        'max_concurrent' => 20,
    ];

    public static function validate(array $value): void
    {
        if (array_keys($value) !== array_keys(self::DEFAULTS) || !is_bool($value['enabled'])) {
            throw new \InvalidArgumentException('Invalid AI cost controls');
        }
        foreach (['daily_cents'=>[1,1000000], 'monthly_cents'=>[1,30000000],
            'input_cents_per_million'=>[0,1000000], 'output_cents_per_million'=>[0,1000000],
            'max_concurrent'=>[1,20]] as $name => [$min,$max]) {
            if (!is_int($value[$name]) || $value[$name] < $min || $value[$name] > $max) {
                throw new \InvalidArgumentException('Invalid AI cost field: '.$name);
            }
        }
        if ($value['enabled'] && ($value['input_cents_per_million']===0 ||
            $value['output_cents_per_million']===0 ||
            $value['monthly_cents']<$value['daily_cents'])) {
            throw new \InvalidArgumentException('Configure both model token rates and consistent monetary limits');
        }
    }

    public static function fromPost(array $post): array
    {
        $data = ['enabled'=>($post['cost_enabled']??null)==='1'];
        if (isset($post['cost_enabled']) && $post['cost_enabled']!=='1') {
            throw new \InvalidArgumentException('Invalid budget enable flag');
        }
        foreach (array_keys(self::DEFAULTS) as $field) {
            if ($field==='enabled') continue;
            $raw=$post['cost_'.$field]??null;
            if (!is_string($raw) || !preg_match('/^(0|[1-9][0-9]{0,8})$/D',$raw)) {
                throw new \InvalidArgumentException('Invalid budget setting: '.$field);
            }
            $data[$field]=(int)$raw;
        }
        self::validate($data);
        return $data;
    }

    public static function file(string $root): string
    {
        return OperationFiles::path($root,'storage/ai/cost-policy.json');
    }

    public static function load(string $root): array
    {
        $file=self::file($root);
        if (!file_exists($file) && !is_link($file)) return self::DEFAULTS;
        self::assertFile($file);
        $policy=OperationFiles::read($file);
        self::validate($policy);
        return $policy;
    }

    public static function save(string $root,array $value): void
    {
        self::validate($value);
        $path=self::file($root);
        OperationFiles::dir(dirname($path),0700);
        $lock=OperationLock::openLockFile(OperationFiles::path($root,'storage/ai/usage.lock'),0600);
        try {
            if (!flock($lock,LOCK_EX)) throw new \RuntimeException('Cannot lock AI budget controls');
            if (file_exists($path) || is_link($path)) self::assertFile($path);
            OperationFiles::write($path,json_encode($value,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n",0600);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    public static function assertFile(string $path): void
    {
        clearstatcache(true,$path);
        $stat=@lstat($path);
        if (!is_array($stat) || ($stat['mode']&0170000)!==0100000 || ($stat['nlink']??0)!==1 ||
            ($stat['mode']&0077)!==0 || $stat['size']>65536 || is_link($path)) {
            throw new \RuntimeException('Unsafe AI cost configuration file');
        }
    }

    /** Integer microcents, intentionally rounded up to avoid underestimating. */
    public static function estimate(int $inputTokens,int $outputTokens,array $policy): int
    {
        return $inputTokens*$policy['input_cents_per_million']+
            $outputTokens*$policy['output_cents_per_million'];
    }
}
