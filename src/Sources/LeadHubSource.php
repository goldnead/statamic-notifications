<?php

namespace Goldnead\Notifications\Sources;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\DigestSource;
use Goldnead\Notifications\NotificationManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bundled source for `goldnead/statamic-leadhub`, registered only when that
 * addon is installed.
 *
 * LeadHub already grew its own notifier (`LeadHubNotifier`, three Laravel mail
 * notifications and a follow-up digest command) — the second independent
 * invention of this pattern in the family, which is what justifies a shared one.
 * This source does not replace it: it contributes open follow-ups to the digest,
 * which are precisely the thing no notification was ever written for.
 *
 * Deliberately reads through the query builder rather than LeadHub's models, so
 * a version bump on the other side cannot break the digest.
 */
class LeadHubSource implements DigestSource
{
    public static function registerTypes(NotificationManager $notifications): void
    {
        $notifications->registerType('crm.lead_assigned', function ($type): void {
            $type->label('Lead zugewiesen')
                ->defaultChannels(['in_app', 'mail']);
        });

        $notifications->registerType('crm.followup_due', function ($type): void {
            $type->label('Follow-up fällig')
                ->defaultChannels(['in_app', 'digest']);
        });
    }

    public function collect(Identity $recipient, Carbon $windowStart, Carbon $windowEnd): array
    {
        if ($recipient->userId === null || ! $this->tableExists('leadhub_followups')) {
            return [];
        }

        // A follow-up has no owner of its own — the contact it belongs to does.
        // Hence the join rather than a column on the follow-up.
        $query = DB::table('leadhub_followups')
            ->join('leadhub_contacts', 'leadhub_contacts.id', '=', 'leadhub_followups.contact_id')
            ->where('leadhub_contacts.assigned_to', $recipient->userId)
            ->whereNull('leadhub_followups.completed_at')
            ->where('leadhub_followups.due_at', '<', $windowEnd);

        // Going through the query builder bypasses LeadHub's global brand scope,
        // so the brand filter has to be applied by hand. Without it this source
        // would happily count another brand's follow-ups into someone's digest.
        $query->where('leadhub_contacts.brand_id', BrandContext::hasCurrent()
            ? BrandContext::currentId()
            : BrandContext::defaultId());

        $overdue = $query->count();

        if ($overdue === 0) {
            return [];
        }

        return ['overdue_followups' => $overdue];
    }

    protected function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
