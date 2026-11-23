<?php
declare(strict_types=1);
namespace CajeerEngine\Operations;

/** Strict, streaming, uncompressed USTAR subset. No shell, Phar/ZIP dependency or extraction by archive paths. */
final class TarPackage
{
    public const MAX_SIZE = 115_000_000;

    /** @param array<string,string> $files Map of canonical member names to private source filenames. */
    public static function create(string $target, array $files): void
    {
        if (count($files)>255 || !$files) throw new \InvalidArgumentException('Invalid archive entry count');
        $handle=@fopen($target,'x+b');
        if (!is_resource($handle)) throw new \RuntimeException('Cannot create archive');
        $complete = false;
        try {
            if (!chmod($target,0600)) throw new \RuntimeException('Cannot protect archive');
            $total=0;
            foreach ($files as $name=>$file) {
                self::name($name);
                if (is_link($file) || !is_file($file)) throw new \RuntimeException('Unsafe archive source');
                $size=filesize($file);
                if (!is_int($size) || $size<0 || $size>self::limit($name) || ($total += $size+1024)>self::MAX_SIZE) {
                    throw new \RuntimeException('Archive limit exceeded');
                }
                self::put($handle,self::header($name,$size));
                $input=@fopen($file,'rb');
                if (!is_resource($input)) throw new \RuntimeException('Cannot open archive member');
                try {
                    if (stream_copy_to_stream($input,$handle,$size)!==$size || fread($input,1)!=='') {
                        throw new \RuntimeException('Source modified during archive write');
                    }
                } finally { fclose($input); }
                $padding=(512-$size%512)%512;
                if ($padding) self::put($handle,str_repeat("\0",$padding));
            }
            self::put($handle,str_repeat("\0",1024));
            if (!fflush($handle) || !fsync($handle)) throw new \RuntimeException('Cannot sync archive');
            \CajeerEngine\Update\OperationFiles::syncDir(dirname($target));
            $complete = true;
        } finally {
            fclose($handle);
            // Never expose a truncated TAR as a finished backup on failure.
            if (!$complete) @unlink($target);
        }
    }

