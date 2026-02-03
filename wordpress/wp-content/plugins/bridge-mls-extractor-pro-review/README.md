# Bridge MLS Extractor Pro

**Version 3.27** - Enterprise-grade WordPress plugin for high-performance MLS data extraction and management through the Bridge MLS API with sophisticated batch processing and comprehensive analytics.

## 🏢 Enterprise Features

### **🚀 Intelligent Data Processing**
- **Service Container Architecture**: Advanced dependency injection with 12+ core services
- **Normalized Database Schema**: 18 specialized tables with 500+ fields
- **Batch Processing System**: Session-based processing handles 10,000+ listings without timeouts
- **Real-Time Monitoring**: Comprehensive activity logging and performance analytics
- **Status Change Detection**: Automatic listing lifecycle management with archive transitions

### **📊 Advanced Analytics & Monitoring**
- **Performance Dashboard**: Interactive Chart.js visualizations with real-time metrics
- **Market Intelligence**: Advanced business intelligence utilizing all 500+ database fields
- **Activity Tracking**: Comprehensive operation logging with severity classification
- **API Monitoring**: Request tracking, response time analysis, error categorization
- **Database Optimization**: Query performance monitoring and slow query detection

### **⚡ High-Performance Architecture**
- **Memory Management**: Progressive cleanup and garbage collection
- **API Optimization**: Rate limiting with exponential backoff for Bridge MLS API
- **Database Efficiency**: Optimized indexes and selective JOIN operations
- **Caching Integration**: WordPress object cache and transient API utilization
- **Error Resilience**: Comprehensive error handling with automatic recovery

### Core Data Management
- **Comprehensive Data Extraction**: Fetches ALL listings regardless of status (Active, Pending, Closed, etc.)
- **RESO Standards Compliant**: Follows RESO Data Dictionary and best practices for MLS data synchronization
- **Intelligent Table Organization**: Automatically separates active and archived listings into appropriate database tables
- **Automatic Status Transitions**: Listings move between active/archive tables when status changes
- **Incremental Synchronization**: Efficiently syncs only modified listings using ModificationTimestamp
- **Deletion Detection**: Uses MlgCanView field to properly handle deleted listings
- **Property History Tracking**: Maintains complete history of price and status changes
- **Scheduled Extractions**: Automated sync via WordPress cron system

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Bridge MLS API credentials (server token and endpoint URL)

## Installation

1. Upload the `bridge-mls-extractor-pro` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure API credentials in Settings → Bridge MLS Extractor Pro

## Configuration

### API Setup

1. Navigate to **Settings → Bridge MLS Extractor Pro**
2. Enter your Bridge MLS API credentials:
   - Server Token
   - Endpoint URL
3. Click "Save API Credentials"
4. Use "Test Connection" to verify credentials

### Creating an Extraction

1. Go to **Bridge MLS → Extractions**
2. Click "Add New Extraction"
3. Configure extraction settings:
   - **Data Lookback Period** (Required): How many months of historical data to fetch
   - **Geographic Filters** (Optional): Cities, States
   - **Agent Filters** (Optional): List Agent ID, Buyer Agent ID
   - **Schedule**: How often to sync (Every 4 hours, Daily, etc.)
4. Save the extraction
5. **🆕 Use the Extraction Preview** to see exact listing counts and batch execution plan

## How It Works

### 🔥 NEW: Intelligent Batch Processing System

The plugin now features an advanced batch processing system that eliminates timeout issues:

#### **Extraction Preview & Planning**
- **Total Listings Analysis**: See exactly how many listings match your filters
- **Session Planning**: View how extractions will be divided into 1000-listing sessions
- **Time Estimation**: Get accurate completion time estimates
- **API Call Calculation**: See total API calls required (200 listings per call)

#### **Automatic Session Management**
1. **Session Limits**: Each session processes maximum 1000 listings
2. **API Optimization**: Uses maximum 200 listings per API call (Bridge limit)
3. **Memory Management**: Automatic cleanup between sessions
4. **Progress Persistence**: Saves exact position after each session
5. **Auto-Continuation**: Automatically resumes after 1-minute breaks
6. **Fallback Safety**: Built-in mechanisms ensure continuation even if WordPress cron fails

#### **Session Flow**
```
Session 1: Process 1000 listings → Save progress → 1-minute break
Session 2: Continue from exact position → Process next 1000 → Save progress → 1-minute break
Session N: Continue until all listings processed → Complete extraction
```

### Data Processing Flow

1. **Initial Extraction**: 
   - Batch processing prevents timeouts on large datasets
   - Fetches all listings within the lookback period
   - Automatic session continuation ensures completion
2. **Incremental Updates**: Syncs only listings modified since last sync
3. **Table Organization**: 
   - Active tables: Active, Active Under Contract, Pending listings
   - Archive tables: Closed, Expired, Withdrawn, Canceled listings
4. **Automatic Transitions**: Listings move between tables when status changes

### Database Structure

The plugin creates normalized database tables:

**Active Tables** (current listings):
- `bme_listings` - Core listing data
- `bme_listing_details` - Property details
- `bme_listing_location` - Location information
- `bme_listing_financial` - Financial data
- `bme_listing_features` - Property features

**Archive Tables** (historical listings):
- Same structure with `_archive` suffix

