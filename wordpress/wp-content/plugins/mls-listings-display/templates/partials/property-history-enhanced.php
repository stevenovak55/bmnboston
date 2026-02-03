<?php
/**
 * Enhanced Property History Display
 * Shows comprehensive property history with all tracked events
 */

// Get property data
// Don't overwrite $mls_number if it already exists (e.g., from parent template)
$history_mls = $listing['listing_id'] ?? '';
$address = $listing['unparsed_address'] ?? '';

// Build comprehensive history array
$history_events = array();
$has_tracked_history = false;

// Get tracked property history (most accurate)
$tracked_history = MLD_Query::get_tracked_property_history($history_mls);
if (!empty($tracked_history)) {
    $has_tracked_history = true;
    foreach ($tracked_history as $event) {
        $event_data = array(
            'date' => strtotime($event['event_date']),
            'raw_date' => $event['event_date'],
            'type' => $event['event_type'],
            'icon' => '',
            'color' => ''
        );
        
        // Parse additional data if present
        $additional = !empty($event['additional_data']) ? json_decode($event['additional_data'], true) : array();
        
        switch ($event['event_type']) {
            case 'new_listing':
                $event_data['event'] = 'Listed for Sale';
                $event_data['icon'] = '🏠';
                $event_data['color'] = '#28a745';
                $event_data['price'] = $event['new_price'];
                $event_data['details'] = 'Original listing';
                if (!empty($event['agent_name'])) {
                    $event_data['agent'] = $event['agent_name'];
                }
                if (!empty($event['office_name'])) {
                    $event_data['office'] = $event['office_name'];
                }
                break;
                
            case 'price_change':
                $change_amount = $event['new_price'] - $event['old_price'];
                $change_percent = !empty($additional['price_change_percent']) ? $additional['price_change_percent'] : 0;
                $event_data['event'] = $change_amount < 0 ? 'Price Reduced' : 'Price Increased';
                $event_data['icon'] = $change_amount < 0 ? '↓' : '↑';
                $event_data['color'] = $change_amount < 0 ? '#dc3545' : '#17a2b8';
                $event_data['price'] = $event['new_price'];
                $event_data['change'] = $change_amount;
                $event_data['change_percent'] = $change_percent;
                $event_data['old_price'] = $event['old_price'];
                $event_data['price_per_sqft'] = $event['price_per_sqft'];
                $event_data['details'] = sprintf('From $%s to $%s (%s%.1f%%)', 
                    number_format($event['old_price']),
                    number_format($event['new_price']),
                    $change_amount > 0 ? '+' : '',
                    $change_percent
                );
                break;
                
            case 'status_change':
                $event_data['event'] = 'Status: ' . ucfirst(strtolower($event['new_status']));
                $event_data['icon'] = '📋';
                $event_data['color'] = '#6c757d';
                $event_data['details'] = $event['old_status'] . ' → ' . $event['new_status'];
                $event_data['price'] = $event['new_price'];
                break;
                
            case 'pending':
                $event_data['event'] = 'Pending Sale';
                $event_data['icon'] = '⏳';
                $event_data['color'] = '#ffc107';
                $event_data['details'] = 'Offer accepted, sale pending';
                $event_data['days_on_market'] = $event['days_on_market'];
                break;
                
            case 'sold':
                $event_data['event'] = 'Sold';
                $event_data['icon'] = '✓';
                $event_data['color'] = '#28a745';
                $event_data['price'] = $event['new_price'] ?: $event['old_price'];
                $event_data['details'] = 'Property sold';
                $event_data['days_on_market'] = $event['days_on_market'];
                break;
                
            case 'off_market':
                $status_map = [
                    'Expired' => 'Listing Expired',
                    'Withdrawn' => 'Withdrawn from Market',
                    'Canceled' => 'Listing Canceled'
                ];
                $event_data['event'] = $status_map[$event['new_status']] ?? 'Off Market';
                $event_data['icon'] = '⚠️';
                $event_data['color'] = '#6c757d';
                $event_data['details'] = 'Removed from active listings';
                break;
                
            case 'moved_to_archive':
                $event_data['event'] = 'Archived';
                $event_data['icon'] = '📦';
                $event_data['color'] = '#6c757d';
                $event_data['details'] = 'Moved to archive (Status: ' . $event['new_status'] . ')';
                break;
                
            case 'moved_to_active':
                $event_data['event'] = 'Reactivated';
                $event_data['icon'] = '♻️';
                $event_data['color'] = '#17a2b8';
                $event_data['details'] = 'Moved to active tables (Status: ' . $event['new_status'] . ')';
                break;
                
            case 'contingency_change':
                $event_data['event'] = 'Contingency Update';
                $event_data['icon'] = '📝';
                $event_data['color'] = '#17a2b8';
                $event_data['details'] = $event['new_value'] ?: 'Contingencies removed';
                break;
                
            case 'agent_change':
                $event_data['event'] = 'Agent Changed';
                $event_data['icon'] = '👤';
                $event_data['color'] = '#6c757d';
                $event_data['details'] = $event['old_value'] . ' → ' . $event['new_value'];
                $event_data['agent'] = $event['new_value'];
                break;
                
            case 'commission_change':
                $event_data['event'] = 'Commission Update';
                $event_data['icon'] = '💰';
                $event_data['color'] = '#6c757d';
                $event_data['details'] = 'Buyer agent compensation: ' . $event['new_value'];
                break;
                
            case 'property_detail_change':
                $label = !empty($additional['label']) ? $additional['label'] : ucwords(str_replace('_', ' ', $event['field_name']));
                $event_data['event'] = $label . ' Updated';
                $event_data['icon'] = '🔧';
                $event_data['color'] = '#6c757d';
                $event_data['details'] = $event['old_value'] . ' → ' . $event['new_value'];
                break;
                
            case 'showing_update':
                $event_data['event'] = 'Showing Instructions Updated';
                $event_data['icon'] = '👁️';
                $event_data['color'] = '#17a2b8';
                $event_data['details'] = 'New showing requirements';
                break;
                
            default:
                $event_data['event'] = ucwords(str_replace('_', ' ', $event['event_type']));
                $event_data['icon'] = '•';
                $event_data['color'] = '#6c757d';
                if (!empty($event['new_value'])) {
                    $event_data['details'] = $event['new_value'];
                }
                break;
        }
        
        $history_events[] = $event_data;
    }
}

