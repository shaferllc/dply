<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * One row in a remote directory listing.
 */
class FileBrowserEntry
{
    public function __construct(
        public readonly string $name,
        public readonly string $type, // file | dir | link | other
        public readonly int $size,
        public readonly int $mtime,
        public readonly string $mode,
        public readonly string $owner,
        public readonly string $group,
        public readonly ?string $linkTarget = null,
        public readonly bool $linkTargetIsDir = false,
    ) {}

    public function isDir(): bool
    {
        return $this->type === 'dir' || ($this->type === 'link' && $this->linkTargetIsDir);
    }

    public function isLink(): bool
    {
        return $this->type === 'link';
    }

    public function isFile(): bool
    {
        return $this->type === 'file';
    }

    /**
     * @return array{name: string, type: string, size: int, mtime: int, mode: string, owner: string, group: string, link_target: ?string, link_target_is_dir: bool, is_dir: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'size' => $this->size,
            'mtime' => $this->mtime,
            'mode' => $this->mode,
            'owner' => $this->owner,
            'group' => $this->group,
            'link_target' => $this->linkTarget,
            'link_target_is_dir' => $this->linkTargetIsDir,
            'is_dir' => $this->isDir(),
        ];
    }
}
