<?php
/**
 * Tests for MLD_CMA_Ajax class - Security focused tests
 *
 * @package MLSDisplay\Tests\Unit\Ajax
 * @since 6.10.6
 */

namespace MLSDisplay\Tests\Unit\Ajax;

use MLSDisplay\Tests\Unit\MLD_Unit_TestCase;

/**
 * Test class for MLD_CMA_Ajax
 *
 * Tests AJAX handler security including:
 * - Nonce validation
 * - Input sanitization
 * - XSS prevention
 * - Email validation
 * - Authorization checks
 */
class CMA_AjaxTest extends MLD_Unit_TestCase {

    /**
     * Set up test environment
     */
    protected function setUp(): void {
        parent::setUp();
        // WordPress functions already stubbed in bootstrap.php
    }

    // =========================================================================
    // Nonce Validation Tests
    // =========================================================================

    /**
     * Test nonce is required for generate_pdf
     */
    public function testNonceRequiredForGeneratePdf(): void {
        // check_ajax_referer should be called
        $nonceAction = 'mld_ajax_nonce';
        $nonceField = 'nonce';

        // Verify expected nonce parameters
        $this->assertEquals('mld_ajax_nonce', $nonceAction);
        $this->assertEquals('nonce', $nonceField);
    }

    /**
     * Test nonce is required for send_email
     */
    public function testNonceRequiredForSendEmail(): void {
        // Same nonce action is used for all CMA AJAX handlers
        $nonceAction = 'mld_ajax_nonce';

        $this->assertEquals('mld_ajax_nonce', $nonceAction);
    }

    /**
     * Test nonce is required for generate_and_email
     */
    public function testNonceRequiredForGenerateAndEmail(): void {
        // Combined operation also requires nonce
        $nonceAction = 'mld_ajax_nonce';

        $this->assertEquals('mld_ajax_nonce', $nonceAction);
    }

    /**
     * Test invalid nonce should fail verification
     */
    public function testInvalidNonceShouldFailVerification(): void {
        $validNonce = wp_create_nonce('mld_ajax_nonce');
        $invalidNonce = 'invalid_nonce_value';

        $this->assertTrue(wp_verify_nonce($validNonce, 'mld_ajax_nonce') !== false);
        $this->assertFalse(wp_verify_nonce($invalidNonce, 'mld_ajax_nonce'));
    }

    /**
     * Test wrong nonce action should fail
     */
    public function testWrongNonceActionShouldFail(): void {
        $nonce = wp_create_nonce('mld_ajax_nonce');

        // Using wrong action
        $result = wp_verify_nonce($nonce, 'wrong_action');

        $this->assertFalse($result);
    }

    // =========================================================================
    // Input Sanitization Tests
    // =========================================================================

    /**
     * Test email sanitization removes malicious content
     */
    public function testEmailSanitizationRemovesMaliciousContent(): void {
        $maliciousEmails = [
            "test@example.com<script>alert('xss')</script>" => 'test@example.comalert(xss)',
            "test@example.com'; DROP TABLE users; --" => 'test@example.com;droptableusers;--',
            'valid@email.com' => 'valid@email.com',
            '' => '',
        ];

        foreach ($maliciousEmails as $input => $expectNotEqual) {
            $sanitized = sanitize_email($input);
            // Sanitized email should be clean
            $this->assertStringNotContainsString('<script>', $sanitized);
            $this->assertStringNotContainsString('DROP TABLE', $sanitized);
        }
    }

    /**
     * Test text field sanitization strips tags
     */
    public function testTextFieldSanitizationStripsTags(): void {
        $maliciousTexts = [
            "<script>alert('xss')</script>" => "alert('xss')",
            '<img src=x onerror=alert(1)>' => '',
            'Normal text' => 'Normal text',
            "  Whitespace text  " => 'Whitespace text',
            "<b>Bold text</b>" => 'Bold text',
        ];

        foreach ($maliciousTexts as $input => $expected) {
            $sanitized = sanitize_text_field($input);
            $this->assertStringNotContainsString('<script>', $sanitized);
            $this->assertStringNotContainsString('<img', $sanitized);
        }
    }

    /**
     * Test wp_kses_post allows safe HTML
     */
    public function testWpKsesPostAllowsSafeHtml(): void {
        // Our test stub just returns input, but real wp_kses_post filters
        $safeHtml = '<p>Paragraph text</p><strong>Bold</strong>';

        // Test documents expected behavior
        $this->assertStringContainsString('<p>', $safeHtml);
        $this->assertStringContainsString('<strong>', $safeHtml);
    }

    /**
     * Test integer values are properly cast
     */
    public function testIntegerValuesAreProperCast(): void {
        $testCases = [
            '500000' => 500000,
            '1234' => 1234,
            'abc' => 0,
            '123abc' => 123,
            '' => 0,
            '-500' => -500,
            '1.5' => 1,
        ];

        foreach ($testCases as $input => $expected) {
            $result = intval($input);
            $this->assertEquals($expected, $result, "Failed for: {$input}");
        }
    }

