<?php
/**
 * PDF Images — image download, photo fetching, and photo rendering.
 *
 * Extracted from Flip_PDF_Generator in v0.14.0.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Flip_PDF_Images {

    /** @var Flip_TCPDF */
    private $pdf;

    /** @var Flip_PDF_Components */
    private $c;

    public function __construct($pdf, Flip_PDF_Components $c) {
        $this->pdf = $pdf;
        $this->c = $c;
    }

    /**
     * Download an image to a temp file.
     * Returns the local file path or false on failure.
     */
    public function download_image_temp(string $url): string|false {
        if (empty($url)) {
            return false;
        }

        $response = wp_remote_get($url, [
            'timeout'   => 10,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return false;
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');
        $ext = 'jpg';
        if (strpos($content_type, 'png') !== false) {
            $ext = 'png';
        } elseif (strpos($content_type, 'webp') !== false) {
            $ext = 'webp';
        }

        $temp_file = wp_tempnam('flip_img_') . '.' . $ext;
        file_put_contents($temp_file, $body);

        $image_info = @getimagesize($temp_file);
        if (!$image_info) {
            @unlink($temp_file);
            return false;
        }

        // TCPDF doesn't support WebP — convert to JPEG via GD
        if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
            $webp = @imagecreatefromwebp($temp_file);
            if ($webp) {
                $jpeg_file = wp_tempnam('flip_img_') . '.jpg';
                imagejpeg($webp, $jpeg_file, 90);
                imagedestroy($webp);
                @unlink($temp_file);
                return $jpeg_file;
            }
        }

        return $temp_file;
    }

    /**
     * Fetch property photos from bme_media table.
     */
    public function fetch_property_photos(string $listing_id, int $limit = 5): array {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT media_url FROM {$wpdb->prefix}bme_media
             WHERE listing_id = %s AND media_url IS NOT NULL AND media_url != ''
             ORDER BY order_index ASC LIMIT %d",
            $listing_id, $limit
        ));
    }

    /**
     * Fetch listing agent name and brokerage office for disclosure.
     *
     * @return array|null ['agent_name' => string, 'office_name' => string] or null.
     */
    public function fetch_listing_agent_info(int $listing_id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT a.agent_full_name, o.office_name
             FROM {$wpdb->prefix}bme_listings l
             LEFT JOIN {$wpdb->prefix}bme_agents a ON l.list_agent_mls_id = a.agent_mls_id
             LEFT JOIN {$wpdb->prefix}bme_offices o ON l.list_office_mls_id = o.office_mls_id
             WHERE l.listing_id = %s",
            (string) $listing_id
        ));

        if (!$row || (empty($row->agent_full_name) && empty($row->office_name))) {
            return null;
        }

        return [
            'agent_name'  => $row->agent_full_name ?? '',
            'office_name' => $row->office_name ?? '',
        ];
    }

    /**
     * Render a horizontal photo thumbnail strip.
     * Returns the Y position after the strip.
     */
    public function render_photo_strip(array $photos, float $y): float {
        if (empty($photos)) {
            return $y;
        }

        $count = count($photos);
        $gap = 3;
        $thumb_h = 28;
        $thumb_w = (Flip_PDF_Components::PW - ($gap * ($count - 1))) / $count;

        foreach ($photos as $i => $url) {
            $x = Flip_PDF_Components::LM + ($i * ($thumb_w + $gap));

            // Gray fallback background
            $this->pdf->SetFillColor(240, 240, 240);
            $this->pdf->RoundedRect($x, $y, $thumb_w, $thumb_h, 2.5, '1111', 'F');

            $temp = $this->download_image_temp($url);
            if ($temp) {
                $info = @getimagesize($temp);
                if ($info) {
                    $img_ratio = $info[0] / $info[1];
                    $box_ratio = $thumb_w / $thumb_h;

                    if ($img_ratio > $box_ratio) {
                        $dw = $thumb_w;
                        $dh = $thumb_w / $img_ratio;
                    } else {
                        $dh = $thumb_h;
                        $dw = $thumb_h * $img_ratio;
                    }

                    $cx = $x + ($thumb_w - $dw) / 2;
                    $cy = $y + ($thumb_h - $dh) / 2;

                    $this->pdf->StartTransform();
                    $this->pdf->RoundedRect($x, $y, $thumb_w, $thumb_h, 2.5, '1111', 'CNZ');
                    $this->pdf->Image($temp, $cx, $cy, $dw, $dh, '', '', '', false, 300, '', false, false, 0);
                    $this->pdf->StopTransform();
                }
                @unlink($temp);
            }
        }

        return $y + $thumb_h + 5;
    }

    /**
     * Render a comparable property card with photo.
     * Returns the Y position after the card.
     */
    public function render_comp_card(array $comp, float $y): float {
        $card_h = 48;
        $photo_w = 55;
        $photo_h = $card_h - 6;

        if ($y + $card_h > 250) {
            $this->pdf->AddPage();
            $this->c->add_section_header('Comparable Sales (continued)');
            $y = $this->pdf->GetY();
        }

        // Shadow
        $this->pdf->SetFillColor(220, 220, 220);
        $this->pdf->SetAlpha(0.35);
        $this->pdf->RoundedRect(Flip_PDF_Components::LM + 0.8, $y + 0.8, Flip_PDF_Components::PW, $card_h, 3, '1111', 'F');
        $this->pdf->SetAlpha(1);

        // Card background
        $this->pdf->SetFillColor(255, 255, 255);
        $this->pdf->SetDrawColor($this->c->card_bdr[0], $this->c->card_bdr[1], $this->c->card_bdr[2]);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->RoundedRect(Flip_PDF_Components::LM, $y, Flip_PDF_Components::PW, $card_h, 3, '1111', 'DF');

        // Photo area
        $photo_x = Flip_PDF_Components::LM + 3;
        $photo_y = $y + 3;

        $this->pdf->SetFillColor(242, 242, 242);
        $this->pdf->RoundedRect($photo_x, $photo_y, $photo_w, $photo_h, 2, '1111', 'F');

        $listing_id = $comp['listing_id'] ?? '';
        $has_photo = false;

        if (!empty($listing_id)) {
            $photos = $this->fetch_property_photos((string) $listing_id, 1);
            if (!empty($photos[0])) {
                $temp = $this->download_image_temp($photos[0]);
                if ($temp) {
                    $info = @getimagesize($temp);
                    if ($info) {
                        $img_ratio = $info[0] / $info[1];
                        $box_ratio = $photo_w / $photo_h;

                        if ($img_ratio > $box_ratio) {
                            $dw = $photo_w;
                            $dh = $photo_w / $img_ratio;
                        } else {
                            $dh = $photo_h;
                            $dw = $photo_h * $img_ratio;
                        }

                        $draw_x = $photo_x + ($photo_w - $dw) / 2;
                        $draw_y = $photo_y + ($photo_h - $dh) / 2;

                        $this->pdf->StartTransform();
                        $this->pdf->RoundedRect($photo_x, $photo_y, $photo_w, $photo_h, 2, '1111', 'CNZ');
                        $this->pdf->Image($temp, $draw_x, $draw_y, $dw, $dh, '', '', '', false, 300, '', false, false, 0);
                        $this->pdf->StopTransform();
                        $has_photo = true;
                    }
                    @unlink($temp);
                }
            }
        }

        if (!$has_photo) {
            $this->pdf->SetFont('helvetica', '', 8);
            $this->pdf->SetTextColor(180, 180, 180);
            $this->pdf->SetXY($photo_x, $photo_y + $photo_h / 2 - 3);
            $this->pdf->Cell($photo_w, 6, 'No Photo', 0, 0, 'C');
        }

        // Content (right side)
        $content_x = $photo_x + $photo_w + 5;
        $content_w = Flip_PDF_Components::PW - $photo_w - 11;

        // Address
        $addr = $comp['address'] ?? 'Unknown';
        if (strlen($addr) > 38) $addr = substr($addr, 0, 36) . '...';
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->c->set_text_color();
        $this->pdf->SetXY($content_x, $y + 4);
        $this->pdf->Cell($content_w - 28, 6, $addr, 0, 1, 'L');

        // Adjustment badge (top-right)
        $total_adj = $comp['total_adjustment'] ?? 0;
        if ($total_adj != 0) {
            $adj_color = $total_adj > 0 ? $this->c->success : $this->c->danger;
            $adj_text = ($total_adj > 0 ? '+' : '') . '$' . number_format($total_adj);
            $bw = max(24, $this->pdf->GetStringWidth($adj_text) + 8);
            $bx = Flip_PDF_Components::LM + Flip_PDF_Components::PW - $bw - 4;
            $this->pdf->SetFillColor($adj_color[0], $adj_color[1], $adj_color[2]);
            $this->pdf->SetAlpha(0.15);
            $this->pdf->RoundedRect($bx, $y + 4, $bw, 6, 2, '1111', 'F');
            $this->pdf->SetAlpha(1);
            $this->pdf->SetFont('helvetica', 'B', 7);
            $this->c->set_color($adj_color);
            $this->pdf->SetXY($bx, $y + 4);
            $this->pdf->Cell($bw, 6, $adj_text, 0, 0, 'C');
        }

        // Price row
        $close_price = $comp['close_price'] ?? 0;
        $adj_price = $comp['adjusted_price'] ?? $close_price;
        $this->pdf->SetFont('helvetica', '', 9);
        $this->c->set_color($this->c->gray);
        $this->pdf->SetXY($content_x, $y + 12);
        $this->pdf->Cell(26, 5, 'Sold:', 0, 0, 'L');
        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->c->set_text_color();
        $this->pdf->Cell(40, 5, '$' . number_format($close_price), 0, 0, 'L');

        $this->pdf->SetFont('helvetica', '', 9);
        $this->c->set_color($this->c->primary);
        $this->pdf->Cell(40, 5, 'Adj: $' . number_format($adj_price), 0, 1, 'L');

        // Details row
        $ppsf = $comp['adjusted_ppsf'] ?? $comp['ppsf'] ?? 0;
        $dist = $comp['distance_miles'] ?? 0;
        $details = [];
        if ($ppsf > 0) $details[] = '$' . number_format($ppsf) . '/sqft';
        if ($dist > 0) $details[] = number_format($dist, 2) . ' mi';
        if (!empty($comp['close_date'])) $details[] = 'Sold ' . $comp['close_date'];

        $this->pdf->SetFont('helvetica', '', 8);
        $this->c->set_color($this->c->gray);
        $this->pdf->SetXY($content_x, $y + 19);
        $this->pdf->Cell($content_w, 5, implode('  •  ', $details), 0, 1, 'L');

        // Adjustment breakdown
        $adj = $comp['adjustments'] ?? [];
        if (!empty($adj)) {
            $fields = ['bed_adjustment' => 'Bed', 'bath_adjustment' => 'Bath', 'sqft_adjustment' => 'SqFt', 'garage_adjustment' => 'Gar', 'basement_adjustment' => 'Bsmt'];
            $parts = [];
            foreach ($fields as $f => $lbl) {
                $val = $adj[$f] ?? 0;
                if ($val != 0) {
                    $parts[] = $lbl . ': ' . ($val > 0 ? '+' : '') . '$' . number_format($val);
                }
            }
            if (!empty($parts)) {
                $this->pdf->SetFont('helvetica', '', 7);
                $this->c->set_color($this->c->gray);
                $this->pdf->SetXY($content_x, $y + 25);
                $this->pdf->Cell($content_w, 4, 'Adj: ' . implode(', ', $parts), 0, 1, 'L');
            }
        }

        return $y + $card_h + 4;
    }
}
