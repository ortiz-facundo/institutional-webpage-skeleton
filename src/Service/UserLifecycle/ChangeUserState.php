<?php

declare(strict_types=1);

namespace App\Service\UserLifecycle;

use App\Entity\User;

/**
 * Template-method base for a User state transition.
 *
 * Concrete subclasses declare the target state (`USER_CHANGE_TO`) and the
 * allowed previous states (`PREVIOUS_VALID_STATES`) and implement the two
 * abstract methods:
 *   - checkValidations(): domain-specific guards (state precondition,
 *     password policy, last-admin protection, etc.)
 *   - changeStateTo(): the actual mutation on the entity plus any
 *     side-effects (audit log, notifications, token rotation).
 *
 * The order is: previous-state check (in `checkValidations`) →
 * `changeStateTo` runs (entity mutation + side-effects) → audit log entry
 * (subclass responsibility, fired from `logAudit` if overridden).
 *
 * `execute()` is the public entry point. Controllers inject the concrete
 * service directly (or via UserStateTransitionService). The entity does
 * NOT call these services — services are the orchestration layer, the
 * entity is pure data.
 */
abstract class ChangeUserState
{
    /**
     * The state this transition targets. Concrete subclass MUST set.
     */
    public const string USER_CHANGE_TO = '';

    /**
     * The states from which this transition is allowed. Concrete subclass
     * MUST set. An empty array means "no transition allowed".
     *
     * @var list<string>
     */
    public const array PREVIOUS_VALID_STATES = [];

    /**
     * Template-method entry point. Runs validations → mutation → audit
     * (if the subclass implements logAudit).
     */
    public function execute(User $user, mixed $context = null): void
    {
        $previous = $user->getState();
        $this->checkValidations($user, $context);
        $this->changeStateTo($user, $context);
        // Use `static::` (late static binding) so the constant resolves
        // to the concrete subclass's USER_CHANGE_TO, not the empty
        // placeholder on the abstract base.
        $this->logAudit($user, $previous, static::USER_CHANGE_TO, $context);
    }

    abstract protected function checkValidations(User $user, mixed $context = null): void;

    abstract protected function changeStateTo(User $user, mixed $context = null): void;

    /**
     * Optional override. Default: no-op. Subclasses that need an audit
     * entry override this method and call their injected logger.
     */
    protected function logAudit(User $user, string $from, string $to, mixed $context): void
    {
    }
}