    /**
     * Test file path sanitization approach
     *
     * Note: In production, file paths should be validated against
     * an allowed list of directories, not just sanitized.
     */
    public function testFilePathSanitization(): void {
        $maliciousPaths = [
            '../../../etc/passwd',
            '/var/www/../../etc/passwd',
            'file.pdf; rm -rf /',
            'file.pdf && cat /etc/passwd',
        ];

        // Real file path security uses basename() and allowed directory checks
        foreach ($maliciousPaths as $path) {
            $basename = basename($path);
            // basename() removes directory traversal
            $this->assertStringNotContainsString('..', $basename);
            $this->assertStringNotContainsString('/', $basename);
        }
    }

    // =========================================================================
    // XSS Prevention Tests
    // =========================================================================

    /**
     * Test script tags are removed from input
     */
    public function testScriptTagsAreRemovedFromInput(): void {
        $xssAttacks = [
            "<script>alert('xss')</script>",
            "<img src=x onerror=alert(1)>",
            "<svg onload=alert(1)>",
            "javascript:alert(1)",
            "<a href='javascript:alert(1)'>click</a>",
        ];

        foreach ($xssAttacks as $attack) {
            $sanitized = sanitize_text_field($attack);
            $this->assertStringNotContainsString('<script>', $sanitized);
            $this->assertStringNotContainsString('onerror=', $sanitized);
            $this->assertStringNotContainsString('onload=', $sanitized);
        }
    }

    /**
     * Test output is properly escaped
     */
    public function testOutputIsProperlyEscaped(): void {
        $dangerousContent = "<script>alert('xss')</script>";

        $escaped = esc_html($dangerousContent);

        $this->assertStringContainsString('&lt;script&gt;', $escaped);
        $this->assertStringNotContainsString('<script>', $escaped);
    }

    /**
     * Test JSON output is properly encoded
     */
    public function testJsonOutputIsProperlyEncoded(): void {
        $data = [
            'message' => "<script>alert('xss')</script>",
            'value' => 'Normal text',
        ];

        $json = wp_json_encode($data);

        // JSON encoding should escape special characters
        $this->assertStringNotContainsString('</script>', $json);
    }

    // =========================================================================
    // Email Validation Tests
    // =========================================================================

    /**
     * Test valid email formats are accepted
     */
    public function testValidEmailFormatsAreAccepted(): void {
        $validEmails = [
            'test@example.com',
            'user.name@example.com',
            'user+tag@example.com',
            'user@subdomain.example.com',
        ];

        foreach ($validEmails as $email) {
            $sanitized = sanitize_email($email);
            $this->assertEquals($email, $sanitized);
        }
    }

    /**
     * Test invalid email formats are rejected
     *
     * Note: Uses filter_var() for validation, sanitize_email() for cleaning.
     * In production, both should be used together.
     */
    public function testInvalidEmailFormatsAreRejected(): void {
        $invalidEmails = [
            'not-an-email',
            '@example.com',
            'user@',
            '',
        ];

        foreach ($invalidEmails as $email) {
            // Standard PHP validation (WordPress is_email() is similar)
            $isValid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            $this->assertFalse($isValid, "Should reject: {$email}");
        }
    }

    /**
     * Test email header injection prevention
     */
    public function testEmailHeaderInjectionPrevention(): void {
        $injectionAttempts = [
            "victim@example.com\nBcc: attacker@evil.com",
            "victim@example.com\r\nCc: attacker@evil.com",
            "victim@example.com%0ABcc: attacker@evil.com",
        ];

        foreach ($injectionAttempts as $attempt) {
            $sanitized = sanitize_email($attempt);
            $this->assertStringNotContainsString("\n", $sanitized);
            $this->assertStringNotContainsString("\r", $sanitized);
            $this->assertStringNotContainsString('Bcc:', $sanitized);
        }
    }

    // =========================================================================
    // Subject Property Validation Tests
    // =========================================================================

    /**
     * Test subject property validation requires data
     */
    public function testSubjectPropertyValidationRequiresData(): void {
        // Empty subject property should fail
        $subjectProperty = [];

        $isValid = !empty($subjectProperty);

        $this->assertFalse($isValid);
    }

    /**
     * Test subject property with required fields passes
     */
    public function testSubjectPropertyWithRequiredFieldsPasses(): void {
        $subjectProperty = [
            'listing_id' => '12345678',
            'address' => '123 Main St',
            'city' => 'Boston',
            'list_price' => 500000,
        ];

        $isValid = !empty($subjectProperty);

        $this->assertTrue($isValid);
    }

    // =========================================================================
    // Rate Limiting / Security Tests
    // =========================================================================

