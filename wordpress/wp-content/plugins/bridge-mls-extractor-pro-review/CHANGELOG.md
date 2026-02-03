# Bridge MLS Extractor Pro - Changelog

## Version 3.10 (September 7, 2025) - 🛠️ Critical Bug Fixes & Activity Logger

### 🚨 CRITICAL FIXES

#### **Database Schema Resolution**
- **Fixed SQL Syntax Errors**: Resolved dbDelta table creation failures caused by comment lines in SQL
- **Column Name Conflicts**: Fixed MySQL reserved word issues by renaming `timestamp` to `created_at`
- **Table Creation Success**: All database tables now create without errors during plugin activation
- **Missing Methods**: Added `get_table_name()` and `get_results()` methods to BME_Database_Manager

#### **Activity Logger System Implementation**
- **Complete Activity Tracking**: Comprehensive logging system for all extraction operations
- **RESO-Compliant Data Extraction**: Enhanced address parsing from listing data
- **Severity Handling**: Added safety checks to prevent undefined key warnings
- **Database Integration**: `bme_activity_logs` and `bme_api_requests` tables properly implemented

#### **Admin Dashboard Fixes**
- **Performance Dashboard**: Now shows accurate real-time extraction statistics
- **Activity Logs Page**: Complete implementation with search, filtering, and pagination
- **Extraction Profiles**: Last Run and Performance columns now populate correctly
- **Form Submissions**: Fixed permission errors on activity logs filter forms

#### **Error Resolution**
- **Array to String Conversion**: Fixed Asset Optimizer image size parameter type checking
- **Undefined Variables**: Resolved variable scope issues in admin dashboard methods
- **SQL Column References**: Updated all database queries to use `created_at` instead of `timestamp`
- **Permission Errors**: Fixed form action URLs to maintain proper WordPress admin context

### Technical Changes

#### `/bridge-mls-extractor-pro.php`
- **Version bump**: Updated from 3.3 to 3.10 to trigger database updates
- **Activity Logger Integration**: Proper dependency injection throughout service container
- **Enhanced Cleanup**: Comprehensive data removal for new activity and API request tables

#### `/includes/class-bme-database-manager.php`
- **SQL Syntax Fix**: Removed problematic comment lines causing dbDelta failures
- **Column Rename**: Changed `timestamp` to `created_at` in `bme_api_requests` table
- **Missing Methods**: Added `get_table_name()` and `get_results()` methods
- **Table Verification**: Enhanced table creation validation

#### `/includes/class-bme-activity-logger.php`
- **Comprehensive Implementation**: Complete activity logging system
- **Address Extraction**: Enhanced RESO-compliant address parsing
- **Safety Checks**: Added default severity handling to prevent undefined key errors
- **Integration Points**: Proper integration with data processor and extraction engine

#### `/includes/class-bme-admin.php`
- **Query Updates**: All database queries now use `created_at` instead of `timestamp`
- **Variable Scope**: Fixed undefined variable issues in chart data methods
- **Form URLs**: Fixed activity logs filter form to use proper admin URLs
- **Dashboard Accuracy**: Performance dashboard now shows real extraction data

#### `/includes/class-bme-asset-optimizer.php`
- **Type Safety**: Added proper handling for array/string image size parameters
- **Error Prevention**: Fixed "Array to string conversion" warnings

### Bug Fixes
- **Database Creation**: No more SQL syntax errors during plugin activation
- **Activity Logging**: Activity logs now populate correctly during extractions
- **Dashboard Data**: Performance metrics now show accurate real-time information
- **Form Permissions**: Activity logs filtering works without permission errors
- **Variable Scope**: Eliminated all undefined variable PHP warnings
- **Column References**: All database queries use consistent column naming

### User Experience Improvements
- **Accurate Dashboards**: Performance dashboard shows real extraction statistics
- **Functional Activity Logs**: Complete activity management with proper filtering
- **Error-Free Operation**: Eliminated PHP warnings and database errors
- **Consistent Interface**: All admin forms work correctly with proper URLs

## Version 2.3.0 (2025) - 🔥 Intelligent Batch Processing System

### 🚀 MAJOR NEW FEATURES

