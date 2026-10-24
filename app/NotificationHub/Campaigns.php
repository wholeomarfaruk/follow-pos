<?php

namespace App\NotificationHub;

use App\Models\NotificationCampaign;
use App\Models\NotificationDispatch;

/**
 * Starting, tracking and cancelling broadcast campaigns. Starting writes
 * every dispatch up front (category "marketing": opt-outs and quiet hours
 * apply); notifications:flush then sends them a batch per minute, which also
 * keeps the SMS gateway from being flooded.
 */
class Campaigns
{
    public function __construct(protected NotificationHub $hub, protected Segments $segments) {}

    /** @return int dispatches written */
    public function start(NotificationCampaign $campaign): int
    {
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return 0;
        }

        $campaign->forceFill(['status' => 'sending', 'started_at' => now()])->save();
        $this->hub->resetRunState();

        $recipients = $this->segments->recipients($campaign->segment, (array) $campaign->segment_filters);
        $channels = array_values(array_intersect((array) $campaign->channels, NotificationCatalog::CHANNELS));
        $written = 0;

        foreach ($recipients as $recipient) {
            $data = $recipient->data + ['name' => $recipient->name, 'phone' => $recipient->phone];

            foreach ($channels as $channel) {
                $written += $this->hub->queue($recipient, $channel, $this->hub->texts($campaign, $channel, $data), [
                    'campaign_id' => $campaign->id,
                    'event_key' => 'campaign',
                    'audience' => $campaign->segment,
                    'created_by' => $campaign->created_by,
                ], 'marketing', "campaign:{$campaign->id}", deliverNow: false) ? 1 : 0;
            }
        }

        $campaign->forceFill(['recipients_count' => $recipients->count()])->save();
        $this->refresh($campaign);

        return $written;
    }

    /** Re-count; marks the campaign sent once nothing is left waiting. */
    public function refresh(?NotificationCampaign $campaign): void
    {
        if (! $campaign) {
            return;
        }

        $campaign->refreshCounts();

        $waiting = $campaign->dispatches()->whereIn('status', ['queued', 'scheduled', 'sending'])->exists();
        if ($campaign->status === 'sending' && ! $waiting) {
            $campaign->forceFill(['status' => 'sent', 'finished_at' => now()])->save();
        }
    }

    public function cancel(NotificationCampaign $campaign): void
    {
        NotificationDispatch::where('campaign_id', $campaign->id)->whereIn('status', ['queued', 'scheduled'])
            ->update(['status' => 'skipped', 'error' => 'Campaign cancelled', 'updated_at' => now()]);

        $campaign->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
        $campaign->refreshCounts();
    }
}
