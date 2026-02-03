<?php
/**
 * Database Cleanup Script for BMN Schools
 *
 * Fixes:
 * 1. Private schools incorrectly marked as public
 * 2. Regional schools assigned to wrong districts
 * 3. Unassigned schools (district_id = 0)
 * 4. Orphan/duplicate district entries
 *
 * Run via: WP Admin > BMN Schools > Tools > Database Cleanup
 * Or via WP-CLI: wp eval-file database-cleanup.php
 *
 * @package BMN_Schools
 * @since 0.6.28
 */

// Prevent direct access
if (!defined('WPINC') && !defined('WP_CLI')) {
    die('Direct access not allowed.');
}

class BMN_Schools_Database_Cleanup {

    /**
     * @var wpdb WordPress database abstraction
     */
    private $wpdb;

    /**
     * @var array Results of cleanup operations
     */
    private $results = [];

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * Run all cleanup operations
     *
     * @param bool $dry_run If true, only report what would be changed
     * @return array Results of all operations
     */
    public function run_all($dry_run = false) {
        $this->results = [
            'dry_run' => $dry_run,
            'timestamp' => current_time('mysql'),
            'operations' => [],
        ];

        // 1. Fix private schools marked as public
        $this->fix_private_schools($dry_run);

        // 2. Create missing regional districts
        $this->create_missing_regional_districts($dry_run);

        // 3. Fix regional schools in wrong districts
        $this->fix_regional_school_assignments($dry_run);

        // 4. Assign unassigned schools to districts
        $this->assign_unassigned_schools($dry_run);

        // 5. Mark orphan districts as inactive (don't delete - might have boundary data)
        $this->mark_orphan_districts($dry_run);

        // 6. Update district school counts
        if (!$dry_run) {
            $this->update_district_counts();
        }

        return $this->results;
    }

    /**
     * Fix private schools incorrectly marked as public
     */
    private function fix_private_schools($dry_run) {
        $schools_table = $this->wpdb->prefix . 'bmn_schools';

        // Pattern-based identification of private schools
        $private_patterns = [
            // Catholic/Religious schools
            "name LIKE '%Catholic%'",
            "name LIKE '%St. %' AND name NOT LIKE '%St. %Street%'",
            "name LIKE '%Saint %'",
            "name LIKE '%Notre Dame%' AND name NOT LIKE '%Charter%'",
            "name LIKE '%Christian%' AND name NOT LIKE '%Charter%'",
            "name LIKE '%Hebrew%'",
            "name LIKE '%Jewish%'",
            "name LIKE '%Lutheran%'",
            "name LIKE '%Baptist%'",
            "name LIKE '%SDA %' OR name LIKE '% SDA School'",
            "name LIKE '%Holy %' AND name NOT LIKE '%Holyoke%'",
            "name LIKE '%Aquinas%'",
            "name LIKE '%Montessori%'",
            "name LIKE '%Waldorf%'",
            "name LIKE '%Immaculata%'",

            // Independent/Private Day Schools
            "name LIKE '%Day School%' AND name NOT LIKE '%Charter%'",
            "name LIKE '%, Inc.%'",
            "name LIKE '%, Inc%' AND name NOT LIKE '%Charter%'",
            "name LIKE '%Country Day%'",

            // Therapeutic/Special Ed Private (approved private schools)
            "name LIKE '%Therapeutic%'",
            "name LIKE 'JRI %'",
            "name LIKE '%Devereux%'",
            "name LIKE '%Perkins School%'",
            "name LIKE '%Learning Group%'",
            "name LIKE '%Eagle Hill School%'",
            "name LIKE 'Carroll School'",
            "name LIKE 'Birches School%'",
            "name LIKE 'Willow Hill School'",
            "name LIKE 'Corwin-Russell%'",
            "name LIKE 'Landmark School%'",
            "name LIKE 'Fenn School'",
            "name LIKE '%Meadowridge%'",
            "name LIKE '%Collaborative%' AND name NOT LIKE '%Charter%'",

            // Known private schools by name
            "name = 'Middlesex School'",
            "name = 'Concord Academy'",
            "name LIKE '%Governors Academy%' OR name LIKE '%Governor\\'s Academy%'",
            "name = 'Berkshire School'",
            "name LIKE 'Buxton School%'",
            "name LIKE 'Fusion Academy%'",
            "name LIKE 'CATS Academy%'",
            "name = 'Pine Cobble School'",
            "name = 'Brookwood School'",
            "name = 'Belmont Day School'",
            "name LIKE 'Brimmer%'",
        ];

        // Build WHERE clause
        $where_patterns = implode(' OR ', $private_patterns);

        // Exclude charter and innovation schools
        $exclude_clause = "AND name NOT LIKE '%Charter%' AND name NOT LIKE '%Innovation%' AND name NOT LIKE '%Horace Mann%'";

        $sql = "SELECT id, name, city, school_type
                FROM {$schools_table}
                WHERE school_type = 'public'
                AND ({$where_patterns})
                {$exclude_clause}";

        $schools = $this->wpdb->get_results($sql);

        $this->results['operations']['fix_private_schools'] = [
            'description' => 'Mark private schools correctly',
            'count' => count($schools),
            'schools' => [],
        ];

        if ($dry_run) {
            foreach ($schools as $school) {
                $this->results['operations']['fix_private_schools']['schools'][] = [
                    'id' => $school->id,
                    'name' => $school->name,
                    'action' => 'Would change school_type from public to private',
                ];
            }
        } else {
            foreach ($schools as $school) {
                $this->wpdb->update(
                    $schools_table,
                    ['school_type' => 'private'],
                    ['id' => $school->id]
                );
                $this->results['operations']['fix_private_schools']['schools'][] = [
                    'id' => $school->id,
                    'name' => $school->name,
                    'action' => 'Changed school_type to private',
                ];
            }
        }
    }

