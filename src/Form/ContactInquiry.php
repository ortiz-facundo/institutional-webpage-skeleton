<?php

declare(strict_types=1);

namespace App\Form;

// Request-scoped data only. Contact mailing does not require database storage.
final class ContactInquiry
{
    public ?string $name = null;
    public ?string $email = null;
    public ?string $phone = null;
    public ?string $message = null;
}
