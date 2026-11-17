<?php

namespace App\Support\Mail;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Maakt HTML uit e-mails veilig om te tonen: geen scripts, geen formulieren
 * en geen externe afbeeldingen (die worden vaak gebruikt om mee te kijken).
 */
class HtmlCleaner
{
    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowMediaSchemes(['data'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->forceAttribute('a', 'target', '_blank')
            ->withMaxInputLength(2_000_000);

        return (new HtmlSanitizer($config))->sanitize($html);
    }
}
