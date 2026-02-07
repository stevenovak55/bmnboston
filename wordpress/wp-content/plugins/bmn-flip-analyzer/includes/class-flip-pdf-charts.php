<?php
/**
 * PDF Charts — score gauge, financial bar chart, sensitivity chart.
 *
 * Extracted from Flip_PDF_Generator in v0.14.0.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_PDF_Charts {

    /** @var Flip_TCPDF */
    private $pdf;

    /** @var Flip_PDF_Components */
    private $c;

    public function __construct($pdf, Flip_PDF_Components $c) {
        $this->pdf = $pdf;
        $this->c = $c;
    }

    /**
     * Render a circular score gauge with coloured arc.
     */
    public function render_score_gauge(float $score, float $cx, float $cy, float $radius): void {
        $color = $this->c->get_score_color($score);
        $lw = 3.5;

        // Background track circle
        $this->pdf->SetLineWidth($lw);
        $this->pdf->SetDrawColor($this->c->track[0], $this->c->track[1], $this->c->track[2]);
        $this->pdf->Circle($cx, $cy, $radius, 0, 360, 'D');

        // Coloured arc from top, clockwise
        if ($score > 0) {
            $this->pdf->SetDrawColor($color[0], $color[1], $color[2]);
            $angle = min(359.9, ($score / 100) * 360);
            if ($score >= 99.9) {
                $this->pdf->Circle($cx, $cy, $radius, 0, 360, 'D');
            } else {
                $start = fmod(90 - $angle + 360, 360);
                $this->pdf->Circle($cx, $cy, $radius, $start, 90, 'D');
            }
        }

        $this->pdf->SetLineWidth(0.2);

        // Score number in centre
        $this->pdf->SetFont('helvetica', 'B', 22);
        $this->c->set_color($color);
        $this->pdf->SetXY($cx - $radius, $cy - 7);
        $this->pdf->Cell($radius * 2, 10, number_format($score, 1), 0, 0, 'C');

        // Sub-label
        $this->pdf->SetFont('helvetica', '', 8);
        $this->c->set_color($this->c->gray);
        $this->pdf->SetXY($cx - $radius, $cy + 4);
        $this->pdf->Cell($radius * 2, 5, 'out of 100', 0, 0, 'C');
    }

    /**
     * Render horizontal stacked bar chart comparing cash vs hard money scenarios.
     */
    public function render_financial_bar_chart(array $d, float $y): void {
        $arv = $d['estimated_arv'];
        if ($arv <= 0) return;

        $purchase = $d['list_price'];
        $rehab = $d['estimated_rehab_cost'];
        $purch_close = $purchase * Flip_Analyzer::PURCHASE_CLOSING_PCT;
        $transfer_buy = $d['transfer_tax_buy'];
        $transfer_sell = $d['transfer_tax_sell'];
        $sale_costs = $arv * (Flip_Analyzer::SALE_COMMISSION_PCT + Flip_Analyzer::SALE_CLOSING_PCT) + $transfer_sell;
        $holding = $d['holding_costs'];
        $financing = $d['financing_costs'];

        $cash_total = $purchase + $rehab + $purch_close + $transfer_buy + $sale_costs + $holding;
        $cash_profit = $arv - $cash_total;
        $hm_total = $cash_total + $financing;
        $hm_profit = $arv - $hm_total;

        $max_val = max($arv, $cash_total + abs($cash_profit), $hm_total + abs($hm_profit));
        if ($max_val <= 0) return;

        $chart_x = Flip_PDF_Components::LM + 2;
        $chart_w = Flip_PDF_Components::PW - 4;
        $bar_h = 14;

        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->c->set_color($this->c->gray);
        $this->pdf->SetXY(Flip_PDF_Components::LM, $y);
        $this->pdf->Cell(Flip_PDF_Components::PW, 5, 'COST BREAKDOWN COMPARISON', 0, 1, 'L');
        $y += 8;

        $segments_cash = [
            ['Purchase', $purchase, [44, 90, 160]],
            ['Rehab', $rehab, [74, 184, 102]],
            ['Closing', $purch_close + $transfer_buy, [219, 166, 23]],
            ['Holding', $holding, [230, 126, 34]],
            ['Sale', $sale_costs, [108, 117, 125]],
        ];

        $segments_hm = [
            ['Purchase', $purchase, [44, 90, 160]],
            ['Rehab', $rehab, [74, 184, 102]],
            ['Closing', $purch_close + $transfer_buy, [219, 166, 23]],
            ['Holding', $holding, [230, 126, 34]],
            ['Financing', $financing, [204, 24, 24]],
            ['Sale', $sale_costs, [108, 117, 125]],
        ];

        // Cash bar
        $this->pdf->SetFont('helvetica', 'B', 8);
        $this->c->set_text_color();
        $this->pdf->SetXY(Flip_PDF_Components::LM, $y);
        $this->pdf->Cell(Flip_PDF_Components::PW, 5, 'Cash Purchase', 0, 1, 'L');
        $y += 5;
        $this->draw_stacked_bar($chart_x, $y, $chart_w, $bar_h, $segments_cash, $max_val, $cash_profit);
        $y += $bar_h + 10;

        // Hard money bar
        $this->pdf->SetFont('helvetica', 'B', 8);
        $this->c->set_text_color();
        $this->pdf->SetXY(Flip_PDF_Components::LM, $y);
        $this->pdf->Cell(Flip_PDF_Components::PW, 5, 'Hard Money Financing', 0, 1, 'L');
        $y += 5;
        $this->draw_stacked_bar($chart_x, $y, $chart_w, $bar_h, $segments_hm, $max_val, $hm_profit);
        $y += $bar_h + 6;

        // Legend
        $this->render_chart_legend($y, $segments_hm);
        $this->pdf->SetY($y + 10);
    }

    public function draw_stacked_bar(float $x, float $y, float $w, float $h, array $segments, float $max_val, float $profit): void {
        $this->pdf->SetFillColor(245, 245, 245);
        $this->pdf->RoundedRect($x, $y, $w, $h, 2, '1111', 'F');

        $cx = $x;
        foreach ($segments as $seg) {
            $seg_w = ($seg[1] / $max_val) * $w;
            if ($seg_w < 0.5) continue;

            $this->pdf->SetFillColor($seg[2][0], $seg[2][1], $seg[2][2]);
            $this->pdf->Rect($cx, $y, $seg_w, $h, 'F');

            if ($seg_w > 18) {
                $this->pdf->SetFont('helvetica', '', 6.5);
                $this->pdf->SetTextColor(255, 255, 255);
                $this->pdf->SetXY($cx, $y + $h / 2 - 2);
                $this->pdf->Cell($seg_w, 4, '$' . number_format($seg[1] / 1000) . 'K', 0, 0, 'C');
            }

            $cx += $seg_w;
        }

        if ($profit != 0) {
            $pc = $profit >= 0 ? $this->c->success : $this->c->danger;
            $this->pdf->SetFont('helvetica', 'B', 8);
            $this->c->set_color($pc);
            $this->pdf->SetXY($cx + 2, $y + $h / 2 - 3);
            $label = ($profit >= 0 ? '+' : '-') . '$' . number_format(abs($profit) / 1000) . 'K';
            $this->pdf->Cell(30, 6, $label, 0, 0, 'L');
        }
    }

    public function render_chart_legend(float $y, array $segments): void {
        $lx = Flip_PDF_Components::LM;
        $this->pdf->SetFont('helvetica', '', 7);
        foreach ($segments as $seg) {
            $this->pdf->SetFillColor($seg[2][0], $seg[2][1], $seg[2][2]);
            $this->pdf->Rect($lx, $y + 1, 4, 4, 'F');
            $this->c->set_color($this->c->gray);
            $this->pdf->SetXY($lx + 5, $y);
            $lw = $this->pdf->GetStringWidth($seg[0]) + 4;
            $this->pdf->Cell($lw, 6, $seg[0], 0, 0, 'L');
            $lx += $lw + 6;
            if ($lx > Flip_PDF_Components::LM + Flip_PDF_Components::PW - 30) {
                $lx = Flip_PDF_Components::LM;
                $y += 7;
            }
        }
    }

    /**
     * Render a sensitivity line chart showing profit across ARV scenarios.
     */
    public function render_sensitivity_chart(array $d, float $y): void {
        $pcts = [85, 90, 95, 100, 105, 110];
        $data = [];
        $labels = [];

        foreach ($pcts as $pct) {
            $s_arv = $d['estimated_arv'] * ($pct / 100);
            $s_fin = $this->c->calc_scenario($d['list_price'], $s_arv, $d['estimated_rehab_cost'], $d['hold_months']);
            $data[] = $s_fin['profit'];
            $labels[] = $pct . '%';
        }

        if (empty($data)) return;

        $chart_h = 58;
        $min_val = min($data);
        $max_val = max($data);
        $range = $max_val - $min_val;
        $padding = $range * 0.15;
        $min_val -= $padding;
        $max_val += $padding;
        $range = $max_val - $min_val;
        if ($range == 0) $range = 1;

        $inner_x = Flip_PDF_Components::LM + 28;
        $inner_w = Flip_PDF_Components::PW - 33;
        $inner_y = $y + 5;
        $inner_h = $chart_h - 18;

        // Chart background
        $this->pdf->SetFillColor(250, 251, 252);
        $this->pdf->RoundedRect($inner_x, $inner_y, $inner_w, $inner_h, 2, '1111', 'F');

        // Grid lines and Y-axis labels
        $grid_lines = 4;
        $this->pdf->SetDrawColor(230, 230, 230);
        $this->pdf->SetLineWidth(0.1);

        for ($i = 0; $i <= $grid_lines; $i++) {
            $gy = $inner_y + ($inner_h * $i / $grid_lines);
            $this->pdf->Line($inner_x, $gy, $inner_x + $inner_w, $gy);

            $val = $max_val - ($range * $i / $grid_lines);
            $this->pdf->SetFont('helvetica', '', 7);
            $this->c->set_color($this->c->gray);
            $this->pdf->SetXY(Flip_PDF_Components::LM, $gy - 2);
            $label = $val >= 0 ? '$' . number_format($val / 1000) . 'K' : '-$' . number_format(abs($val) / 1000) . 'K';
            $this->pdf->Cell(26, 4, $label, 0, 0, 'R');
        }

        // Zero line (dashed)
        if ($min_val < 0 && $max_val > 0) {
            $zero_y = $inner_y + $inner_h - ((0 - $min_val) / $range * $inner_h);
            $this->pdf->SetDrawColor($this->c->danger[0], $this->c->danger[1], $this->c->danger[2]);
            $this->pdf->SetLineWidth(0.3);
            $dash = 2;
            for ($dx = $inner_x; $dx < $inner_x + $inner_w; $dx += $dash * 2) {
                $this->pdf->Line($dx, $zero_y, min($dx + $dash, $inner_x + $inner_w), $zero_y);
            }
        }

        // Calculate points
        $point_count = count($data);
        $x_step = $inner_w / max(1, $point_count - 1);
        $points = [];
        foreach ($data as $i => $val) {
            $px = $inner_x + ($i * $x_step);
            $py = $inner_y + $inner_h - (($val - $min_val) / $range * $inner_h);
            $points[] = [$px, $py, $val];
        }

        // Connecting lines
        $this->pdf->SetDrawColor($this->c->primary[0], $this->c->primary[1], $this->c->primary[2]);
        $this->pdf->SetLineWidth(0.8);
        for ($i = 1; $i < count($points); $i++) {
            $this->pdf->Line($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
        }

        // Data point circles
        foreach ($points as $pt) {
            $pc = $pt[2] >= 0 ? $this->c->success : $this->c->danger;
            $this->pdf->SetFillColor($pc[0], $pc[1], $pc[2]);
            $this->pdf->Circle($pt[0], $pt[1], 1.5, 0, 360, 'F');
        }

        // X-axis labels
        $this->pdf->SetFont('helvetica', '', 7);
        foreach ($labels as $i => $label) {
            $lx = $inner_x + ($i * $x_step) - 5;
            $this->c->set_color($this->c->gray);
            $this->pdf->SetXY($lx, $inner_y + $inner_h + 1);
            $this->pdf->Cell(12, 4, $label, 0, 0, 'C');
        }

        // Chart border
        $this->pdf->SetDrawColor(210, 210, 210);
        $this->pdf->SetLineWidth(0.2);
        $this->pdf->RoundedRect($inner_x, $inner_y, $inner_w, $inner_h, 2, '1111', 'D');

        $this->pdf->SetY($y + $chart_h + 4);
    }
}
