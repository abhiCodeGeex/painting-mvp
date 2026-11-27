<?php

namespace App\Filament\Resources\Sales\Orders\Widgets;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Money across all orders, on top of the Orders and Payments lists (cancelled orders are left out):
 * what the orders are worth, what they cost us, what has come in, and the profit.
 *
 * Expenses = artwork + materials + labour (the cost saved on each quotation).
 * Profit = order value before GST − expenses (GST is collected for the government, not ours).
 */
class OrderMoneyOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, int> */
    public static function totals(): array
    {
        $row = Order::query()
            ->join('quotes', 'quotes.id', '=', 'orders.quote_id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw('count(*) as orders, coalesce(sum(orders.grand_total), 0) as value, coalesce(sum(quotes.taxable), 0) as before_gst, coalesce(sum(quotes.total_cost), 0) as expenses')
            ->first();

        $payments = Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->select('payments.status', DB::raw('coalesce(sum(payments.amount), 0) as total'))
            ->groupBy('payments.status')
            ->pluck('total', 'status');

        $received = (int) ($payments[PaymentStatus::Received->value] ?? 0);

        return [
            'orders' => (int) $row->orders,
            'value' => (int) $row->value,
            'before_gst' => (int) $row->before_gst,
            'expenses' => (int) $row->expenses,
            'profit' => (int) $row->before_gst - (int) $row->expenses,
            'received' => $received,
            'to_check' => (int) ($payments[PaymentStatus::Submitted->value] ?? 0),
            'to_come' => max(0, (int) $row->value - $received),
        ];
    }

    protected function getStats(): array
    {
        $t = self::totals();
        $margin = $t['before_gst'] > 0 ? round($t['profit'] / $t['before_gst'] * 100, 1) : null;

        return [
            Stat::make('Orders value', Money::format($t['value']))
                ->description($t['orders'].' '.($t['orders'] === 1 ? 'order' : 'orders').' · incl. GST · cancelled not counted')
                ->icon('heroicon-o-shopping-bag')
                ->color('gray'),
            Stat::make('Total expenses', Money::format($t['expenses']))
                ->description('Artwork, materials and labour')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('gray'),
            Stat::make('Total received', Money::format($t['received']))
                ->description(Money::format($t['to_come']).' still to come'.($t['to_check'] > 0 ? ' · '.Money::format($t['to_check']).' to check' : ''))
                ->icon('heroicon-o-banknotes')
                ->color('success'),
            Stat::make('Profit', Money::format($t['profit']))
                ->description(($margin !== null ? $margin.'% · ' : '').'value before GST − expenses')
                ->icon('heroicon-o-arrow-trending-up')
                ->color($t['profit'] < 0 ? 'danger' : 'success'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
