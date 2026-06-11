{{--
    "Disk name" field for the object-storage binding modal.

    `s3` is Laravel's default filesystem disk and needs no app changes. A
    different name attaches an additional bucket as its OWN disk, with env keys
    namespaced by the disk slug (AWS_<DISK>_*) — so we surface the matching
    config/filesystems.php snippet to paste in. Shared by the attach + provision
    storage branches.
--}}
@php
    $diskSlug = $this->storageDiskSlugPreview($bindingForm['disk'] ?? '');
    $diskSnippet = $this->storageSnippetForDiskName($bindingForm['disk'] ?? '');
    $diskIsPrimary = $diskSlug === 's3';
@endphp
<div class="sm:col-span-2">
    <x-input-label for="binding_storage_disk" :value="__('Filesystem disk name')" />
    <x-text-input id="binding_storage_disk" wire:model.live="bindingForm.disk" class="mt-1 block w-full font-mono text-sm" placeholder="s3" />
    <x-input-error :messages="$errors->get('bindingForm.disk')" class="mt-1" />
    <p class="mt-1 text-xs text-brand-moss">
        @if ($diskIsPrimary)
            {{ __('This is the default disk — injects FILESYSTEM_DISK=s3 + AWS_* and works with Laravel out of the box.') }}
        @else
            {{ __('Attaches an additional bucket as the ":disk" disk. Its variables are namespaced (AWS_:env_*); add the disk to your config/filesystems.php below.', ['disk' => $diskSlug, 'env' => strtoupper($diskSlug)]) }}
        @endif
    </p>
    @if (! $diskIsPrimary && $diskSnippet !== '')
        <div x-data="{ copied: false, async copy() { try { await navigator.clipboard.writeText(@js($diskSnippet)); this.copied = true; setTimeout(() => this.copied = false, 1200); } catch (e) {} } }" class="mt-2">
            <div class="flex items-center justify-between">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Add to config/filesystems.php → disks') }}</p>
                <button type="button" @click="copy()" class="text-[11px] font-semibold text-brand-sage hover:underline"><span x-show="! copied">{{ __('Copy') }}</span><span x-show="copied" x-cloak class="text-emerald-600">{{ __('Copied') }}</span></button>
            </div>
            <pre class="mt-1 overflow-x-auto rounded bg-brand-ink/90 p-2 font-mono text-[10px] leading-relaxed text-brand-cream">{{ $diskSnippet }}</pre>
        </div>
    @endif
</div>
