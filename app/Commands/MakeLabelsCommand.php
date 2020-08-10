<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Kduma\BulkGenerator\BulkGenerator;
use Kduma\BulkGenerator\ContentGenerators\TwigTemplateContentGenerator;
use Kduma\BulkGenerator\DataSources\CsvDataSource;
use Kduma\BulkGenerator\OverridableBulkGenerator;
use Kduma\BulkGenerator\PageOptions\PageSize;
use Kduma\BulkGenerator\PdfGenerators\MpdfGenerator;
use Kduma\PdfImposition\DTO\Size;
use Kduma\PdfImposition\LayoutGenerators\AutoGridPageLayoutGenerator;
use Kduma\PdfImposition\LayoutGenerators\Markers\OutsideBoxCutMarkings;
use Kduma\PdfImposition\PdfImposer;
use Kduma\PdfImposition\PdfSource;
use LaravelZero\Framework\Commands\Command;

class MakeLabelsCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'make {source} {destination} {--no-imposition} {--delimiter=,} {--enclosure="} {--escape=\\} {--imposition-spacing=5}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Generates labels based on source file provided';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $source_file = $this->argument('source');
        if(!file_exists($source_file)){
            $this->error("File '$source_file' doesn\'t exists!");
            return;
        }

        $this->generateSingleLabels($source_file);

        if(!$this->option('no-imposition'))
            $this->imposeForPrinting();
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule)
    {
        // $schedule->command(static::class)->everyMinute();
    }

    /**
     * @param $source_file
     *
     * @throws \Exception
     */
    protected function generateSingleLabels($source_file): void
    {
        $dataSource = new CsvDataSource(
            $source_file,
            $this->option('delimiter'),
            $this->option('enclosure'),
            $this->option('escape')
        );

        $pdfGenerator = new MpdfGenerator(new PageSize(80, 80));
        $pdfGenerator->setCss(file_get_contents(base_path('templates/style.css')));

        $layouts = collect(glob(base_path('templates/layouts/*.twig')))
            ->mapWithKeys(fn($file) => [pathinfo($file, PATHINFO_FILENAME) => $file]);

        $patterns = $layouts->map(fn($file, $key) => '/^' . str_replace(['0', 'A'], [
                '[0-9]', '[A-Z0-9]'
            ], $key) . '$/us');

        $generator = (new OverridableBulkGenerator($dataSource, $pdfGenerator, function ($row) use ($patterns) {
            foreach ($patterns as $key => $pattern) {
                if (preg_match($pattern, $row['column_0']))
                    return $key;
            }
        }))->setFrontContentGenerator(new TwigTemplateContentGenerator(file_get_contents(base_path('templates/default.twig'))));

        $layouts->each(fn($file, $layout) => $generator->setFrontContentGenerator(
            new TwigTemplateContentGenerator(file_get_contents($file)),
            $layout
        ));

        $generator->generate($this->argument('destination'));
    }

    protected function imposeForPrinting(): void
    {
        $layoutGenerator = new AutoGridPageLayoutGenerator(
            Size::make(80, 80),
            $this->option('imposition-spacing'),
            $this->option('imposition-spacing')
        );
        $layoutGenerator->center();

        $layoutGenerator = new OutsideBoxCutMarkings($layoutGenerator);

        $PdfImposer = new PdfImposer($layoutGenerator);

        $cards = (new PdfSource)->read($this->argument('destination'));
        $PdfImposer->export($cards, $this->argument('destination'));
    }
}
