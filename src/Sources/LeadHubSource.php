<?php

namespace Goldnead\Notifications\Sources;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Notifications\Contracts\DigestSource;
use Goldnead\Notifications\NotificationManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\User;

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
                ->defaultChannels(['in_app', 'mail'])
                ->appliesTo(fn (Identity $recipient): bool => self::isStaff($recipient));
        });

        $notifications->registerType('crm.followup_due', function ($type): void {
            $type->label('Follow-up fällig')
                ->defaultChannels(['in_app', 'digest'])
                ->appliesTo(fn (Identity $recipient): bool => self::isStaff($recipient));
        });
    }

    /**
     * Internal CRM types reach people on the team, not contacts. "Has an
     * account" is not that line: on a site where every subscriber has one
     * (ChoirLive), the preference page offered them "Lead zugewiesen". The
     * line is whether the user may view LeadHub. Only the preference matrix
     * reads this; sending is unchanged.
     */
    public static function isStaff(Identity $recipient): bool
    {
        if ($recipient->userId === null) {
            return false;
        }

        $user = User::find($recipient->userId);

        return $user !== null && ($user->isSuper() || $user->hasPermission('view leadhub'));
    }

    /**
     * @return array{line?: string}
     */
    public function collect(Identity $recipient, Carbon $since, Carbon $until): array
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
            // Newly overdue only. A follow-up that fell due before the last
            // digest was already reported in it and is not news a second time,
            // however open it still is. Without this lower bound a single
            // forgotten follow-up keeps the weekly mail going out forever.
            ->where('leadhub_followups.due_at', '>=', $since)
            ->where('leadhub_followups.due_at', '<', $until);

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

        // A sentence under `line`, because it goes into a mail a person reads.
        // The template used to receive the count and had nothing to do with it
        // but print it as JSON.
        return ['line' => trans_choice('notifications::mail.leadhub_overdue_followups', $overdue, ['count' => $overdue])];
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
