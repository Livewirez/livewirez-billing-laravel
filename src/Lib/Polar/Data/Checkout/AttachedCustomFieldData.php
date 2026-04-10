<?php 

namespace Livewirez\Billing\Lib\Polar\Data\Checkout;

use Livewirez\Billing\Lib\Polar\Data;

class AttachedCustomFieldData extends Data
{
    public function __construct(
        /**
         * ID of the custom field.
         */
        public readonly string $customFieldId,
        /**
         * Schema for a custom field of type text.
         */
        public readonly CustomFieldTextData|CustomFieldNumberData|CustomFieldDateData|CustomFieldCheckboxData|CustomFieldSelectData $customField,
        /**
         * Order of the custom field in the resource.
         */
        public readonly int $order,
        /**
         * Whether the value is required for this custom field.
         */
        public readonly bool $required,
    ) {}
}