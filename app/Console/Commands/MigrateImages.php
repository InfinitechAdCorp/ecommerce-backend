<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\ProductController;

class MigrateImages extends Command
{
    protected $signature = 'images:migrate';
    protected $description = 'Migrate all product images from storage to public directory';

    public function handle()
    {
        $this->info('Starting image migration...');
        
        $controller = new ProductController();
        $result = $controller->migrateAllImages();
        
        $data = $result->getData(true);
        
        if ($data['success']) {
            $this->info($data['message']);
            $this->info('Migration completed successfully!');
        } else {
            $this->error('Migration failed: ' . $data['message']);
        }
        
        return 0;
    }
}