// Get full property history for all transactions at this address
$full_history = MLD_Query::get_full_property_history_by_address($address, $history_mls);
if (!empty($full_history)) {
    // Limit history to prevent memory issues
    $history_count = 0;
    $max_history_items = 100; // Prevent memory exhaustion

    foreach ($full_history as $transaction_mls => $transaction_events) {
        if ($history_count++ > $max_history_items) {
            break; // Stop processing after max items
        }

        // Process each transaction's full history
        $transaction_data = array(
            'mls_number' => $transaction_mls,
            'events' => array()
        );
        
        // Limit events per transaction to prevent memory issues
        $event_count = 0;
        $max_events_per_transaction = 50;

        foreach ($transaction_events as $event) {
            if ($event_count++ > $max_events_per_transaction) {
                break;
            }

            $event_data = array(
                'date' => strtotime($event['event_date']),
                'raw_date' => $event['event_date'],
                'type' => $event['event_type'],
                'icon' => '',
                'color' => '',
                'transaction_mls' => $transaction_mls
            );
            
            // Parse additional data if present
            $additional = !empty($event['additional_data']) ? json_decode($event['additional_data'], true) : array();
            
            switch ($event['event_type']) {
                case 'new_listing':
                    $event_data['event'] = 'Listed for Sale';
                    $event_data['icon'] = '🏠';
                    $event_data['color'] = '#28a745';
                    $event_data['price'] = $event['new_price'];
                    $event_data['details'] = 'MLS #' . $transaction_mls;
                    if (!empty($event['agent_name'])) {
                        $event_data['agent'] = $event['agent_name'];
                    }
                    if (!empty($event['office_name'])) {
                        $event_data['office'] = $event['office_name'];
                    }
                    break;
                    
                case 'price_change':
                    $change_amount = $event['new_price'] - $event['old_price'];
                    $change_percent = !empty($additional['price_change_percent']) ? $additional['price_change_percent'] : 0;
                    $event_data['event'] = $change_amount < 0 ? 'Price Reduced' : 'Price Increased';
                    $event_data['icon'] = $change_amount < 0 ? '↓' : '↑';
                    $event_data['color'] = $change_amount < 0 ? '#dc3545' : '#17a2b8';
                    $event_data['price'] = $event['new_price'];
                    $event_data['change'] = $change_amount;
                    $event_data['change_percent'] = $change_percent;
                    $event_data['old_price'] = $event['old_price'];
                    $event_data['price_per_sqft'] = $event['price_per_sqft'];
                    $event_data['details'] = 'MLS #' . $transaction_mls;
                    break;
                    
                case 'status_change':
                    $event_data['event'] = 'Status: ' . ucfirst(strtolower($event['new_status']));
                    $event_data['icon'] = '📋';
                    $event_data['color'] = '#6c757d';
                    $event_data['details'] = $event['old_status'] . ' → ' . $event['new_status'] . ' (MLS #' . $transaction_mls . ')';
                    $event_data['price'] = $event['new_price'];
                    break;
                    
                case 'pending':
                    $event_data['event'] = 'Pending Sale';
                    $event_data['icon'] = '⏳';
                    $event_data['color'] = '#ffc107';
                    $event_data['details'] = 'Offer accepted (MLS #' . $transaction_mls . ')';
                    $event_data['days_on_market'] = $event['days_on_market'];
                    break;
                    
                case 'sold':
                    $event_data['event'] = 'Sold';
                    $event_data['icon'] = '✓';
                    $event_data['color'] = '#28a745';
                    $event_data['price'] = $event['new_price'] ?: $event['old_price'];
                    $event_data['details'] = 'MLS #' . $transaction_mls;
                    $event_data['days_on_market'] = $event['days_on_market'];
                    break;
                    
                default:
                    $event_data['event'] = ucwords(str_replace('_', ' ', $event['event_type']));
                    $event_data['icon'] = '•';
                    $event_data['color'] = '#6c757d';
                    $event_data['details'] = 'MLS #' . $transaction_mls;
                    break;
            }
            
            $event_data['is_previous_transaction'] = true;
            $history_events[] = $event_data;
        }
    }
}

