<script>
    document.addEventListener('livewire:init', () => {
        Livewire.on('notify', (e) => {
            const payload = Array.isArray(e) ? e[0] : e;
            window.dispatchEvent(
                new CustomEvent('toast', {
                    detail: {
                        message: payload?.message ?? payload?.detail?.message ?? 'Done',
                        type: payload?.type ?? payload?.detail?.type ?? 'success',
                        position:
                            payload?.position ??
                            payload?.detail?.position,
                    },
                })
            );
        });

        Livewire.on('toast-preview', (e) => {
            const payload = Array.isArray(e) ? e[0] : e;
            window.dispatchEvent(
                new CustomEvent('toast', {
                    detail: {
                        message: payload?.message ?? 'Preview',
                        type: 'success',
                        position: payload?.position,
                    },
                })
            );
        });
    });
</script>
