@php /** @var \App\Models\EmailMessage $record */ $record = $getRecord(); @endphp
<div class="flex flex-wrap gap-2">
    @foreach ($record->attachments as $attachment)
        <x-filament::button
            size="sm"
            color="gray"
            icon="heroicon-o-paper-clip"
            wire:click="mountAction('downloadAttachment', { id: {{ (int) $attachment->id }} })"
        >
            {{ $attachment->filename }} ({{ \Illuminate\Support\Number::fileSize($attachment->size) }})
        </x-filament::button>
    @endforeach
</div>
