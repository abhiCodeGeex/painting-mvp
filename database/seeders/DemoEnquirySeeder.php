<?php

namespace Database\Seeders;

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Enquiries\EnquiryWorkflow;
use App\Enums\AddressKind;
use App\Enums\CustomerType;
use App\Enums\LineSource;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Models\Artwork;
use App\Models\Finishing;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Mat;
use App\Models\Medium;
use App\Models\QualityTier;
use App\Models\State;
use App\Models\Substrate;
use App\Models\User;
use Database\Seeders\Support\DemoImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;

/**
 * Local demo: a customer with an address, one draft and one submitted enquiry where the
 * admin has asked a question — so both the portal and the admin panel have something to show.
 */
class DemoEnquirySeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'customer@paint-mvp.test')->first();

        if (! $user) {
            $customer = app(CustomerAccounts::class)->create([
                'name' => 'Demo Customer',
                'email' => 'customer@paint-mvp.test',
                'password' => 'password',
                'type' => CustomerType::Company,
                'company_name' => 'Lotus Boutique Hotel',
                'phone' => '+91 98765 43210',
                'terms_accepted_at' => now(),
            ], verified: true);
        } else {
            $user->assignRole(Role::Customer->value);
            $customer = $user->customer ?? $user->customer()->create([
                'type' => CustomerType::Company,
                'company_name' => 'Lotus Boutique Hotel',
                'phone' => '+91 98765 43210',
                'terms_accepted_at' => now(),
            ]);
        }

        if ($customer->enquiries()->exists()) {
            return;
        }

        $address = $customer->addresses()->create([
            'kind' => AddressKind::Delivery,
            'label' => 'Hotel site',
            'contact_name' => 'Demo Customer',
            'phone' => '+91 98765 43210',
            'line1' => '12 Marine Drive',
            'city' => 'Mumbai',
            'pin_code' => '400020',
            'state_id' => State::where('gst_code', '27')->value('id'),
            'is_default' => true,
        ]);

        $premium = QualityTier::where('name', 'Premium')->first();
        $canvas = Substrate::where('name', 'Stretched canvas 38 mm')->first();
        $paper = Substrate::where('name', 'Fine-art paper 310 gsm')->first();
        $giclee = Medium::where('name', 'Giclée print')->first();
        $walnut = Frame::where('code', 'FR-WAL-30')->first();
        $floater = Frame::where('code', 'FR-FLT-BLK')->first();
        $ivory = Mat::where('code', 'MAT-IVR')->first();
        $uv = Glazing::where('name', 'UV-protective acrylic')->first();
        $noGlazing = Glazing::where('name', 'No glazing')->first();
        $dRings = Finishing::where('name', 'D-rings + wire')->first();

        // Submitted enquiry with a question from the team. Mail is faked so seeding sends nothing.
        Notification::fake();

        $enquiry = $customer->enquiries()->create([
            'project_name' => 'Lobby & guest rooms',
            'project_type' => ProjectType::Hotel,
            'delivery_address_id' => $address->id,
            'required_by' => now()->addWeeks(6)->toDateString(),
            'budget_range' => '1l_5l',
            'installation_needed' => true,
            'notes' => 'Lobby wall is 4.2 m wide. Guest rooms need 12 identical pieces.',
        ]);

        $lobby = $enquiry->lines()->create([
            'position' => 1,
            'source_type' => LineSource::Catalog,
            'artwork_id' => Artwork::where('code', 'ART-0006')->value('id'),
            'unit' => 'cm',
            'size_reference' => 'image',
            'width_mm' => 1200,
            'height_mm' => 1200,
            'quantity' => 1,
            'quality_tier_id' => $premium?->id,
            'medium_id' => $giclee?->id,
            'substrate_id' => $canvas?->id,
            'edge_style' => 'mirrored',
            'frame_id' => $floater?->id,
            'glazing_id' => $noGlazing?->id,
            'placement' => 'Lobby, behind reception',
            'wall_width_mm' => 4200,
            'wall_height_mm' => 3000,
            'wall_colour' => '#CFC6BA',
        ]);
        $lobby->finishings()->sync(array_filter([$dRings?->id]));

        $rooms = $enquiry->lines()->create([
            'position' => 2,
            'source_type' => LineSource::Catalog,
            'artwork_id' => Artwork::where('code', 'ART-0005')->value('id'),
            'unit' => 'cm',
            'size_reference' => 'image',
            'width_mm' => 500,
            'height_mm' => 700,
            'quantity' => 12,
            'quality_tier_id' => $premium?->id,
            'medium_id' => $giclee?->id,
            'substrate_id' => $paper?->id,
            'frame_id' => $walnut?->id,
            'mat_id' => $ivory?->id,
            'mat_border_mm' => 60,
            'glazing_id' => $uv?->id,
            'placement' => 'Guest rooms, above the bed',
            'wall_colour' => '#B7C2A8',
        ]);
        $rooms->finishings()->sync(array_filter([$dRings?->id]));

        $own = $enquiry->lines()->create([
            'position' => 3,
            'source_type' => LineSource::OwnArtwork,
            'title' => 'Founder’s portrait (heirloom)',
            'own_artwork' => ['service' => 'frame_only', 'condition_notes' => 'Oil on canvas, unframed, good condition.'],
            'rights_confirmed_at' => now(),
            'unit' => 'cm',
            'size_reference' => 'image',
            'width_mm' => 600,
            'height_mm' => 800,
            'quantity' => 1,
            'frame_id' => Frame::where('code', 'FR-GLD-40')->value('id'),
            'glazing_id' => $noGlazing?->id,
            'placement' => 'Library',
            'wall_colour' => '#2E3A55',
        ]);
        $own->addMedia(DemoImage::abstract(900, 1200, ['#3B2F2F', '#7A5C48', '#C9A27E', '#E8D5B7'], 99, 'botanical'))
            ->usingFileName('portrait-photo.jpg')
            ->toMediaCollection('artwork_photo');

        $workflow = app(EnquiryWorkflow::class);
        $workflow->submit($enquiry, $customer->user);

        $admin = User::staff()->role(Role::SuperAdmin->value)->first();
        if ($admin) {
            $workflow->startReview($enquiry, $admin);
            $workflow->askQuestion(
                $enquiry,
                $admin,
                "Thank you! For the 12 guest-room prints: would you prefer the walnut frame in all rooms, or a lighter oak for the smaller rooms?\nAlso, could you share a photo of the lobby wall?",
            );
        }

        // A draft the customer is still working on.
        $customer->enquiries()->create([
            'project_name' => 'Restaurant refresh',
            'project_type' => ProjectType::Restaurant,
            'delivery_address_id' => $address->id,
        ]);
    }
}
