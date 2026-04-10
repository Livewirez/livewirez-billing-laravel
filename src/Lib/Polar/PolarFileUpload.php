<?php

namespace Livewirez\Billing\Lib\Polar;

use Stringable;

class PolarFileUpload implements Stringable
{
    public function __construct(
        public string | array $name,
        /**@var string|resource $contents */
        public mixed $contents = '',
        public ?string $filename = null,
        public string $mimeType = '',
        public array $headers = []
    ) {
        if (! (is_string($this->contents) || is_resource($this->contents))) {
            throw new \InvalidArgumentException('Contents must be a string or a resource.');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            contents: $data['contents'] ?? '',
            filename: $data['filename'] ?? null,
            mimeType: $data['mime_type'] ?? '',
            headers: $data['headers'] ?? []
        );
    }

    public function updateHeaders(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    public function __toString(): string 
    {
        return $this->name . ' ' . $this->filename;
    }
}