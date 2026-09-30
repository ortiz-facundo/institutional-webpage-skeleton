<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/** Text-backed select whose options a page-specific controller may populate. */
final class SelectTextType extends AbstractType
{
    public function getParent(): string { return TextType::class; }

    public function getBlockPrefix(): string { return 'select_text'; }
}
