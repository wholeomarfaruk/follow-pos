<?php

namespace App\Actions\Profiles;

use App\Models\MasterProfile;
use App\Services\Profiles\ProfileBalances;
use App\Sms\SmsManager;
use App\Support\AccountsSettings;
use App\Support\Bdt;
use App\Support\BdPhone;
use Throwable;

/**
 * Due reminders and balance statements by SMS, through the app's SMS
 * gateway manager — the gateway and sender ID chosen in Accounts › Settings ›
 * SMS (it queues when configured to). Every send — or failure — is written
 * to the profile's timeline.
 */
class SendProfileSms
{
    public function __construct(protected SmsManager $sms, protected ProfileBalances $balances) {}

    public function dueReminder(MasterProfile $profile): bool
    {
        $due = (float) $this->balances->forProfile($profile->id)->receivable;

        if ($due <= 0.5) {
            return false;
        }

        $text = AccountsSettings::sms('due', ['name' => $profile->display_name, 'due' => $due, 'amount' => $due, 'date' => now()->format('d M Y')]);

        return $this->send($profile, $text, 'Due reminder SMS sent');
    }

    public function statement(MasterProfile $profile, float $closing): bool
    {
        $site = \App\Models\Setting::get('site_name', config('app.name'), 'general') ?: config('app.name');
        $side = $closing > 0 ? 'আপনার কাছে পাওনা' : 'আপনাকে দেওয়া বাকি';
        $text = abs($closing) < 0.5
            ? "প্রিয় {$profile->display_name}, {$site}-এর সাথে আপনার হিসাব এখন Clear। ধন্যবাদ।"
            : "প্রিয় {$profile->display_name}, " . now()->format('d M Y') . " পর্যন্ত {$site}-এর হিসাবে {$side} " . Bdt::money(abs($closing)) . '।';

        return $this->send($profile, $text, 'Statement sent by SMS');
    }

    public function send(MasterProfile $profile, string $text, string $activity): bool
    {
        $to = BdPhone::normalize($profile->phone);

        if (! $to) {
            return false;
        }

        // Settings › SMS & reminders: gateway off → keep the text on the timeline, send nothing.
        $gateway = AccountsSettings::smsGateway();
        if ($gateway === 'log') {
            $profile->logActivity('sms', "{$activity} — not sent (Accounts SMS off)", ['to' => $to, 'text' => $text]);

            return false;
        }

        try {
            $response = $this->sms->sendVia($gateway === 'system' ? null : $gateway, $to, $text, (string) AccountsSettings::get('sms_mask', ''), 'accounts');
            $ok = $response->success ?? true;
        } catch (Throwable $e) {
            report($e);
            $ok = false;
        }

        $profile->logActivity('sms', $ok ? $activity : "{$activity} — failed", ['to' => $to]);

        return $ok;
    }
}
