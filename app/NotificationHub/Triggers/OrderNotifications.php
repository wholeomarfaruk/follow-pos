<?php

namespace App\NotificationHub\Triggers;

use App\Enums\Sales\OrderStatus;
use App\Models\NotificationRule;
use App\Models\Order;
use App\NotificationHub\NotificationHub;
use App\NotificationHub\Recipient;
use App\Support\BdPhone;

/**
 * Order events for the Notification Hub, fired from Order::booted():
 * order.placed + order.big once the order is complete (after commit and
 * after the response, so items and totals are saved), order.delivered /
 * order.cancelled on the status change. Never throws.
 */
class OrderNotifications
{
    public function __construct(protected NotificationHub $hub) {}

    public function placed(?Order $order): void
    {
        if (! $order) {
            return;
        }

        rescue(function () use ($order) {
            $order->loadMissing(['customer.masterProfile.user', 'customer.user', 'shippingAddress']);
            $context = $this->context($order) + ['dedupe' => "order:{$order->id}"];
            $source = $order->source?->value ?? (string) $order->source;
            $total = (float) $order->total_amount;

            $this->hub->fire('order.placed', $context + [
                'when' => fn (NotificationRule $rule) => ! in_array($source, (array) $rule->condition('exclude_sources', []), true),
            ]);

            $this->hub->fire('order.big', $context + [
                'when' => fn (NotificationRule $rule) => $total >= (float) $rule->condition('min_total', 20000),
            ]);
        }, null, true);
    }

    public function statusChanged(Order $order): void
    {
        $event = match ($order->status) {
            OrderStatus::DELIVERED => 'order.delivered',
            OrderStatus::CANCELLED => 'order.cancelled',
            default => null,
        };

        if (! $event) {
            return;
        }

        rescue(function () use ($order, $event) {
            $order->loadMissing(['customer.masterProfile.user', 'customer.user', 'shippingAddress']);
            $this->hub->fire($event, $this->context($order) + ['dedupe' => "{$event}:{$order->id}"]);
        }, null, true);
    }

    protected function context(Order $order): array
    {
        $customer = $this->customerOf($order);
        $source = $order->source;

        return [
            'customer' => $customer,
            'data' => $order->smsPlaceholders() + [
                'name' => $customer?->name ?: 'Customer',
                'source' => $source && method_exists($source, 'label') ? $source->label() : ucfirst((string) ($source?->value ?? $source)),
            ],
            'related' => $order,
            'url' => rescue(fn () => route('admin.sales.orders.show', $order->id), null, false),
        ];
    }

    /** The order's customer; a guest order still reaches the phone on its shipping address. */
    protected function customerOf(Order $order): ?Recipient
    {
        $address = $order->shippingAddress;
        $recipient = $order->customer ? Recipient::fromCustomer($order->customer) : null;

        if (! $recipient) {
            return $address?->phone ? new Recipient((string) ($address->name ?: 'Customer'), $address->phone) : null;
        }

        if (! $recipient->phone && $address?->phone) {
            $recipient->phone = BdPhone::normalize($address->phone) ?: null;
        }

        return $recipient;
    }
}
