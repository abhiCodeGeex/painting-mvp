<?php

use App\Enums\Role;
use App\Enums\StockStatus;
use App\Enums\StockUnit;
use App\Filament\Resources\Framing\Mats\MatResource;
use App\Filament\Resources\Inventory\StockItems\Pages\ManageStockItems;
use App\Filament\Resources\Inventory\StockItems\StockItemResource;
use App\Filament\Resources\Inventory\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\Inventory\StockMovements\StockMovementResource;
use App\Filament\Widgets\LowStockItems;
use App\Filament\Widgets\StockOverview;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Mat;
use App\Models\StockItem;
use App\Models\Substrate;
use App\Models\User;
use App\Notifications\LowStockAlert;
use Database\Seeders\DemoStockSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\StateSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();

    $this->admin = User::factory()->staff()->create();
    $this->admin->assignRole(Role::SuperAdmin->value);

    $this->seed([StateSeeder::class, MasterDataSeeder::class]);
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin);
});

function stockFor(string $model, string $key): StockItem
{
    $item = $model::query()->where($model === Glazing::class || $model === Substrate::class ? 'name' : 'code', $key)->firstOrFail();

    return $item->stockItem()->firstOrFail();
}

test('every physical catalogue item gets a stock row in the right unit', function () {
    expect(Frame::count())->toBe(StockItem::where('stockable_type', Frame::class)->count())
        ->and(Mat::count())->toBe(StockItem::where('stockable_type', Mat::class)->count())
        ->and(stockFor(Frame::class, 'FR-OAK-25')->unit)->toBe(StockUnit::Metre)
        ->and(stockFor(Mat::class, 'MAT-WHT')->unit)->toBe(StockUnit::Sheet)
        ->and(stockFor(Substrate::class, 'Canvas roll (unstretched)')->unit)->toBe(StockUnit::Roll);

    // "No glazing" is not a physical item.
    expect(Glazing::where('name', 'No glazing')->first()->stockItem()->exists())->toBeFalse();
});

test('status is out at zero, low under the threshold, ok from the threshold', function () {
    expect(StockStatus::for(0, 20))->toBe(StockStatus::Out)
        ->and(StockStatus::for(0.5, 20))->toBe(StockStatus::Low)
        ->and(StockStatus::for(19.99, 20))->toBe(StockStatus::Low)
        ->and(StockStatus::for(20, 20))->toBe(StockStatus::Ok)
        ->and(StockStatus::for(4, 3))->toBe(StockStatus::Ok);

    $item = stockFor(Mat::class, 'MAT-WHT');
    expect($item->low_stock_threshold)->toBe(20.0)
        ->and($item->status())->toBe(StockStatus::Out);
});

test('adjusting stock logs movements and never goes below zero', function () {
    $item = stockFor(Frame::class, 'FR-BLK-20');

    $item->adjust(30, 'Delivery', $this->admin);
    $item->adjust(-12.5, 'Used on job', $this->admin);
    $item->countAs(40, null, $this->admin);

    expect($item->fresh()->quantity)->toBe(40.0)
        ->and($item->movements()->count())->toBe(3)
        ->and($item->movements()->first()->note)->toBe('Stock count')
        ->and($item->movements()->first()->change)->toBe(22.5);

    expect(fn () => $item->adjust(-41))->toThrow(ValidationException::class);
    expect($item->fresh()->quantity)->toBe(40.0);
});

test('admins are notified once when stock crosses below the threshold', function () {
    $item = stockFor(Mat::class, 'MAT-IVR');
    $item->adjust(50);
    Notification::assertNothingSent();

    $item->adjust(-35); // 50 -> 15: crosses into Low
    Notification::assertSentToTimes($this->admin, LowStockAlert::class, 1);

    $item->adjust(-5);  // 15 -> 10: still Low, no new alert
    $item->adjust(2);   // 10 -> 12
    Notification::assertSentToTimes($this->admin, LowStockAlert::class, 1);

    $item->adjust(-12); // 12 -> 0: now Out, alert again
    Notification::assertSentToTimes($this->admin, LowStockAlert::class, 2);
    Notification::assertSentTo($this->admin, LowStockAlert::class, fn (LowStockAlert $n) => $n->status === StockStatus::Out);

    // Customers never get stock alerts.
    $customer = User::factory()->create();
    Notification::assertNotSentTo($customer, LowStockAlert::class);
});

test('raising the alert level above the stock also alerts', function () {
    $item = stockFor(Mat::class, 'MAT-GRY');
    $item->adjust(25);
    $item->update(['low_stock_threshold' => 30]);

    Notification::assertSentToTimes($this->admin, LowStockAlert::class, 1);
});

