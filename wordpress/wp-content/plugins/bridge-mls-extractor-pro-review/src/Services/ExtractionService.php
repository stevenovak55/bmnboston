<?php
/**
 * Extraction Service for MLS data extraction operations
 *
 * @package BridgeMLS\Services
 * @since 1.0.0
 */

namespace BridgeMLS\Services;

use BridgeMLS\Contracts\ExtractionEngineInterface;
use BridgeMLS\Repositories\ListingRepository;

/**
 * Service for MLS data extraction
 */
class ExtractionService {

    /**
     * Extraction engine
     * @var ExtractionEngineInterface
     */
    private ExtractionEngineInterface $engine;

    /**
     * Listing repository
     * @var ListingRepository
     */
    private ListingRepository $listingRepository;

    /**
     * Data processing service
     * @var DataProcessingService
     */
    private DataProcessingService $dataProcessor;

    /**
     * Current extraction process ID
     * @var string|null
     */
    private ?string $processId = null;

    /**
     * Constructor
     */
    public function __construct(
        ExtractionEngineInterface $engine,
        ListingRepository $listingRepository,
        DataProcessingService $dataProcessor
    ) {
        $this->engine = $engine;
        $this->listingRepository = $listingRepository;
        $this->dataProcessor = $dataProcessor;
    }

    /**
     * Start extraction process
     */
    public function startExtraction(array $filters = [], int $limit = 1000): array {
        if ($this->isExtractionRunning()) {
            throw new \Exception('Extraction is already running');
        }

        $this->processId = $this->generateProcessId();

        // Initialize extraction
        $config = $this->getExtractionConfig();
        if (!$this->engine->initialize($config)) {
            throw new \Exception('Failed to initialize extraction engine');
        }

        // Test connection
        if (!$this->engine->testConnection()) {
            throw new \Exception('MLS connection test failed');
        }

        // Start background process
        $this->scheduleExtractionJob($filters, $limit);

        return [
            'process_id' => $this->processId,
            'status' => 'started',
            'timestamp' => current_time('mysql'),
            'filters' => $filters,
            'limit' => $limit
        ];
    }

    /**
     * Execute extraction (called by background job)
     */
    public function executeExtraction(array $filters = [], int $limit = 1000): array {
        $startTime = microtime(true);
        $stats = [
            'listings_processed' => 0,
            'photos_processed' => 0,
            'agents_processed' => 0,
            'errors' => [],
            'start_time' => current_time('mysql'),
            'status' => 'running'
        ];

        try {
            // Update status
            $this->updateExtractionStatus('running', $stats);

            // Extract listings
            $listings = $this->engine->extractListings($filters, $limit);
            $stats['listings_fetched'] = count($listings);

            $processed = 0;
            foreach ($listings as $listing) {
                try {
                    // Process and validate listing data
                    $processedListing = $this->dataProcessor->processListing($listing);

                    // Save to database
                    if ($this->listingRepository->save($processedListing)) {
                        $processed++;
                    }

                    // Extract photos for new/updated listings
                    $this->extractListingPhotos($listing['ListingId']);

                    // Update progress
                    if ($processed % 50 === 0) {
                        $stats['listings_processed'] = $processed;
                        $this->updateExtractionStatus('running', $stats);
                    }

                } catch (\Exception $e) {
                    $stats['errors'][] = [
                        'listing_id' => $listing['ListingId'] ?? 'unknown',
                        'error' => $e->getMessage(),
                        'timestamp' => current_time('mysql')
                    ];
                }
            }

            $stats['listings_processed'] = $processed;

            // Extract agents if enabled
            if ($this->shouldExtractAgents()) {
                $agents = $this->engine->extractAgents();
                $stats['agents_processed'] = $this->processAgents($agents);
            }

            // Calculate duration
            $stats['duration'] = round(microtime(true) - $startTime, 2);
            $stats['end_time'] = current_time('mysql');
            $stats['status'] = 'completed';

        } catch (\Exception $e) {
            $stats['status'] = 'failed';
            $stats['error'] = $e->getMessage();
            $stats['end_time'] = current_time('mysql');
        } finally {
            // Cleanup
            $this->engine->cleanup();
            $this->updateExtractionStatus($stats['status'], $stats);
        }

        return $stats;
    }

    /**
     * Get extraction progress
     */
    public function getProgress(): array {
        return get_option('bme_extraction_progress', [
            'status' => 'idle',
            'listings_processed' => 0,
            'total_estimated' => 0,
            'current_phase' => 'idle',
            'errors_count' => 0,
            'start_time' => null,
            'estimated_completion' => null
        ]);
    }

    /**
     * Get extraction status
     */
    public function getStatus(): string {
        $progress = $this->getProgress();
        return $progress['status'] ?? 'idle';
    }

    /**
     * Check if extraction is running
     */
    public function isExtractionRunning(): bool {
        $status = $this->getStatus();
        return in_array($status, ['running', 'starting', 'stopping']);
    }

    /**
     * Cancel ongoing extraction
     */
    public function cancelExtraction(): bool {
        if (!$this->isExtractionRunning()) {
            return false;
        }

        // Update status to cancelling
        $progress = $this->getProgress();
        $progress['status'] = 'cancelling';
        update_option('bme_extraction_progress', $progress);

        // Try to cancel engine operation
        $result = $this->engine->cancel();

        // Update final status
        $progress['status'] = 'cancelled';
        $progress['end_time'] = current_time('mysql');
        update_option('bme_extraction_progress', $progress);

        return $result;
    }

