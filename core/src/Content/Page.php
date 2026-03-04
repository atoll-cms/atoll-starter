<?php

declare(strict_types=1);

namespace Atoll\Content;

final class Page
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $id,
        public readonly string $collection,
        public readonly string $slug,
        public readonly string $sourcePath,
        public readonly string $url,
        public readonly array $data,
        public readonly string $markdown,
        public readonly string $content
    ) {
    }

    public function title(): string
    {
        return (string) ($this->data['title'] ?? ucfirst($this->slug));
    }

    public function excerpt(): string
    {
        if (!empty($this->data['excerpt'])) {
            return (string) $this->data['excerpt'];
        }

        $text = trim(strip_tags($this->content));
        if (mb_strlen($text) <= 160) {
            return $text;
        }

        return mb_substr($text, 0, 157) . '...';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->data,
            'id' => $this->id,
            'collection' => $this->collection,
            'slug' => $this->slug,
            'url' => $this->url,
            'source_path' => $this->sourcePath,
            'content' => $this->content,
            'markdown' => $this->markdown,
            'title' => $this->title(),
            'excerpt' => $this->excerpt(),
        ];
    }
}