// Also get basic previous sales info as fallback
$previous_sales = MLD_Query::get_property_sales_history($address, $history_mls);
if (!empty($previous_sales)) {
    foreach ($previous_sales as $sale) {
        // Check if we already have history for this MLS number
        if (!isset($full_history[$sale['listing_id']])) {
            // Add sold event
            if (!empty($sale['close_date']) || !empty($sale['mlspin_ant_sold_date'])) {
                $history_events[] = array(
                    'date' => strtotime($sale['close_date'] ?? $sale['mlspin_ant_sold_date'] ?? $sale['modification_timestamp']),
                    'event' => 'Previously Sold',
                    'icon' => '🏷️',
                    'color' => '#28a745',
                    'price' => $sale['close_price'] ?? $sale['list_price'] ?? 0,
                    'details' => 'MLS #' . $sale['listing_id'],
                    'type' => 'previous_sale',
                    'transaction_mls' => $sale['listing_id'],
                    'is_previous_transaction' => true
                );
            }
            
            // Add listing event if we have creation date
            if (!empty($sale['creation_timestamp']) || !empty($sale['original_entry_timestamp'])) {
                $history_events[] = array(
                    'date' => strtotime($sale['creation_timestamp'] ?? $sale['original_entry_timestamp']),
                    'event' => 'Previously Listed',
                    'icon' => '📌',
                    'color' => '#17a2b8',
                    'price' => $sale['original_list_price'] ?? $sale['list_price'] ?? 0,
                    'details' => 'MLS #' . $sale['listing_id'],
                    'type' => 'previous_listing',
                    'transaction_mls' => $sale['listing_id'],
                    'is_previous_transaction' => true
                );
            }
        }
    }
}

// If viewing a sold property, also get current active listings at same address
$current_listings = array();
if (!empty($listing['standard_status']) && $listing['standard_status'] !== 'Active') {
    $current_listings = MLD_Query::get_current_listings_at_address($address, $history_mls);
    if (!empty($current_listings)) {
        foreach ($current_listings as $active) {
            $history_events[] = array(
                'date' => strtotime($active['creation_timestamp'] ?? $active['original_entry_timestamp'] ?? 'now'),
                'event' => 'Currently Listed',
                'icon' => '🏡',
                'color' => '#007bff',
                'price' => $active['list_price'],
                'details' => 'MLS #' . $active['listing_id'] . ' - Active',
                'type' => 'current_active',
                'transaction_mls' => $active['listing_id'],
                'is_current_active' => true
            );
        }
    }
}

