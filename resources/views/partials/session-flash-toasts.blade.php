{{-- Full-page navigations: flash session success/error into the same toast stack as Livewire notify() (respects notification position). --}}
@php($__toastPos = \App\Support\NotificationToastPosition::resolvedFor(auth()->user()))
@if (session()->has('success') || session()->has('error'))
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            @if (session()->has('success'))
                window.dispatchEvent(new CustomEvent('toast', {
                    detail: @json(['message' => session('success'), 'type' => 'success', 'position' => $__toastPos]),
                }));
            @endif
            @if (session()->has('error'))
                window.dispatchEvent(new CustomEvent('toast', {
                    detail: @json(['message' => session('error'), 'type' => 'error', 'position' => $__toastPos]),
                }));
            @endif
        });
    </script>
@endif
