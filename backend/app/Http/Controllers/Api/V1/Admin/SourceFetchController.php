<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Demographics\Discovery\CountryDataFetcher;
use App\Http\Controllers\Controller;
use App\Http\Presenters\SourcePresenter;
use App\Models\Entity;
use App\Models\SourceCandidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Get data for this country": scrape the configured source for one entity, and fall
 * back to a web search when the scrape yields nothing.
 */
class SourceFetchController extends Controller
{
    public function __construct(private readonly CountryDataFetcher $fetcher) {}

    public function fetch(Request $request, Entity $entity): JsonResponse
    {
        $validated = $request->validate([
            'dataset' => ['nullable', 'string', 'exists:source_datasets,code'],
        ]);

        $result = $this->fetcher->fetch(
            $entity,
            $validated['dataset'] ?? 'wikipedia_vital_statistics',
            $request->user()?->id,
        );

        if ($result['error'] !== null) {
            return response()->json([
                'message' => 'The source could not be read.',
                'error' => $result['error'],
                'searched' => $result['searched'],
                'candidates' => $this->candidates($result['candidates']),
            ], 502);
        }

        return response()->json([
            'entity' => ['iso_alpha3' => $entity->iso_alpha3, 'name' => $entity->name],
            'run' => $result['run'] !== null ? SourcePresenter::run($result['run']) : null,
            'observations_created' => $result['created'],
            'observations_unchanged' => $result['unchanged'],
            'searched_web' => $result['searched'],
            'candidates' => $this->candidates($result['candidates']),
        ]);
    }

    /**
     * @param  array<int, SourceCandidate>  $candidates
     */
    private function candidates(array $candidates): array
    {
        return array_map(fn ($candidate): array => [
            'id' => $candidate->id,
            'title' => $candidate->title,
            'url' => $candidate->url,
            'host' => $candidate->host,
            'snippet' => $candidate->snippet,
            'status' => $candidate->status,
        ], $candidates);
    }
}
