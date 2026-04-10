<?php

namespace Livewirez\Billing\Lib\Polar\Data\Products;

use Livewirez\Billing\Lib\Polar\Data;



class ProductFileData extends Data
{
    public function __construct(
        /**
         * The ID of the product media.
         */
        public readonly string $id,
        public readonly string $organizationId,
        public readonly string $name,
        public readonly string $path,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly ?string $storageVersion,
        public readonly ?string $checksumEtag,
        public readonly ?string $checksumSha256Base64,
        public readonly ?string $checksumSha256Hex,
        public readonly ?string $lastModifiedAt,
        public readonly ?string $version,
        /**
         * Allowed value: `"product_media"`
         */
        public readonly string $service,
        public readonly bool $isUploaded,
        public readonly string $createdAt,
        public readonly string $sizeReadable,
        public readonly ?string $publicUrl,
    ) {}
}
