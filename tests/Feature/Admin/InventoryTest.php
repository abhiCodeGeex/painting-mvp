<?php

use App\Enums\Role;
use App\Enums\StockStatus;
use App\Enums\StockUnit;
use App\Filament\Resources\Catalog\Substrates\Pages\ManageSubstrates;
use App\Filament\Resources\Framing\Frames\FrameResource;
use App\Filament\Resources\Framing\Frames\Pages\ListFrames;
use App\Filament\Resources\Framing\Glazings\Pages\ManageGlazings;
use App\Filament\Resources\Framing\Mats\MatResource;
use App\Filament\Resources\Framing\Mats\Pages\ManageMats;
use App\Filament\Resources\Inventory\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\Inventory\StockMovements\StockMovementResource;
use App\Filament\Support\StockColumns;
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
use Spatie\Permission\Models\Permission;

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

test('there is no separate Stock menu: only the stock history', function () {
    expect(class_exists('App\\Filament\\Resources\\Inventory\\StockItems\\StockItemResource'))->toBeFalse();

    $labels = collect(Filament::getPanel('admin')->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item) => $item->getLabel())
        ->all();

    expect($labels)->toContain('Stock history')
        ->not->toContain('Stock')
        ->not->toContain('Stock movements');

    $this->get('/admin/inventory/stock')->assertNotFound();
});

test('item listings show stock, tint low rows and filter by stock status', function () {
    $this->seed(DemoStockSeeder::class);
    Notification::assertNothingSent(); // seeding does not alert

    $gold = Frame::where('code', 'FR-GLD-40')->firstOrFail();   // out
    $oak = Frame::where('code', 'FR-OAK-25')->firstOrFail();    // low
    $black = Frame::where('code', 'FR-BLK-20')->firstOrFail();  // ok

    $this->get(FrameResource::getUrl('index'))
        ->assertOk()
        ->assertSee('Antique gold ornate 40 mm')
        ->assertSee('Out of stock')
        ->assertSee('stock-row-out')
        ->assertSee('stock-row-low');

    Livewire::test(ListFrames::class)
        ->assertTableColumnExists('stock_on_hand')
        ->assertTableColumnExists('stock_status')
        ->filterTable('stock', StockStatus::Low->value)
        ->assertCanSeeTableRecords([$oak])
        ->assertCanNotSeeTableRecords([$gold, $black])
        ->filterTable('stock', StockColumns::ATTENTION)
        ->assertCanSeeTableRecords([$gold, $oak])
        ->assertCanNotSeeTableRecords([$black])
        ->filterTable('stock', StockStatus::Out->value)
        ->assertCanSeeTableRecords([$gold])
        ->assertCanNotSeeTableRecords([$oak, $black]);
});

test('refill and reduce from an item listing record stock movements', function () {
    $gold = Frame::where('code', 'FR-GLD-40')->firstOrFail();
    $stock = $gold->stockItem;

    Livewire::test(ListFrames::class)
        ->callTableAction('refillStock', $gold, data: ['quantity' => 60, 'note' => 'Supplier delivery'])
        ->assertHasNoTableActionErrors();

    expect($stock->fresh()->quantity)->toBe(60.0)
        ->and($stock->movements()->first()->note)->toBe('Supplier delivery')
        ->and($stock->movements()->first()->change)->toBe(60.0)
        ->and($stock->movements()->first()->user_id)->toBe($this->admin->id);

    Livewire::test(ListFrames::class)
        ->callTableAction('reduceStock', $gold, data: ['quantity' => 45.5, 'reason' => 'Used on job'])
        ->assertHasNoTableActionErrors();

    expect($stock->fresh()->quantity)->toBe(14.5)
        ->and($stock->movements()->count())->toBe(2)
        ->and($stock->movements()->first()->change)->toBe(-45.5)
        ->and($stock->movements()->first()->note)->toBe('Used on job');

    // Dropped under the alert level (20): admins alerted once, linking to the frame list.
    Notification::assertSentToTimes($this->admin, LowStockAlert::class, 1);
    Notification::assertSentTo($this->admin, LowStockAlert::class, fn (LowStockAlert $n) => str_starts_with((string) $n->listingUrl, FrameResource::getUrl('index')));

    // A reason is required, and you cannot remove more than is in stock.
    Livewire::test(ListFrames::class)
        ->callTableAction('reduceStock', $gold, data: ['quantity' => 5, 'reason' => ''])
        ->assertHasTableActionErrors(['reason' => 'required']);
    Livewire::test(ListFrames::class)
        ->callTableAction('reduceStock', $gold, data: ['quantity' => 15, 'reason' => 'Used'])
        ->assertHasTableActionErrors(['quantity']);

    expect($stock->fresh()->quantity)->toBe(14.5)
        ->and($stock->movements()->count())->toBe(2);
});

