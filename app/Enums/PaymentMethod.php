<?php

namespace App\Enums;

/**
 * How money moved — the one list every module uses: sales (POS, order
 * payments / refunds, checkout, courier COD), purchase (supplier payments,
 * advances, "paid now" on a bill) and Accounts vouchers. Stored as the
 * string value in order_payments.payment_method,
 * supplier_invoices.payment_method and journal_entries.payment_method.
 *
 * The method is what the person chose (bKash, cheque, …); the cash/bank
 * Account is where it landed. accountSubtype() / forAccountSubtype() map
 * between the two so a form can pre-pick one from the other.
 */
enum PaymentMethod: string
{
    case CASH         = 'cash';
    case BKASH        = 'bkash';
    case NAGAD        = 'nagad';
    case ROCKET       = 'rocket';
    case BANK         = 'bank';
    case CARD         = 'card';
    case CHEQUE       = 'cheque';
    case STORE_CREDIT = 'store_credit';
    case COD          = 'cod';

    public function label(): string
    {
        return match ($this) {
            self::CASH         => 'Cash',
            self::BKASH        => 'bKash',
            self::NAGAD        => 'Nagad',
            self::ROCKET       => 'Rocket',
            self::BANK         => 'Bank Transfer',
            self::CARD         => 'Card',
            self::CHEQUE       => 'Cheque',
            self::STORE_CREDIT => 'Store Credit',
            self::COD          => 'Cash on Delivery',
        };
    }

    /** Placeholder for the reference field, or null when the method has none (cash). */
    public function referenceLabel(): ?string
    {
        return match ($this) {
            self::BKASH, self::NAGAD, self::ROCKET => 'Transaction ID',
            self::BANK                              => 'Bank reference',
            self::CARD                              => 'Card / approval no.',
            self::CHEQUE                            => 'Cheque no.',
            default                                 => null,
        };
    }

    /** The cash/bank Account subtype money paid this way lands in (null: it moves no cash). */
    public function accountSubtype(): ?string
    {
        return match ($this) {
            self::CASH                              => 'cash',
            self::BANK, self::CARD, self::CHEQUE    => 'bank',
            self::BKASH, self::NAGAD, self::ROCKET  => 'mobile_banking',
            self::STORE_CREDIT, self::COD           => null,
        };
    }

    /**
     * Best guess at the method from the Account the money moved through,
     * for records made without a method picker (refunds, bulk-order
     * advances, older data).
     */
    public static function forAccountSubtype(?string $subtype): self
    {
        return match ($subtype) {
            'bank'           => self::BANK,
            'mobile_banking' => self::BKASH,
            default          => self::CASH,
        };
    }

    /**
     * Same, from the Account itself (array or model with name / subtype):
     * a wallet named after a provider ("Nagad Personal") gives that provider
     * instead of the bKash default.
     */
    public static function forAccount(mixed $account): self
    {
        $subtype = data_get($account, 'subtype');
        $name = strtolower((string) data_get($account, 'name'));

        if ($subtype === 'mobile_banking') {
            foreach ([self::BKASH, self::NAGAD, self::ROCKET] as $wallet) {
                if (str_contains($name, $wallet->value)) {
                    return $wallet;
                }
            }
        }

        return self::forAccountSubtype($subtype);
    }

    /**
     * The account to pre-select for this method: one of the matching
     * subtype named after the method (e.g. "bKash Merchant" for bKash),
     * else the first of that subtype. Accounts may be arrays or models with
     * id / name / subtype.
     */
    public function pickAccount(iterable $accounts): ?int
    {
        $matching = collect($accounts)->filter(fn ($a) => data_get($a, 'subtype') === $this->accountSubtype());

        $pick = $matching->first(fn ($a) => str_contains(strtolower((string) data_get($a, 'name')), $this->value))
            ?? $matching->first();

        return $pick ? (int) data_get($pick, 'id') : null;
    }

    /** Methods a person picks when money changes hands (not COD / store credit, which the system records). */
    public static function manual(): array
    {
        return array_values(array_filter(self::cases(), fn (self $m) => $m->accountSubtype() !== null));
    }

    /** Checkout's customer-facing choices. */
    public static function checkout(): array
    {
        return [self::COD, self::BKASH];
    }

    /** @param  array<int, self>|null  $cases  value => label for a <select>, default manual() */
    public static function options(?array $cases = null): array
    {
        return collect($cases ?? self::manual())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }

    /** @param  array<int, self>|null  $cases */
    public static function values(?array $cases = null): array
    {
        return array_map(fn (self $m) => $m->value, $cases ?? self::cases());
    }
}