    /** Extracts only the exact permitted members to a generated private directory; rejects any links, pax, duplicates and unexpected entries. */
    public static function unpack(string $source, string $stage): array
    {
        $stat=@lstat($source);
        if (!is_array($stat) || ($stat['mode'] & 0170000)!==0100000 || $stat['nlink']!==1 || $stat['size']<1024 || $stat['size']>self::MAX_SIZE) {
            throw new \RuntimeException('Unsafe or oversized archive');
        }
        $in=@fopen($source,'rb');
        if (!is_resource($in)) throw new \RuntimeException('Cannot open archive');
        $actual=[]; $total=0;
        try {
            $opened=fstat($in); $now=@lstat($source);
            if (!is_array($opened) || !is_array($now) || $opened['ino']!==$now['ino'] || $opened['dev']!==$now['dev'] || $opened['nlink']!==1) {
                throw new \RuntimeException('Archive replaced during open');
            }
            while (true) {
                $header=fread($in,512);
                if (!is_string($header) || strlen($header)!==512) throw new \RuntimeException('Truncated archive header');
                if ($header===str_repeat("\0",512)) {
                    if (fread($in,512)!==str_repeat("\0",512) || fread($in,1)!=='') throw new \RuntimeException('Invalid TAR terminator');
                    break;
                }
                if (substr($header,257,6)!=="ustar\0" || substr($header,263,2)!=='00' ||
                    !in_array($header[156],["0","\0"],true) || trim(substr($header,157,100),"\0")!=='' ||
                    trim(substr($header,345,155),"\0")!=='') throw new \RuntimeException('Unsupported TAR member type');
                $checksum=self::octal(substr($header,148,8));
                if ($checksum!==array_sum(array_map('ord',str_split(substr_replace($header,str_repeat(' ',8),148,8))))) {
                    throw new \RuntimeException('Invalid TAR checksum');
                }
                $name=rtrim(substr($header,0,100),"\0"); self::name($name);
                if (isset($actual[$name])) throw new \RuntimeException('Duplicate TAR member');
                $size=self::octal(substr($header,124,12));
                if ($size<0 || $size>self::limit($name) || ($total+=$size+1024)>self::MAX_SIZE || count($actual)>=255) {
                    throw new \RuntimeException('TAR entry limit exceeded');
                }
                // Never join untrusted archive paths. Only fixed filenames/hex IDs are accepted.
                $out=$stage.'/'.str_replace('/','__',$name);
                $dst=@fopen($out,'x+b');
                if (!is_resource($dst)) throw new \RuntimeException('Cannot stage TAR member');
                try {
                    chmod($out,0600);
                    if (stream_copy_to_stream($in,$dst,$size)!==$size || !fflush($dst) || !fsync($dst)) {
                        throw new \RuntimeException('Truncated TAR member');
                    }
                } finally { fclose($dst); }
                $skip=(512-$size%512)%512;
                if ($skip) {
                    $padding=fread($in,$skip);
                    if (!is_string($padding) || strlen($padding)!==$skip || $padding!==str_repeat("\0",$skip)) {
                        throw new \RuntimeException('Invalid TAR padding');
                    }
                }
                $actual[$name]=['path'=>$out,'size'=>$size,'sha256'=>hash_file('sha256',$out)];
            }
            if (!isset($actual['manifest.json'],$actual['site.json'],$actual['media/index.json'])) throw new \RuntimeException('Incomplete CMS archive');
            $manifest=json_decode((string)file_get_contents($actual['manifest.json']['path']),true,32,JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || array_keys($manifest)!==['format','kind','created_at','files'] ||
                $manifest['format']!==1 || !in_array($manifest['kind'],['backup','export'],true) ||
                !is_string($manifest['created_at']) || !is_array($manifest['files']) ||
                count($manifest['files'])!==count($actual)-1 || isset($manifest['files']['manifest.json'])) throw new \RuntimeException('Invalid CMS manifest');
            foreach ($actual as $name=>$info) {
                if ($name==='manifest.json') continue;
                $expected=$manifest['files'][$name]??null;
                if (!is_array($expected) || array_keys($expected)!==['size','sha256'] ||
                    $expected['size']!==$info['size'] || !is_string($expected['sha256']) ||
                    !hash_equals($info['sha256'],$expected['sha256'])) throw new \RuntimeException('CMS archive checksum mismatch');
            }
            return ['manifest'=>$manifest,'files'=>$actual];
        } finally { fclose($in); }
    }

    private static function name(string $name): void
    {
        if (!in_array($name,['manifest.json','site.json','media/index.json'],true) &&
            !preg_match('~^media/files/[a-f0-9]{32}\.(?:jpg|png|webp|gif|pdf)$~D',$name)) {
            throw new \InvalidArgumentException('Unsafe TAR member name');
        }
    }
    private static function limit(string $name): int
    {
        return match($name) { 'manifest.json'=>100_000,'site.json'=>4_000_000,'media/index.json'=>250_000,default=>8_000_000 };
    }
    private static function octal(string $raw): int
    {
        $number=trim($raw," \0");
        if ($number==='' || !preg_match('/^[0-7]{1,11}$/D',$number)) throw new \RuntimeException('Invalid TAR number');
        return octdec($number);
    }
    private static function header(string $name,int $size): string
    {
        $h=str_pad($name,100,"\0").sprintf("%07o\0",0600).sprintf("%07o\0",0).sprintf("%07o\0",0).
            sprintf("%011o\0",$size).sprintf("%011o\0",time()).str_repeat(' ',8).'0'.
            str_repeat("\0",100)."ustar\0".'00';
        $h=str_pad($h,512,"\0");
        $sum=array_sum(array_map('ord',str_split($h)));
        return substr_replace($h,sprintf("%06o\0 ",$sum),148,8);
    }
    private static function put(mixed $out,string $bytes): void
    {
        $length = strlen($bytes);
        for ($offset = 0; $offset < $length;) {
            $written = @fwrite($out, substr($bytes, $offset, min(65536, $length - $offset)));
            if (!is_int($written) || $written <= 0) throw new \RuntimeException('Cannot write TAR');
            $offset += $written;
        }
    }
}
