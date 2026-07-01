<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Administration\User;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig helpers for the initials-avatar partial used by the sidebar
 * footer and the self-service profile page.
 *
 *   {{ user_avatar_color(user) }}  → Tailwind colour suffix (e.g. "slate-700")
 *   {{ user_initials(user) }}      → 2-char uppercase string
 *
 * The colour is deterministic per email (crc32 mod palette length) so
 * the same user always gets the same colour across pages and refreshes.
 * Initials come from the user's `name`, falling back to the first two
 * chars of the email when no name is set.
 *
 * Palette stays on neutrals + a few muted accents that don't clash with
 * the amber-600/700 chrome accent or the violet-700 impersonation icon.
 */
final class UserAvatarExtension
{
    private const array PALETTE = [
        'slate-700',  'stone-700',  'zinc-700',   'neutral-700',
        'rose-800',   'emerald-800', 'teal-800',  'sky-800',
        'indigo-800', 'amber-800',
    ];

    #[AsTwigFunction('user_avatar_color')]
    public function color(User $user): string
    {
        $index = crc32($user->getEmail()) % count(self::PALETTE);
        return self::PALETTE[$index];
    }

    #[AsTwigFunction('user_initials')]
    public function initials(User $user): string
    {
        $source = $user->getName() !== null && $user->getName() !== ''
            ? $user->getName()
            : $user->getEmail();

        return strtoupper(substr(trim($source), 0, 2));
    }
}