// If no tracked history, create basic events from current listing data
if (!$has_tracked_history && !empty($listing)) {
    // Current status
    $history_events[] = array(
        'date' => strtotime($listing['modification_timestamp'] ?? 'now'),
        'event' => 'Current Status: ' . ucfirst(strtolower($listing['standard_status'] ?? 'Active')),
        'icon' => '📍',
        'color' => '#007bff',
        'price' => $listing['list_price'],
        'type' => 'current'
    );
    
    // Price reduction if applicable
    if (!empty($listing['original_list_price']) && 
        !empty($listing['list_price']) && 
        $listing['original_list_price'] != $listing['list_price']) {
        
        $price_change = $listing['list_price'] - $listing['original_list_price'];
        $history_events[] = array(
            'date' => strtotime($listing['modification_timestamp'] ?? 'now'),
            'event' => $price_change < 0 ? 'Price Reduced' : 'Price Increased',
            'icon' => $price_change < 0 ? '↓' : '↑',
            'color' => $price_change < 0 ? '#dc3545' : '#17a2b8',
            'price' => $listing['list_price'],
            'change' => $price_change,
            'old_price' => $listing['original_list_price'],
            'details' => 'Dates may be approximate',
            'type' => 'price_change'
        );
    }
    
    // Original listing
    if (!empty($listing['creation_timestamp']) || !empty($listing['original_entry_timestamp'])) {
        $history_events[] = array(
            'date' => strtotime($listing['creation_timestamp'] ?? $listing['original_entry_timestamp'] ?? 'now'),
            'event' => 'Listed for Sale',
            'icon' => '🏠',
            'color' => '#28a745',
            'price' => $listing['original_list_price'] ?? $listing['list_price'],
            'type' => 'listing'
        );
    }
}

// Sort by date descending (newest first)
usort($history_events, function($a, $b) {
    return $b['date'] - $a['date'];
});

// Group events by transaction for better visualization
$grouped_events = array();
$current_transaction = array();
$transaction_summaries = array();

foreach ($history_events as $event) {
    // Determine transaction key
    $transaction_key = $event['transaction_mls'] ?? ($event['type'] === 'current' ? 'current' : 'unknown');
    
    if (!isset($grouped_events[$transaction_key])) {
        $grouped_events[$transaction_key] = array();
        $transaction_summaries[$transaction_key] = array(
            'mls' => $transaction_key,
            'is_previous' => !empty($event['is_previous_transaction']),
            'is_current_active' => !empty($event['is_current_active']),
            'first_date' => $event['date'],
            'last_date' => $event['date'],
            'initial_price' => null,
            'final_price' => null,
            'sold' => false
        );
    }
    
    $grouped_events[$transaction_key][] = $event;
    
    // Update transaction summary
    if ($event['date'] < $transaction_summaries[$transaction_key]['first_date']) {
        $transaction_summaries[$transaction_key]['first_date'] = $event['date'];
    }
    if ($event['date'] > $transaction_summaries[$transaction_key]['last_date']) {
        $transaction_summaries[$transaction_key]['last_date'] = $event['date'];
    }
    
    if ($event['type'] === 'new_listing' || $event['type'] === 'previous_listing') {
        $transaction_summaries[$transaction_key]['initial_price'] = $event['price'] ?? null;
    }
    
    if ($event['type'] === 'sold' || $event['type'] === 'previous_sale') {
        $transaction_summaries[$transaction_key]['final_price'] = $event['price'] ?? null;
        $transaction_summaries[$transaction_key]['sold'] = true;
    }
}

// Calculate market insights
$total_days_on_market = 0;
$price_changes = 0;
$price_reductions = 0;
$highest_price = 0;
$lowest_price = PHP_INT_MAX;

