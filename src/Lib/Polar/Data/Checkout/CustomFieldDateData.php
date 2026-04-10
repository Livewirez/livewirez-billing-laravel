<?php 

namespace Livewirez\Billing\Lib\Polar\Data\Checkout;

use Livewirez\Billing\Lib\Polar\Data;

class CustomFieldDateData extends Data
{
    public function __construct(
        /**
         * The ID of the benefit.
         */
        public readonly string $id,
        /** @var array<string, string|int|bool> */
        public readonly array $metadata,
        /**
         * Allowed value: `"date"`
         */
        public readonly string $type,
        /**
         * Identifier of the custom field. It'll be used as key when storing the value.
         */
        public readonly string $slug,
        /**
         * Name of the custom field.
         */
        public readonly string $name,
        /**
         * The ID of the organization owning the custom field.
         */
        public readonly string $organizationId,
        public readonly CustomFieldNumberPropertiesData $properties,

                /**
         * Creation timestamp of the object.
         */
        public readonly string $createdAt,
        /**
         * Last modification timestamp of the object.
         */
        public readonly ?string $modifiedAt,
    ) {}
}