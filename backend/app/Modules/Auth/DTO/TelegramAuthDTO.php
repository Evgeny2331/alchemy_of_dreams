<?php

namespace App\Modules\Auth\DTO;

class TelegramAuthDTO
{
    public function __construct(
        public readonly string $initData,
        public readonly ?int $telegramId = null,
        public readonly ?string $username = null,
        public readonly ?string $firstName = null
    ) {}
}
