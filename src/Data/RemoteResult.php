<?php

namespace BaseOS\Connect\Data;

/**
 * What your site returns after a write/read — mirrors Base OS `RemoteSnapshot`
 * (ReceiverAdapter::refFromResult / snapshotFromResult).
 */
final class RemoteResult
{
    public const PUBLISHED = 'published';

    public const DRAFT = 'draft';

    public const UNPUBLISHED = 'unpublished';

    public const TRASHED = 'trashed';

    /**
     * @param  array<string, mixed>  $fields  Optional current values (post.fetch): title, slug, seo, taxonomy…
     */
    public function __construct(
        public readonly string $remoteId,
        public readonly ?string $url = null,
        public readonly ?string $adminUrl = null,
        public readonly string $status = self::DRAFT,
        public readonly ?string $remoteHash = null,
        public readonly ?int $revisionSeen = null,
        public readonly ?string $contentId = null,
        public readonly ?string $modifiedAt = null,
        public readonly array $fields = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            remoteId: (string) ($data['remote_id'] ?? ''),
            url: $data['url'] ?? null,
            adminUrl: $data['admin_url'] ?? null,
            status: (string) ($data['status'] ?? self::DRAFT),
            remoteHash: $data['remote_hash'] ?? null,
            revisionSeen: isset($data['revision_seen']) ? (int) $data['revision_seen'] : null,
            contentId: $data['content_id'] ?? null,
            modifiedAt: $data['modified_at'] ?? null,
            fields: is_array($data['fields'] ?? null) ? $data['fields'] : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'remote_id' => $this->remoteId,
            'url' => $this->url,
            'admin_url' => $this->adminUrl,
            'status' => $this->status,
            'remote_hash' => $this->remoteHash,
            'revision_seen' => $this->revisionSeen,
        ];
        if ($this->contentId !== null) {
            $out['content_id'] = $this->contentId;
        }
        if ($this->modifiedAt !== null) {
            $out['modified_at'] = $this->modifiedAt;
        }
        if ($this->fields !== []) {
            $out['fields'] = $this->fields;
        }

        return $out;
    }
}
