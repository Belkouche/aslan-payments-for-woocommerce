<?php

declare(strict_types=1);

namespace Aslan\Woo\Admin;

final class PriceScanner
{
    /** @return list<array{id:int,sku:string,price:string}> */
    public function scan(int $limit = 500): array
    {
        $results = [];
        $query   = new \WC_Product_Query([
            'limit'  => $limit,
            'status' => 'publish',
            'return' => 'objects',
        ]);

        /** @var list<\WC_Product> $products */
        $products = $query->get_products();
        foreach ($products as $product) {
            $price = (float) $product->get_price();
            if ($price > 0 && (int) round($price * 100) % 100 !== 0) {
                $results[] = [
                    'id'    => $product->get_id(),
                    'sku'   => $product->get_sku(),
                    'price' => (string) $product->get_price(),
                ];
            }
        }

        return $results;
    }

    public function renderNoticeIfAny(): void
    {
        $rows = $this->scan();
        if ($rows === []) {
            return;
        }

        add_action('admin_notices', static function () use ($rows): void {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__(
                'Aslan cannot charge fractional-MAD amounts. The following products have fractional prices:',
                'aslan-payments-for-woocommerce'
            );
            echo '</p><ul>';
            foreach (array_slice($rows, 0, 10) as $row) {
                printf(
                    '<li>#%s — %s — %s MAD</li>',
                    esc_html((string) $row['id']),
                    esc_html($row['sku']),
                    esc_html($row['price'])
                );
            }
            if (count($rows) > 10) {
                printf(
                    '<li>%s</li>',
                    esc_html(
                        sprintf(
                            /* translators: %d: number of additional products not listed. */
                            __('… and %d more', 'aslan-payments-for-woocommerce'),
                            count($rows) - 10
                        )
                    )
                );
            }
            echo '</ul></div>';
        });
    }
}
