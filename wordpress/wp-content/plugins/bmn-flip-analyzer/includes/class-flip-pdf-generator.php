<?php
/**
 * PDF Report Generator for Flip Analyzer — Investor-Grade Design.
 *
 * Generates polished, multi-page PDF reports suitable for presenting
 * to potential investors. Uses TCPDF with rounded cards, metric grids,
 * professional tables, and strong typography hierarchy.
 *
 * Visual patterns adapted from the CMA PDF generator:
 * - Hero image clipping (StartTransform + Rect CNZ)
 * - RoundedRect metric cards with gray backgrounds
 * - Professional styled tables (blue header, zebra rows)
 * - Score bars with rounded ends
 * - Card-based section grouping with accent bars
 *
 * v0.9.1 enhancements: photo strip, score gauge, financial bar chart,
 * sensitivity line chart, comparable photo cards, blue section headers,
 * card shadows, larger fonts.
 *
 * @package BMN_Flip_Analyzer
 * @since 0.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load extracted PDF helper classes (lazy-loaded with PDF, not in main bootstrap)
require_once __DIR__ . '/class-flip-pdf-components.php';
require_once __DIR__ . '/class-flip-pdf-charts.php';
require_once __DIR__ . '/class-flip-pdf-images.php';

// Load TCPDF from MLS Listings Display plugin
if (!class_exists('TCPDF')) {
    $tcpdf_path = WP_PLUGIN_DIR . '/mls-listings-display/vendor/tecnickcom/tcpdf/tcpdf.php';
    if (file_exists($tcpdf_path)) {
        require_once $tcpdf_path;
    }
}

/**
 * TCPDF subclass with custom footer.
 */
if (class_exists('TCPDF') && !class_exists('Flip_TCPDF')) {
    class Flip_TCPDF extends TCPDF {
        /** @var string|false Path to logo temp file for footer */
        public $footer_logo = false;

        public function Footer() {
            $this->SetY(-12);

            // Small logo on left
            $logo_x = 12;
            if ($this->footer_logo && file_exists($this->footer_logo)) {
                $this->Image($this->footer_logo, $logo_x, $this->GetY() + 1.5, 22, 0, '', '', '', false, 300, '', false, false, 0);
                $logo_x = 36;
            }

            $this->SetFont('helvetica', '', 7.5);
            $this->SetTextColor(150, 150, 150);
            $this->SetX($logo_x);
            $usable = 216 - 12 - $logo_x; // page width minus right margin minus logo offset
            $this->Cell($usable, 10,
                'BMN Boston Real Estate  |  Steve Novak (617) 955-2224  |  bmnboston.com  |  Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(),
                0, false, 'C'
            );
        }
    }
}

class Flip_PDF_Generator {

    /** @var Flip_TCPDF */
    private $pdf;

    /** @var Flip_PDF_Components */
    private $c;

    /** @var Flip_PDF_Charts */
    private $charts;

    /** @var Flip_PDF_Images */
    private $images;

    /** @var array Formatted property data */
    private $d;

    /** @var object Raw database row */
    private $raw;

    /** @var object|null BME enrichment data (listings + details + financial) */
    private $bme = null;

    // ─── BRANDING ────────────────────────────────────────────────

    private $logo_url       = 'https://bmnboston.com/wp-content/uploads/2025/12/BMN-Logo-Croped.png';
    private $agent_photo_url = 'https://bmnboston.com/wp-content/uploads/2025/12/Steve-Novak-600x600-1.jpg';
    private $agent_name     = 'Steve Novak';
    private $agent_title    = 'Team Lead';
    private $agent_phone    = '(617) 955-2224';
    private $agent_email    = 'steve@bmnboston.com';
    private $agent_license  = '#9517748';
    private $company_name   = 'BMN Boston Real Estate';
    private $company_phone  = '(617) 800-9008';
    private $company_address = '20 Park Plaza, Boston, MA 02118';
    private $company_url    = 'bmnboston.com';

    // ─── PUBLIC API ────────────────────────────────────────────────

    /**
     * Generate flip analysis PDF report.
     *
     * @param int $listing_id MLS listing ID.
     * @return string|false File path on success, false on failure.
     */
    public function generate(int $listing_id, ?int $report_id = null) {
        if (!class_exists('Flip_TCPDF')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flip PDF: TCPDF library not found.');
            }
            return false;
        }

        $this->raw = $report_id
            ? Flip_Database::get_result_by_listing_and_report($listing_id, $report_id)
            : Flip_Database::get_result_by_listing($listing_id);
        if (!$this->raw) {
            return false;
        }

        if (!class_exists('Flip_Admin_Dashboard')) {
            require_once FLIP_PLUGIN_PATH . 'admin/class-flip-admin-dashboard.php';
        }

        $this->d = Flip_Admin_Dashboard::format_result($this->raw);
        $this->fetch_enriched_data($listing_id);

        $this->initialize_pdf();
        $this->add_cover_page();
        $this->add_scores_and_valuation();
        $this->add_financial_analysis();
        $this->add_rental_analysis();
        $this->add_brrrr_analysis();
        $this->add_risk_and_sensitivity();
        $this->add_comparables();
        $this->add_property_and_location();
        $this->add_photo_analysis();
        $this->add_call_to_action();

