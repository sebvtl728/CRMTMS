<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Visibility\CrmVisibility;

/**
 * Permissions crm.quotes.* (creating is part of crm.quotes.update), on a
 * visible opportunity (invisible quotes are already 404 at route
 * binding) whose fiche is not trashed. Whether the quote is still a draft is
 * checked by the controller (409, not a matter of rights).
 */
class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::QUOTES_VIEW);
    }

    public function view(User $user, Quote $quote): bool
    {
        return $user->can(Permissions::QUOTES_VIEW) && $this->visible($user, $quote);
    }

    /**
     * A new quote (or a new version) on $opportunity.
     */
    public function create(User $user, Opportunity $opportunity): bool
    {
        return $user->can(Permissions::QUOTES_UPDATE) && $this->opportunityOpen($user, $opportunity);
    }

    public function update(User $user, Quote $quote): bool
    {
        return $user->can(Permissions::QUOTES_UPDATE) && $this->editable($user, $quote);
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $user->can(Permissions::QUOTES_DELETE) && $this->editable($user, $quote);
    }

    public function duplicate(User $user, Quote $quote): bool
    {
        $opportunity = $quote->opportunity;

        return $opportunity !== null && $this->visible($user, $quote) && $this->create($user, $opportunity);
    }

    private function editable(User $user, Quote $quote): bool
    {
        $opportunity = $quote->opportunity;

        return ! $quote->trashed() && $opportunity !== null && $this->visible($user, $quote) && $this->opportunityOpen($user, $opportunity);
    }

    /**
     * Visible, not trashed, on a fiche that is not trashed either.
     */
    private function opportunityOpen(User $user, Opportunity $opportunity): bool
    {
        if ($opportunity->trashed()) {
            return false;
        }
        $fiche = $opportunity->organisation_id !== null ? $opportunity->organisation : $opportunity->person;

        return $fiche !== null && ! $fiche->trashed()
            && (! CrmVisibility::restricts($user) || CrmVisibility::opportunities(Opportunity::query(), $user)->whereKey($opportunity->getKey())->exists());
    }

    private function visible(User $user, Quote $quote): bool
    {
        return ! CrmVisibility::restricts($user)
            || CrmVisibility::quotes(Quote::withTrashed(), $user)->whereKey($quote->getKey())->exists();
    }
}