    /**
     * Get extraction statistics
     */
    public function getStatistics(): array {
        $lastLog = get_option('bme_last_extraction_log', []);

        return [
            'last_extraction' => $lastLog['timestamp'] ?? null,
            'last_status' => $lastLog['status'] ?? 'never_run',
            'last_duration' => $lastLog['duration'] ?? 0,
            'last_listings_count' => $lastLog['listings_processed'] ?? 0,
            'last_errors_count' => count($lastLog['errors'] ?? []),
            'total_extractions' => get_option('bme_total_extractions', 0),
            'avg_duration' => get_option('bme_avg_extraction_duration', 0)
        ];
    }

    /**
     * Get supported MLS systems
     */
    public function getSupportedSystems(): array {
        return $this->engine->getSupportedSystems();
    }

    /**
     * Get extraction engine
     */
    public function getEngine(): ExtractionEngineInterface {
        return $this->engine;
    }

    /**
     * Schedule extraction as background job
     */
    private function scheduleExtractionJob(array $filters, int $limit): void {
        // Store job parameters
        update_option('bme_extraction_job_params', [
            'filters' => $filters,
            'limit' => $limit,
            'process_id' => $this->processId,
            'scheduled_at' => current_time('mysql')
        ]);

        // Schedule immediate execution
        wp_schedule_single_event(time(), 'bme_execute_extraction', [$filters, $limit]);
    }

    /**
     * Extract photos for a specific listing
     */
    private function extractListingPhotos(string $listingId): int {
        try {
            $photos = $this->engine->extractPhotos([$listingId]);
            return $this->processPhotos($listingId, $photos);
        } catch (\Exception $e) {
            error_log("Failed to extract photos for listing {$listingId}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Process and save photos
     */
    private function processPhotos(string $listingId, array $photos): int {
        $processed = 0;

        foreach ($photos as $photo) {
            try {
                $processedPhoto = $this->dataProcessor->processPhoto($photo, $listingId);

                // Save photo to database (would need PhotoRepository)
                // $this->photoRepository->save($processedPhoto);

                $processed++;
            } catch (\Exception $e) {
                error_log("Failed to process photo for listing {$listingId}: " . $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Process and save agents
     */
    private function processAgents(array $agents): int {
        $processed = 0;

        foreach ($agents as $agent) {
            try {
                $processedAgent = $this->dataProcessor->processAgent($agent);

                // Save agent to database (would need AgentRepository)
                // $this->agentRepository->save($processedAgent);

                $processed++;
            } catch (\Exception $e) {
                error_log("Failed to process agent: " . $e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Update extraction status and progress
     */
    private function updateExtractionStatus(string $status, array $stats): void {
        $progress = [
            'status' => $status,
            'listings_processed' => $stats['listings_processed'] ?? 0,
            'total_estimated' => $stats['listings_fetched'] ?? 0,
            'current_phase' => $this->getCurrentPhase($status, $stats),
            'errors_count' => count($stats['errors'] ?? []),
            'start_time' => $stats['start_time'] ?? null,
            'end_time' => $stats['end_time'] ?? null,
            'updated_at' => current_time('mysql')
        ];

        update_option('bme_extraction_progress', $progress);

        // Save to log on completion
        if (in_array($status, ['completed', 'failed', 'cancelled'])) {
            $this->saveExtractionLog($stats);
        }
    }

    /**
     * Get current extraction phase
     */
    private function getCurrentPhase(string $status, array $stats): string {
        if ($status === 'running') {
            if (($stats['listings_processed'] ?? 0) > 0) {
                return 'processing_listings';
            } else {
                return 'fetching_data';
            }
        }

        return $status;
    }

    /**
     * Save extraction log
     */
    private function saveExtractionLog(array $stats): void {
        update_option('bme_last_extraction_log', $stats);

        // Update statistics
        $totalExtractions = get_option('bme_total_extractions', 0) + 1;
        update_option('bme_total_extractions', $totalExtractions);

        if ($stats['status'] === 'completed' && isset($stats['duration'])) {
            $avgDuration = get_option('bme_avg_extraction_duration', 0);
            $newAvg = (($avgDuration * ($totalExtractions - 1)) + $stats['duration']) / $totalExtractions;
            update_option('bme_avg_extraction_duration', round($newAvg, 2));
        }
    }

    /**
     * Generate unique process ID
     */
    private function generateProcessId(): string {
        return 'bme_' . uniqid() . '_' . time();
    }

    /**
     * Get extraction configuration
     */
    private function getExtractionConfig(): array {
        return [
            'mls_url' => get_option('bme_mls_url', ''),
            'username' => get_option('bme_mls_username', ''),
            'password' => get_option('bme_mls_password', ''),
            'timeout' => get_option('bme_extraction_timeout', 300),
            'batch_size' => get_option('bme_batch_size', 100),
            'rate_limit' => get_option('bme_rate_limit', 10)
        ];
    }

    /**
     * Check if agent extraction is enabled
     */
    private function shouldExtractAgents(): bool {
        return get_option('bme_extract_agents', true);
    }
}