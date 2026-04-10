<?php

namespace Livewirez\Billing\Lib\Polar\Data\Users;

use Livewirez\Billing\Lib\Polar\Data;


class UserData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly string $publicName,
        public readonly ?string $avatarUrl,
        public readonly ?string $githubUsername,
    ) {}
}
