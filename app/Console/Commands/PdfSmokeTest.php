<?php

namespace App\Console\Commands;

use App\Settings\CompanySettings;
use App\Support\Money;
use Illuminate\Console\Command;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * Renders a one-page PDF through the configured driver (Gotenberg) to prove the PDF
 * pipeline works. Quote PDFs (M4) use the same pipeline.
 */
class PdfSmokeTest extends Command
{
    protected $signature = 'pdf:smoke-test {--path=app/pdf-smoke-test.pdf : Output path relative to storage/}';

    protected $description = 'Render a test PDF to verify the PDF driver is working';

    public function handle(CompanySettings $company): int
    {
        $path = storage_path($this->option('path'));

        Pdf::view('pdf.smoke-test', [
            'company' => $company->name,
            'generatedAt' => now()->format('d M Y, H:i'),
            'amount' => Money::format(12345600),
        ])->format('a4')->save($path);

        $this->info("PDF written to {$path} (".filesize($path).' bytes)');

        return self::SUCCESS;
    }
}
