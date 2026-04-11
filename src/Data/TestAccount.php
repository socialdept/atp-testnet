<?php

declare(strict_types=1);

namespace SocialDept\AtpTestnet\Data;

class TestAccount
{
    public function __construct(
        public readonly string $did,
        public readonly string $handle,
        public readonly string $email,
        public readonly string $password,
        public readonly ?string $accessJwt = null,
        public readonly ?string $refreshJwt = null,
    ) {}
}
