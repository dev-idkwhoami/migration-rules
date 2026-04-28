<?php

declare(strict_types=1);

namespace Idkwhoami\MigrationRules;

class MigrationManifest
{
    public const CURRENT_VERSION = 1;

    public function __construct(
        public int $version = self::CURRENT_VERSION,
        public string $prefix = '0001_01_01',
        public array $slots = [],
        public array $inlined = [],
        public int $baseStep = 50,
        public int $pivotStep = 25,
        public string $rulesFile = '.migration.rules'
    ) {}

    /**
     * Load manifest from a JSON file.
     */
    public static function load(string $path): self
    {
        if (! file_exists($path)) {
            return new self;
        }

        $data = json_decode(file_get_contents($path), true);
        if (! is_array($data)) {
            return new self;
        }

        return new self(
            version: $data['version'] ?? self::CURRENT_VERSION,
            prefix: $data['prefix'] ?? '0001_01_01',
            slots: $data['slots'] ?? [],
            inlined: $data['inlined'] ?? [],
            baseStep: $data['baseStep'] ?? 50,
            pivotStep: $data['pivotStep'] ?? 25,
            rulesFile: $data['rulesFile'] ?? '.migration.rules'
        );
    }

    /**
     * Save manifest to a JSON file.
     */
    public function save(string $path): void
    {
        $data = [
            'version' => $this->version,
            'prefix' => $this->prefix,
            'slots' => $this->slots,
            'inlined' => $this->inlined,
            'baseStep' => $this->baseStep,
            'pivotStep' => $this->pivotStep,
            'rulesFile' => $this->rulesFile,
        ];

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Check if a migration file is already inlined.
     */
    public function isInlined(string $filename): bool
    {
        return in_array($filename, $this->inlined, true);
    }

    /**
     * Add a file to the inlined list.
     */
    public function addInlined(string $filename): void
    {
        $this->inlined[] = $filename;
    }
}
