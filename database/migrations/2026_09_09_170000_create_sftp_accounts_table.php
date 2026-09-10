<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File-transfer-only Linux accounts ("FTP accounts" in the UI, SFTP on the wire).
 *
 * One row per Linux account dply created for file transfer. These are real
 * /etc/passwd users in group `dply-sftp`, which a single constant sshd snippet
 * pins to `ForceCommand internal-sftp` — no shell, no port forwarding. Access to
 * a site's tree is granted by POSIX ACL rather than group membership, because
 * the web group (www-data) would hand the account read access to every site on
 * the box.
 *
 * `site_id` null means server-scoped: the account is granted the whole sites
 * parent (/home/<deploy user>) instead of one site directory.
 *
 * Deliberately NOT a place for the password. dply generates one, shows it once,
 * and forgets it — /etc/shadow on the box is the only store. "Reset password"
 * is the recovery path.
 *
 * Distinct from `server_system_users`, which is a passive /etc/passwd snapshot.
 * Both features useradd into the same namespace, hence the (server, username)
 * unique here and the passwd collision check at create time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sftp_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('server_id')->constrained('servers')->cascadeOnDelete();

            // Null = server-scoped (whole sites parent). Set = one site tree.
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->cascadeOnDelete();

            $table->string('username', 32);

            // Where the account lands on login, and the directory holding the
            // symlink(s) to what it can reach. Always /home/<username>: the
            // account's own home, never the site directory — `userdel -r` on a
            // site-homed account would delete the site.
            $table->string('home_path');

            // pending | active | error. Drift ("exists here, gone on the box")
            // is derived from the system-user snapshot, not stored.
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();

            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('provisioned_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            // /etc/passwd is per-server, so this is the real identity constraint.
            $table->unique(['server_id', 'username']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sftp_accounts');
    }
};