#### **Intelligent Batch Processing**
- **Automatic Session Management**: Processes extractions in 1000-listing sessions to prevent PHP timeouts
- **Zero-Timeout Extractions**: Eliminates all timeout issues on large datasets
- **Automatic Session Continuation**: 1-minute breaks between sessions with automatic restart
- **Progress Persistence**: Saves exact position after each session for seamless resumption
- **Memory Management**: Automatic cleanup between sessions prevents memory exhaustion

#### **📊 Extraction Preview & Planning**
- **Real-time Listing Count**: Shows exactly how many listings match your filters before extraction
- **Batch Execution Plan**: Detailed breakdown of sessions, API calls, and timing estimates
- **Configuration Summary**: Visual display of all configured filters and settings
- **Time Estimation**: Accurate completion time estimates based on listing count
- **Beautiful Admin Interface**: Enhanced UI with statistics boxes and session breakdowns

#### **⚡ Optimized Bridge API Usage**
- **Maximum API Efficiency**: Uses Bridge API limit of 200 listings per call
- **Smart Pagination**: Proper `@odata.nextLink` handling for optimal performance  
- **Enhanced Error Handling**: Better API response validation and error messages
- **Rate Limiting**: Built-in protection against API rate limits

#### **🔧 Robust Background Processing**
- **WordPress Cron Integration**: Proper hook registration for session continuation
- **Fallback Safety System**: Backup triggers ensure continuation even if cron fails
- **Enhanced Logging**: Detailed batch processing logs for monitoring and debugging
- **Admin Controls**: Manual session management and batch status monitoring

### Technical Improvements
- **New Classes**: `BME_Batch_Manager` for intelligent session management
- **Enhanced Background Processor**: Proper cron hook registration and fallback mechanisms
- **Updated Extraction Engine**: Session-aware processing with batch integration
- **Improved API Client**: Session-limit support and optimized pagination
- **Better Error Recovery**: Multiple layers of continuation assurance

### User Experience Enhancements
- **Visual Batch Plans**: Beautiful admin interface showing extraction details
- **Real-time Progress**: Session-by-session progress updates
- **No Manual Intervention**: Large extractions complete automatically
- **Better Feedback**: Clear status messages and progress indicators

## Version 2.2.0 (2025) - Large Extraction Safety Features

### Major Safety Enhancements

#### Memory and Timeout Protection
- **Extraction Safety Manager** - Monitors memory usage and execution time
- **Automatic pausing** when approaching memory or time limits
- **Memory cleanup** with garbage collection and cache flushing
- **Configurable safety thresholds** (80% of available resources by default)

#### Dynamic Batch Size Adjustment
- **Adaptive batch sizing** based on available memory and performance
- **Automatic reduction** when memory pressure detected
- **Performance-based increases** when resources available
- **Range: 10-500 listings per batch** with intelligent adjustment

#### API Rate Limiting
- **Dynamic rate limiting** based on API response patterns
- **Configurable delays** from 0.5 to 10 seconds between calls
- **Request tracking** to prevent hitting rate limits
- **Automatic backoff** when approaching limits

#### Background Processing
- **Optional background execution** for large extractions
- **Automatic resumption** after pauses or failures
- **Lock management** to prevent duplicate runs
- **Status tracking** visible in admin UI

#### Extraction Resumption
- **Checkpoint saving** for interrupted extractions
- **Automatic resume** from last successful point
- **State persistence** across server restarts
- **Resume capability** for up to 24 hours

### New Admin Features
- **Background processing toggle** per extraction
- **Configurable initial batch size** (10-500)
- **API rate limit settings** (Dynamic/Fast/Normal/Slow)
- **System capability checks** with warnings
- **Real-time safety statistics** during extraction

### Technical Implementation
- New class: `BME_Extraction_Safety` - Manages all safety features
- New class: `BME_Background_Processor` - Handles background execution
- Enhanced: `BME_API_Client` - Dynamic batch sizes and rate limiting
- Enhanced: `BME_Extraction_Engine` - Integration with safety manager
- Updated: `BME_Admin` - New UI controls for safety settings

## Version 2.1.2 (2025)

### Major Changes