foreach ($history_events as $event) {
    if (!empty($event['price']) && $event['price'] > $highest_price) {
        $highest_price = $event['price'];
    }
    if (!empty($event['price']) && $event['price'] < $lowest_price && $event['price'] > 0) {
        $lowest_price = $event['price'];
    }
    if ($event['type'] === 'price_change') {
        $price_changes++;
        if (!empty($event['change']) && $event['change'] < 0) {
            $price_reductions++;
        }
    }
    if (!empty($event['days_on_market'])) {
        $total_days_on_market = max($total_days_on_market, $event['days_on_market']);
    }
}

?>

<div class="mld-v3-history-container">
    
    <!-- Market Insights Summary -->
    <?php if (count($history_events) > 1): ?>
    <div class="mld-v3-market-insights">
        <h4>Market Insights</h4>
        <div class="mld-v3-insight-cards">
            <?php if ($price_changes > 0): ?>
            <div class="mld-v3-insight-card">
                <div class="insight-value"><?php echo $price_changes; ?></div>
                <div class="insight-label">Price Changes</div>
            </div>
            <?php endif; ?>
            
            <?php if ($highest_price > 0 && $lowest_price < PHP_INT_MAX && $highest_price != $lowest_price): ?>
            <div class="mld-v3-insight-card">
                <div class="insight-value">
                    <?php 
                    $price_range_percent = round((($highest_price - $lowest_price) / $highest_price) * 100, 1);
                    echo $price_range_percent . '%';
                    ?>
                </div>
                <div class="insight-label">Price Range</div>
            </div>
            <?php endif; ?>
            
            <?php if ($total_days_on_market > 0): ?>
            <div class="mld-v3-insight-card">
                <div class="insight-value"><?php echo $total_days_on_market; ?></div>
                <div class="insight-label">Total Days on Market</div>
            </div>
            <?php endif; ?>
            
            <?php 
            $previous_sales_count = count(array_filter($history_events, function($e) { 
                return $e['type'] === 'previous_sale'; 
            }));
            if ($previous_sales_count > 0): ?>
            <div class="mld-v3-insight-card">
                <div class="insight-value"><?php echo $previous_sales_count; ?></div>
                <div class="insight-label">Previous Sales</div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Enhanced Timeline View -->
    <div class="mld-v3-history-timeline">
        <h4>Property Timeline</h4>
        
        <?php if (empty($history_events)): ?>
            <p class="mld-v3-no-history">No property history available.</p>
        <?php else: ?>
            <div class="mld-v3-timeline">
                <?php 
                $current_transaction = '';
                foreach ($history_events as $index => $event): 
                    $transaction_key = $event['transaction_mls'] ?? ($event['type'] === 'current' ? 'current' : 'unknown');
                    
                    // Show transaction header for new transaction groups
                    if ($transaction_key !== $current_transaction && isset($transaction_summaries[$transaction_key])): 
                        $summary = $transaction_summaries[$transaction_key];
                        $current_transaction = $transaction_key;
                        
                        if ($summary['is_previous'] || $summary['is_current_active']): ?>
                            <div class="transaction-header">
                                <h5>
                                    <?php if ($summary['is_current_active']): ?>
                                        Current Active Listing - MLS #<?php echo esc_html($summary['mls']); ?>
                                    <?php else: ?>
                                        Previous Transaction - MLS #<?php echo esc_html($summary['mls']); ?>
                                    <?php endif; ?>
                                </h5>
                                <?php if ($summary['sold'] && $summary['initial_price'] && $summary['final_price']): ?>
                                    <div class="transaction-summary">
                                        Listed: $<?php echo number_format($summary['initial_price']); ?> | 
                                        Sold: $<?php echo number_format($summary['final_price']); ?>
                                        <?php 
                                        $price_diff = $summary['final_price'] - $summary['initial_price'];
                                        $price_percent = $summary['initial_price'] > 0 ? round(($price_diff / $summary['initial_price']) * 100, 1) : 0;
                                        ?>
                                        (<?php echo $price_diff >= 0 ? '+' : ''; ?>$<?php echo esc_html(number_format(abs($price_diff))); ?>, <?php echo $price_percent >= 0 ? '+' : ''; ?><?php echo esc_html($price_percent); ?>%)
                                    </div>
                                <?php else: ?>
                                    <div class="transaction-summary">
                                        <em>Full transaction history available when tracking began</em>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif;
                    endif; ?>
                    
                    <div class="mld-v3-timeline-item <?php echo ($event['is_previous_transaction'] ?? false) ? 'previous-transaction' : ''; ?> <?php echo ($event['is_current_active'] ?? false) ? 'current-active' : ''; ?>">
                        <div class="timeline-marker" style="background-color: <?php echo esc_attr($event['color']); ?>">
                            <span class="timeline-icon"><?php echo esc_html($event['icon']); ?></span>
                        </div>
                        
                        <div class="timeline-content">
                            <div class="timeline-header">
                                <h5 class="timeline-title"><?php echo esc_html($event['event']); ?></h5>
                                <span class="timeline-date">
                                    <?php echo date('M j, Y', $event['date']); ?>
                                </span>
                            </div>
                            
                            <div class="timeline-body">
                                <?php if (!empty($event['price'])): ?>
                                    <div class="timeline-price">
                                        $<?php echo number_format($event['price']); ?>
                                        
                                        <?php if (!empty($event['change'])): ?>
                                            <span class="price-change <?php echo $event['change'] < 0 ? 'negative' : 'positive'; ?>">
                                                <?php echo $event['change'] > 0 ? '+' : ''; ?>$<?php echo esc_html(number_format(abs($event['change']))); ?>
                                                
                                                <?php if (!empty($event['change_percent'])): ?>
                                                    (<?php echo $event['change_percent'] > 0 ? '+' : ''; ?><?php echo esc_html($event['change_percent']); ?>%)
                                                <?php endif; ?>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($event['price_per_sqft'])): ?>
                                            <span class="price-per-sqft">
                                                $<?php echo number_format($event['price_per_sqft']); ?>/sqft
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($event['details'])): ?>
                                    <div class="timeline-details">
                                        <?php echo esc_html($event['details']); ?>
                                        
                                        <?php if (!empty($event['transaction_mls']) && ($event['is_previous_transaction'] || $event['is_current_active'])): ?>
                                            <div class="timeline-view-link">
                                                <a href="/property/<?php echo esc_attr($event['transaction_mls']); ?>/" target="_blank" class="view-listing-link">
                                                    View Listing →
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($event['agent']) || !empty($event['office'])): ?>
                                    <div class="timeline-agent-info">
                                        <?php if (!empty($event['agent'])): ?>
                                            <span class="agent-name">Agent: <?php echo esc_html($event['agent']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($event['office'])): ?>
                                            <span class="office-name">Office: <?php echo esc_html($event['office']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($event['days_on_market'])): ?>
                                    <div class="timeline-market-time">
                                        Days on Market: <?php echo $event['days_on_market']; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Traditional Table View (Collapsible) -->
    <div class="mld-v3-history-table-section">
        <h4>
            <button class="mld-v3-collapse-toggle" data-target="history-table">
                Detailed History Table 
                <span class="toggle-icon">▼</span>
            </button>
        </h4>
        
        <div id="history-table" class="mld-v3-collapsible collapsed">
            <table class="mld-v3-history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Event</th>
                        <th>Price</th>
                        <th>Change</th>
                        <th>$/sqft</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history_events as $event): ?>
                        <tr>
                            <td><?php echo date('m/d/Y', $event['date']); ?></td>
                            <td>
                                <span style="color: <?php echo esc_attr($event['color']); ?>">
                                    <?php echo esc_html($event['icon']); ?>
                                </span>
                                <?php echo esc_html($event['event']); ?>
                            </td>
                            <td>
                                <?php if (!empty($event['price'])): ?>
                                    $<?php echo number_format($event['price']); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($event['change'])): ?>
                                    <span class="<?php echo $event['change'] < 0 ? 'negative' : 'positive'; ?>">
                                        <?php echo $event['change'] > 0 ? '+' : ''; ?>$<?php echo esc_html(number_format(abs($event['change']))); ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($event['price_per_sqft'])): ?>
                                    $<?php echo number_format($event['price_per_sqft']); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="details-cell">
                                <?php echo esc_html($event['details'] ?? ''); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