    /**
     * Create missing regional districts
     */
    private function create_missing_regional_districts($dry_run) {
        $districts_table = $this->wpdb->prefix . 'bmn_school_districts';

        // Regional districts that need to be created (not already in database)
        // Most regional districts already exist with slightly different names
        $regional_districts = [
            // These don't exist yet and are needed for school assignments
            ['name' => 'Narragansett Regional School District', 'state_district_id' => '2508470', 'type' => 'Regional'],
            ['name' => 'North Middlesex Regional School District', 'state_district_id' => '2508970', 'type' => 'Regional'],
            ['name' => 'Mount Everett Regional School District', 'state_district_id' => '2508280', 'type' => 'Regional'],
        ];

        $this->results['operations']['create_regional_districts'] = [
            'description' => 'Create missing regional district entries',
            'count' => 0,
            'districts' => [],
        ];

        foreach ($regional_districts as $district) {
            // Check if district exists
            $exists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$districts_table} WHERE name = %s OR state_district_id = %s",
                $district['name'], $district['state_district_id']
            ));

            if (!$exists) {
                if ($dry_run) {
                    $this->results['operations']['create_regional_districts']['districts'][] = [
                        'name' => $district['name'],
                        'action' => 'Would create new district',
                    ];
                } else {
                    $this->wpdb->insert($districts_table, [
                        'name' => $district['name'],
                        'state_district_id' => $district['state_district_id'],
                        'type' => $district['type'],
                        'state' => 'MA',
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ]);
                    $this->results['operations']['create_regional_districts']['districts'][] = [
                        'id' => $this->wpdb->insert_id,
                        'name' => $district['name'],
                        'action' => 'Created new district',
                    ];
                }
                $this->results['operations']['create_regional_districts']['count']++;
            }
        }
    }

    /**
     * Fix regional schools assigned to wrong districts
     */
    private function fix_regional_school_assignments($dry_run) {
        $schools_table = $this->wpdb->prefix . 'bmn_schools';
        $districts_table = $this->wpdb->prefix . 'bmn_school_districts';

        // Mapping: school pattern => correct district name
        $school_to_district = [
            // Lincoln-Sudbury
            ['pattern' => 'Lincoln-Sudbury Regional High School', 'district' => 'Lincoln-Sudbury School District'],

            // Dover-Sherborn
            ['pattern' => 'Dover-Sherborn Regional High School', 'district' => 'Dover-Sherborn School District'],
            ['pattern' => 'Dover-Sherborn Regional Middle School', 'district' => 'Dover-Sherborn School District'],

            // Concord-Carlisle
            ['pattern' => 'Concord Carlisle High School', 'district' => 'Concord-Carlisle School District'],

            // Somerset-Berkley
            ['pattern' => 'Somerset Berkley Regional High School', 'district' => 'Somerset-Berkley School District'],

            // Northborough-Southborough (Algonquin)
            ['pattern' => 'Algonquin Regional High School', 'district' => 'Northborough-Southborough School District'],

            // King Philip (District ID 158)
            ['pattern' => 'King Philip Regional High School', 'district' => 'King Philip School District'],
            ['pattern' => 'King Philip Regional Middle School', 'district' => 'King Philip School District'],

            // Nauset (District ID 213)
            ['pattern' => 'Nauset Regional High School', 'district' => 'Nauset School District'],
            ['pattern' => 'Nauset Regional Middle School', 'district' => 'Nauset School District'],

            // Triton (District ID 304)
            ['pattern' => 'Triton Regional High School', 'district' => 'Triton School District'],
            ['pattern' => 'Triton Regional Middle School', 'district' => 'Triton School District'],

            // Pentucket (District ID 246)
            ['pattern' => 'Pentucket Regional Senior High School', 'district' => 'Pentucket School District'],
            ['pattern' => 'Pentucket Regional Middle School', 'district' => 'Pentucket School District'],

            // Tantasqua - keep in Sturbridge but ensure properly mapped
            ['pattern' => 'Tantasqua Regional%', 'district' => 'Sturbridge School District'],

            // Wachusett (District ID 309)
            ['pattern' => 'Wachusett Regional High School', 'district' => 'Wachusett School District'],

            // Narragansett - need to create this district
            ['pattern' => 'Narragansett Regional High School', 'district' => 'Narragansett Regional School District'],
            ['pattern' => 'Narragansett Middle School', 'district' => 'Narragansett Regional School District'],

            // North Middlesex - need to create this district
            ['pattern' => 'North Middlesex Regional High School', 'district' => 'North Middlesex Regional School District'],

            // Gateway (District ID 125)
            ['pattern' => 'Gateway Regional High School', 'district' => 'Gateway School District'],
            ['pattern' => 'Gateway Regional Middle School', 'district' => 'Gateway School District'],

            // Hoosac Valley (District ID 151)
            ['pattern' => 'Hoosac Valley%School', 'district' => 'Hoosac Valley School District'],

            // Berkshire Hills (District ID 61)
            ['pattern' => 'Monument Mountain Regional High School', 'district' => 'Berkshire Hills School District'],
            ['pattern' => 'W.E.B. Du Bois Regional Middle School', 'district' => 'Berkshire Hills School District'],
            ['pattern' => 'Muddy Brook Regional Elementary School', 'district' => 'Berkshire Hills School District'],

            // Nashoba (District ID 211)
            ['pattern' => 'Nashoba Regional High School', 'district' => 'Nashoba School District'],

            // Monomoy (District ID 204)
            ['pattern' => 'Monomoy Regional High School', 'district' => 'Monomoy Regional School District'],
            ['pattern' => 'Monomoy Regional Middle School', 'district' => 'Monomoy Regional School District'],

            // Quabbin (District ID 255)
            ['pattern' => 'Quabbin Regional High School', 'district' => 'Quabbin School District'],
            ['pattern' => 'Quabbin Regional Middle School', 'district' => 'Quabbin School District'],

            // Quaboag (District ID 256)
            ['pattern' => 'Quaboag Regional High School', 'district' => 'Quaboag Regional School District'],
            ['pattern' => 'Quaboag Regional Middle%', 'district' => 'Quaboag Regional School District'],

            // Mohawk Trail (District ID 202)
            ['pattern' => 'Mohawk Trail Regional High School', 'district' => 'Mohawk Trail School District'],

            // Mount Greylock (District ID 206)
            ['pattern' => 'Mount Greylock Regional High School', 'district' => 'Mount Greylock School District'],

            // Mount Everett - need to create
            ['pattern' => 'Mount Everett Regional School', 'district' => 'Mount Everett Regional School District'],

            // Pioneer Valley (District ID 248)
            ['pattern' => 'Pioneer Valley Regional School', 'district' => 'Pioneer Valley School District'],

            // Manchester-Essex (District ID 178)
            ['pattern' => 'Manchester Essex Regional High School', 'district' => 'Manchester Essex Regional School District'],
            ['pattern' => 'Manchester Essex Regional Middle School', 'district' => 'Manchester Essex Regional School District'],
        ];

        $this->results['operations']['fix_regional_assignments'] = [
            'description' => 'Reassign regional schools to correct districts',
            'count' => 0,
            'schools' => [],
        ];

        foreach ($school_to_district as $mapping) {
            // Get district ID
            $district_id = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$districts_table} WHERE name = %s",
                $mapping['district']
            ));

            if (!$district_id) {
                // Try partial match
                $district_id = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT id FROM {$districts_table} WHERE name LIKE %s LIMIT 1",
                    '%' . $mapping['district'] . '%'
                ));
            }

            if (!$district_id) {
                $this->results['operations']['fix_regional_assignments']['schools'][] = [
                    'pattern' => $mapping['pattern'],
                    'district' => $mapping['district'],
                    'action' => 'ERROR: District not found',
                ];
                continue;
            }

            // Find schools matching pattern
            $schools = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, name, district_id FROM {$schools_table} WHERE name LIKE %s",
                str_replace('*', '%', $mapping['pattern'])
            ));

            foreach ($schools as $school) {
                if ($school->district_id != $district_id) {
                    if ($dry_run) {
                        $this->results['operations']['fix_regional_assignments']['schools'][] = [
                            'id' => $school->id,
                            'name' => $school->name,
                            'action' => "Would change district_id from {$school->district_id} to {$district_id} ({$mapping['district']})",
                        ];
                    } else {
                        $this->wpdb->update(
                            $schools_table,
                            ['district_id' => $district_id],
                            ['id' => $school->id]
                        );
                        $this->results['operations']['fix_regional_assignments']['schools'][] = [
                            'id' => $school->id,
                            'name' => $school->name,
                            'action' => "Changed district_id to {$district_id} ({$mapping['district']})",
                        ];
                    }
                    $this->results['operations']['fix_regional_assignments']['count']++;
                }
            }
        }
    }

    /**
     * Assign unassigned schools to districts based on city
     */
    private function assign_unassigned_schools($dry_run) {
        $schools_table = $this->wpdb->prefix . 'bmn_schools';
        $districts_table = $this->wpdb->prefix . 'bmn_school_districts';

        // Get unassigned schools (excluding regional schools which are handled separately)
        $unassigned = $this->wpdb->get_results("
            SELECT id, name, city
            FROM {$schools_table}
            WHERE (district_id = 0 OR district_id IS NULL)
            AND name NOT LIKE '%Regional%'
            ORDER BY city, name
        ");

        $this->results['operations']['assign_unassigned'] = [
            'description' => 'Assign schools to districts based on city',
            'count' => 0,
            'schools' => [],
        ];

        foreach ($unassigned as $school) {
            if (empty($school->city)) {
                continue;
            }

            // Try to find district by city name
            $city_clean = strtoupper(trim($school->city));

            // Try exact match first
            $district_id = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$districts_table} WHERE UPPER(name) LIKE %s LIMIT 1",
                $city_clean . ' School District'
            ));

            // Try without "School District"
            if (!$district_id) {
                $district_id = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT id FROM {$districts_table} WHERE UPPER(name) = %s LIMIT 1",
                    $city_clean
                ));
            }

            // Try partial match
            if (!$district_id) {
                $district_id = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT id FROM {$districts_table} WHERE UPPER(name) LIKE %s LIMIT 1",
                    '%' . $city_clean . '%'
                ));
            }

            if ($district_id) {
                if ($dry_run) {
                    $this->results['operations']['assign_unassigned']['schools'][] = [
                        'id' => $school->id,
                        'name' => $school->name,
                        'city' => $school->city,
                        'action' => "Would assign to district_id {$district_id}",
                    ];
                } else {
                    $this->wpdb->update(
                        $schools_table,
                        ['district_id' => $district_id],
                        ['id' => $school->id]
                    );
                    $this->results['operations']['assign_unassigned']['schools'][] = [
                        'id' => $school->id,
                        'name' => $school->name,
                        'city' => $school->city,
                        'action' => "Assigned to district_id {$district_id}",
                    ];
                }
                $this->results['operations']['assign_unassigned']['count']++;
            } else {
                $this->results['operations']['assign_unassigned']['schools'][] = [
                    'id' => $school->id,
                    'name' => $school->name,
                    'city' => $school->city,
                    'action' => "No matching district found for city: {$school->city}",
                ];
            }
        }
    }

    /**
     * Mark orphan districts (no schools) with a flag
     */
    private function mark_orphan_districts($dry_run) {
        $districts_table = $this->wpdb->prefix . 'bmn_school_districts';
        $schools_table = $this->wpdb->prefix . 'bmn_schools';

        // Find districts with no schools
        $orphans = $this->wpdb->get_results("
            SELECT d.id, d.name, d.type
            FROM {$districts_table} d
            LEFT JOIN {$schools_table} s ON d.id = s.district_id
            WHERE s.id IS NULL
            AND d.name NOT LIKE '% in %'  -- Exclude tuition agreement entries
            ORDER BY d.name
        ");

        $this->results['operations']['mark_orphans'] = [
            'description' => 'Identify orphan districts (no schools assigned)',
            'count' => count($orphans),
            'districts' => [],
        ];

        foreach ($orphans as $district) {
            $this->results['operations']['mark_orphans']['districts'][] = [
                'id' => $district->id,
                'name' => $district->name,
                'type' => $district->type,
                'action' => 'Identified as orphan (no schools)',
            ];
        }
    }

    /**
     * Update school counts in districts table
     */
    private function update_district_counts() {
        $districts_table = $this->wpdb->prefix . 'bmn_school_districts';
        $schools_table = $this->wpdb->prefix . 'bmn_schools';

        $this->wpdb->query("
            UPDATE {$districts_table} d
            SET total_schools = (
                SELECT COUNT(*)
                FROM {$schools_table} s
                WHERE s.district_id = d.id
            )
        ");

        $this->results['operations']['update_counts'] = [
            'description' => 'Updated district school counts',
            'count' => $this->wpdb->rows_affected,
        ];
    }

    /**
     * Get summary of current database state
     */
    public function get_database_summary() {
        $schools_table = $this->wpdb->prefix . 'bmn_schools';
        $districts_table = $this->wpdb->prefix . 'bmn_school_districts';
        $rankings_table = $this->wpdb->prefix . 'bmn_district_rankings';

        return [
            'total_schools' => $this->wpdb->get_var("SELECT COUNT(*) FROM {$schools_table}"),
            'public_schools' => $this->wpdb->get_var("SELECT COUNT(*) FROM {$schools_table} WHERE school_type = 'public'"),
            'private_schools' => $this->wpdb->get_var("SELECT COUNT(*) FROM {$schools_table} WHERE school_type = 'private'"),
            'charter_schools' => $this->wpdb->get_var("SELECT COUNT(*) FROM {$schools_table} WHERE school_type = 'charter'"),
            'unassigned_schools' => $this->wpdb->get_var("SELECT COUNT(*) FROM {$schools_table} WHERE district_id = 0 OR district_id IS NULL"),
            'total_districts' => $this->wpdb->get_var("SELECT COUNT(*) FROM {$districts_table}"),
            'empty_districts' => $this->wpdb->get_var("
                SELECT COUNT(*) FROM {$districts_table} d
                LEFT JOIN {$schools_table} s ON d.id = s.district_id
                WHERE s.id IS NULL
            "),
            'ranked_districts' => $this->wpdb->get_var("SELECT COUNT(*) FROM {$rankings_table}"),
            'districts_with_incomplete_data' => $this->wpdb->get_var("
                SELECT COUNT(*) FROM {$rankings_table}
                WHERE elementary_avg = 0 AND middle_avg = 0 AND high_avg = 0
            "),
        ];
    }
}

// If called directly via WP-CLI
if (defined('WP_CLI') && WP_CLI) {
    $cleanup = new BMN_Schools_Database_Cleanup();

    // Check for --dry-run flag
    $dry_run = in_array('--dry-run', $GLOBALS['argv'] ?? []);

    WP_CLI::log("Running database cleanup" . ($dry_run ? " (DRY RUN)" : "") . "...\n");

    $results = $cleanup->run_all($dry_run);

    foreach ($results['operations'] as $op_name => $op_data) {
        WP_CLI::log("\n=== {$op_data['description']} ===");
        WP_CLI::log("Count: {$op_data['count']}");

        if (!empty($op_data['schools'])) {
            foreach ($op_data['schools'] as $item) {
                WP_CLI::log("  - " . ($item['name'] ?? $item['pattern']) . ": " . $item['action']);
            }
        }
        if (!empty($op_data['districts'])) {
            foreach ($op_data['districts'] as $item) {
                WP_CLI::log("  - " . $item['name'] . ": " . $item['action']);
            }
        }
    }

    WP_CLI::success("Cleanup complete!");
}
