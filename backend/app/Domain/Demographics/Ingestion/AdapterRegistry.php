<?php

namespace App\Domain\Demographics\Ingestion;

use App\Domain\Demographics\Ingestion\Adapters\UnWppAdapter;
use App\Domain\Demographics\Ingestion\Adapters\WikipediaDemographicsAdapter;
use App\Domain\Demographics\Ingestion\Adapters\WikipediaListAdapter;
use App\Domain\Demographics\Ingestion\Adapters\WikipediaVitalStatisticsAdapter;
use App\Domain\Demographics\Ingestion\Adapters\WorldBankAdapter;
use App\Domain\Demographics\Ingestion\Contracts\SourceAdapter;
use App\Models\SourceDataset;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Resolves the adapter for a dataset from its `adapter_key`, so adding a source is
 * a matter of writing an adapter and registering it here.
 */
class AdapterRegistry
{
    /** @var array<string, class-string<SourceAdapter>> */
    private array $adapters = [
        'world_bank' => WorldBankAdapter::class,
        'un_wpp' => UnWppAdapter::class,
        'wikipedia' => WikipediaDemographicsAdapter::class,
        'wikipedia_vital_statistics' => WikipediaVitalStatisticsAdapter::class,
        'wikipedia_list' => WikipediaListAdapter::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function for(SourceDataset $dataset): SourceAdapter
    {
        return $this->forKey((string) $dataset->adapter_key);
    }

    public function forKey(string $key): SourceAdapter
    {
        if (! isset($this->adapters[$key])) {
            throw new InvalidArgumentException("No adapter is registered for key [{$key}].");
        }

        return $this->container->make($this->adapters[$key]);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->adapters);
    }
}