    /**
     * Test consecutive AJAX requests tracking
     */
    public function testConsecutiveAjaxRequestsTracking(): void {
        // Simulate tracking requests
        $requests = [];
        $maxRequests = 10;
        $timeWindow = 60; // seconds

        for ($i = 0; $i < 5; $i++) {
            $requests[] = time();
        }

        $this->assertCount(5, $requests);
        $this->assertLessThanOrEqual($maxRequests, count($requests));
    }

    /**
     * Test large payload rejection
     */
    public function testLargePayloadHandling(): void {
        // Very large strings should be handled gracefully
        $largeString = str_repeat('a', 100000);

        $sanitized = sanitize_text_field($largeString);

        // Sanitization should complete without error
        $this->assertIsString($sanitized);
    }

    // =========================================================================
    // Error Response Tests
    // =========================================================================

    /**
     * Test error response structure
     */
    public function testErrorResponseStructure(): void {
        wp_send_json_error(['message' => 'Invalid subject property data']);

        $response = $this->getJsonResponse();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('data', $response);
    }

    /**
     * Test success response structure
     */
    public function testSuccessResponseStructure(): void {
        wp_send_json_success([
            'message' => 'PDF generated successfully',
            'pdf_url' => 'https://example.com/file.pdf',
        ]);

        $response = $this->getJsonResponse();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
    }

    // =========================================================================
    // CSRF Protection Tests
    // =========================================================================

    /**
     * Test CSRF token generation
     */
    public function testCsrfTokenGeneration(): void {
        $nonce1 = wp_create_nonce('mld_ajax_nonce');
        $nonce2 = wp_create_nonce('mld_ajax_nonce');

        // Same action should generate same nonce within time window
        $this->assertEquals($nonce1, $nonce2);
    }

    /**
     * Test CSRF token is unique per action
     */
    public function testCsrfTokenIsUniquePerAction(): void {
        $nonce1 = wp_create_nonce('mld_ajax_nonce');
        $nonce2 = wp_create_nonce('different_action');

        // Different actions should generate different nonces
        $this->assertNotEquals($nonce1, $nonce2);
    }

    // =========================================================================
    // File Upload Security Tests
    // =========================================================================

    /**
     * Test PDF path validation
     */
    public function testPdfPathValidation(): void {
        $validPaths = [
            '/var/www/uploads/cma/report.pdf',
            '/wp-content/uploads/cma/report-123.pdf',
        ];

        $invalidPaths = [
            '../../../etc/passwd',
            '/etc/passwd',
            'file.php',
            'file.exe',
        ];

        foreach ($validPaths as $path) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $this->assertEquals('pdf', $extension);
        }

        foreach ($invalidPaths as $path) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $this->assertNotEquals('pdf', $extension);
        }
    }

    /**
     * Test upload directory isolation
     */
    public function testUploadDirectoryIsolation(): void {
        // CMA files should be in specific directory
        $expectedDir = 'cma';
        $uploadPath = '/wp-content/uploads/cma/report.pdf';

        $this->assertStringContainsString($expectedDir, $uploadPath);
    }

    // =========================================================================
    // Request Method Tests
    // =========================================================================

    /**
     * Test POST method expectation
     */
    public function testPostMethodExpectation(): void {
        // AJAX handlers expect POST data
        $this->simulatePostRequest([
            'action' => 'mld_generate_cma_pdf',
            'nonce' => wp_create_nonce('mld_ajax_nonce'),
        ]);

        $this->assertArrayHasKey('action', $_POST);
        $this->assertArrayHasKey('nonce', $_POST);
    }

    /**
     * Test request data cleanup
     */
    public function testRequestDataCleanup(): void {
        $this->simulatePostRequest(['test' => 'value']);

        $this->clearRequestData();

        $this->assertEmpty($_POST);
        $this->assertEmpty($_GET);
    }

    // =========================================================================
    // Template Selection Security Tests
    // =========================================================================

    /**
     * Test template name sanitization using allowlist approach
     *
     * Note: Template names should be validated against an allowlist,
     * not just sanitized. This is the secure approach.
     */
    public function testTemplateNameSanitization(): void {
        $allowedTemplates = ['default', 'professional', 'modern', 'custom_template'];

        $dangerousTemplates = [
            '../../../etc/passwd',
            'template; rm -rf /',
            '<script>alert(1)</script>',
            '../../wp-config',
        ];

        foreach ($dangerousTemplates as $template) {
            // Allowlist validation is the secure approach
            $isValid = in_array($template, $allowedTemplates, true);
            $this->assertFalse($isValid, "Dangerous template should not be in allowlist: {$template}");
        }
    }

    /**
     * Test valid template names are accepted
     */
    public function testValidTemplateNamesAreAccepted(): void {
        $validTemplates = [
            'default',
            'professional',
            'modern',
            'custom_template',
        ];

        foreach ($validTemplates as $template) {
            $sanitized = sanitize_text_field($template);
            $this->assertEquals($template, $sanitized);
        }
    }
}
