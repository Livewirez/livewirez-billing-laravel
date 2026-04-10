<?php

namespace Livewirez\Billing\Lib\Polar\Data\Benefits;

use Livewirez\Billing\Lib\Polar\Data;


class BenefitsData extends Data
{
    public function __construct(
        /**
         * Creation timestamp of the object.
         */
        public readonly string $createdAt,
        /**
         * Last modification timestamp of the object.
         */
        public readonly ?string $modifiedAt,
        /**
         * The ID of the benefit.
         */
        public readonly string $id,
        /**
         * The type of the benefit.
         *
         * Available options: `custom`, `discord`, `github_repository`, `downloadables`, `license_keys`
         */
        public readonly string $type,
        /**
         * The description of the benefit.
         */
        public readonly string $description,
        /**
         * Whether the benefit is selectable when creating a product.
         */
        public readonly bool $selectable,
        /**
         * Whether the benefit is deletable.
         */
        public readonly bool $deletable,
        /**
         * The ID of the organization owning the benefit.
         */
        public readonly string $organizationId,
    ) {
        //
    }
}
