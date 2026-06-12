<?php

namespace App\Console\Commands;

use App\Jobs\CreateOsintCapsule;
use App\Support\CapsuleRetentionPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class IngestLeak extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'osint:ingest
        {path : Absolute path to the file}
        {name : Display name}
        {date : Leak date (YYYY-MM-DD)}
        {format=UNSTRUCTURED : Data classification}
        {retention=breach : Retention class: breach, stealer, ulp_log, telegram, scraped}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually dispatch an OSINT ingestion job for a new leak capsule';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $path = $this->argument('path');
        $name = $this->argument('name');
        $date = $this->argument('date');
        $format = $this->argument('format');
        $retention = $this->argument('retention');

        if (! file_exists($path)) {
            $this->error("File not found at: {$path}");

            return 1;
        }

        $validator = Validator::make([
            'date' => $date,
            'retention' => $retention,
        ], [
            'date' => ['required', 'date'],
            'retention' => ['required', Rule::in(CapsuleRetentionPolicy::values())],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return 1;
        }

        CreateOsintCapsule::dispatch($path, $name, $date, $format, $retention);

        $this->info("Successfully dispatched ingestion job for: {$name}");
        $this->info('Retention: '.CapsuleRetentionPolicy::retentionDescription($retention));
        $this->info("Check the 'jobs' table or your worker logs for progress.");

        return 0;
    }
}
