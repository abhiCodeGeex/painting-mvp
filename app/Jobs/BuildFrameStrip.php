<?php

namespace App\Jobs;

use App\Models\Frame;
use App\Support\FrameStrip\FrameStripBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds (or removes) a frame's moulding strip from its uploaded photo.
 */
class BuildFrameStrip implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(public int $frameId, public bool $force = false) {}

    public function handle(FrameStripBuilder $builder): void
    {
        $frame = Frame::withTrashed()->find($this->frameId);
        if ($frame) {
            $builder->build($frame, $this->force);
        }
    }
}
