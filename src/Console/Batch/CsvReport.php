<?php

namespace App\Console\Batch;

/** Small CSV writer for var/reports/*.csv (UTF-8 with BOM so Excel opens it correctly). */
final class CsvReport
{
    /** @var resource */
    private $handle;
    public readonly string $path;
    private int $rows = 0;

    public function __construct(string $varDir, string $name, array $header)
    {
        $dir = $varDir . '/reports';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $base = $dir . '/' . $name . '-' . date('Ymd-His');
        $path = $base . '.csv';
        for ($i = 2; file_exists($path); $i++) {
            $path = "$base-$i.csv";
        }
        $this->path = $path;
        $this->handle = fopen($this->path, 'w');
        fwrite($this->handle, "\xEF\xBB\xBF");
        fputcsv($this->handle, $header);
    }

    public function add(array $row): void
    {
        fputcsv($this->handle, $row);
        $this->rows++;
    }

    public function count(): int
    {
        return $this->rows;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