#### Complete Status Filter Removal
- **Removed all status filtering from API queries** - The system now fetches ALL listings regardless of status
- **Made lookback period required** for all extractions to prevent fetching ancient data
- **Simplified extraction configuration** - Removed status selection UI from admin interface
- **Updated API client** to use only date-based filtering with ModificationTimestamp

#### RESO Best Practices Implementation
- **Implemented industry-standard replication pattern** following RESO guidelines
- **Added MlgCanView field handling** for proper deletion detection
- **Uses ModificationTimestamp** for all incremental synchronization
- **Fetches all modified listings** to properly detect status changes

#### Table Transition System
- **Automatic table transitions** when listing status changes (e.g., Active → Closed)
- **Preserves all related data** during transitions (details, location, financial, features)
- **Maintains extraction ID tracking** through status changes
- **Property history preserved** during all transitions

### Detailed File Changes

#### `/includes/class-bme-admin.php`
- Removed status selection checkboxes from extraction configuration form
- Simplified UI to show only required lookback period field
- Added explanation that ALL statuses will be fetched
- Removed status validation logic from save handler
- Removed status group conflict checking
- Added `delete_post_meta()` to clean up existing status metadata
- Made lookback period validation required for all extractions

#### `/includes/class-bme-api-client.php`
- Complete rewrite of `build_filter_query()` method
- Removed all status-based filtering logic
- Now uses only ModificationTimestamp for filtering
- Simplified to use lookback period for both initial and incremental syncs
- Added comprehensive debug logging for filter queries

#### `/includes/class-bme-data-processor.php`
- Updated `archived_statuses` array: removed "Pending" and "Active Under Contract"
- These statuses now go to active tables: Active, Active Under Contract, Pending
- Archive statuses remain: Closed, Expired, Withdrawn, Canceled
- Added complete table transition system:
  - `process_core_listing_with_transition()` - Handles status-based table placement
  - `move_listing_to_archive()` - Moves listings from active to archive
  - `move_listing_to_active()` - Moves listings from archive to active
- Added MlgCanView field handling for deletion detection
- Removed status filtering check that rejected non-matching listings
- Commented out obsolete `handle_status_mismatch_listing()` method

#### `/includes/class-bme-extraction-engine.php`
- Modified `get_extraction_config()` to remove status retrieval
- Added first-run detection logic
- Made lookback period required for all extractions
- Added stale listing cleanup mechanism (30+ days)
- Automatic migration trigger on plugin initialization
- Improved error handling and logging

#### `/includes/class-bme-database-manager.php`
- Added migration method `migrate_pending_and_under_contract_listings()`
- Implements transaction-safe data migration between tables
- Tracks migration with option `bme_pro_migration_pending_active_v1`
- Comprehensive migration of all related tables

#### `/mls-listings-display/includes/class-mld-query.php`
- Updated archived statuses array to match extractor plugin
- Fixed property history queries to work with new status logic
- Ensured compatibility with table transition system

### Bug Fixes
- **Fixed canceled listings not updating** - System now properly detects and handles status changes
- **Fixed first-run extractions fetching all historical data** - Now properly detects first runs and requires lookback
- **Fixed status changes not being detected** - Removed status filtering from incremental syncs
- **Fixed thousands of old listings being fetched** - Implemented proper lookback period enforcement

### Performance Improvements
- Reduced unnecessary API calls by implementing proper incremental sync
- Improved database query efficiency with proper indexing
- Added transaction support for atomic operations
- Implemented batch processing for large datasets

### Migration Notes
When updating to this version:
1. Existing extractions will automatically have their status filters removed
2. The migration will automatically run to move Pending and Active Under Contract listings to active tables
3. Users must set a lookback period for all extractions
4. First sync after update may take longer as it establishes proper baseline

### Breaking Changes
- Status selection is no longer available in extraction configuration
- All extractions now require a lookback period to be set
- API filter queries have completely changed structure

### Deprecations
- `handle_status_mismatch_listing()` method is deprecated
- Status-based extraction filtering is deprecated
- Mixed status group validation is deprecated

## Previous Versions

### Version 2.1.1
- Initial table transition system implementation
- Added property history tracking

### Version 2.1.0
- Added MlgCanView field support
- Improved deletion detection

### Version 2.0.0
- Major refactor with normalized database architecture
- Introduced active/archive table separation