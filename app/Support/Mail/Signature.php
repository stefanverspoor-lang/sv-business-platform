<?php

namespace App\Support\Mail;

use App\Models\Mailbox;
use Illuminate\Support\Facades\Storage;

/**
 * Handtekening van een mailbox: opgemaakte tekst (met {naam} en {email})
 * en eventueel een logo, dat als ingesloten afbeelding wordt meegestuurd.
 */
class Signature
{
    public const LOGO_CID = 'signature-logo';

    public function __construct(
        public string $html,
        public string $text,
        public ?string $logoPath = null,
        public ?string $logoMime = null,
    ) {}

    public static function for(Mailbox $mailbox, ?string $senderName = null): ?self
    {
        $html = trim((string) $mailbox->signature);
        $logo = $mailbox->signature_logo_path && Storage::disk('local')->exists($mailbox->signature_logo_path) ? $mailbox->signature_logo_path : null;
        if (trim(strip_tags($html)) === '' && ! $logo) {
            return null;
        }

        $replace = ['{naam}' => e($senderName ?? auth()->user()?->name ?? $mailbox->name), '{email}' => e($mailbox->email)];
        $html = strtr(HtmlCleaner::clean($html) ?? '', $replace);
        $text = trim(html_entity_decode(strip_tags(preg_replace(['/<br\s*\/?>/i', '/<\/(p|div|li|h\d)>/i'], "\n", $html)), ENT_QUOTES | ENT_HTML5));
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return new self($html, $text, $logo, $logo ? (Storage::disk('local')->mimeType($logo) ?: 'image/png') : null);
    }

    /** HTML voor de verstuurde mail (logo via cid) of voor de opgeslagen kopie (logo als data-URI). */
    public function toHtml(bool $forStorage = false): string
    {
        $logo = '';
        if ($this->logoPath) {
            $src = $forStorage
                ? 'data:'.$this->logoMime.';base64,'.base64_encode(Storage::disk('local')->get($this->logoPath))
                : 'cid:'.self::LOGO_CID;
            $logo = '<p style="margin:8px 0 0 0"><img src="'.$src.'" alt="" style="max-width:200px;max-height:90px;height:auto"></p>';
        }

        return '<div style="margin-top:16px;color:#444;font-size:13px;line-height:1.45">'.$this->html.$logo.'</div>';
    }
}