        return $this->save_pdf();
    }

    // ─── INITIALISATION ────────────────────────────────────────────

    private function initialize_pdf(): void {
        $this->pdf = new Flip_TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);

        // Initialize extracted helper classes
        $this->c = new Flip_PDF_Components($this->pdf);
        $this->charts = new Flip_PDF_Charts($this->pdf, $this->c);
        $this->images = new Flip_PDF_Images($this->pdf, $this->c);

        // Download logo once for footer use on every page
        $this->pdf->footer_logo = $this->images->download_image_temp($this->logo_url);

        $this->pdf->SetCreator('BMN Flip Analyzer');
        $this->pdf->SetAuthor('BMN Boston');
        $this->pdf->SetTitle('Property Investment Report - ' . $this->d['address']);

        $this->pdf->SetMargins(Flip_PDF_Components::LM, Flip_PDF_Components::LM, Flip_PDF_Components::LM);
        $this->pdf->SetAutoPageBreak(true, 15);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(true);
        $this->pdf->SetFont('helvetica', '', 10);
    }

    // ─── PAGE 1: COVER ─────────────────────────────────────────────

    private function add_cover_page(): void {
        $this->pdf->AddPage();
        $d = $this->d;

        // Hero image with clipping (CMA pattern)
        $hero_h = 80;
        $photo_file = $this->images->download_image_temp($d['main_photo_url'] ?? '');

        if ($photo_file) {
            $img_info = @getimagesize($photo_file);
            if ($img_info) {
                $img_ratio = $img_info[0] / $img_info[1];
                $scaled_h  = Flip_PDF_Components::PW / $img_ratio;

                if ($scaled_h > $hero_h) {
                    $this->pdf->StartTransform();
                    $this->pdf->Rect(Flip_PDF_Components::LM, Flip_PDF_Components::LM, Flip_PDF_Components::PW, $hero_h, 'CNZ');
                    $y_off = Flip_PDF_Components::LM - ($scaled_h - $hero_h) / 2;
                    $this->pdf->Image($photo_file, Flip_PDF_Components::LM, $y_off, Flip_PDF_Components::PW, $scaled_h, '', '', '', false, 300, '', false, false, 0);
                    $this->pdf->StopTransform();
                } else {
                    $this->pdf->Image($photo_file, Flip_PDF_Components::LM, Flip_PDF_Components::LM, Flip_PDF_Components::PW, $hero_h, '', '', '', false, 300, '', false, false, 0);
                }
            } else {
                $this->pdf->Image($photo_file, Flip_PDF_Components::LM, Flip_PDF_Components::LM, Flip_PDF_Components::PW, $hero_h, '', '', '', false, 300, '', false, false, 0);
            }

            // Dark gradient overlay on bottom 35mm
            $this->pdf->SetFillColor(0, 0, 0);
            $this->pdf->SetAlpha(0.50);
            $this->pdf->Rect(Flip_PDF_Components::LM, Flip_PDF_Components::LM + $hero_h - 35, Flip_PDF_Components::PW, 35, 'F');
            $this->pdf->SetAlpha(1);
            @unlink($photo_file);
        } else {
            // Solid colour fallback
            $this->pdf->SetFillColor($this->c->primary[0], $this->c->primary[1], $this->c->primary[2]);
            $this->pdf->Rect(Flip_PDF_Components::LM, Flip_PDF_Components::LM, Flip_PDF_Components::PW, $hero_h, 'F');
        }

        // BMN logo in hero overlay (top-right)
        $logo_file = $this->images->download_image_temp($this->logo_url);
        if ($logo_file) {
            $this->pdf->SetAlpha(0.92);
            $this->pdf->Image($logo_file, Flip_PDF_Components::LM + Flip_PDF_Components::PW - 52, Flip_PDF_Components::LM + 4, 44, 0, '', '', '', false, 300, '', false, false, 0);
            $this->pdf->SetAlpha(1);
            @unlink($logo_file);
        }

        // Title on overlay
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetXY(Flip_PDF_Components::LM + 8, Flip_PDF_Components::LM + $hero_h - 30);
        $this->pdf->SetFont('helvetica', 'B', 26);
        $this->pdf->Cell(Flip_PDF_Components::PW - 16, 12, 'PROPERTY INVESTMENT REPORT', 0, 1, 'L');

        $this->pdf->SetX(Flip_PDF_Components::LM + 8);
        $this->pdf->SetFont('helvetica', '', 11);
        $this->pdf->Cell(Flip_PDF_Components::PW - 16, 6, 'Prepared by ' . $this->agent_name . '  |  ' . current_time('F j, Y'), 0, 1, 'L');

        // Address block
        $y = Flip_PDF_Components::LM + $hero_h + 7;
        $this->pdf->SetY($y);
        $this->c->set_text_color();
        $this->pdf->SetFont('helvetica', 'B', 22);
        $this->pdf->Cell(Flip_PDF_Components::PW, 10, $d['address'], 0, 1, 'L');

        $this->pdf->SetFont('helvetica', '', 13);
        $this->c->set_color($this->c->gray);
        $this->pdf->Cell(Flip_PDF_Components::PW, 7, $d['city'] . '  |  MLS# ' . $d['listing_id'], 0, 1, 'L');

        // Status badges
        $this->pdf->Ln(3);
        $badge_y = $this->pdf->GetY();
        $badge_x = Flip_PDF_Components::LM;

        if ($d['disqualified']) {
            $badge_x = $this->c->draw_badge($badge_x, $badge_y, 'DISQUALIFIED', $this->c->danger);
            if (!empty($d['disqualify_reason'])) {
                $this->pdf->SetXY(Flip_PDF_Components::LM, $badge_y + 9);
                $this->pdf->SetFont('helvetica', 'I', 9);
                $this->c->set_color($this->c->danger);
                $this->pdf->MultiCell(Flip_PDF_Components::PW, 5, $d['disqualify_reason'], 0, 'L');
            }
        } elseif (!empty($d['near_viable']) && $d['near_viable']) {
            $badge_x = $this->c->draw_badge($badge_x, $badge_y, 'NEAR VIABLE', $this->c->warning);
        }

        if (!empty($d['lead_paint_flag']) && $d['lead_paint_flag']) {
            $this->c->draw_badge($badge_x + 4, $badge_y, 'LEAD PAINT (Pre-1978)', [180, 130, 30]);
        }

        // Photo thumbnail strip
        $photos = $this->images->fetch_property_photos($d['listing_id']);
        $strip_y = max($this->pdf->GetY() + 4, $badge_y + 16);
        $after_strip_y = $this->images->render_photo_strip($photos, $strip_y);

        // 6 metric cards — 3 columns x 2 rows
        $metrics_y = $after_strip_y + 2;
        $this->pdf->SetY($metrics_y);

        $cards = [
            ['label' => 'Total Score',    'value' => $this->c->fmt_score($d['total_score']),           'color' => $this->c->get_score_color($d['total_score'])],
            ['label' => 'Risk Grade',     'value' => $d['deal_risk_grade'] ?? '--',                 'color' => $this->c->get_risk_color($d['deal_risk_grade'] ?? 'F')],
            ['label' => 'List Price',     'value' => '$' . number_format($d['list_price']),         'color' => $this->c->text],
            ['label' => 'Estimated ARV',  'value' => '$' . number_format($d['estimated_arv']),      'color' => $this->c->primary],
            ['label' => 'Profit (Fin.)',  'value' => $this->c->fmt_currency($d['estimated_profit']),   'color' => $d['estimated_profit'] >= 0 ? $this->c->success : $this->c->danger],
            ['label' => 'Ann. ROI',       'value' => $this->c->fmt_pct($d['annualized_roi']),          'color' => $d['annualized_roi'] >= 0 ? $this->c->success : $this->c->danger],
        ];

        $this->c->render_metric_grid($cards, 3, $this->pdf->GetY());

        // Market strength ribbon
        $rib_y = $this->pdf->GetY() + 4;
        $ms = $d['market_strength'] ?? 'balanced';
        $ms_color = $this->c->market_colors[$ms] ?? $this->c->gray;

        $this->pdf->SetFillColor($ms_color[0], $ms_color[1], $ms_color[2]);
        $this->pdf->SetAlpha(0.12);
        $this->pdf->RoundedRect(Flip_PDF_Components::LM, $rib_y, Flip_PDF_Components::PW, 10, 2, '1111', 'F');
        $this->pdf->SetAlpha(1);

        $this->pdf->SetXY(Flip_PDF_Components::LM + 6, $rib_y + 1.5);
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->c->set_color($ms_color);
        $ms_label = ucwords(str_replace('_', ' ', $ms)) . ' Market';
        if ($d['avg_sale_to_list'] > 0) {
            $ms_label .= '  |  Sale/List Ratio: ' . number_format($d['avg_sale_to_list'], 3);
        }
        $this->pdf->Cell(Flip_PDF_Components::PW - 12, 7, $ms_label, 0, 1, 'L');
    }

    // ─── PAGE 2: SCORES & VALUATION ────────────────────────────────

    private function add_scores_and_valuation(): void {
        $this->pdf->AddPage();
        $d = $this->d;

        // Total score hero
        $this->c->add_section_header('Score Breakdown');

        // Score gauge (left) with label (right)
        $gauge_y = $this->pdf->GetY();
        $this->charts->render_score_gauge($d['total_score'], Flip_PDF_Components::LM + 24, $gauge_y + 20, 16);

        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->c->set_color($this->c->gray);
        $this->pdf->SetXY(Flip_PDF_Components::LM + 48, $gauge_y + 10);
        $this->pdf->Cell(60, 6, 'TOTAL SCORE', 0, 1, 'L');

        $grade = $d['deal_risk_grade'] ?? '--';
        $gc = $this->c->get_risk_color($grade);
        $this->pdf->SetXY(Flip_PDF_Components::LM + 48, $gauge_y + 18);
        $this->pdf->SetFont('helvetica', '', 9);
        $this->c->set_color($this->c->gray);
        $this->pdf->Cell(22, 5, 'Risk Grade:', 0, 0, 'L');
        $this->pdf->SetFont('helvetica', 'B', 13);
        $this->c->set_color($gc);
        $this->pdf->Cell(10, 5, $grade, 0, 1, 'L');

        $this->pdf->SetY($gauge_y + 42);

        // Score card
        $card_y = $this->pdf->GetY();
        $scores = [
            ['Financial',  $d['financial_score'], 40],
            ['Property',   $d['property_score'],  25],
            ['Location',   $d['location_score'],  25],
            ['Market',     $d['market_score'],     10],
        ];
        if ($d['photo_score'] !== null) {
            $scores[] = ['Photo', $d['photo_score'], null];
        }

        $card_h = 8 + (count($scores) * 12) + 4;
        $this->c->render_card_start($card_y, $card_h, ['accent' => $this->c->primary]);

        $this->pdf->SetY($card_y + 5);
        foreach ($scores as $s) {
            $this->c->render_score_bar($s[0], $s[1], $s[2]);
        }

        $this->pdf->SetY($card_y + $card_h + 6);

        // Strategy scores
        $this->render_strategy_cards();

        // Valuation section — needs ~90mm; start new page if not enough room
        $page_bottom = $this->pdf->getPageHeight() - $this->pdf->getBreakMargin();
        if (($page_bottom - $this->pdf->GetY()) < 90) {
            $this->pdf->AddPage();
        }
        $this->c->add_section_header('Property Valuation');

        $arv = $d['estimated_arv'];
        $spread = match ($d['arv_confidence']) {
            'high'   => 0.10,
            'medium' => 0.15,
            default  => 0.20,
        };
        $floor = $arv * (1 - $spread);
        $ceil  = $arv * (1 + $spread);

        // ARV hero display
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->c->set_color($this->c->gray);
        $this->pdf->Cell(50, 7, 'ESTIMATED ARV', 0, 0, 'L');

        // Confidence badge
        $conf = ucfirst($d['arv_confidence'] ?? 'unknown');
        $conf_color = match ($d['arv_confidence']) {
            'high'   => $this->c->success,
            'medium' => $this->c->warning,
            default  => $this->c->danger,
        };
        $this->c->draw_badge($this->pdf->GetX() + 2, $this->pdf->GetY() + 1, $conf . ' Confidence', $conf_color);
        $this->pdf->Ln();

        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->c->set_color($this->c->primary);
        $this->pdf->Cell(Flip_PDF_Components::PW, 12, '$' . number_format($arv), 0, 1, 'L');
        $this->pdf->Ln(2);

        // Range bar
        $this->c->render_range_bar($floor, $arv, $ceil, $this->pdf->GetY());
        $this->pdf->Ln(6);

        // Valuation details in card
        $val_y = $this->pdf->GetY();
        $val_h = 36;
        if (($d['road_arv_discount'] ?? 0) > 0) $val_h += 7;
        $this->c->render_card_start($val_y, $val_h);

        $this->pdf->SetY($val_y + 4);
        $this->c->render_kv_row('Floor Value', '$' . number_format($floor));
        $this->c->render_kv_row('Mid Value (ARV)', '$' . number_format($arv), ['bold' => true]);
        $this->c->render_kv_row('Ceiling Value', '$' . number_format($ceil));
        $this->c->render_kv_row('Comparable Sales Used', (string) $d['comp_count']);

        if (($d['road_arv_discount'] ?? 0) > 0) {
            $this->c->render_kv_row('Road Discount', '-' . ($d['road_arv_discount'] * 100) . '% (' . $d['road_type'] . ')', ['color' => $this->c->danger]);
        }
    }

    /**
     * Render strategy score cards (Flip / Rental / BRRRR) — 3-column layout.
     */
    private function render_strategy_cards(): void {
        $d = $this->d;

        $this->c->add_section_header('Strategy Analysis');

        $strategies = [
            ['key' => 'flip',   'label' => 'FLIP',   'score' => $d['flip_score'],   'viable' => $d['flip_viable']],
            ['key' => 'rental', 'label' => 'RENTAL', 'score' => $d['rental_score'], 'viable' => $d['rental_viable']],
            ['key' => 'brrrr',  'label' => 'BRRRR',  'score' => $d['brrrr_score'],  'viable' => $d['brrrr_viable']],
        ];

        $gap    = 4;
        $card_w = (Flip_PDF_Components::PW - 2 * $gap) / 3;
        $card_h = 32;
        $y      = $this->pdf->GetY();

        foreach ($strategies as $i => $strat) {
            $x = Flip_PDF_Components::LM + $i * ($card_w + $gap);

            $is_best = ($d['best_strategy'] === $strat['key']);
            $has_score = ($strat['score'] !== null);

            // Card background
            $this->pdf->SetFillColor($this->c->light[0], $this->c->light[1], $this->c->light[2]);
            $this->pdf->RoundedRect($x, $y, $card_w, $card_h, 2.5, '1111', 'F');

            // Best strategy accent border
            if ($is_best) {
                $this->pdf->SetDrawColor($this->c->success[0], $this->c->success[1], $this->c->success[2]);
                $this->pdf->SetLineWidth(0.6);
                $this->pdf->RoundedRect($x, $y, $card_w, $card_h, 2.5, '1111', 'D');
                $this->pdf->SetLineWidth(0.3);

                // "BEST" pill badge centered at top
                $badge_text = 'BEST';
                $this->pdf->SetFont('helvetica', 'B', 6.5);
                $badge_w = $this->pdf->GetStringWidth($badge_text) + 6;
                $badge_x = $x + ($card_w - $badge_w) / 2;
                $this->pdf->SetFillColor($this->c->success[0], $this->c->success[1], $this->c->success[2]);
                $this->pdf->RoundedRect($badge_x, $y + 2.5, $badge_w, 5, 2, '1111', 'F');
                $this->pdf->SetTextColor(255, 255, 255);
                $this->pdf->SetXY($badge_x, $y + 2.5);
                $this->pdf->Cell($badge_w, 5, $badge_text, 0, 0, 'C');
            }

            // Score value
            $score_y = $y + ($is_best ? 9 : 6);
            if ($has_score) {
                $color = $this->c->get_score_color($strat['score']);
                $this->c->set_color($color);
                $this->pdf->SetFont('helvetica', 'B', 20);
                $this->pdf->SetXY($x, $score_y);
                $this->pdf->Cell($card_w, 9, $this->c->fmt_score($strat['score']), 0, 0, 'C');
            } else {
                $this->c->set_color($this->c->gray);
                $this->pdf->SetFont('helvetica', 'B', 20);
                $this->pdf->SetXY($x, $score_y);
                $this->pdf->Cell($card_w, 9, '--', 0, 0, 'C');
            }

            // Strategy label
            $this->pdf->SetXY($x, $score_y + 10);
            $this->pdf->SetFont('helvetica', 'B', 9);
            $this->c->set_color($this->c->gray);
            $this->pdf->Cell($card_w, 5, $strat['label'], 0, 0, 'C');

            // Viability indicator
            $indicator_y = $score_y + 16;
            if ($has_score) {
                if ($strat['viable']) {
                    $dot_color = $this->c->success;
                    $label = 'Viable';
                } else {
                    $dot_color = $this->c->danger;
                    $label = 'Not Viable';
                }
            } else {
                $dot_color = $this->c->gray;
                $label = 'N/A';
            }

            // Draw small circle
            $this->pdf->SetFillColor($dot_color[0], $dot_color[1], $dot_color[2]);
            $dot_r = 1.2;
            $this->pdf->SetFont('helvetica', '', 7.5);
            $label_w = $this->pdf->GetStringWidth($label);
            $total_w = $dot_r * 2 + 2 + $label_w;
            $dot_x = $x + ($card_w - $total_w) / 2 + $dot_r;
            $this->pdf->Circle($dot_x, $indicator_y + 2.5, $dot_r, 0, 360, 'F');

            // Viability label
            $this->pdf->SetXY($dot_x + $dot_r + 2, $indicator_y);
            $this->c->set_color($dot_color);
            $this->pdf->Cell($label_w + 2, 5, $label, 0, 0, 'L');
        }

        $this->pdf->SetY($y + $card_h + 4);

        // Strategy reasoning (if available)
        $reasoning = $d['rental_analysis']['strategy']['reasoning'] ?? null;
        if (!empty($reasoning)) {
            $this->pdf->SetFont('helvetica', 'I', 8.5);
            $this->c->set_color($this->c->gray);
            $this->pdf->MultiCell(Flip_PDF_Components::PW, 4, $reasoning, 0, 'L');
            $this->pdf->Ln(2);
        }
    }

    // ─── PAGE 3: FINANCIAL ANALYSIS ────────────────────────────────

    private function add_financial_analysis(): void {
        $this->pdf->AddPage();
        $d = $this->d;
        $arv = $d['estimated_arv'];

        $this->c->add_section_header('Financial Analysis');

        // Cost Breakdown card
        $base_rehab = $d['estimated_rehab_cost'] - ($d['rehab_contingency'] ?? 0);
        $rehab_note = ucfirst($d['rehab_level'] ?? 'unknown');
        if (($d['rehab_multiplier'] ?? 1.0) != 1.0) {
            $rehab_note .= ', ' . number_format($d['rehab_multiplier'], 2) . 'x remarks adj.';
        }

        $purch_close = $d['list_price'] * Flip_Analyzer::PURCHASE_CLOSING_PCT;
        $sale_pct = (Flip_Analyzer::SALE_COMMISSION_PCT + Flip_Analyzer::SALE_CLOSING_PCT) * 100;
        $sale_base = $arv * (Flip_Analyzer::SALE_COMMISSION_PCT + Flip_Analyzer::SALE_CLOSING_PCT);

        $cost_rows = [
            ['Purchase Price',              '$' . number_format($d['list_price'])],
            ['Rehab Cost (' . $rehab_note . ')', '$' . number_format($base_rehab)],
        ];

        if (!empty($d['lead_paint_flag']) && $d['lead_paint_flag']) {
            $cost_rows[] = ['Lead Paint Allowance', '$' . number_format(Flip_Analyzer::LEAD_PAINT_ALLOWANCE)];
        }

        $cont_pct = ($base_rehab > 0) ? round(($d['rehab_contingency'] / $base_rehab) * 100) : 0;
        $cost_rows[] = ['Contingency (' . $cont_pct . '%)', '$' . number_format($d['rehab_contingency'] ?? 0)];
        $cost_rows[] = ['Purchase Closing (1.5%)', '$' . number_format($purch_close)];
        $cost_rows[] = ['Transfer Tax (Buy)', '$' . number_format($d['transfer_tax_buy'])];
        $cost_rows[] = ['Sale Costs (' . $sale_pct . '%)', '$' . number_format($sale_base)];
        $cost_rows[] = ['Transfer Tax (Sell)', '$' . number_format($d['transfer_tax_sell'])];
        $cost_rows[] = ['Holding Costs (' . $d['hold_months'] . ' mo)', '$' . number_format($d['holding_costs'])];

        $card_y = $this->pdf->GetY();
        $card_h = 6 + count($cost_rows) * 7 + 4;
        $this->c->render_card_start($card_y, $card_h);

        $this->pdf->SetY($card_y + 4);
        foreach ($cost_rows as $cr) {
            $this->c->render_kv_row($cr[0], $cr[1]);
        }

        $this->pdf->SetY($card_y + $card_h + 6);

        // Side-by-side Investment Scenarios
        $this->c->add_subsection_header('Investment Scenarios');
        $box_w = (Flip_PDF_Components::PW - 8) / 2;
        $box_x1 = Flip_PDF_Components::LM;
        $box_x2 = Flip_PDF_Components::LM + $box_w + 8;
        $box_y = $this->pdf->GetY();

        // Cash Purchase
        $total_inv = $d['list_price'] + $d['estimated_rehab_cost'] + $purch_close + $d['holding_costs'];
        $this->c->draw_scenario_card($box_x1, $box_y, $box_w, 'Cash Purchase', [
            'Total Investment' => '$' . number_format($total_inv),
            'Profit'           => $this->c->fmt_currency($d['cash_profit']),
            'ROI'              => $this->c->fmt_pct($d['cash_roi']),
        ], $d['cash_profit']);

        // Hard Money
        $cash_in = ($d['list_price'] * 0.20) + $d['estimated_rehab_cost'] + $purch_close;
        $this->c->draw_scenario_card($box_x2, $box_y, $box_w, 'Hard Money (10.5%, 2pts, 80% LTV)', [
            'Cash Invested'    => '$' . number_format($cash_in),
            'Financing Costs'  => '$' . number_format($d['financing_costs']),
            'Profit'           => $this->c->fmt_currency($d['estimated_profit']),
            'Cash-on-Cash ROI' => $this->c->fmt_pct($d['cash_on_cash_roi']),
            'Annualized ROI'   => $this->c->fmt_pct($d['annualized_roi']),
        ], $d['estimated_profit']);

        $this->pdf->SetY($box_y + 56);

        // MAO callout
        $mao_y = $this->pdf->GetY() + 2;
        $this->pdf->SetFillColor($this->c->primary[0], $this->c->primary[1], $this->c->primary[2]);
        $this->pdf->SetAlpha(0.08);
        $this->pdf->RoundedRect(Flip_PDF_Components::LM, $mao_y, Flip_PDF_Components::PW, 20, 3, '1111', 'F');
        $this->pdf->SetAlpha(1);

        $half_w = Flip_PDF_Components::PW / 2;
        $this->pdf->SetXY(Flip_PDF_Components::LM + 6, $mao_y + 3);
        $this->pdf->SetFont('helvetica', '', 9);
        $this->c->set_color($this->c->gray);
        $this->pdf->Cell($half_w - 10, 5, 'MAO (70% Rule)', 0, 1, 'L');
        $this->pdf->SetXY(Flip_PDF_Components::LM + 6, $mao_y + 9);
        $this->pdf->SetFont('helvetica', 'B', 14);
        $this->c->set_color($this->c->primary);
        $this->pdf->Cell($half_w - 10, 7, '$' . number_format($d['mao']), 0, 0, 'L');

        $adj_mao = $d['mao'] - ($d['holding_costs'] ?? 0) - ($d['financing_costs'] ?? 0);
        $this->pdf->SetXY(Flip_PDF_Components::LM + $half_w + 2, $mao_y + 3);
        $this->pdf->SetFont('helvetica', '', 9);
        $this->c->set_color($this->c->gray);
        $this->pdf->Cell($half_w - 10, 5, 'Adjusted MAO (incl. holding + financing)', 0, 0, 'L');
        $this->pdf->SetXY(Flip_PDF_Components::LM + $half_w + 2, $mao_y + 9);
        $this->pdf->SetFont('helvetica', 'B', 14);
        $this->c->set_color($this->c->primary);
        $this->pdf->Cell($half_w - 10, 7, '$' . number_format($adj_mao), 0, 0, 'L');

        $this->pdf->SetY($mao_y + 24);

        // Breakeven ARV callout
        if ($d['breakeven_arv'] > 0 && $d['estimated_arv'] > 0) {
            $margin_pct = (($d['estimated_arv'] - $d['breakeven_arv']) / $d['estimated_arv']) * 100;
            $margin_color = $margin_pct >= 10 ? $this->c->success : ($margin_pct >= 0 ? $this->c->warning : $this->c->danger);

            $be_y = $this->pdf->GetY() + 2;
            $this->c->render_callout_box(
                'Breakeven ARV: $' . number_format($d['breakeven_arv']) .
                '  (' . number_format(abs($margin_pct), 1) . '% ' . ($margin_pct >= 0 ? 'safety margin' : 'above ARV — underwater') . ')',
                $margin_color,
                $be_y
            );
        }

        // Financial bar chart — add to new page if not enough room
        if ($this->pdf->GetY() > 200) {
            $this->pdf->AddPage();
        }
        $this->charts->render_financial_bar_chart($this->d,$this->pdf->GetY() + 4);
    }

    // ─── RENTAL HOLD ANALYSIS ─────────────────────────────────────

    private function add_rental_analysis(): void {
        $rental = $this->d['rental_analysis']['rental'] ?? null;
        if (!$rental) return;

        $this->pdf->AddPage();
        $this->c->add_section_header('Rental Hold Analysis');

        // ── Rental Income card ──
        $vacancy_loss = ($rental['annual_gross_income'] ?? 0) - ($rental['effective_gross'] ?? 0);
        $income_rows = [
            ['Monthly Rent', '$' . number_format($rental['monthly_rent'] ?? 0)],
            ['Rent Source', ucfirst($rental['rent_source'] ?? 'unknown')],
            ['Annual Gross Income', '$' . number_format($rental['annual_gross_income'] ?? 0)],
            ['Vacancy (' . round(($rental['vacancy_rate'] ?? 0.05) * 100) . '%)',
             '-$' . number_format($vacancy_loss)],
            ['Effective Gross Income', '$' . number_format($rental['effective_gross'] ?? 0)],
        ];
        $inc_y = $this->pdf->GetY();
        $inc_h = 6 + count($income_rows) * 7 + 4;
        $this->c->render_card_start($inc_y, $inc_h, ['accent' => $this->c->success]);
        $this->pdf->SetY($inc_y + 4);
        foreach ($income_rows as $r) {
            $bold = ($r[0] === 'Effective Gross Income');
            $this->c->render_kv_row($r[0], $r[1], $bold ? ['bold' => true] : []);
        }
        $this->pdf->SetY($inc_y + $inc_h + 4);

        // ── Operating Expenses card ──
        $exp = $rental['expenses'] ?? [];
        $exp_rows = [
            ['Property Tax', '$' . number_format($exp['property_tax'] ?? 0)],
            ['Insurance', '$' . number_format($exp['insurance'] ?? 0)],
            ['Management', '$' . number_format($exp['management'] ?? 0)],
            ['Maintenance', '$' . number_format($exp['maintenance'] ?? 0)],
            ['CapEx Reserve', '$' . number_format($exp['capex_reserve'] ?? 0)],
            ['Total Annual Expenses', '$' . number_format($exp['total_annual'] ?? 0)],
        ];
        $exp_y = $this->pdf->GetY();
        $exp_h = 6 + count($exp_rows) * 7 + 4;
        $this->c->render_card_start($exp_y, $exp_h, ['accent' => $this->c->danger]);
        $this->pdf->SetY($exp_y + 4);
        foreach ($exp_rows as $r) {
            $bold = ($r[0] === 'Total Annual Expenses');
            $this->c->render_kv_row($r[0], $r[1], $bold ? ['bold' => true] : []);
        }
        $this->pdf->SetY($exp_y + $exp_h + 6);

        // ── Key Metrics (4 metric cards) ──
        $noi = $rental['noi'] ?? 0;
        $cap_rate = $rental['cap_rate'] ?? 0;
        $coc = $rental['cash_on_cash'] ?? 0;
        $grm = $rental['grm'] ?? 0;

        $metric_cards = [
            ['label' => 'NOI', 'value' => '$' . number_format($noi) . '/yr', 'color' => $noi >= 0 ? $this->c->success : $this->c->danger],
            ['label' => 'Cap Rate', 'value' => number_format($cap_rate, 1) . '%', 'color' => $cap_rate >= 6 ? $this->c->success : ($cap_rate >= 4 ? $this->c->warning : $this->c->danger)],
            ['label' => 'Cash-on-Cash', 'value' => number_format($coc, 1) . '%', 'color' => $coc >= 8 ? $this->c->success : ($coc >= 5 ? $this->c->warning : $this->c->danger)],
            ['label' => 'GRM', 'value' => number_format($grm, 1), 'color' => $this->c->text],
        ];
        $this->c->render_metric_grid($metric_cards, 4, $this->pdf->GetY());

        // ── Monthly Cash Flow callout ──
        $monthly_cf = $noi / 12;
        $cf_color = $monthly_cf >= 0 ? $this->c->success : $this->c->danger;
        $cf_text = 'Monthly Cash Flow: ' . ($monthly_cf >= 0 ? '$' : '-$') . number_format(abs($monthly_cf)) . '/mo'
                 . '  |  Annual: ' . ($noi >= 0 ? '$' : '-$') . number_format(abs($noi)) . '/yr';
        $this->c->render_callout_box($cf_text, $cf_color, $this->pdf->GetY() + 2);

        // ── Tax Benefits card ──
        $tax = $rental['tax_benefits'] ?? null;
        if ($tax) {
            $tax_y = $this->pdf->GetY();
            $this->c->add_subsection_header('Tax Benefits');
            $tax_card_y = $this->pdf->GetY();
            $tax_rows = [
                ['Depreciable Basis', '$' . number_format($tax['depreciable_basis'] ?? 0)],
                ['Annual Depreciation (27.5yr)', '$' . number_format($tax['annual_depreciation'] ?? 0)],
                ['Annual Tax Savings (' . round(($tax['marginal_tax_rate'] ?? 0.32) * 100) . '% bracket)',
                 '$' . number_format($tax['annual_tax_savings'] ?? 0)],
            ];
            $tax_h = 6 + count($tax_rows) * 7 + 4;
            $this->c->render_card_start($tax_card_y, $tax_h);
            $this->pdf->SetY($tax_card_y + 4);
            foreach ($tax_rows as $r) {
                $this->c->render_kv_row($r[0], $r[1]);
            }
            $this->pdf->SetY($tax_card_y + $tax_h + 4);
        }

        // ── Multi-Year Projections table ──
        $proj = $rental['projections'] ?? [];
        if (!empty($proj)) {
            // Check if we need a new page
            if ($this->pdf->GetY() > 200) {
                $this->pdf->AddPage();
            }
            $this->c->add_subsection_header('Multi-Year Projections');

            $cols = [38, 38, 38, 38, 40];
            $this->c->render_styled_table_header($cols, ['Year', 'Property Value', 'Equity Gain', 'Cum. Cash Flow', 'Total Return']);

            $this->pdf->SetFont('helvetica', '', 9);
            $zebra = false;
            foreach ($proj as $p) {
                if ($zebra) {
                    $this->pdf->SetFillColor($this->c->zebra[0], $this->c->zebra[1], $this->c->zebra[2]);
                    $this->pdf->Rect(Flip_PDF_Components::LM, $this->pdf->GetY(), Flip_PDF_Components::PW, 7, 'F');
                }
                $this->c->set_text_color();
                $this->pdf->Cell($cols[0], 7, 'Year ' . $p['year'], 0, 0, 'L');
                $this->pdf->Cell($cols[1], 7, '$' . number_format($p['property_value']), 0, 0, 'R');
                $this->pdf->Cell($cols[2], 7, '$' . number_format($p['equity_gain']), 0, 0, 'R');

                $cf_val = $p['cumulative_cf'] ?? 0;
                $this->c->set_color($cf_val >= 0 ? $this->c->success : $this->c->danger);
                $this->pdf->Cell($cols[3], 7, ($cf_val >= 0 ? '$' : '-$') . number_format(abs($cf_val)), 0, 0, 'R');

                $ret_pct = $p['total_return_pct'] ?? 0;
                $this->c->set_color($ret_pct >= 0 ? $this->c->success : $this->c->danger);
                $this->pdf->Cell($cols[4], 7, number_format($ret_pct, 1) . '%', 0, 1, 'R');

                $zebra = !$zebra;
            }
        }

        // ── Per-unit metrics (multifamily) ──
        $per_unit = $rental['per_unit'] ?? null;
        if ($per_unit && ($per_unit['units'] ?? 0) > 1) {
            $this->pdf->Ln(4);
            $pu_y = $this->pdf->GetY();
            $pu_rows = [
                ['Units', (string) $per_unit['units']],
                ['Price / Unit', '$' . number_format($per_unit['price_per_unit'] ?? 0)],
                ['Rent / Unit', '$' . number_format($per_unit['rent_per_unit'] ?? 0) . '/mo'],
                ['NOI / Unit', '$' . number_format($per_unit['noi_per_unit'] ?? 0) . '/yr'],
            ];
            $pu_h = 6 + count($pu_rows) * 7 + 4;
            $this->c->render_card_start($pu_y, $pu_h);
            $this->pdf->SetY($pu_y + 4);
            foreach ($pu_rows as $r) {
                $this->c->render_kv_row($r[0], $r[1]);
            }
        }

        // ── MLS Financial Data — actuals vs estimates (BME enrichment) ──
        $this->render_mls_actuals_card($rental);
    }

    // ─── BRRRR ANALYSIS ───────────────────────────────────────────

    private function add_brrrr_analysis(): void {
        $brrrr = $this->d['rental_analysis']['brrrr'] ?? null;
        $rental = $this->d['rental_analysis']['rental'] ?? null;
        if (!$brrrr) return;

        $this->pdf->AddPage();
        $this->c->add_section_header('BRRRR Analysis');

        // ── Capital Required card ──
        $cap_rows = [
            ['Purchase Price', '$' . number_format($brrrr['purchase_price'] ?? 0)],
            ['Rehab Cost', '$' . number_format($brrrr['rehab_cost'] ?? 0)],
            ['Purchase Closing', '$' . number_format($brrrr['purchase_closing'] ?? 0)],
            ['Total Cash In', '$' . number_format($brrrr['total_cash_in'] ?? 0)],
        ];
        $cap_y = $this->pdf->GetY();
        $cap_h = 6 + count($cap_rows) * 7 + 4;
        $this->c->render_card_start($cap_y, $cap_h, ['accent' => $this->c->primary]);
        $this->pdf->SetY($cap_y + 4);
        foreach ($cap_rows as $r) {
            $bold = ($r[0] === 'Total Cash In');
            $this->c->render_kv_row($r[0], $r[1], $bold ? ['bold' => true] : []);
        }
        $this->pdf->SetY($cap_y + $cap_h + 6);

        // ── Refinance Details card ──
        $this->c->add_subsection_header('Refinance Details');
        $refi_rows = [
            ['After Repair Value (ARV)', '$' . number_format($brrrr['arv'] ?? 0)],
            ['Refi LTV', round(($brrrr['refi_ltv'] ?? 0.75) * 100) . '%'],
            ['Refi Loan Amount', '$' . number_format($brrrr['refi_loan'] ?? 0)],
            ['Interest Rate', number_format(($brrrr['refi_rate'] ?? 0.065) * 100, 1) . '%'],
            ['Loan Term', ($brrrr['refi_term'] ?? 30) . ' years'],
            ['Monthly P&I', '$' . number_format($brrrr['monthly_payment'] ?? 0)],
        ];
        $refi_y = $this->pdf->GetY();
        $refi_h = 6 + count($refi_rows) * 7 + 4;
        $this->c->render_card_start($refi_y, $refi_h);
        $this->pdf->SetY($refi_y + 4);
        foreach ($refi_rows as $r) {
            $this->c->render_kv_row($r[0], $r[1]);
        }
        $this->pdf->SetY($refi_y + $refi_h + 6);

        // ── Capital Recovery hero metrics ──
        $cash_out = $brrrr['cash_out'] ?? 0;
        $cash_left = $brrrr['cash_left_in_deal'] ?? 0;
        $equity = $brrrr['equity_captured'] ?? 0;
        $infinite = $brrrr['infinite_return'] ?? false;
        $total_in = $brrrr['total_cash_in'] ?? 1;
        $recovery_pct = $total_in > 0 ? (($total_in - max(0, $cash_left)) / $total_in) * 100 : 0;

        $recovery_cards = [
            ['label' => 'Cash Out at Refi', 'value' => '$' . number_format($cash_out), 'color' => $this->c->success],
            ['label' => 'Cash Left in Deal', 'value' => $cash_left <= 0 ? '$0' : '$' . number_format($cash_left),
             'color' => $cash_left <= 0 ? $this->c->success : $this->c->warning],
            ['label' => 'Equity Captured', 'value' => '$' . number_format($equity), 'color' => $this->c->primary],
        ];
        $this->c->render_metric_grid($recovery_cards, 3, $this->pdf->GetY());

        // Infinite return callout
        if ($infinite || $cash_left <= 0) {
            $this->c->render_callout_box(
                'Infinite Return — all capital recovered at refinance. ' .
                'Capital Recovery: ' . number_format(min(100, $recovery_pct), 0) . '%',
                $this->c->success,
                $this->pdf->GetY() + 2
            );
        } else {
            $this->c->render_callout_box(
                'Capital Recovery: ' . number_format($recovery_pct, 0) . '% — $' .
                number_format($cash_left) . ' still in the deal',
                $recovery_pct >= 80 ? $this->c->warning : $this->c->danger,
                $this->pdf->GetY() + 2
            );
        }

        // ── Post-Refi Cash Flow table ──
        $this->c->add_subsection_header('Post-Refi Monthly Cash Flow');
        $mb = $brrrr['monthly_breakdown'] ?? [];
        $monthly_rent = $mb['rent'] ?? 0;
        $monthly_exp = $rental ? (($rental['expenses']['total_annual'] ?? 0) / 12) : 0;
        $monthly_pi = $brrrr['monthly_payment'] ?? 0;
        $monthly_net = $monthly_rent - $monthly_exp - $monthly_pi;

        $cf_y = $this->pdf->GetY();
        $cf_rows = [
            ['Rental Income', '+$' . number_format($monthly_rent), '+$' . number_format($monthly_rent * 12)],
            ['Operating Expenses', '-$' . number_format($monthly_exp), '-$' . number_format($monthly_exp * 12)],
            ['Mortgage P&I', '-$' . number_format($monthly_pi), '-$' . number_format($monthly_pi * 12)],
        ];

        $cols = [76, 58, 58];
        $this->c->render_styled_table_header($cols, ['', 'Monthly', 'Annual']);

        $this->pdf->SetFont('helvetica', '', 9);
        foreach ($cf_rows as $r) {
            $this->c->set_text_color();
            $this->pdf->Cell($cols[0], 7, $r[0], 0, 0, 'L');
            $is_income = (strpos($r[1], '+') === 0);
            $this->c->set_color($is_income ? $this->c->success : $this->c->danger);
            $this->pdf->Cell($cols[1], 7, $r[1], 0, 0, 'R');
            $this->pdf->Cell($cols[2], 7, $r[2], 0, 1, 'R');
        }

        // Net row
        $this->pdf->SetFont('helvetica', 'B', 10);
        $net_color = $monthly_net >= 0 ? $this->c->success : $this->c->danger;
        $this->c->set_text_color();
        $this->pdf->Cell($cols[0], 7, 'Net Cash Flow', 'T', 0, 'L');
        $this->c->set_color($net_color);
        $net_prefix = $monthly_net >= 0 ? '$' : '-$';
        $this->pdf->Cell($cols[1], 7, $net_prefix . number_format(abs($monthly_net)), 'T', 0, 'R');
        $this->pdf->Cell($cols[2], 7, $net_prefix . number_format(abs($monthly_net * 12)), 'T', 1, 'R');

        // DSCR callout
        $dscr = $brrrr['dscr'] ?? null;
        if ($dscr !== null) {
            $dscr_color = $dscr >= 1.25 ? $this->c->success : ($dscr >= 1.0 ? $this->c->warning : $this->c->danger);
            $dscr_label = $dscr >= 1.25 ? 'Strong' : ($dscr >= 1.0 ? 'Adequate' : 'Below threshold');
            $this->pdf->Ln(2);
            $this->c->render_callout_box(
                'DSCR: ' . number_format($dscr, 2) . ' — ' . $dscr_label . ' (1.25+ preferred by lenders)',
                $dscr_color,
                $this->pdf->GetY()
            );
        }

        // ── BRRRR Projections table ──
        $proj = $brrrr['projections'] ?? [];
        if (!empty($proj)) {
            if ($this->pdf->GetY() > 210) {
                $this->pdf->AddPage();
            }
            $this->c->add_subsection_header('BRRRR Projections');

            $cols = [38, 38, 38, 38, 40];
            $this->c->render_styled_table_header($cols, ['Year', 'Property Value', 'Equity Gain', 'Cum. Cash Flow', 'Total Return']);

            $this->pdf->SetFont('helvetica', '', 9);
            $zebra = false;
            foreach ($proj as $p) {
                if ($zebra) {
                    $this->pdf->SetFillColor($this->c->zebra[0], $this->c->zebra[1], $this->c->zebra[2]);
                    $this->pdf->Rect(Flip_PDF_Components::LM, $this->pdf->GetY(), Flip_PDF_Components::PW, 7, 'F');
                }
                $this->c->set_text_color();
                $this->pdf->Cell($cols[0], 7, 'Year ' . $p['year'], 0, 0, 'L');
                $this->pdf->Cell($cols[1], 7, '$' . number_format($p['property_value']), 0, 0, 'R');
                $this->pdf->Cell($cols[2], 7, '$' . number_format($p['equity_gain']), 0, 0, 'R');

                $cf_val = $p['cumulative_cf'] ?? 0;
                $this->c->set_color($cf_val >= 0 ? $this->c->success : $this->c->danger);
                $this->pdf->Cell($cols[3], 7, ($cf_val >= 0 ? '$' : '-$') . number_format(abs($cf_val)), 0, 0, 'R');

                // For BRRRR, cap display at 999.9% to avoid absurd infinite-return percentages
                $ret_pct = min($p['total_return_pct'] ?? 0, 999.9);
                $this->c->set_color($ret_pct >= 0 ? $this->c->success : $this->c->danger);
                $this->pdf->Cell($cols[4], 7, number_format($ret_pct, 1) . '%', 0, 1, 'R');

                $zebra = !$zebra;
            }
        }
    }

    // ─── RISK & SENSITIVITY ──────────────────────────────────────

    private function add_risk_and_sensitivity(): void {
        $this->pdf->AddPage();
        $d = $this->d;

        // Deal Risk Grade — large hero card
        $this->c->add_section_header('Deal Risk Assessment');
        $grade = $d['deal_risk_grade'] ?? '--';
        $grade_color = $this->c->get_risk_color($grade);

        $explanations = [
            'A' => 'Strong deal — high confidence, solid margins, good comp support',
            'B' => 'Good deal — reasonable confidence with moderate risk factors',
            'C' => 'Moderate deal — some uncertainty in ARV or margins',
            'D' => 'Marginal deal — significant risk factors present',
            'F' => 'High risk — low confidence, thin margins, or poor comp support',
        ];

        // Risk card with coloured background tint
        $risk_y = $this->pdf->GetY();
        $this->pdf->SetFillColor($grade_color[0], $grade_color[1], $grade_color[2]);
        $this->pdf->SetAlpha(0.08);
        $this->pdf->RoundedRect(Flip_PDF_Components::LM, $risk_y, Flip_PDF_Components::PW, 30, 3, '1111', 'F');
        $this->pdf->SetAlpha(1);

        // Border
        $this->pdf->SetDrawColor($grade_color[0], $grade_color[1], $grade_color[2]);
        $this->pdf->SetLineWidth(0.4);
        $this->pdf->RoundedRect(Flip_PDF_Components::LM, $risk_y, Flip_PDF_Components::PW, 30, 3, '1111', 'D');

        // Grade circle
        $cx = Flip_PDF_Components::LM + 16;
        $cy = $risk_y + 15;
        $this->pdf->SetFillColor($grade_color[0], $grade_color[1], $grade_color[2]);
        $this->pdf->Circle($cx, $cy, 10, 0, 360, 'F');
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetFont('helvetica', 'B', 18);
        $this->pdf->SetXY($cx - 10, $cy - 5);
        $this->pdf->Cell(20, 10, $grade, 0, 0, 'C');

        // Grade explanation
        $this->pdf->SetXY(Flip_PDF_Components::LM + 32, $risk_y + 6);
        $this->c->set_text_color();
        $this->pdf->SetFont('helvetica', 'B', 13);
        $this->pdf->Cell(Flip_PDF_Components::PW - 38, 7, 'Risk Grade ' . $grade, 0, 1, 'L');

        $this->pdf->SetXY(Flip_PDF_Components::LM + 32, $risk_y + 14);
        $this->pdf->SetFont('helvetica', '', 10);
        $this->c->set_color($this->c->gray);
        $this->pdf->Cell(Flip_PDF_Components::PW - 38, 6, $explanations[$grade] ?? 'Not graded', 0, 1, 'L');

        $this->pdf->SetY($risk_y + 36);

        // Sensitivity table
        $this->c->add_section_header('Sensitivity Analysis (Hard Money Financing)');

        $scenarios = [
            ['Base Case',    1.00, 1.00],
            ['Conservative', 0.90, 1.20],
            ['Worst Case',   0.85, 1.30],
        ];

        $cols = [50, 30, 30, 40, 42];
        $headers = ['Scenario', 'ARV Adj.', 'Rehab Adj.', 'Profit', 'ROI'];

        $this->c->render_styled_table_header($cols, $headers);

        $this->pdf->SetFont('helvetica', '', 9);
        foreach ($scenarios as $si => $sc) {
            $s_arv   = $d['estimated_arv'] * $sc[1];
            $s_rehab = $d['estimated_rehab_cost'] * $sc[2];
            $s_fin   = $this->c->calc_scenario($d['list_price'], $s_arv, $s_rehab, $d['hold_months']);

            $is_zebra = ($si % 2 === 1);
            if ($is_zebra) {
                $this->pdf->SetFillColor($this->c->zebra[0], $this->c->zebra[1], $this->c->zebra[2]);
            }

            $this->c->set_text_color();
            $this->pdf->Cell($cols[0], 7, $sc[0], 0, 0, 'L', $is_zebra);
            $this->pdf->Cell($cols[1], 7, ($sc[1] * 100) . '%', 0, 0, 'R', $is_zebra);
            $this->pdf->Cell($cols[2], 7, ($sc[2] * 100) . '%', 0, 0, 'R', $is_zebra);

            $pc = $s_fin['profit'] >= 0 ? $this->c->success : $this->c->danger;
            $this->c->set_color($pc);
            $this->pdf->Cell($cols[3], 7, $this->c->fmt_currency($s_fin['profit']), 0, 0, 'R', $is_zebra);
            $this->pdf->Cell($cols[4], 7, $this->c->fmt_pct($s_fin['roi']), 0, 0, 'R', $is_zebra);
            $this->pdf->Ln();
        }

        $this->pdf->Ln(4);

        // Sensitivity line chart
        $this->c->add_subsection_header('Profit vs ARV Scenario');
        $this->charts->render_sensitivity_chart($this->d,$this->pdf->GetY());

        // Market-Adaptive Thresholds
        $thresholds = $d['applied_thresholds'] ?? null;
        if ($thresholds) {
            $this->c->add_section_header('Market-Adaptive Thresholds');

            $th_cards = [
                ['label' => 'Min Profit',     'value' => '$' . number_format($thresholds['min_profit'] ?? 0),     'color' => $this->c->text],
                ['label' => 'Min ROI',         'value' => number_format($thresholds['min_roi'] ?? 0, 1) . '%',    'color' => $this->c->text],
                ['label' => 'Max Price/ARV',   'value' => number_format(($thresholds['max_price_arv'] ?? 0) * 100, 0) . '%', 'color' => $this->c->text],
                ['label' => 'Market Strength', 'value' => ucwords(str_replace('_', ' ', $thresholds['market_strength'] ?? 'balanced')), 'color' => $this->c->market_colors[$thresholds['market_strength'] ?? 'balanced'] ?? $this->c->gray],
            ];

            $this->c->render_metric_grid($th_cards, 4, $this->pdf->GetY());
        }
    }

    // ─── PAGE 5+: COMPARABLES ──────────────────────────────────────

    private function add_comparables(): void {
        $comps = $this->d['comps'] ?? [];
        if (empty($comps)) {
            return;
        }

        $this->pdf->AddPage();
        $this->c->add_section_header('Comparable Sales (' . count($comps) . ' comps)');

        // Average $/sqft summary
        $total_ppsf = 0;
        $ppsf_count = 0;
        foreach ($comps as $c) {
            $ppsf = $c['adjusted_ppsf'] ?? $c['ppsf'] ?? 0;
            if ($ppsf > 0) { $total_ppsf += $ppsf; $ppsf_count++; }
        }
        if ($ppsf_count > 0) {
            $avg_ppsf = $total_ppsf / $ppsf_count;
            $this->pdf->SetFont('helvetica', '', 10);
            $this->c->set_color($this->c->gray);
            $this->pdf->Cell(Flip_PDF_Components::PW, 6, 'Average Comp $/SqFt: $' . number_format($avg_ppsf) . '  |  ' . count($comps) . ' comparables used', 0, 1, 'L');
            $this->pdf->Ln(4);
        }

        // Comp photo cards (limit to 8 to avoid excessive photo downloads)
        $y = $this->pdf->GetY();
        $rendered = 0;
        foreach ($comps as $c) {
            if ($rendered >= 8) break;
            $y = $this->images->render_comp_card($c, $y);
            $rendered++;
        }

        // Remaining comps as compact table if more than 8
        if (count($comps) > 8) {
            if ($y > 230) {
                $this->pdf->AddPage();
                $y = Flip_PDF_Components::LM + 5;
            }
            $this->pdf->SetY($y + 2);
            $this->c->add_subsection_header('Additional Comparables');

            $cols = [58, 28, 30, 22, 22, 32];
            $headers = ['Address', 'Sold Price', 'Adj. Price', '$/SqFt', 'Distance', 'Close Date'];
            $this->c->render_styled_table_header($cols, $headers);

            $this->pdf->SetFont('helvetica', '', 8.5);
            foreach (array_slice($comps, 8) as $ci => $c) {
                if ($this->pdf->GetY() > 240) {
                    $this->pdf->AddPage();
                    $this->c->render_styled_table_header($cols, $headers);
                    $this->pdf->SetFont('helvetica', '', 8.5);
                }

                $is_zebra = ($ci % 2 === 1);
                if ($is_zebra) {
                    $this->pdf->SetFillColor($this->c->zebra[0], $this->c->zebra[1], $this->c->zebra[2]);
                }

                $this->c->set_text_color();
                $addr = $c['address'] ?? 'Unknown';
                if (strlen($addr) > 33) $addr = substr($addr, 0, 31) . '...';
                $this->pdf->Cell($cols[0], 7, $addr, 0, 0, 'L', $is_zebra);
                $this->pdf->Cell($cols[1], 7, '$' . number_format($c['close_price'] ?? 0), 0, 0, 'R', $is_zebra);
                $adj_p = $c['adjusted_price'] ?? ($c['close_price'] ?? 0);
                $this->pdf->Cell($cols[2], 7, '$' . number_format($adj_p), 0, 0, 'R', $is_zebra);
                $pp = $c['adjusted_ppsf'] ?? $c['ppsf'] ?? 0;
                $this->pdf->Cell($cols[3], 7, $pp > 0 ? '$' . number_format($pp) : '--', 0, 0, 'R', $is_zebra);
                $di = $c['distance_miles'] ?? 0;
                $this->pdf->Cell($cols[4], 7, $di > 0 ? number_format($di, 2) . ' mi' : '--', 0, 0, 'R', $is_zebra);
                $this->pdf->Cell($cols[5], 7, $c['close_date'] ?? '--', 0, 0, 'R', $is_zebra);
                $this->pdf->Ln();
            }
        }
    }

    // ─── PAGE 6: PROPERTY & LOCATION ───────────────────────────────

    private function add_property_and_location(): void {
        $this->pdf->AddPage();
        $d = $this->d;

        $this->c->add_section_header('Property Characteristics');

        $sqft = $d['building_area_total'] ?? 0;
        $lot_sqft = ($d['lot_size_acres'] ?? 0) * 43560;
        $lot_house = ($sqft > 0 && $lot_sqft > 0) ? number_format($lot_sqft / $sqft, 1) . 'x' : '--';

        $prop_cards = [
            ['label' => 'Bedrooms',   'value' => (string) ($d['bedrooms_total'] ?? '--'),                      'color' => $this->c->text],
            ['label' => 'Bathrooms',  'value' => (string) ($d['bathrooms_total'] ?? '--'),                     'color' => $this->c->text],
            ['label' => 'Sq Footage', 'value' => $sqft > 0 ? number_format($sqft) : '--',                     'color' => $this->c->text],
            ['label' => 'Year Built', 'value' => $d['year_built'] > 0 ? (string) $d['year_built'] : '--',     'color' => $this->c->text],
            ['label' => 'Lot Size',   'value' => ($d['lot_size_acres'] ?? 0) > 0 ? number_format($d['lot_size_acres'], 2) . ' ac' : '--', 'color' => $this->c->text],
            ['label' => 'Price/SqFt', 'value' => $sqft > 0 ? '$' . number_format($d['list_price'] / $sqft) : '--', 'color' => $this->c->text],
            ['label' => 'DOM',        'value' => ($d['days_on_market'] ?? 0) > 0 ? $d['days_on_market'] . ' days' : '--', 'color' => $this->c->text],
            ['label' => 'Lot/House',  'value' => $lot_house,                                                   'color' => $this->c->text],
        ];

        $this->c->render_metric_grid($prop_cards, 4, $this->pdf->GetY());
        $this->pdf->Ln(2);

        // Location analysis card
        $this->c->add_section_header('Location Analysis');

        $road_labels = [
            'cul-de-sac' => 'Cul-de-sac', 'quiet-residential' => 'Quiet Residential',
            'moderate-traffic' => 'Moderate Traffic', 'busy-road' => 'Busy Road',
            'highway-adjacent' => 'Highway Adjacent', 'unknown' => 'Unknown',
        ];

        $road_type = $d['road_type'] ?? 'unknown';
        $road_colors = [
            'cul-de-sac' => $this->c->success, 'quiet-residential' => $this->c->success,
            'moderate-traffic' => $this->c->warning, 'busy-road' => $this->c->danger,
            'highway-adjacent' => $this->c->danger, 'unknown' => $this->c->gray,
        ];

        $loc_y = $this->pdf->GetY();
        $loc_h = 40;
        $this->c->render_card_start($loc_y, $loc_h);

        $this->pdf->SetY($loc_y + 4);

        // Road type with colour indicator
        $road_color = $road_colors[$road_type] ?? $this->c->gray;
        $dot_y = $this->pdf->GetY() + 2;
        $this->pdf->SetFillColor($road_color[0], $road_color[1], $road_color[2]);
        $this->pdf->Circle(Flip_PDF_Components::LM + 7, $dot_y + 1, 2, 0, 360, 'F');
        $this->pdf->SetX(Flip_PDF_Components::LM + 12);
        $this->pdf->SetFont('helvetica', '', 9);
        $this->c->set_color($this->c->gray);
        $this->pdf->Cell(78, 5, 'Road Type', 0, 0, 'L');
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->c->set_text_color();
        $this->pdf->Cell(Flip_PDF_Components::PW - 92, 5, $road_labels[$road_type] ?? $road_type, 0, 1, 'R');

        $this->c->render_kv_row('Neighborhood Ceiling', ($d['neighborhood_ceiling'] ?? 0) > 0 ? '$' . number_format($d['neighborhood_ceiling']) : '--');

        $ceil_pct = $d['ceiling_pct'] ?? 0;
        $ceil_color = $ceil_pct > 100 ? $this->c->danger : ($ceil_pct > 90 ? $this->c->warning : $this->c->success);
        $this->c->render_kv_row('ARV / Ceiling', $ceil_pct > 0 ? number_format($ceil_pct, 1) . '%' : '--', ['color' => $ceil_color]);

        $this->c->render_kv_row('Lot / House Ratio', $lot_house);

        $this->pdf->SetY($loc_y + $loc_h + 6);

        // ── Property Details card (BME enrichment) ──
        $this->render_property_details_card();

        // ── Financial & Tax card (BME enrichment) ──
        $this->render_financial_tax_card();

        // ── Rent Roll card (multifamily — BME enrichment) ──
        $this->render_rent_roll_card();

        // ARV Projections
        $avg_ppsf = (float) ($this->raw->avg_comp_ppsf ?? 0);
        if ($avg_ppsf > 0 && $sqft > 0) {
            $this->c->add_section_header('ARV Projection Scenarios');

            $road_disc = Flip_Analyzer::ROAD_ARV_DISCOUNT[$d['road_type'] ?? ''] ?? 0;
            $eff_ppsf = $avg_ppsf * (1 - $road_disc);
            $addition_cost = 250;

            $this->pdf->SetFont('helvetica', '', 9);
            $this->c->set_color($this->c->gray);
            $this->pdf->Cell(Flip_PDF_Components::PW, 5, 'Based on avg comp $/sqft of $' . number_format($avg_ppsf) . ($road_disc > 0 ? ' (after ' . ($road_disc * 100) . '% road discount)' : ''), 0, 1, 'L');
            $this->pdf->Ln(3);

            $scenarios_proj = [
                ['Current',                        $d['bedrooms_total'], $d['bathrooms_total'], $sqft],
                ['+1 Bed, +300 sqft',              $d['bedrooms_total'] + 1, $d['bathrooms_total'], $sqft + 300],
                ['+1 Bed, +1 Bath, +500 sqft',     $d['bedrooms_total'] + 1, $d['bathrooms_total'] + 1, $sqft + 500],
            ];

            $pcols = [52, 16, 16, 22, 30, 28, 28];
            $pheaders = ['Scenario', 'Beds', 'Baths', 'SqFt', 'Proj. ARV', 'Profit', 'ROI'];

            $this->c->render_styled_table_header($pcols, $pheaders);

            $this->pdf->SetFont('helvetica', '', 8.5);
            foreach ($scenarios_proj as $si => $sp) {
                $new_sqft = $sp[3];
                $added_sqft = max(0, $new_sqft - $sqft);
                $proj_arv = $eff_ppsf * $new_sqft;
                $add_cost = $added_sqft * $addition_cost;
                $s_fin = $this->c->calc_scenario($d['list_price'], $proj_arv, $d['estimated_rehab_cost'] + $add_cost, $d['hold_months']);

                $is_zebra = ($si % 2 === 1);
                if ($is_zebra) {
                    $this->pdf->SetFillColor($this->c->zebra[0], $this->c->zebra[1], $this->c->zebra[2]);
                }

                $this->c->set_text_color();
                $this->pdf->Cell($pcols[0], 7, $sp[0], 0, 0, 'L', $is_zebra);
                $this->pdf->Cell($pcols[1], 7, (string) $sp[1], 0, 0, 'R', $is_zebra);
                $this->pdf->Cell($pcols[2], 7, (string) $sp[2], 0, 0, 'R', $is_zebra);
                $this->pdf->Cell($pcols[3], 7, number_format($new_sqft), 0, 0, 'R', $is_zebra);
                $this->pdf->Cell($pcols[4], 7, '$' . number_format($proj_arv / 1000) . 'K', 0, 0, 'R', $is_zebra);

                $pc = $s_fin['profit'] >= 0 ? $this->c->success : $this->c->danger;
                $this->c->set_color($pc);
                $this->pdf->Cell($pcols[5], 7, $this->c->fmt_currency($s_fin['profit']), 0, 0, 'R', $is_zebra);
                $this->pdf->Cell($pcols[6], 7, $this->c->fmt_pct($s_fin['roi']), 0, 0, 'R', $is_zebra);
                $this->pdf->Ln();
            }
        }
    }

    // ─── PHOTO ANALYSIS (conditional) ──────────────────────────────

    private function add_photo_analysis(): void {
        $pa = $this->d['photo_analysis'] ?? null;
        $signals = $this->d['remarks_signals'] ?? [];

        if (!$pa && empty($signals)) {
            return;
        }

        $this->pdf->AddPage();

        if ($pa) {
            $this->c->add_section_header('Photo Analysis');

            // Key photo metrics in cards
            $photo_cards = [
                ['label' => 'Condition',    'value' => isset($pa['overall_condition']) ? $pa['overall_condition'] . '/10' : '--', 'color' => $this->c->get_score_color(($pa['overall_condition'] ?? 5) * 10)],
                ['label' => 'Reno Level',   'value' => ucfirst($pa['renovation_level'] ?? '--'),                                   'color' => $this->c->text],
                ['label' => 'Cost/SqFt',    'value' => isset($pa['estimated_cost_per_sqft']) ? '$' . $pa['estimated_cost_per_sqft'] : '--', 'color' => $this->c->text],
                ['label' => 'Photo Score',  'value' => $this->d['photo_score'] !== null ? number_format($this->d['photo_score'], 1) : '--', 'color' => $this->d['photo_score'] !== null ? $this->c->get_score_color($this->d['photo_score']) : $this->c->gray],
            ];

            $this->c->render_metric_grid($photo_cards, 4, $this->pdf->GetY());
            $this->pdf->Ln(2);

            // Room condition bars in card
            $rooms = [
                'kitchen_condition'  => 'Kitchen',
                'bathroom_condition' => 'Bathroom',
                'flooring_condition' => 'Flooring',
                'exterior_condition' => 'Exterior',
                'curb_appeal'        => 'Curb Appeal',
            ];

            $has_rooms = false;
            foreach ($rooms as $key => $label) {
                if (($pa[$key] ?? null) !== null) { $has_rooms = true; break; }
            }

            if ($has_rooms) {
                $this->c->add_subsection_header('Room Condition Scores');

                $room_y = $this->pdf->GetY();
                $room_count = 0;
                foreach ($rooms as $key => $label) {
                    if (($pa[$key] ?? null) !== null) $room_count++;
                }
                $room_h = 4 + ($room_count * 12) + 2;
                $this->c->render_card_start($room_y, $room_h);
                $this->pdf->SetY($room_y + 3);

                foreach ($rooms as $key => $label) {
                    $val = $pa[$key] ?? null;
                    if ($val !== null) {
                        $this->c->render_score_bar($label, $val * 10, null);
                    }
                }

                $this->pdf->SetY($room_y + $room_h + 4);
            }

            // Structural concerns
            if (!empty($pa['structural_concerns'])) {
                $this->c->render_callout_box(
                    'STRUCTURAL CONCERNS: ' . ($pa['structural_details'] ?? 'Potential issues identified in photos'),
                    $this->c->warning,
                    $this->pdf->GetY()
                );
                $this->pdf->Ln(2);
            }

            // Renovation summary
            if (!empty($pa['renovation_summary'])) {
                $this->pdf->Ln(2);
                $this->c->add_subsection_header('Renovation Summary');
                $this->pdf->SetFont('helvetica', 'I', 9);
                $this->c->set_text_color();
                $this->pdf->MultiCell(Flip_PDF_Components::PW, 5, $pa['renovation_summary'], 0, 'L');
            }
        }

        // Property Description (from BME public_remarks)
        $remarks_text = $this->bme->listing->public_remarks ?? null;
        $positives = $signals['positive'] ?? [];
        $negatives = $signals['negative'] ?? [];
        $adj_total = $signals['adjustment'] ?? 0;
        $has_signals = !empty($positives) || !empty($negatives);

        if (!empty($remarks_text) || $has_signals) {
            $this->pdf->Ln(4);

            // Page break guard — need at least 60mm for description block
            $page_bottom = $this->pdf->getPageHeight() - $this->pdf->getBreakMargin();
            if (($page_bottom - $this->pdf->GetY()) < 60) {
                $this->pdf->AddPage();
            }

            $this->c->add_section_header('Property Description');

            if (!empty($remarks_text)) {
                // Truncate at ~1,500 characters
                $display_text = $remarks_text;
                if (mb_strlen($display_text) > 1500) {
                    $display_text = mb_substr($display_text, 0, 1497) . '...';
                }

                // Render in a card with word-wrapped text
                $this->pdf->SetFont('helvetica', '', 9);
                // Estimate card height: ~4.5mm per line, ~95 chars per line at 9pt
                $line_count = max(3, ceil(mb_strlen($display_text) / 95));
                $text_h = $line_count * 4.5;
                $card_h = $text_h + 10;

                $card_y = $this->pdf->GetY();
                $this->c->render_card_start($card_y, $card_h);
                $this->pdf->SetXY(Flip_PDF_Components::LM + 5, $card_y + 5);
                $this->c->set_text_color();
                $this->pdf->MultiCell(Flip_PDF_Components::PW - 10, 4.5, $display_text, 0, 'L');
                $this->pdf->SetY($card_y + $card_h + 4);
            }

            // Condensed keyword signals below description
            if ($has_signals) {
                $this->c->add_subsection_header('Keyword Signals');

                if (!empty($positives)) {
                    $this->pdf->SetFont('helvetica', 'B', 9);
                    $this->c->set_color($this->c->success);
                    $this->pdf->Cell(28, 6, 'Positive:', 0, 0, 'L');

                    $pill_x = $this->pdf->GetX();
                    $pill_y = $this->pdf->GetY();
                    foreach ($positives as $p) {
                        if ($pill_x > Flip_PDF_Components::LM + Flip_PDF_Components::PW - 30) {
                            $this->pdf->Ln(8);
                            $pill_x = Flip_PDF_Components::LM + 28;
                            $pill_y = $this->pdf->GetY();
                        }
                        $pill_x = $this->c->render_pill_badge($p, $this->c->success, $pill_x, $pill_y);
                    }
                    $this->pdf->Ln(10);
                }

                if (!empty($negatives)) {
                    $this->pdf->SetFont('helvetica', 'B', 9);
                    $this->c->set_color($this->c->danger);
                    $this->pdf->Cell(28, 6, 'Negative:', 0, 0, 'L');

                    $pill_x = $this->pdf->GetX();
                    $pill_y = $this->pdf->GetY();
                    foreach ($negatives as $n) {
                        if ($pill_x > Flip_PDF_Components::LM + Flip_PDF_Components::PW - 30) {
                            $this->pdf->Ln(8);
                            $pill_x = Flip_PDF_Components::LM + 28;
                            $pill_y = $this->pdf->GetY();
                        }
                        $pill_x = $this->c->render_pill_badge($n, $this->c->danger, $pill_x, $pill_y);
                    }
                    $this->pdf->Ln(10);
                }

                if ($adj_total != 0) {
                    $adj_color = $adj_total > 0 ? $this->c->success : $this->c->danger;
                    $this->pdf->SetFont('helvetica', 'B', 10);
                    $this->c->set_color($adj_color);
                    $arrow = $adj_total > 0 ? '+' : '';
                    $this->pdf->Cell(Flip_PDF_Components::PW, 6, 'Score Adjustment: ' . $arrow . $adj_total . ' pts', 0, 1, 'L');
                }
            }
        }
    }

    // ─── CALL TO ACTION PAGE ──────────────────────────────────────

    private function add_call_to_action(): void {
        $this->pdf->AddPage();
        $d = $this->d;

        // Top accent bar
        $this->pdf->SetFillColor($this->c->primary[0], $this->c->primary[1], $this->c->primary[2]);
        $this->pdf->Rect(Flip_PDF_Components::LM, Flip_PDF_Components::LM, Flip_PDF_Components::PW, 3, 'F');

        // Company logo (centered)
        $logo_file = $this->images->download_image_temp($this->logo_url);
        if ($logo_file) {
            $this->pdf->Image($logo_file, Flip_PDF_Components::LM + (Flip_PDF_Components::PW - 70) / 2, Flip_PDF_Components::LM + 8, 70, 0, '', '', '', false, 300, '', false, false, 0);
            @unlink($logo_file);
        }

        $this->pdf->SetY(Flip_PDF_Components::LM + 32);

        // Thin divider line
        $this->pdf->SetDrawColor(220, 220, 220);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line(Flip_PDF_Components::LM + 40, $this->pdf->GetY(), Flip_PDF_Components::LM + Flip_PDF_Components::PW - 40, $this->pdf->GetY());
        $this->pdf->Ln(8);

        // Headline
        $this->pdf->SetFont('helvetica', 'B', 24);
        $this->c->set_color($this->c->primary);
        $this->pdf->Cell(Flip_PDF_Components::PW, 12, 'Interested in This Property?', 0, 1, 'C');
        $this->pdf->Ln(4);

        // Subheadline
        $this->pdf->SetFont('helvetica', '', 12);
        $this->c->set_color($this->c->gray);
        $this->pdf->MultiCell(Flip_PDF_Components::PW, 7,
            'This analysis was prepared exclusively for you. If you\'d like to discuss this opportunity, schedule a walkthrough, or explore other flip candidates, I\'m here to help.',
            0, 'C'
        );
        $this->pdf->Ln(8);

        // Agent card
        $card_w = 140;
        $card_x = Flip_PDF_Components::LM + (Flip_PDF_Components::PW - $card_w) / 2;
        $card_y = $this->pdf->GetY();
        $card_h = 52;

        // Card shadow
        $this->pdf->SetFillColor(210, 210, 210);
        $this->pdf->SetAlpha(0.3);
        $this->pdf->RoundedRect($card_x + 1, $card_y + 1, $card_w, $card_h, 4, '1111', 'F');
        $this->pdf->SetAlpha(1);

        // Card background
        $this->pdf->SetFillColor(255, 255, 255);
        $this->pdf->SetDrawColor($this->c->card_bdr[0], $this->c->card_bdr[1], $this->c->card_bdr[2]);
        $this->pdf->SetLineWidth(0.4);
        $this->pdf->RoundedRect($card_x, $card_y, $card_w, $card_h, 4, '1111', 'DF');

        // Left accent bar
        $this->pdf->SetFillColor($this->c->primary[0], $this->c->primary[1], $this->c->primary[2]);
        $this->pdf->RoundedRect($card_x, $card_y, 3, $card_h, 4, '1010', 'F');

        // Agent photo (circular clip)
        $photo_size = 36;
        $photo_x = $card_x + 12;
        $photo_y = $card_y + ($card_h - $photo_size) / 2;

        // Circle background
        $this->pdf->SetFillColor(240, 240, 240);
        $cx = $photo_x + $photo_size / 2;
        $cy = $photo_y + $photo_size / 2;
        $this->pdf->Circle($cx, $cy, $photo_size / 2, 0, 360, 'F');

        $agent_photo = $this->images->download_image_temp($this->agent_photo_url);
        if ($agent_photo) {
            $info = @getimagesize($agent_photo);
            if ($info) {
                $this->pdf->StartTransform();
                $this->pdf->Circle($cx, $cy, $photo_size / 2, 0, 360, 'CNZ');
                $this->pdf->Image($agent_photo, $photo_x, $photo_y, $photo_size, $photo_size, '', '', '', false, 300, '', false, false, 0);
                $this->pdf->StopTransform();
            }
            @unlink($agent_photo);
        }

        // Agent info (right of photo)
        $info_x = $photo_x + $photo_size + 10;
        $info_w = $card_w - $photo_size - 34;

        $this->pdf->SetXY($info_x, $card_y + 8);
        $this->pdf->SetFont('helvetica', 'B', 16);
        $this->c->set_text_color();
        $this->pdf->Cell($info_w, 8, $this->agent_name, 0, 1, 'L');

        $this->pdf->SetX($info_x);
        $this->pdf->SetFont('helvetica', '', 10);
        $this->c->set_color($this->c->primary);
        $this->pdf->Cell($info_w, 6, $this->agent_title . '  |  ' . $this->company_name, 0, 1, 'L');

        $this->pdf->SetX($info_x);
        $this->pdf->Ln(3);
        $this->pdf->SetX($info_x);
        $this->pdf->SetFont('helvetica', '', 10);
        $this->c->set_color($this->c->gray);
        $this->pdf->Cell($info_w, 6, $this->agent_phone . '  |  ' . $this->agent_email, 0, 1, 'L');

        $this->pdf->SetX($info_x);
        $this->pdf->SetFont('helvetica', '', 9);
        $this->pdf->Cell($info_w, 5, 'License ' . $this->agent_license, 0, 1, 'L');

        $this->pdf->SetY($card_y + $card_h + 12);

        // CTA buttons (styled as rounded rects)
        $btn_w = 80;
        $btn_h = 12;
        $btn_gap = 10;
        $btn_y = $this->pdf->GetY();

        // Phone button
        $btn_x1 = Flip_PDF_Components::LM + (Flip_PDF_Components::PW - $btn_w * 2 - $btn_gap) / 2;
        $this->pdf->SetFillColor($this->c->primary[0], $this->c->primary[1], $this->c->primary[2]);
        $this->pdf->RoundedRect($btn_x1, $btn_y, $btn_w, $btn_h, 4, '1111', 'F');
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->SetXY($btn_x1, $btn_y + 2);
        $this->pdf->Cell($btn_w, 8, 'Call ' . $this->agent_phone, 0, 0, 'C');

        // Email button
        $btn_x2 = $btn_x1 + $btn_w + $btn_gap;
        $this->pdf->SetFillColor(255, 255, 255);
        $this->pdf->SetDrawColor($this->c->primary[0], $this->c->primary[1], $this->c->primary[2]);
        $this->pdf->SetLineWidth(0.5);
        $this->pdf->RoundedRect($btn_x2, $btn_y, $btn_w, $btn_h, 4, '1111', 'DF');
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->c->set_color($this->c->primary);
        $this->pdf->SetXY($btn_x2, $btn_y + 2);
        $this->pdf->Cell($btn_w, 8, 'Email Steve', 0, 0, 'C');

        $this->pdf->SetY($btn_y + $btn_h + 14);

        // Bottom section — company info
        $this->pdf->SetDrawColor(220, 220, 220);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line(Flip_PDF_Components::LM + 40, $this->pdf->GetY(), Flip_PDF_Components::LM + Flip_PDF_Components::PW - 40, $this->pdf->GetY());
        $this->pdf->Ln(6);

        $this->pdf->SetFont('helvetica', '', 9);
        $this->c->set_color($this->c->gray);
        $this->pdf->Cell(Flip_PDF_Components::PW, 5, $this->company_name . '  |  ' . $this->company_address, 0, 1, 'C');
        $this->pdf->Cell(Flip_PDF_Components::PW, 5, $this->company_phone . '  |  ' . $this->company_url, 0, 1, 'C');
        $this->pdf->Ln(3);
        $this->pdf->SetFont('helvetica', 'I', 8);
        $this->pdf->Cell(Flip_PDF_Components::PW, 4, 'Member of Douglas Elliman', 0, 1, 'C');

        // Property offered by disclosure
        $listing_info = $this->images->fetch_listing_agent_info($d['listing_id']);
        if ($listing_info) {
            $this->pdf->Ln(6);

            $disc_y = $this->pdf->GetY();
            $disc_h = 22;

            // Light background
            $this->pdf->SetFillColor($this->c->light[0], $this->c->light[1], $this->c->light[2]);
            $this->pdf->RoundedRect(Flip_PDF_Components::LM + 20, $disc_y, Flip_PDF_Components::PW - 40, $disc_h, 3, '1111', 'F');

            $this->pdf->SetXY(Flip_PDF_Components::LM + 20, $disc_y + 3);
            $this->pdf->SetFont('helvetica', 'B', 8);
            $this->c->set_color($this->c->gray);
            $this->pdf->Cell(Flip_PDF_Components::PW - 40, 5, 'Property Offered By', 0, 1, 'C');

            $this->pdf->SetX(Flip_PDF_Components::LM + 20);
            $this->pdf->SetFont('helvetica', '', 9);
            $this->c->set_text_color();
            $offered_line = $listing_info['agent_name'];
            if (!empty($listing_info['office_name'])) {
                $offered_line .= '  |  ' . $listing_info['office_name'];
            }
            $this->pdf->Cell(Flip_PDF_Components::PW - 40, 5, $offered_line, 0, 1, 'C');

            $this->pdf->SetY($disc_y + $disc_h + 2);
        }

        // Disclaimer
        $this->pdf->Ln(4);
        $this->pdf->SetFont('helvetica', '', 7);
        $this->c->set_color([170, 170, 170]);
        $this->pdf->MultiCell(Flip_PDF_Components::PW, 3.5,
            'Disclaimer: This analysis is for informational purposes only and does not constitute financial advice. All estimates including ARV, rehab costs, and projected returns are based on comparable sales data and algorithmic modeling. Actual results may vary. Consult with your financial advisor, contractor, and real estate attorney before making investment decisions.',
            0, 'C'
        );
    }

    // ─── ENRICHMENT CARDS ────────────────────────────────────────

    /**
     * Property Details card — systems, construction, features from BME.
     */
    private function render_property_details_card(): void {
        $det = $this->bme->details ?? null;
        $listing = $this->bme->listing ?? null;
        if (!$det && !$listing) return;

        $rows = [];
        if ($val = $listing->property_sub_type ?? null) $rows[] = ['Property Type', $val];
        if ($val = $this->format_bme_array($det->architectural_style ?? null)) $rows[] = ['Architectural Style', $val];
        if ($val = $det->stories_total ?? null) $rows[] = ['Stories', (string) $val];
        if ($val = $this->format_bme_array($det->heating ?? null)) $rows[] = ['Heating', $val];
        if ($val = $this->format_bme_array($det->cooling ?? null)) $rows[] = ['Cooling', $val];
        if ($val = $this->format_bme_array($det->water_source ?? null)) $rows[] = ['Water', $val];
        if ($val = $this->format_bme_array($det->sewer ?? null)) $rows[] = ['Sewer', $val];
        if ($val = $this->format_bme_array($det->foundation_details ?? null)) $rows[] = ['Foundation', $val];
        if ($val = $this->format_bme_array($det->roof ?? null)) $rows[] = ['Roof', $val];
        if ($val = $this->format_bme_array($det->construction_materials ?? null)) $rows[] = ['Exterior', $val];
        if ($val = $this->format_bme_array($det->flooring ?? null)) $rows[] = ['Flooring', $val];
        if ($val = $this->format_bme_array($det->interior_features ?? null)) $rows[] = ['Interior Features', $val];
        if ($val = $this->format_bme_array($det->appliances ?? null)) $rows[] = ['Appliances', $val];
        if ($val = $this->format_bme_array($det->basement ?? null)) $rows[] = ['Basement', $val];

        // Parking — combine garage + total
        $garage = $det->garage_spaces ?? null;
        $parking_total = $det->parking_total ?? null;
        if ($garage || $parking_total) {
            $parking_str = '';
            if ($garage && $garage > 0) $parking_str .= $garage . ' garage';
            if ($parking_total && $parking_total > 0) {
                if ($parking_str) $parking_str .= ', ';
                $parking_str .= $parking_total . ' total';
            }
            if ($parking_str) $rows[] = ['Parking', $parking_str];
        }

        // Fireplace
        $fp_yn = $det->fireplace_yn ?? null;
        $fp_count = $det->fireplaces_total ?? null;
        if ($fp_yn === 'Y' || $fp_yn === '1' || $fp_yn === 'true' || ($fp_count && $fp_count > 0)) {
            $rows[] = ['Fireplace', $fp_count ? $fp_count . ' fireplace(s)' : 'Yes'];
        }

        if ($val = $this->format_bme_array($det->property_condition ?? null)) $rows[] = ['Property Condition', $val];

        if (empty($rows)) return;

        // Page break guard
        $needed = 6 + count($rows) * 7 + 10;
        $page_bottom = $this->pdf->getPageHeight() - $this->pdf->getBreakMargin();
        if (($page_bottom - $this->pdf->GetY()) < $needed) {
            $this->pdf->AddPage();
        }

        $this->c->add_subsection_header('Property Details');

        $card_y = $this->pdf->GetY();
        $card_h = 6 + count($rows) * 7 + 4;
        $this->c->render_card_start($card_y, $card_h);
        $this->pdf->SetY($card_y + 4);
        foreach ($rows as $r) {
            $this->c->render_kv_row($r[0], $r[1]);
        }
        $this->pdf->SetY($card_y + $card_h + 6);
    }

    /**
     * Financial & Tax card — tax, HOA, zoning, REO flags from BME.
     */
    private function render_financial_tax_card(): void {
        $fin = $this->bme->financial ?? null;
        if (!$fin) return;

        $rows = [];

        // Price Reduction (original → current)
        $orig = (float) ($this->bme->listing->original_list_price ?? 0);
        $curr = (float) ($this->d['list_price'] ?? 0);
        if ($orig > 0 && $curr > 0 && $orig > $curr) {
            $reduction = $orig - $curr;
            $pct = ($reduction / $orig) * 100;
            $rows[] = ['Price Reduction', '$' . number_format($orig) . ' → $' . number_format($curr) . ' (-' . number_format($pct, 1) . '%)'];
        }

        // Annual Property Tax
        $tax = $fin->tax_annual_amount ?? null;
        if ($tax && $tax > 0) {
            $tax_str = '$' . number_format($tax);
            if ($yr = $fin->tax_year ?? null) $tax_str .= ' (' . $yr . ')';
            $rows[] = ['Annual Property Tax', $tax_str];
        }

        // Tax Assessed Value
        if (($val = $fin->tax_assessed_value ?? null) && $val > 0) {
            $rows[] = ['Tax Assessed Value', '$' . number_format($val)];
        }

        // HOA/Condo Fee
        $assoc_yn = $fin->association_yn ?? null;
        $assoc_fee = $fin->association_fee ?? null;
        if (($assoc_yn === 'Y' || $assoc_yn === '1' || $assoc_yn === 'true') && $assoc_fee && $assoc_fee > 0) {
            $freq = $fin->association_fee_frequency ?? '';
            $fee_str = '$' . number_format($assoc_fee);
            if ($freq) $fee_str .= '/' . strtolower($freq);
            $rows[] = ['HOA / Condo Fee', $fee_str];

            if ($val = $this->format_bme_array($fin->association_fee_includes ?? null)) {
                $rows[] = ['Fee Includes', $val];
            }
        }

        if ($val = $this->format_bme_array($fin->zoning ?? null)) $rows[] = ['Zoning', $val];

        // Lead Paint flag
        $lead = $fin->mlspin_lead_paint ?? null;
        if ($lead !== null && $lead !== '') {
            $lead_lower = strtolower($lead);
            if ($lead_lower === 'y' || $lead_lower === 'yes' || $lead_lower === '1' || $lead_lower === 'true') {
                $rows[] = ['Lead Paint', 'Yes'];
            } elseif ($lead_lower === 'n' || $lead_lower === 'no' || $lead_lower === '0' || $lead_lower === 'false' || $lead_lower === 'unknown') {
                $rows[] = ['Lead Paint', ucfirst($lead_lower === '0' || $lead_lower === 'false' ? 'no' : $lead_lower)];
            }
        }

        // Lender Owned (REO)
        $reo = $fin->mlspin_lender_owned ?? null;
        if ($reo !== null && $reo !== '') {
            $reo_lower = strtolower($reo);
            if ($reo_lower === 'y' || $reo_lower === 'yes' || $reo_lower === '1' || $reo_lower === 'true') {
                $rows[] = ['Lender Owned (REO)', 'Yes'];
            }
        }

        if (empty($rows)) return;

        // Page break guard
        $needed = 6 + count($rows) * 7 + 10;
        $page_bottom = $this->pdf->getPageHeight() - $this->pdf->getBreakMargin();
        if (($page_bottom - $this->pdf->GetY()) < $needed) {
            $this->pdf->AddPage();
        }

        $this->c->add_subsection_header('Financial & Tax');

        $card_y = $this->pdf->GetY();
        $card_h = 6 + count($rows) * 7 + 4;
        $this->c->render_card_start($card_y, $card_h);
        $this->pdf->SetY($card_y + 4);
        foreach ($rows as $r) {
            $this->c->render_kv_row($r[0], $r[1]);
        }
        $this->pdf->SetY($card_y + $card_h + 6);
    }

    /**
     * Rent Roll card — per-unit rents for multifamily properties from BME.
     */
    private function render_rent_roll_card(): void {
        $fin = $this->bme->financial ?? null;
        if (!$fin) return;

        // Only show when mlspin_rent1 data exists (multifamily)
        $rent1 = $fin->mlspin_rent1 ?? null;
        if (!$rent1 || $rent1 <= 0) return;

        // Page break guard
        $page_bottom = $this->pdf->getPageHeight() - $this->pdf->getBreakMargin();
        if (($page_bottom - $this->pdf->GetY()) < 80) {
            $this->pdf->AddPage();
        }

        $this->c->add_subsection_header('Rent Roll');

        // Build rent rows
        $rent_rows = [];
        $rent_sum = 0;
        for ($i = 1; $i <= 4; $i++) {
            $rent_key = "mlspin_rent{$i}";
            $lease_key = "mlspin_lease_{$i}";
            $rent = $fin->$rent_key ?? null;
            if ($rent && $rent > 0) {
                $lease = $fin->$lease_key ?? null;
                $lease_str = $lease ? ' (' . $lease . ')' : '';
                $rent_rows[] = ['Unit ' . $i, '$' . number_format($rent) . '/mo' . $lease_str];
                $rent_sum += $rent;
            }
        }

        // Total actual rent from MLS
        $total_actual = $fin->total_actual_rent ?? null;
        if ($total_actual && $total_actual > 0) {
            $rent_rows[] = ['Total Actual Rent', '$' . number_format($total_actual) . '/mo'];
        } elseif ($rent_sum > 0) {
            $rent_rows[] = ['Total (Sum)', '$' . number_format($rent_sum) . '/mo'];
        }

        // MLS income fields
        if (($val = $fin->gross_income ?? null) && $val > 0) {
            $rent_rows[] = ['MLS Gross Income', '$' . number_format($val) . '/yr'];
        }
        if (($val = $fin->net_operating_income ?? null) && $val > 0) {
            $rent_rows[] = ['MLS NOI', '$' . number_format($val) . '/yr'];
        }

        if (empty($rent_rows)) return;

        $card_y = $this->pdf->GetY();
        $card_h = 6 + count($rent_rows) * 7 + 4;
        $this->c->render_card_start($card_y, $card_h, ['accent' => $this->c->success]);
        $this->pdf->SetY($card_y + 4);
        foreach ($rent_rows as $r) {
            $bold = (strpos($r[0], 'Total') !== false || strpos($r[0], 'Gross') !== false || strpos($r[0], 'NOI') !== false);
            $this->c->render_kv_row($r[0], $r[1], $bold ? ['bold' => true] : []);
        }
        $this->pdf->SetY($card_y + $card_h + 6);
    }

    /**
     * MLS Actuals vs Estimates — comparison table + rental terms from BME.
     */
    private function render_mls_actuals_card(?array $rental): void {
        $fin = $this->bme->financial ?? null;
        if (!$fin) return;

        $mls_gross = (float) ($fin->gross_income ?? 0);
        $mls_noi = (float) ($fin->net_operating_income ?? 0);
        $mls_opex = (float) ($fin->operating_expense ?? 0);

        // Only show if MLS has financial data
        if ($mls_gross <= 0 && $mls_noi <= 0) {
            // Still check for rental terms
            $this->render_rental_terms_card();
            return;
        }

        // Page break guard
        $page_bottom = $this->pdf->getPageHeight() - $this->pdf->getBreakMargin();
        if (($page_bottom - $this->pdf->GetY()) < 70) {
            $this->pdf->AddPage();
        }

        $this->pdf->Ln(4);
        $this->c->add_subsection_header('MLS Financial Data vs Estimates');

        // Build comparison rows
        $est_gross = ($rental['annual_gross_income'] ?? 0);
        $est_opex = ($rental['expenses']['total_annual'] ?? 0);
        $est_noi = ($rental['noi'] ?? 0);

        $cols = [68, 62, 62];
        $this->c->render_styled_table_header($cols, ['Metric', 'MLS Reported', 'Estimated']);

        $this->pdf->SetFont('helvetica', '', 9);
        $comparison_rows = [];
        if ($mls_gross > 0) {
            $comparison_rows[] = ['Gross Income', '$' . number_format($mls_gross), '$' . number_format($est_gross)];
        }
        if ($mls_opex > 0) {
            $comparison_rows[] = ['Operating Expenses', '$' . number_format($mls_opex), '$' . number_format($est_opex)];
        }
        if ($mls_noi > 0) {
            $comparison_rows[] = ['Net Operating Income', '$' . number_format($mls_noi), '$' . number_format($est_noi)];
        }

        foreach ($comparison_rows as $ri => $r) {
            $is_zebra = ($ri % 2 === 1);
            if ($is_zebra) {
                $this->pdf->SetFillColor($this->c->zebra[0], $this->c->zebra[1], $this->c->zebra[2]);
            }
            $this->c->set_text_color();
            $this->pdf->Cell($cols[0], 7, $r[0], 0, 0, 'L', $is_zebra);
            $this->pdf->SetFont('helvetica', 'B', 9);
            $this->pdf->Cell($cols[1], 7, $r[1], 0, 0, 'R', $is_zebra);
            $this->pdf->SetFont('helvetica', '', 9);
            $this->c->set_color($this->c->gray);
            $this->pdf->Cell($cols[2], 7, $r[2], 0, 1, 'R', $is_zebra);
        }

        $this->pdf->Ln(4);

        // Caveat note when MLS vs estimated income diverge significantly
        if ($mls_gross > 0 && $est_gross > 0) {
            $gross_delta = abs($est_gross - $mls_gross) / $mls_gross;
            if ($gross_delta > 0.50) {
                $this->pdf->SetFont('helvetica', 'I', 7.5);
                $this->pdf->SetTextColor(140, 140, 140);
                $this->pdf->MultiCell(
                    Flip_PDF_Components::PW, 3.5,
                    'Note: MLS reported income reflects historical lease terms. Estimated income uses current market rental comparables and may differ for properties with older leases, furnished rentals, or commercial tenants.',
                    0, 'L'
                );
                $this->pdf->Ln(2);
            }
        }

        // Rental terms card
        $this->render_rental_terms_card();
    }

    /**
     * Rental Terms card — rent includes, tenant pays, from BME.
     */
    private function render_rental_terms_card(): void {
        $fin = $this->bme->financial ?? null;
        if (!$fin) return;

        $rows = [];
        if ($val = $this->format_bme_array($fin->rent_includes ?? null)) $rows[] = ['Rent Includes', $val];
        if ($val = $this->format_bme_array($fin->tenant_pays ?? null)) $rows[] = ['Tenant Pays', $val];

        if (empty($rows)) return;

        // Page break guard
        $page_bottom = $this->pdf->getPageHeight() - $this->pdf->getBreakMargin();
        if (($page_bottom - $this->pdf->GetY()) < 40) {
            $this->pdf->AddPage();
        }

        $this->c->add_subsection_header('Rental Terms');

        $card_y = $this->pdf->GetY();
        $card_h = 6 + count($rows) * 7 + 4;
        $this->c->render_card_start($card_y, $card_h);
        $this->pdf->SetY($card_y + 4);
        foreach ($rows as $r) {
            $this->c->render_kv_row($r[0], $r[1]);
        }
        $this->pdf->SetY($card_y + $card_h + 4);
    }

    // ─── BME DATA ENRICHMENT ──────────────────────────────────────

    /**
     * Fetch enriched property data from BME tables (indexed by listing_id — sub-ms).
     */
    private function fetch_enriched_data(int $listing_id): void {
        global $wpdb;

        $this->bme = (object) [
            'listing'   => null,
            'details'   => null,
            'financial' => null,
        ];

        // bme_listings → public_remarks, disclosures, property_sub_type, original_list_price
        $this->bme->listing = $wpdb->get_row($wpdb->prepare(
            "SELECT public_remarks, disclosures, property_sub_type, original_list_price
             FROM {$wpdb->prefix}bme_listings WHERE listing_id = %s",
            $listing_id
        ));

        // bme_listing_details → systems, construction, features
        $this->bme->details = $wpdb->get_row($wpdb->prepare(
            "SELECT heating, cooling, sewer, water_source, foundation_details, roof,
                    construction_materials, flooring, basement, stories_total,
                    fireplace_yn, fireplaces_total, garage_spaces, parking_total,
                    parking_features, architectural_style, property_condition,
                    interior_features, appliances, number_of_units_total
             FROM {$wpdb->prefix}bme_listing_details WHERE listing_id = %s",
            $listing_id
        ));

        // bme_listing_financial → tax, HOA, income, zoning
        $this->bme->financial = $wpdb->get_row($wpdb->prepare(
            "SELECT tax_annual_amount, tax_year, tax_assessed_value, association_yn,
                    association_fee, association_fee_frequency, association_fee_includes,
                    gross_income, net_operating_income, operating_expense,
                    total_actual_rent, rent_includes, zoning,
                    mlspin_lender_owned, mlspin_lead_paint
             FROM {$wpdb->prefix}bme_listing_financial WHERE listing_id = %s",
            $listing_id
        ));

        // Try to get per-unit rent columns (mlspin_rent1-4, mlspin_lease_1-4, tenant_pays)
        // These may not exist in all schemas, so query separately
        $rent_data = $wpdb->get_row($wpdb->prepare(
            "SELECT mlspin_rent1, mlspin_rent2, mlspin_rent3, mlspin_rent4,
                    mlspin_lease_1, mlspin_lease_2, mlspin_lease_3, mlspin_lease_4,
                    tenant_pays
             FROM {$wpdb->prefix}bme_listing_financial WHERE listing_id = %s",
            $listing_id
        ));

        if ($rent_data && $this->bme->financial) {
            foreach (get_object_vars($rent_data) as $k => $v) {
                $this->bme->financial->$k = $v;
            }
        }
    }

    /**
     * Parse a BME JSON-encoded array field into a comma-separated string.
     * BME stores many fields as JSON arrays, e.g. '["Gas Forced Air","Natural Gas"]'.
     *
     * @param  string|null $raw Raw field value from BME table.
     * @return string|null Comma-separated string or null if empty.
     */
    private function format_bme_array(?string $raw): ?string {
        if ($raw === null || $raw === '' || $raw === 'null') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $filtered = array_filter($decoded, fn($v) => $v !== null && $v !== '');
            return !empty($filtered) ? implode(', ', $filtered) : null;
        }

        // If it's not JSON, return the raw string (some fields are plain text)
        return trim($raw) !== '' ? trim($raw) : null;
    }

    // ─── FILE OUTPUT ──────────────────────────────────────────────

    private function save_pdf(): string|false {
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/flip-reports';

        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }

        $filename = 'flip-report-' . $this->d['listing_id'] . '-' . time() . '.pdf';
        $filepath = $pdf_dir . '/' . $filename;

        // Clean up footer logo temp file
        if ($this->pdf->footer_logo && file_exists($this->pdf->footer_logo)) {
            @unlink($this->pdf->footer_logo);
        }

        try {
            $this->pdf->Output($filepath, 'F');
            return $filepath;
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flip PDF generation failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    // ─── CLEANUP ─────────────────────────────────────────────────

    /**
     * Delete PDF reports older than a given number of days.
     *
     * Only targets files matching the naming pattern `flip-report-*.pdf`
     * inside the `flip-reports` upload directory.
     *
     * @param int $days Delete files older than this many days.
     * @return int Number of files deleted.
     */
    public static function cleanup_old_pdfs(int $days = 30): int {
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/flip-reports';

        if (!is_dir($pdf_dir)) {
            return 0;
        }

        $cutoff  = time() - ($days * DAY_IN_SECONDS);
        $deleted = 0;

        foreach (glob($pdf_dir . '/flip-report-*.pdf') as $file) {
            if (filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        if ($deleted > 0) {
            error_log("Flip PDF cleanup: deleted {$deleted} PDFs older than {$days} days.");
        }

        return $deleted;
    }
}