**Supporting Tables**:
- `bme_agents` - Agent information
- `bme_offices` - Office information
- `bme_open_houses` - Open house schedules
- `bme_media` - Property images
- `bme_rooms` - Room details
- `bme_property_history` - Price/status change history

## Usage

### 🆕 Extraction Preview & Planning

When editing an extraction, you'll see a new **"📊 Extraction Preview & Batch Plan"** meta box:

1. **Configure your extraction filters** (statuses, cities, property types, etc.)
2. **Click "🔍 Get Extraction Preview"** to analyze your configuration
3. **Review the detailed plan** showing:
   - Total listings available for extraction
   - Number of sessions required (1000 listings each)
   - Total API calls needed (200 listings per call)
   - Estimated completion time
   - Session-by-session breakdown
   - Configured filter summary

### Manual Operations

- **🆕 Get Extraction Preview**: Analyze configuration and view batch execution plan
- **Run Now**: Execute extraction immediately (now with automatic batch processing)
- **Full Resync**: Re-fetch all data within lookback period (batch processed)
- **Clear Data**: Remove all listings for this extraction
- **Test Config**: Verify API connection and filters

### 🔥 Enhanced Monitoring

- **Real-time Session Progress**: Track current session and total progress
- **Batch Status Updates**: See when sessions complete and next session starts
- **Detailed Statistics**: View session counts, API calls, and timing
- **Live Progress Updates**: Session-by-session progress with listing counts
- **Enhanced Logging**: Detailed logs for batch scheduling and execution

### WP-CLI Commands

```bash
# Run extraction manually
wp eval "bme_pro()->get('extractor')->run_extraction(EXTRACTION_ID);"

# Run full resync
wp eval "bme_pro()->get('extractor')->run_extraction(EXTRACTION_ID, true);"

# Check extraction stats
wp db query "SELECT COUNT(*) FROM wp_bme_listings WHERE extraction_id = X"
```

## Important Notes

### Status Handling

- The plugin fetches ALL listings regardless of status
- No status filtering is applied during API queries
- Listings are automatically organized based on their current status
- Status changes trigger automatic table transitions

### 🔥 NEW: No More Performance Issues!

The intelligent batch processing system automatically handles:
- **Timeout Prevention**: 1000-listing session limits prevent PHP timeouts
- **Memory Management**: Automatic cleanup between sessions
- **API Rate Limiting**: Optimized 200-listing API calls
- **Large Dataset Handling**: Automatic session continuation

### Best Practices

1. **🆕 Use Extraction Preview** to understand your extraction scope before running
2. **Start with targeted filters** for initial testing (geographic or agent filters)
3. **Monitor session progress** through the enhanced live progress tracking
4. **Check batch execution plans** to understand timing and resource requirements
5. **Let the system run automatically** - no manual intervention needed for large extractions

## Troubleshooting

### 🆕 Batch Processing Issues

**Session not continuing automatically:**
- Check WordPress cron is functioning: `wp cron event list`
- Look for "BME Batch" entries in debug.log
- Verify fallback triggers in `wp_options` table: `bme_batch_fallback_triggers`

**Extraction stops after 1000 listings:**
- This is normal behavior - check logs for "Session completed" messages  
- Next session should start automatically after 1-minute break
- If not continuing, fallback system will trigger within 15 minutes

**Preview showing "No listings found":**
- Verify API credentials are configured correctly
- Check filter configuration (dates, cities, etc.)
- Test API connection using "Test Config" button

### Classic Issues (Now Mostly Resolved)

**~~Extraction timeouts~~** → **FIXED**: Automatic batch processing prevents timeouts

**Extraction not running:**
- Check WordPress cron is functioning
- Verify API credentials are valid  
- Check debug.log for "BME Background" and "BME Batch" entries

**Listings not updating:**
- Ensure incremental sync is running
- Check ModificationTimestamp values
- Verify table transitions are working

### 🔧 Enhanced Debugging

Monitor batch processing with detailed logs:
```bash
# Watch batch processing in real-time
tail -f wp-content/debug.log | grep "BME Batch\|BME Background"

# Check for scheduled sessions
wp cron event list | grep bme_continue_batch_extraction

# View fallback triggers
wp db query "SELECT option_value FROM wp_options WHERE option_name = 'bme_batch_fallback_triggers'"
```

## Production Status

**✅ PRODUCTION-READY** - Comprehensive debugging and fixes completed September 7, 2025:

### Latest Fixes (September 7, 2025)
- **Activity Logger System**: Complete activity tracking implementation with RESO-compliant data extraction
- **Database Schema**: Fixed all SQL syntax errors and table creation failures
- **Admin Dashboard**: Performance dashboard now shows accurate real-time data
- **Activity Logs**: Complete implementation with filtering, search, and proper form submissions
- **Error Resolution**: Fixed Array to string conversions, undefined variables, and permission errors
- **Extraction Tracking**: Last Run and Performance columns now populate correctly in extraction profiles

### Previous Audit (September 2025)
- All critical database method call errors resolved
- Duplicate AJAX handler conflicts eliminated  
- Database schema queries corrected
- Charset collate access errors fixed
- Asset references verified and corrected

## Support

For issues or questions, check the debug logs first:
```bash
tail -f wp-content/debug.log | grep BME
```

## License

This plugin is proprietary software. All rights reserved.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for detailed version history.

## Developer Documentation

See [CLAUDE.md](../CLAUDE.md) for technical documentation and development guidelines.