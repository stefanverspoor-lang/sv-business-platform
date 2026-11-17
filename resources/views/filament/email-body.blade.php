@php
    /** @var \App\Models\EmailMessage $record */
    $record = $getRecord();
    $html = $record->body_html;
    $doc = '<!DOCTYPE html><html><head><meta charset="utf-8">'
        .'<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data: cid:; style-src \'unsafe-inline\'">'
        .'<base target="_blank">'
        .'<style>body{font-family:system-ui,sans-serif;font-size:14px;line-height:1.5;color:#1c1917;margin:0;padding:4px;word-break:break-word}img{max-width:100%;height:auto}pre{white-space:pre-wrap}</style>'
        .'</head><body>'
        .($html ?: '<pre>'.e((string) $record->body_text).'</pre>')
        .'</body></html>';
@endphp
<div>
    <iframe
        sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin"
        srcdoc="{{ $doc }}"
        title="{{ __('Inhoud van de e-mail') }}"
        style="width:100%;min-height:420px;border:0;background:#fff;border-radius:8px"
        onload="try{this.style.height=(this.contentDocument.documentElement.scrollHeight+20)+'px'}catch(e){}"
    ></iframe>
    <p class="mt-2 text-xs text-gray-500">{{ __('Externe afbeeldingen worden niet geladen, voor je privacy.') }}</p>
</div>
