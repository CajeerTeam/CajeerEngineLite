<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

use CajeerEngine\Update\OperationFiles;

/** Store only derived recommendations; no raw logs, user input, secrets or credentials. */
final class SupportStore
{
    private string $dir;
    public function __construct(private readonly string $root,private readonly string $ownerGeneration)
    {
        if (!preg_match('/^[a-f0-9]{32,128}$/D',$ownerGeneration)) throw new \RuntimeException('Invalid owner identity revision');
        $this->dir=OperationFiles::path($root,'storage/ai/technical-support');
        OperationFiles::dir($this->dir,0700);
        clearstatcache(true,$this->dir);
        $st=@lstat($this->dir);
        if (!is_array($st) || ($st['mode']&0170000)!==0040000 || ($st['mode']&0077)!==0) {
            throw new \RuntimeException('Unsafe support history directory');
        }
    }
    private function file(string $category,array $snapshot): string
    {
        SupportDiagnostics::category($category);
        return OperationFiles::path($this->root,'storage/ai/technical-support/'.
            hash('sha256',$this->ownerGeneration.':'.$category.':'.SupportDiagnostics::fingerprint($snapshot)).'.json');
    }
    private static function validate(array $data,string $category,array $snapshot): void
    {
        if (!is_string($data['fingerprint']??null) ||
            !hash_equals(SupportDiagnostics::fingerprint($snapshot),$data['fingerprint']) ||
            ($data['category']??null)!==$category || !is_int($data['created']??null) ||
            $data['created']<1 || $data['created']>time()+60 ||
            !is_array($data['fields']??null) ||
            (count($data['fields'])!==4 ||
                array_diff(['summary','causes','steps','cautions'],array_keys($data['fields']))!==[]) ||
            !is_string($data['model']??null) || strlen($data['model'])>80 ||
            !is_int($data['input_tokens']??null) || !is_int($data['output_tokens']??null)) {
            throw new \RuntimeException('Invalid technical support record');
        }
        foreach ($data['fields'] as $v) {
            if (!is_string($v) || strlen($v)>4000 || trim($v)==='' || !preg_match('//u',$v)) {
                throw new \RuntimeException('Invalid support recommendation');
            }
        }
    }
    public function read(string $category,array $snapshot): ?array
    {
        $file=$this->file($category,$snapshot);
        clearstatcache(true,$file);
        if (!file_exists($file) && !is_link($file)) return null;
        $st=@lstat($file);
        if (!is_array($st) || ($st['mode']&0170000)!==0100000 || $st['nlink']!==1 ||
            ($st['mode']&0077)!==0 || $st['size']>16000) throw new \RuntimeException('Unsafe support history file');
        $data=OperationFiles::read($file);
        self::validate($data,$category,$snapshot);
        return $data;
    }
    public function save(string $category,array $snapshot,array $result): void
    {
        self::validate($result,$category,$snapshot);
        $json=json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (strlen($json)>16000) throw new \RuntimeException('Support response exceeds limit');
        OperationFiles::write($this->file($category,$snapshot),$json."\n",0600);
    }
    public function delete(string $category,array $snapshot): void
    {
        OperationFiles::remove($this->file($category,$snapshot));
    }
}
