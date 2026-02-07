<?php
/**
 * PDF Components — reusable rendering elements, formatting, and color constants.
 *
 * Extracted from Flip_PDF_Generator in v0.14.0. Contains all shared visual
 * components, section headers, formatting utilities, and color/layout constants.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_PDF_Components {

    /** @var Flip_TCPDF */
    private $pdf;

    // ─── BRAND COLORS ──────────────────────────────────────────────

    public $primary   = [44, 90, 160];
    public $secondary = [30, 66, 120];
    public $success   = [40, 167, 69];
    public $warning   = [219, 166, 23];
    public $danger    = [204, 24, 24];
    public $text      = [51, 51, 51];
    public $light     = [248, 249, 250];
    public $gray      = [108, 117, 125];
    public $zebra     = [249, 251, 253];
    public $card_bg   = [255, 255, 255];
    public $card_bdr  = [230, 230, 230];
    public $track     = [233, 236, 239];

    /** Risk grade colors */
    public $risk_colors = [
        'A' => [0, 163, 42],
        'B' => [74, 184, 102],
        'C' => [219, 166, 23],
        'D' => [230, 126, 34],
        'F' => [204, 24, 24],
    ];

    /** Market strength colors */
    public $market_colors = [
        'very_hot' => [214, 51, 132],
        'hot'      => [220, 53, 69],
        'balanced' => [108, 117, 125],
        'soft'     => [13, 110, 253],
        'cold'     => [13, 202, 240],
    ];

    /** Page usable width in mm (Letter = 216mm - 12mm margins each side) */
    const PW = 192;

    /** Left margin in mm */
    const LM = 12;

    public function __construct($pdf) {
        $this->pdf = $pdf;
    }

    // ─── REUSABLE VISUAL HELPERS ───────────────────────────────────

    /**
     * Render a grid of metric cards.
     *
     * @param array $cards  [['label'=>..,'value'=>..,'color'=>[r,g,b]], ...]
     * @param int   $cols   Number of columns (3 or 4)
     * @param float $y      Starting Y
     */
    public function render_metric_grid(array $cards, int $cols, float $y): void {
        $gap   = 3;
        $card_w = (self::PW - ($gap * ($cols - 1))) / $cols;
        $card_h = 26;

        foreach ($cards as $i => $c) {
            $col = $i % $cols;
            $row = intdiv($i, $cols);
            $x = self::LM + ($col * ($card_w + $gap));
            $cy = $y + ($row * ($card_h + $gap));

            // Card background
            $this->pdf->SetFillColor($this->light[0], $this->light[1], $this->light[2]);
            $this->pdf->RoundedRect($x, $cy, $card_w, $card_h, 2.5, '1111', 'F');

            // Value
            $this->pdf->SetXY($x, $cy + 4);
            $this->pdf->SetFont('helvetica', 'B', 18);
            $clr = $c['color'] ?? $this->text;
            $this->set_color($clr);
            $this->pdf->Cell($card_w, 8, $c['value'], 0, 0, 'C');

            // Label
            $this->pdf->SetXY($x, $cy + 15);
            $this->pdf->SetFont('helvetica', '', 8.5);
            $this->set_color($this->gray);
            $this->pdf->Cell($card_w, 5, $c['label'], 0, 0, 'C');
        }

        $total_rows = ceil(count($cards) / $cols);
        $this->pdf->SetY($y + ($total_rows * ($card_h + $gap)));
    }

    /**
     * Begin a white card with optional accent bar and gray border.
     */
    public function render_card_start(float $y, float $h, array $opts = []): void {
        // Shadow
        $this->pdf->SetFillColor(210, 210, 210);
        $this->pdf->SetAlpha(0.3);
        $this->pdf->RoundedRect(self::LM + 0.7, $y + 0.7, self::PW, $h, 3, '1111', 'F');
        $this->pdf->SetAlpha(1);

        // White background
        $this->pdf->SetFillColor(255, 255, 255);
        $this->pdf->SetDrawColor($this->card_bdr[0], $this->card_bdr[1], $this->card_bdr[2]);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->RoundedRect(self::LM, $y, self::PW, $h, 3, '1111', 'DF');

        // Optional accent bar
        if (!empty($opts['accent'])) {
            $ac = $opts['accent'];
            $this->pdf->SetFillColor($ac[0], $ac[1], $ac[2]);
            $this->pdf->RoundedRect(self::LM, $y, self::PW, 2, 3, '1100', 'F');
        }
    }

    /**
     * Render a styled table header row (blue background, white text).
     */
    public function render_styled_table_header(array $cols, array $headers): void {
        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->pdf->SetFillColor($this->primary[0], $this->primary[1], $this->primary[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        foreach ($headers as $i => $h) {
            $align = $i === 0 ? 'L' : 'R';
            $this->pdf->Cell($cols[$i], 7, $h, 0, 0, $align, true);
        }
        $this->pdf->Ln();
        $this->set_text_color();
    }

    /**
     * Render a score bar with rounded ends.
     */
    public function render_score_bar(string $label, float $score, ?float $weight): void {
        $label_w = 50;
        $bar_w = self::PW - $label_w - 42;
        $bar_h = 6;
        $radius = 3;
        $y = $this->pdf->GetY();

        // Label
        $this->pdf->SetFont('helvetica', '', 9);
        $this->set_text_color();
        $weight_str = $weight !== null ? ' (' . $weight . '%)' : '';
        $this->pdf->SetXY(self::LM + 4, $y);
        $this->pdf->Cell($label_w, $bar_h + 2, $label . $weight_str, 0, 0, 'L');

        // Background track (rounded)
        $bar_x = self::LM + $label_w + 2;
        $this->pdf->SetFillColor($this->track[0], $this->track[1], $this->track[2]);
        $this->pdf->RoundedRect($bar_x, $y + 1, $bar_w, $bar_h, $radius, '1111', 'F');

        // Filled bar (rounded) — minimum 2×radius so corners render properly
        $fill_w = max(0, min($bar_w, ($score / 100) * $bar_w));
        if ($fill_w > 0 && $fill_w < $radius * 2) {
            $fill_w = $radius * 2;
        }
        if ($fill_w > 0) {
            $color = $this->get_score_color($score);
            $this->pdf->SetFillColor($color[0], $color[1], $color[2]);
            $this->pdf->RoundedRect($bar_x, $y + 1, $fill_w, $bar_h, $radius, '1111', 'F');
        }

        // Score value
        $this->pdf->SetXY(self::LM + self::PW - 40, $y);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->set_color($this->get_score_color($score));
        $this->pdf->Cell(38, $bar_h + 2, number_format($score, 1), 0, 0, 'R');

        $this->pdf->SetY($y + $bar_h + 4);
    }

    /**
     * Render a three-segment range bar (floor — ARV — ceiling).
     */
    public function render_range_bar(float $floor, float $mid, float $ceil, float $y): void {
        $bar_x = self::LM;
        $bar_w = self::PW;
        $bar_h = 8;

        // Full bar background
        $this->pdf->SetFillColor($this->track[0], $this->track[1], $this->track[2]);
        $this->pdf->RoundedRect($bar_x, $y, $bar_w, $bar_h, 3, '1111', 'F');

        // Left segment (floor to mid) — lighter primary
        $range = $ceil - $floor;
        if ($range > 0) {
            $mid_pct = ($mid - $floor) / $range;
            $mid_w = $mid_pct * $bar_w;

            $this->pdf->SetFillColor($this->primary[0], $this->primary[1], $this->primary[2]);
            $this->pdf->SetAlpha(0.35);
            $this->pdf->RoundedRect($bar_x, $y, $mid_w, $bar_h, 3, '1010', 'F');
            $this->pdf->SetAlpha(0.6);
            $this->pdf->RoundedRect($bar_x + $mid_w, $y, $bar_w - $mid_w, $bar_h, 3, '0101', 'F');
            $this->pdf->SetAlpha(1);

            // ARV marker
            $marker_x = $bar_x + $mid_w;
            $this->pdf->SetFillColor($this->primary[0], $this->primary[1], $this->primary[2]);
            $this->pdf->Rect($marker_x - 0.5, $y - 1, 1, $bar_h + 2, 'F');
        }

        // Labels below
        $this->pdf->SetFont('helvetica', '', 8);
        $this->set_color($this->gray);
        $this->pdf->SetXY($bar_x, $y + $bar_h + 1);
        $this->pdf->Cell($bar_w * 0.33, 4, '$' . number_format($floor), 0, 0, 'L');
        $this->pdf->Cell($bar_w * 0.34, 4, '$' . number_format($mid), 0, 0, 'C');
        $this->pdf->Cell($bar_w * 0.33, 4, '$' . number_format($ceil), 0, 0, 'R');

        $this->pdf->SetY($y + $bar_h + 6);
    }

    /**
     * Render a coloured callout box.
     */
    public function render_callout_box(string $text, array $color, float $y): void {
        // Left accent bar + light background
        $this->pdf->SetFillColor($color[0], $color[1], $color[2]);
        $this->pdf->SetAlpha(0.10);
        $this->pdf->RoundedRect(self::LM, $y, self::PW, 10, 2, '1111', 'F');
        $this->pdf->SetAlpha(1);

        // Left accent stripe
        $this->pdf->SetFillColor($color[0], $color[1], $color[2]);
        $this->pdf->Rect(self::LM, $y, 2.5, 10, 'F');

        $this->pdf->SetXY(self::LM + 6, $y + 2);
        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->set_color($color);
        $this->pdf->Cell(self::PW - 10, 6, $text, 0, 1, 'L');

        $this->pdf->SetY($y + 12);
    }

    /**
     * Render an inline pill badge. Returns X position after badge.
     */
    public function render_pill_badge(string $text, array $color, float $x, float $y): float {
        $this->pdf->SetFont('helvetica', '', 7.5);
        $w = max(16, $this->pdf->GetStringWidth($text) + 6);

        $this->pdf->SetFillColor($color[0], $color[1], $color[2]);
        $this->pdf->SetAlpha(0.15);
        $this->pdf->RoundedRect($x, $y + 1, $w, 5.5, 2.5, '1111', 'F');
        $this->pdf->SetAlpha(1);

        $this->pdf->SetXY($x, $y + 1);
        $this->set_color($color);
        $this->pdf->Cell($w, 5.5, $text, 0, 0, 'C');

        return $x + $w + 2;
    }

    // ─── SECTION HEADERS ───────────────────────────────────────────

    public function add_section_header(string $title): void {
        $y = $this->pdf->GetY();

        // Blue background band
        $this->pdf->SetFillColor($this->primary[0], $this->primary[1], $this->primary[2]);
        $this->pdf->SetAlpha(0.07);
        $this->pdf->RoundedRect(self::LM, $y, self::PW, 13, 2, '1111', 'F');
        $this->pdf->SetAlpha(1);

        $this->pdf->SetFont('helvetica', 'B', 18);
        $this->set_color($this->primary);
        $this->pdf->SetXY(self::LM + 5, $y + 1.5);
        $this->pdf->Cell(self::PW - 10, 10, $title, 0, 1, 'L');
        $this->set_text_color();

        $this->pdf->SetY($y + 16);
    }

    public function add_subsection_header(string $title): void {
        $this->pdf->SetFont('helvetica', 'B', 13);
        $this->set_text_color();
        $this->pdf->Cell(0, 8, $title, 0, 1, 'L');
        $this->pdf->Ln(2);
    }

    // ─── ROW & BADGE HELPERS ───────────────────────────────────────

    public function render_kv_row(string $label, string $value, array $opts = []): void {
        $label_w = 90;
        $value_w = self::PW - $label_w;

        $this->pdf->SetFont('helvetica', '', 9);
        $this->set_color($this->gray);
        $this->pdf->Cell($label_w, 6.5, $label, 0, 0, 'L');

        $this->pdf->SetFont('helvetica', !empty($opts['bold']) ? 'B' : '', 10);
        if (!empty($opts['color'])) {
            $this->set_color($opts['color']);
        } else {
            $this->set_text_color();
        }
        $this->pdf->Cell($value_w, 6.5, $value, 0, 1, 'R');

        // Thin separator
        $y = $this->pdf->GetY();
        $this->pdf->SetDrawColor(235, 235, 235);
        $this->pdf->SetLineWidth(0.1);
        $this->pdf->Line(self::LM + 3, $y, self::LM + self::PW - 3, $y);
    }

    public function draw_badge(float $x, float $y, string $text, array $color): float {
        $this->pdf->SetFont('helvetica', 'B', 8);
        $w = max(30, $this->pdf->GetStringWidth($text) + 10);
        $this->pdf->SetFillColor($color[0], $color[1], $color[2]);
        $this->pdf->SetTextColor(255, 255, 255);
        $this->pdf->RoundedRect($x, $y, $w, 7, 2, '1111', 'F');
        $this->pdf->SetXY($x, $y + 0.5);
        $this->pdf->Cell($w, 6, $text, 0, 0, 'C');
        $this->set_text_color();
        return $x + $w;
    }

    /**
     * Draw an investment scenario card (side-by-side layout).
     */
    public function draw_scenario_card(float $x, float $y, float $w, string $title, array $rows, float $profit): void {
        $row_h = 6;
        $h = 8 + (count($rows) * $row_h) + 8;
        $accent = $profit >= 0 ? $this->success : $this->danger;

        // Card background
        $this->pdf->SetFillColor(255, 255, 255);
        $this->pdf->SetDrawColor($this->card_bdr[0], $this->card_bdr[1], $this->card_bdr[2]);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->RoundedRect($x, $y, $w, $h, 3, '1111', 'DF');

        // Top accent bar
        $this->pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
        $this->pdf->RoundedRect($x, $y, $w, 2, 3, '1100', 'F');

        // Title
        $this->pdf->SetXY($x + 5, $y + 5);
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->set_text_color();
        $this->pdf->Cell($w - 10, 5, $title, 0, 1, 'L');

        // Rows
        $ry = $y + 13;
        foreach ($rows as $label => $value) {
            $this->pdf->SetXY($x + 5, $ry);
            $this->pdf->SetFont('helvetica', '', 8.5);
            $this->set_color($this->gray);
            $this->pdf->Cell(($w - 10) * 0.52, 5, $label, 0, 0, 'L');

            $this->pdf->SetFont('helvetica', 'B', 9);
            $is_financial = (strpos($label, 'Profit') !== false || strpos($label, 'ROI') !== false);
            if ($is_financial) {
                $this->set_color($profit >= 0 ? $this->success : $this->danger);
            } else {
                $this->set_text_color();
            }
            $this->pdf->Cell(($w - 10) * 0.48, 5, $value, 0, 0, 'R');
            $ry += $row_h;
        }
    }

    // ─── FINANCIAL MATH ────────────────────────────────────────────

    public function calc_scenario(float $list_price, float $arv, float $rehab, int $hold_months): array {
        $purchase_closing = $list_price * Flip_Analyzer::PURCHASE_CLOSING_PCT;
        $transfer_buy  = $list_price * Flip_Analyzer::MA_TRANSFER_TAX_RATE;
        $transfer_sell = $arv * Flip_Analyzer::MA_TRANSFER_TAX_RATE;
        $sale_costs    = $arv * (Flip_Analyzer::SALE_COMMISSION_PCT + Flip_Analyzer::SALE_CLOSING_PCT) + $transfer_sell;

        $monthly_tax = ($list_price * Flip_Analyzer::ANNUAL_TAX_RATE) / 12;
        $monthly_ins = ($list_price * Flip_Analyzer::ANNUAL_INSURANCE_RATE) / 12;
        $holding = ($monthly_tax + $monthly_ins + Flip_Analyzer::MONTHLY_UTILITIES) * $hold_months;

        $loan = $list_price * Flip_Analyzer::HARD_MONEY_LTV;
        $points = $loan * Flip_Analyzer::HARD_MONEY_POINTS;
        $interest = $loan * (Flip_Analyzer::HARD_MONEY_RATE / 12) * $hold_months;
        $financing = $points + $interest;

        $profit = $arv - $list_price - $rehab - $purchase_closing - $transfer_buy - $sale_costs - $holding - $financing;
        $cash_invested = ($list_price * (1 - Flip_Analyzer::HARD_MONEY_LTV)) + $rehab + $purchase_closing;
        $roi = $cash_invested > 0 ? ($profit / $cash_invested) * 100 : 0;

        return ['profit' => $profit, 'roi' => $roi];
    }

    // ─── COLOUR & FORMAT HELPERS ───────────────────────────────────

    public function get_score_color(float $score): array {
        if ($score >= 80) return [40, 167, 69];
        if ($score >= 65) return [74, 184, 102];
        if ($score >= 50) return [219, 166, 23];
        if ($score >= 30) return [230, 126, 34];
        return [204, 24, 24];
    }

    public function get_risk_color(string $grade): array {
        return $this->risk_colors[$grade] ?? $this->gray;
    }

    public function set_text_color(): void {
        $this->pdf->SetTextColor($this->text[0], $this->text[1], $this->text[2]);
    }

    public function set_color(array $c): void {
        $this->pdf->SetTextColor($c[0], $c[1], $c[2]);
    }

    public function fmt_currency(float $val): string {
        $prefix = $val < 0 ? '-$' : '$';
        return $prefix . number_format(abs($val));
    }

    public function fmt_pct(float $val): string {
        return number_format($val, 1) . '%';
    }

    public function fmt_score(float $val): string {
        return number_format($val, 1);
    }
}
