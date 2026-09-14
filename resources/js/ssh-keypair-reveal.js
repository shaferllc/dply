/**
 * One-shot private-key dialog after server-side Ed25519 generation
 * (views/livewire/partials/ssh-keypair-reveal-modal.blade.php).
 *
 * Registered here rather than in an inline <script> in the partial: the SSH
 * keys page is #[Lazy], so its HTML arrives after load, and Livewire does not
 * execute plain <script> tags that arrive that way. The factory was never
 * defined, x-data pointed at nothing, and the dialog never opened — keys were
 * generated and installed but the private key was never shown. It also stays
 * out of x-data="{…}" because the bash strings below collide with attribute
 * quoting.
 */
const sshKeypairReveal = () => ({
    revealOpen: false,
    privateKey: '',
    publicKey: '',
    copiedPrivate: false,
    copiedPublic: false,
    copiedInstall: false,
    installFilename: 'id_ed25519_dply',
    acknowledged: false,
    // Set by "Get SSH access": where the key is already installed, so the
    // install command can also write a one-key ~/.ssh/config entry.
    access: null,
    openFromLivewire(detail) {
        const d = detail || {};
        this.privateKey = d.privateKey ?? d.private_key ?? '';
        this.publicKey = d.publicKey ?? d.public_key ?? '';
        this.access = d.access && d.access.host ? d.access : null;
        if (this.access && this.access.file) {
            this.installFilename = this.access.file;
        }
        this.copiedPrivate = false;
        this.copiedPublic = false;
        this.copiedInstall = false;
        this.acknowledged = false;
        this.revealOpen = true;
    },
    async copyPrivate() {
        try { await navigator.clipboard.writeText(this.privateKey); this.copiedPrivate = true; } catch (e) {}
    },
    async copyPublic() {
        try { await navigator.clipboard.writeText(this.publicKey); this.copiedPublic = true; } catch (e) {}
    },
    safeFilename() {
        return (this.installFilename || 'id_ed25519').replace(/[^A-Za-z0-9._-]/g, '_');
    },
    downloadPrivate() {
        const blob = new Blob([this.privateKey], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = this.safeFilename();
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    },
    installCommand() {
        const name = this.safeFilename();
        const key = String(this.privateKey || '').replace(/\r?\n$/, '');
        const keyFile =
            'mkdir -p ~/.ssh && chmod 700 ~/.ssh\n' +
            'umask 077 && cat > ~/.ssh/' + name + " <<'DPLY_KEY_EOF'\n" +
            key + '\n' +
            'DPLY_KEY_EOF\n' +
            'chmod 600 ~/.ssh/' + name + '\n';
        const a = this.access;
        if (a && a.host) {
            // Not added to the agent: a fuller agent is exactly what spends
            // the server's MaxAuthTries. The config entry offers this key
            // alone for this host — the dply button's ssh:// link included.
            return (
                keyFile +
                'touch ~/.ssh/config && chmod 600 ~/.ssh/config\n' +
                'grep -qs "IdentityFile ~/.ssh/' + name + '$" ~/.ssh/config || cat >> ~/.ssh/config <<\'DPLY_CFG_EOF\'\n' +
                '\n' +
                'Host ' + a.host + ' ' + a.alias + '\n' +
                '    HostName ' + a.host + '\n' +
                '    User ' + a.user + '\n' +
                (a.port && Number(a.port) !== 22 ? '    Port ' + a.port + '\n' : '') +
                '    IdentityFile ~/.ssh/' + name + '\n' +
                '    IdentitiesOnly yes\n' +
                'DPLY_CFG_EOF\n' +
                'echo "Ready: ssh ' + a.alias + '   (or: ssh ' + a.host + ')"\n'
            );
        }
        return (
            keyFile +
            'if command -v ssh-add >/dev/null 2>&1; then\n' +
            '  if [ "$(uname)" = "Darwin" ]; then ssh-add --apple-use-keychain ~/.ssh/' + name + ' 2>/dev/null || ssh-add ~/.ssh/' + name + ';\n' +
            '  else ssh-add ~/.ssh/' + name + ';\n' +
            '  fi\n' +
            'fi\n' +
            'echo "Installed: ~/.ssh/' + name + '"\n'
        );
    },
    async copyInstallCommand() {
        try {
            await navigator.clipboard.writeText(this.installCommand());
            this.copiedInstall = true;
            setTimeout(() => this.copiedInstall = false, 2400);
        } catch (e) {}
    },
    closeReveal() {
        if (!this.acknowledged) return;
        this.revealOpen = false;
        this.privateKey = '';
        this.publicKey = '';
        this.access = null;
    },
    cancelReveal() {
        this.revealOpen = false;
        this.privateKey = '';
        this.publicKey = '';
        this.access = null;
        this.copiedPrivate = false;
        this.copiedPublic = false;
        this.copiedInstall = false;
        this.acknowledged = false;
    },
});

export function registerSshKeypairReveal(Alpine) {
    // Two names for two contexts (server workspace, profile) so a page that
    // embeds both keeps separate dialog state.
    Alpine.data('dplySshKeypairReveal', sshKeypairReveal);
    Alpine.data('dplySshKeypairRevealProfile', sshKeypairReveal);
}