test('every stock-tracked listing has the stock actions', function () {
    $mat = Mat::where('code', 'MAT-WHT')->firstOrFail();
    $glazing = Glazing::where('name', 'Clear acrylic 3 mm')->firstOrFail();
    $none = Glazing::where('name', 'No glazing')->firstOrFail();
    $substrate = Substrate::where('name', 'Canvas roll (unstretched)')->firstOrFail();

    Livewire::test(ManageMats::class)
        ->callTableAction('refillStock', $mat, data: ['quantity' => 12])
        ->assertHasNoTableActionErrors();
    expect($mat->stockItem->fresh()->quantity)->toBe(12.0)
        ->and($mat->stockItem->movements()->first()->note)->toBe('Refilled');

    // Sheets are whole pieces.
    Livewire::test(ManageMats::class)
        ->callTableAction('reduceStock', $mat, data: ['quantity' => 1.5, 'reason' => 'Damaged'])
        ->assertHasTableActionErrors(['quantity']);

    Livewire::test(ManageGlazings::class)
        ->assertTableActionVisible('refillStock', $glazing)
        ->assertTableActionHidden('refillStock', $none) // "No glazing" holds no stock
        ->callTableAction('refillStock', $glazing, data: ['quantity' => 3]);
    expect($glazing->stockItem->fresh()->quantity)->toBe(3.0);

    Livewire::test(ManageSubstrates::class)
        ->callTableAction('refillStock', $substrate, data: ['quantity' => 2, 'note' => 'Delivery'])
        ->callTableAction('stockAlertLevel', $substrate, data: ['low_stock_threshold' => 1]);
    expect($substrate->stockItem->fresh()->quantity)->toBe(2.0)
        ->and($substrate->stockItem->fresh()->low_stock_threshold)->toBe(1.0)
        ->and($substrate->stockItem->fresh()->status())->toBe(StockStatus::Ok);
});

test('staff without stock permission do not get the stock actions', function () {
    foreach (['ViewAny:Mat', 'ViewAny:StockItem', 'Update:StockItem'] as $name) {
        Permission::findOrCreate($name, 'web');
    }
    $mat = Mat::where('code', 'MAT-WHT')->firstOrFail();

    $viewer = User::factory()->staff()->create();
    $viewer->givePermissionTo(['ViewAny:Mat', 'ViewAny:StockItem']);
    $this->actingAs($viewer);

    Livewire::test(ManageMats::class)
        ->assertTableColumnVisible('stock_on_hand')
        ->assertTableActionHidden('refillStock', $mat)
        ->assertTableActionHidden('reduceStock', $mat)
        ->assertTableActionHidden('stockAlertLevel', $mat)
        ->assertTableBulkActionHidden('bulkStockAlertLevel');

    $blind = User::factory()->staff()->create();
    $blind->givePermissionTo(['ViewAny:Mat']);
    $this->actingAs($blind);

    Livewire::test(ManageMats::class)
        ->assertTableColumnHidden('stock_on_hand')
        ->assertTableActionHidden('refillStock', $mat);
    $this->get('/admin/inventory/movements')->assertForbidden();
});

test('archived items drop out of the restock list', function () {
    $mat = Mat::where('code', 'MAT-GRY')->firstOrFail();
    $stock = $mat->stockItem;
    $mat->delete();

    Livewire::test(LowStockItems::class)->assertCanNotSeeTableRecords([$stock]);
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
        ->assertCanNotSeeTableRecords([stockFor(Frame::class, 'FR-BLK-20')])
        ->callTableAction('refillStock', stockFor(Frame::class, 'FR-GLD-40'), data: ['quantity' => 5, 'note' => 'Delivery']);

    expect(stockFor(Frame::class, 'FR-GLD-40')->quantity)->toBe(5.0);

    // Warnings link to the item's own list, filtered, not to a separate Stock page.
    expect(StockColumns::listingUrl(stockFor(Frame::class, 'FR-GLD-40')))
        ->toStartWith(FrameResource::getUrl('index'))
        ->toContain('search=')
        ->toContain('filters');
    expect(StockColumns::listingUrl(stockFor(Mat::class, 'MAT-BLK')))->toStartWith(MatResource::getUrl('index'));
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

test('alert level can be set on several items at once from a listing', function () {
    $mats = Mat::with('stockItem')->get();
    $mats->each(fn (Mat $mat) => $mat->stockItem->adjust(10));

    Livewire::test(ManageMats::class)
        ->callTableBulkAction('bulkStockAlertLevel', $mats, data: ['low_stock_threshold' => 5]);

    expect(StockItem::where('stockable_type', Mat::class)->pluck('low_stock_threshold')->unique()->all())->toBe([5.0])
        ->and(StockItem::where('stockable_type', Mat::class)->get()->every(fn ($i) => $i->status() === StockStatus::Ok))->toBeTrue();
});
