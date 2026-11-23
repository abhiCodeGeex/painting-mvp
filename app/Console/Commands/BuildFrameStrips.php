<?php

namespace App\Console\Commands;

use App\Jobs\BuildFrameStrip;
use App\Models\Frame;
use App\Support\FrameStrip\FrameStripBuilder;
use Illuminate\Console\Command;

class BuildFrameStrips extends Command
{
    protected $signature = 'frames:build-strips
        {frames?* : Frame codes or ids (default: all frames)}
        {--force : Rebuild even when the strip is up to date}
        {--queue : Dispatch to the queue instead of building now}';

    protected $description = 'Build the moulding strip for each frame from its uploaded photo';

    public function handle(FrameStripBuilder $builder): int
    {
        $wanted = $this->argument('frames');
        $frames = Frame::withTrashed()
            ->when($wanted, fn ($q) => $q->where(fn ($q) => $q->whereIn('code', $wanted)
                ->orWhereIn('id', array_filter($wanted, 'ctype_digit'))))
            ->orderBy('code')
            ->get();

        if ($frames->isEmpty()) {
            $this->warn('No frames found.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($frames as $frame) {
            if ($this->option('queue')) {
                BuildFrameStrip::dispatch($frame->id, (bool) $this->option('force'));
                $this->line("{$frame->code}: queued");

                continue;
            }

            $status = $builder->build($frame, (bool) $this->option('force'));
            if ($status === FrameStripBuilder::FAILED) {
                $failed++;
                $this->error("{$frame->code}: failed — {$frame->strip_error}");
            } else {
                $this->line("{$frame->code}: {$status}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
