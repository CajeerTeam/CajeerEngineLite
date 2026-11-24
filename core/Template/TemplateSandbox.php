<?php

declare(strict_types=1);

namespace CajeerEngine\Template;

final readonly class TemplateSandbox
{
    public function __construct(private string $templatesRoot)
    {
    }

    public function assertTemplatePath(string $templatePath): void
    {
        $configured = rtrim($this->templatesRoot, '/\\');
        $root = realpath($configured);
        $template = realpath($templatePath);
        if ($root === false || $template === false || is_link($configured) ||
            !str_starts_with($template, $root . DIRECTORY_SEPARATOR) ||
            !str_starts_with($templatePath, $configured . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Template outside allowed directory');
        }
        // realpath() alone cannot detect hardlinks or symlinked subdirectories:
        // a local file with sensitive contents could otherwise be rendered as a
        // public template. Reject each named component before reading it.
        $relative=substr($templatePath,strlen($configured)+1);
        $current=$configured;
        foreach (explode(DIRECTORY_SEPARATOR,$relative) as $component) {
            if ($component==='' || $component==='.' || $component==='..') {
                throw new \RuntimeException('Unsafe template path component');
            }
            $current.=DIRECTORY_SEPARATOR.$component;
            if (is_link($current)) throw new \RuntimeException('Template symlink denied');
        }
        clearstatcache(true,$templatePath);
        $stat=@lstat($templatePath);
        if (!is_array($stat) || ($stat['mode']&0170000)!==0100000 ||
            $stat['nlink']!==1 || !str_ends_with($template, '.cjr')) {
            throw new \RuntimeException('Unsafe or non-regular template');
        }
    }

    /** Read the same regular inode that passed path validation; never eval an
     * unbounded or substituted template file.
     */
    public function readSource(string $templatePath): string
    {
        $this->assertTemplatePath($templatePath);
        $before=@lstat($templatePath);
        if (!is_array($before) || $before['size']>131072) {
            throw new \RuntimeException('Template exceeds 128 KiB');
        }
        $stream=@fopen($templatePath,'rb');
        if (!is_resource($stream)) throw new \RuntimeException('Cannot open template');
        try {
            $opened=fstat($stream);
            clearstatcache(true,$templatePath);
            $current=@lstat($templatePath);
            if (!is_array($opened) || !is_array($current) ||
                $opened['dev']!==$before['dev'] || $opened['ino']!==$before['ino'] ||
                $current['dev']!==$before['dev'] || $current['ino']!==$before['ino'] ||
                $opened['nlink']!==1 || $current['nlink']!==1 ||
                ($opened['mode']&0170000)!==0100000 ||
                $opened['size']!==$before['size'] || $current['size']!==$before['size']) {
                throw new \RuntimeException('Template replaced during read');
            }
            $source=stream_get_contents($stream,131073);
            if (!is_string($source) || strlen($source)!==$opened['size']) {
                throw new \RuntimeException('Cannot read complete template');
            }
            return $source;
        } finally { fclose($stream); }
    }

    /** @param array<string, mixed> $context */
    public function execute(string $compiledPhp, array $context, CajeerTemplateEngine $engine, array &$sections = []): string
    {
        $reserved = ['compiledPhp', 'context', '__engine', '__sections'];
        foreach ($reserved as $key) {
            unset($context[$key]);
        }

        if (is_array($context['sections'] ?? null)) {
            $sections = array_merge($sections, $context['sections']);
        }
        unset($context['sections']);
        $__sections = &$sections;
        $__engine = $engine;

        ob_start();
        try {
        (static function () use ($compiledPhp, $context, $__engine, &$__sections): void {
            extract($context, EXTR_SKIP);
            eval('?>' . $compiledPhp);
        })();

        return (string) ob_get_contents();
        } finally { ob_end_clean(); }
    }
}