test('the inventory lists stock with filters and an adjust action', function () {
    $this->seed(DemoStockSeeder::class);
    Notification::assertNothingSent(); // seeding does not alert

    $gold = stockFor(Frame::class, 'FR-GLD-40');   // out
    $oak = stockFor(Frame::class, 'FR-OAK-25');    // low
    $black = stockFor(Frame::class, 'FR-BLK-20');  // ok

    $this->get(StockItemResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Antique gold ornate 40 mm')
        ->assertSee('stock-row-out')
        ->assertSee('stock-row-low');

    Livewire::test(ManageStockItems::class)
        ->filterTable('status', StockStatus::Low->value)
        ->assertCanSeeTableRecords([$oak])
        ->assertCanNotSeeTableRecords([$gold, $black])
        ->filterTable('status', StockStatus::Out->value)
        ->assertCanSeeTableRecords([$gold])
        ->assertCanNotSeeTableRecords([$oak, $black]);

    Livewire::test(ManageStockItems::class)
        ->callTableAction('adjustStock', $gold, data: ['direction' => 'add', 'quantity' => 60, 'note' => 'Supplier delivery'])
        ->assertHasNoTableActionErrors();

    expect($gold->fresh()->quantity)->toBe(60.0)
        ->and($gold->movements()->first()->note)->toBe('Supplier delivery')
        ->and($gold->movements()->first()->user_id)->toBe($this->admin->id);

    expect(StockItemResource::getNavigationBadge())->not->toBeNull();
});

test('archived items drop out of the inventory', function () {
    $mat = Mat::where('code', 'MAT-GRY')->firstOrFail();
    $stock = $mat->stockItem;
    $mat->delete();

    Livewire::test(ManageStockItems::class)->assertCanNotSeeTableRecords([$stock]);
});

test('the dashboard shows stock warnings and the restock list', function () {
    $this->seed(DemoStockSeeder::class);

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(StockOverview::class)
        ->assertSeeLivewire(LowStockItems::class);

    Livewire::test(StockOverview::class)
        ->assertSee('Out of stock')
        ->assertSee('Low stock')
        ->assertSee('stock-stat-out')
        ->assertSee('stock-stat-low');

    Livewire::test(LowStockItems::class)
        ->assertCanSeeTableRecords([stockFor(Frame::class, 'FR-GLD-40'), stockFor(Mat::class, 'MAT-BLK')])
        ->assertCanNotSeeTableRecords([stockFor(Frame::class, 'FR-BLK-20')]);
});

test('item lists show the stock column', function () {
    $this->seed(DemoStockSeeder::class);

    $this->get(MatResource::getUrl('index'))->assertOk()->assertSee('4 sheets');
});

test('a stock count uses the stock in the database, not an out-of-date copy', function () {
    $item = stockFor(Mat::class, 'MAT-WHT');
    $stale = StockItem::find($item->id);   // e.g. a page opened before someone else changed it

    $item->adjust(30);                     // another user: 0 -> 30
    $movement = $stale->countAs(25);       // counted 25

    expect($item->fresh()->quantity)->toBe(25.0)
        ->and($movement->change)->toBe(-5.0)
        ->and($stale->quantity)->toBe(25.0);
});

test('a glazing switched to "No glazing" loses its empty stock row, and gets one back when switched again', function () {
    $glazing = Glazing::where('name', 'Clear acrylic 3 mm')->firstOrFail();
    expect($glazing->stockItem()->exists())->toBeTrue();

    $glazing->update(['material' => 'none']);
    expect($glazing->stockItem()->exists())->toBeFalse();

    $glazing->update(['material' => 'acrylic']);
    expect($glazing->stockItem()->exists())->toBeTrue();

    // Once it has history the row is kept.
    $glazing->stockItem->adjust(5);
    $glazing->update(['material' => 'none']);
    expect($glazing->stockItem()->exists())->toBeTrue();
});

test('the movements log lists every change and filters by direction', function () {
    $oak = stockFor(Frame::class, 'FR-OAK-25');
    $in = $oak->adjust(40, 'Delivery', $this->admin);
    $out = $oak->adjust(-7.5, 'Used on job', $this->admin);

    $this->get(StockMovementResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Natural oak 25 mm')
        ->assertSee('Used on job');

    Livewire::test(ListStockMovements::class)
        ->filterTable('direction', 'out')
        ->assertCanSeeTableRecords([$out])
        ->assertCanNotSeeTableRecords([$in])
        ->searchTable('oak')
        ->assertCanSeeTableRecords([$out]);
});

test('alert level can be set on several items at once, and the list downloads as CSV', function () {
    $mats = StockItem::where('stockable_type', Mat::class)->get();
    $mats->each->adjust(10);

    Livewire::test(ManageStockItems::class)
        ->callTableBulkAction('bulkThreshold', $mats, data: ['low_stock_threshold' => 5]);

    expect(StockItem::where('stockable_type', Mat::class)->pluck('low_stock_threshold')->unique()->all())->toBe([5.0])
        ->and(StockItem::where('stockable_type', Mat::class)->get()->every(fn ($i) => $i->status() === StockStatus::Ok))->toBeTrue();

    Livewire::test(ManageStockItems::class)
        ->filterTable('stockable_type', Mat::class)
        ->callAction('exportCsv')
        ->assertFileDownloaded('stock-'.now()->format('Y-m-d').'.csv');
});
