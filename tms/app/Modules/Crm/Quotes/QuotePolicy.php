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
        return CrmVisibility::allows($user, Permissions::QUOTES_VIEW, $quote);
    }

    /**
     * A new quote (or a new version) on $opportunity: only by whoever sees
     * that opportunity (as QuoteRequest::opportunity() on store).
     */
    public function create(User $user, Opportunity $opportunity): bool
    {
        return $this->opportunityOpen($opportunity)
            && CrmVisibility::allows($user, Permissions::OPPORTUNITIES_VIEW, $opportunity)
            && CrmVisibility::quotable($user, Permissions::QUOTES_UPDATE, $opportunity);
    }

    public function update(User $user, Quote $quote): bool
    {
        return $this->editable($quote) && CrmVisibility::allows($user, Permissions::QUOTES_UPDATE, $quote);
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $this->editable($quote) && CrmVisibility::allows($user, Permissions::QUOTES_DELETE, $quote);
    }

    public function duplicate(User $user, Quote $quote): bool
    {
        $opportunity = $quote->opportunity;

        return $opportunity !== null && $this->view($user, $quote) && $this->create($user, $opportunity);
    }

    private function editable(Quote $quote): bool
    {
        $opportunity = $quote->opportunity;

        return ! $quote->trashed() && $opportunity !== null && $this->opportunityOpen($opportunity);
    }

    /**
     * Not trashed, on a fiche that is not trashed either.
     */
    private function opportunityOpen(Opportunity $opportunity): bool
    {
        if ($opportunity->trashed()) {
            return false;
        }
        $fiche = $opportunity->organisation_id !== null ? $opportunity->organisation : $opportunity->person;

        return $fiche !== null && ! $fiche->trashed();
    }
}