/* Market Insights */
.mld-v3-market-insights {
    margin-bottom: 2rem;
}

.mld-v3-insight-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.mld-v3-insight-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 1.5rem;
    text-align: center;
    border: 1px solid #e9ecef;
}

.insight-value {
    font-size: 2rem;
    font-weight: 700;
    color: #333;
    margin-bottom: 0.5rem;
}

.insight-label {
    font-size: 0.875rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Timeline Styles */
.mld-v3-timeline {
    position: relative;
    padding-left: 40px;
}

.mld-v3-timeline::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e9ecef;
}

.mld-v3-timeline-item {
    position: relative;
    margin-bottom: 2rem;
}

.timeline-marker {
    position: absolute;
    left: -25px;
    top: 0;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #007bff;
    color: white;
    font-size: 14px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.timeline-content {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.timeline-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.timeline-title {
    margin: 0;
    font-size: 1.125rem;
    font-weight: 600;
}

.timeline-date {
    font-size: 0.875rem;
    color: #6c757d;
}

.timeline-price {
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.price-change {
    margin-left: 1rem;
    font-size: 1rem;
}

.price-change.negative {
    color: #dc3545;
}

.price-change.positive {
    color: #28a745;
}

.price-per-sqft {
    display: block;
    font-size: 0.875rem;
    color: #6c757d;
    font-weight: normal;
    margin-top: 0.25rem;
}

.timeline-details {
    color: #6c757d;
    font-size: 0.875rem;
    margin-top: 0.5rem;
}

.timeline-agent-info {
    margin-top: 0.5rem;
    font-size: 0.875rem;
    color: #495057;
}

.timeline-agent-info span {
    display: inline-block;
    margin-right: 1rem;
}

.timeline-market-time {
    margin-top: 0.5rem;
    font-size: 0.875rem;
    color: #6c757d;
    font-style: italic;
}

/* Table Styles */
.mld-v3-history-table-section {
    margin-top: 2rem;
}

.mld-v3-collapse-toggle {
    background: none;
    border: none;
    font-size: 1.125rem;
    font-weight: 600;
    cursor: pointer;
    padding: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.toggle-icon {
    transition: transform 0.3s ease;
}

.mld-v3-collapsible {
    max-height: 1000px;
    overflow: hidden;
    transition: max-height 0.3s ease;
}

.mld-v3-collapsible.collapsed {
    max-height: 0;
}

.mld-v3-history-table {
    width: 100%;
    margin-top: 1rem;
    border-collapse: collapse;
}

.mld-v3-history-table th,
.mld-v3-history-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.mld-v3-history-table th {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.details-cell {
    font-size: 0.875rem;
    color: #6c757d;
}

/* View Listing Links */
.timeline-view-link {
    margin-top: 0.5rem;
}

.view-listing-link {
    display: inline-block;
    font-size: 0.875rem;
    color: #007bff;
    text-decoration: none;
    padding: 0.25rem 0.5rem;
    border: 1px solid #007bff;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.view-listing-link:hover {
    background-color: #007bff;
    color: white;
    text-decoration: none;
}

/* Transaction Grouping */
.transaction-header {
    margin: 2rem 0 1rem 0;
    padding: 1rem;
    background: #f8f9fa;
    border-left: 4px solid #007bff;
    border-radius: 4px;
}

.transaction-header h5 {
    margin: 0 0 0.5rem 0;
    color: #333;
    font-size: 1.125rem;
}

.transaction-summary {
    font-size: 0.875rem;
    color: #6c757d;
}

.mld-v3-timeline-item.previous-transaction {
    margin-left: 20px;
}

.mld-v3-timeline-item.current-active .timeline-content {
    background-color: #e3f2fd;
    border-color: #2196f3;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .mld-v3-timeline {
        padding-left: 30px;
    }
    
    .timeline-marker {
        left: -15px;
        width: 24px;
        height: 24px;
        font-size: 12px;
    }
    
    .mld-v3-timeline::before {
        left: 12px;
    }
    
    .timeline-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .mld-v3-history-table {
        font-size: 0.875rem;
    }
    
    .mld-v3-history-table th,
    .mld-v3-history-table td {
        padding: 0.5rem;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Collapsible sections
    $('.mld-v3-collapse-toggle').on('click', function() {
        const target = $(this).data('target');
        const $target = $('#' + target);
        const $icon = $(this).find('.toggle-icon');
        
        $target.toggleClass('collapsed');
        
        if ($target.hasClass('collapsed')) {
            $icon.text('▼');
        } else {
            $icon.text('▲');
        }
    });
});
</script>