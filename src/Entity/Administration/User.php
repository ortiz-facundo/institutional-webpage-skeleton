<?php

declare(strict_types=1);

namespace App\Entity\Administration;

use App\Repository\Administration\UserRepository;
use App\Security\AdminPolicy;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity(
    fields: ['email'],
    message: 'app.user.email.duplicate',
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    /**
     * Possible account states. The values are the constant NAMES so that
     * `SELECT * FROM app_user WHERE state = '...'` is self-documenting and
     * there's no place where the string and the PHP constant can drift.
     */
    public const string USER_STATE_PENDING  = 'USER_STATE_PENDING';
    public const string USER_STATE_ACTIVE   = 'USER_STATE_ACTIVE';
    public const string USER_STATE_DISABLED = 'USER_STATE_DISABLED';

    /** @var list<string> */
    public const array USER_STATES = [
        self::USER_STATE_PENDING,
        self::USER_STATE_ACTIVE,
        self::USER_STATE_DISABLED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'email', type: 'string', length: 180, unique: true)]
    private string $email;

    #[ORM\Column(name: 'name', type: 'string', length: 120, nullable: true)]
    private ?string $name = null;

    /**
     * Nullable: Pending users haven't picked a password yet. The
     * `?string` and `string` are reconciled by `setPassword()` which
     * is only ever called with a real hashed value.
     */
    #[ORM\Column(name: 'password', type: 'string', nullable: true)]
    private ?string $password = null;

    /**
     * Account state. Defaults to USER_STATE_PENDING so a freshly created
     * user (without a confirmed password) can't log in. The migration
     * marks all pre-existing users as USER_STATE_ACTIVE.
     */
    #[ORM\Column(name: 'state', type: 'string', length: 32, options: ['default' => self::USER_STATE_PENDING])]
    private string $state = self::USER_STATE_PENDING;

    /**
     * User's preferred UI language. `null` = "use the system default"
     * (Spanish for unauthenticated requests; resolved by LocaleResolver).
     * The column is nullable so that "unset" is a valid state distinct
     * from any concrete value.
     */
    #[ORM\Column(name: 'locale', type: 'string', length: 5, nullable: true)]
    private ?string $locale = null;

    /** @var Collection<int, Role> */
    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_role')]
    private Collection $roles;

    public function __construct()
    {
        $this->roles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = mb_strtolower(trim($email));
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $hashed): self
    {
        $this->password = $hashed;
        return $this;
    }

    /**
     * Internal. Always go through the ChangeUserState* services so the
     * validations, audit, and side effects (token rotation, email) run.
     * Public because the services legitimately call it; direct use from
     * controllers/forms is discouraged.
     */
    public function setState(string $state): self
    {
        if (!in_array($state, self::USER_STATES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown user state "%s". Allowed: %s.',
                $state,
                implode(', ', self::USER_STATES)
            ));
        }
        $this->state = $state;
        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function isPending(): bool
    {
        return $this->state === self::USER_STATE_PENDING;
    }

    public function isActive(): bool
    {
        return $this->state === self::USER_STATE_ACTIVE;
    }

    public function isDisabled(): bool
    {
        return $this->state === self::USER_STATE_DISABLED;
    }

    /**
     * Whether this user holds any admin-tier permission (see
     * {@see AdminPolicy}). Twig-callable so row-action partials can
     * gate a button directly off the row entity (e.g. hide "impersonate"
     * on admin rows) without a controller-side hidden-id hook.
     */
    public function isAdmin(): bool
    {
        return AdminPolicy::isAdmin($this);
    }

    /** @var list<string> Locales the system knows about. Keep aligned with
     *  the `choices` on Account\Form\LocaleType and with the keys in
     *  `translations/<module>/messages.<locale>.yaml`. */
    public const array SUPPORTED_LOCALES = ['en', 'es'];

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * @throws \InvalidArgumentException when $locale is not null and not in
     *                                   self::SUPPORTED_LOCALES
     */
    public function setLocale(?string $locale): self
    {
        if ($locale !== null && !in_array($locale, self::SUPPORTED_LOCALES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported locale "%s". Allowed: %s, or null.',
                $locale,
                implode(', ', self::SUPPORTED_LOCALES)
            ));
        }
        $this->locale = $locale;
        return $this;
    }

    /**
     * Whether this user is allowed to authenticate through the login
     * firewall. Only USER_STATE_ACTIVE users pass — Pending users have
     * not set a password yet, Disabled users are blocked by admin.
     */
    public function isLoginAllowed(): bool
    {
        return $this->state === self::USER_STATE_ACTIVE;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Symfony's `is_granted()` only sees the strings returned here, not
     * the role/permission relationship stored in the database. So we
     * flatten: for every role the user has, we return the role name AND
     * every permission attached to that role. That way
     * `is_granted(Permission::PERMISSION_VIEW_BACKOFFICE)` works for
     * any user that has a role granting that permission.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $names = ['ROLE_USER'];
        foreach ($this->roles as $role) {
            $names[] = $role->getName();
            foreach ($role->getPermissions() as $permission) {
                $names[] = $permission->getName();
            }
        }
        return array_values(array_unique($names));
    }

    /** @return Collection<int, Role> */
    public function getRoleEntities(): Collection
    {
        return $this->roles;
    }

    public function addRole(Role $role): self
    {
        if (!$this->roles->contains($role)) {
            $this->roles->add($role);
        }
        return $this;
    }

    public function removeRole(Role $role): self
    {
        $this->roles->removeElement($role);
        return $this;
    }

    public function eraseCredentials(): void
    {
    }
}
