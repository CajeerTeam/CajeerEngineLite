<?php
declare(strict_types=1);
namespace CajeerEngine\AI;

/** Last-line outbound minimization: never send recognizable credentials or direct contact details to an AI API. */
final class DataProtection
{
    public static function outbound(string $text): string
    {
        if (str_contains($text,"\0") || !preg_match('//u',$text)) {
            throw new \InvalidArgumentException('Invalid AI request text');
        }
        $patterns = [
            '~\bsk-(?:proj-)?[A-Za-z0-9_-]{12,}\b~' => '[REDACTED_API_KEY]',
            '~\b(?:Bearer\s+)[A-Za-z0-9_.+/-]{12,}~i' => '[REDACTED_BEARER_TOKEN]',
            '~\b(?:eyJ[A-Za-z0-9_-]{16,}\.){2}[A-Za-z0-9_-]{8,}~' => '[REDACTED_JWT]',
            '~\b(?:password|client_secret|api_key|access_token|refresh_token)\s*[:=]\s*[\"\x27]?[^\s\"\x27,;]{5,}~i' => '[REDACTED_CREDENTIAL]',
            '~\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b~iu' => '[REDACTED_EMAIL]',
            '~(?<![\pL\pN])\+?\d[\d\s().-]{8,}\d(?![\pL\pN])~u' => '[REDACTED_PHONE]',
            '~(?<!\d)(?:\d{1,3}\.){3}\d{1,3}(?!\d)~' => '[REDACTED_IP]',
        ];
        foreach ($patterns as $pattern=>$replacement) {
            $changed=preg_replace($pattern,$replacement,$text);
            if (!is_string($changed)) throw new \RuntimeException('AI request privacy filtering failed');
            $text=$changed;
        }
        return $text;
    }
}
