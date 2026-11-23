<?php
declare(strict_types=1);
namespace CajeerEngine\Security;

use CajeerEngine\Operations\TarPackage;

/** Send private CMS archives by checked descriptor, not a racy path-based readfile(). */
final class PrivateArchiveDownload
{
    /** @return array{0:resource,1:int} The caller closes the descriptor. */
    public static function open(string $path): array
    {
        clearstatcache(true,$path);
        $before=@lstat($path);
        if (!self::safe($before)) throw new \RuntimeException('Unsafe archive download file');
        $handle=@fopen($path,'rb');
        if (!is_resource($handle)) throw new \RuntimeException('Cannot open archive download');
        clearstatcache(true,$path);
        $current=@lstat($path);
        $descriptor=fstat($handle);
        if (!self::safe($descriptor) || !self::safe($current) ||
            $descriptor['ino']!==$before['ino'] || $descriptor['dev']!==$before['dev'] ||
            $current['ino']!==$before['ino'] || $current['dev']!==$before['dev'] ||
            $descriptor['size']!==$before['size'] || $current['size']!==$before['size']) {
            fclose($handle);
            throw new \RuntimeException('Archive file changed during open');
        }
        return [$handle,$before['size']];
    }

    private static function safe(mixed $entry): bool
    {
        return is_array($entry) && ($entry['mode'] & 0170000)===0100000 &&
            ($entry['mode'] & 0077)===0 && $entry['nlink']===1 &&
            $entry['size']>=1024 && $entry['size']<=TarPackage::MAX_SIZE;
    }

    public static function send(string $path,string $name): void
    {
        if (preg_match('/\A[a-zA-Z0-9_.-]+\z/D',$name)!==1) throw new \InvalidArgumentException('Invalid download filename');
        [$stream,$size]=self::open($path);
        try {
            header('Content-Type: application/x-tar');
            header('Content-Disposition: attachment; filename="'.$name.'"');
            header('Content-Length: '.$size);
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
            $output=@fopen('php://output','wb');
            if (!is_resource($output)) throw new \RuntimeException('Cannot open download stream');
            try {
                if (stream_copy_to_stream($stream,$output,$size)!==$size) {
                    throw new \RuntimeException('Incomplete archive download');
                }
            } finally { fclose($output); }
        } finally { fclose($stream); }
    }
}
