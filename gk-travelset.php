<?php
/**
 * Plugin Name: GK – Travel-Set Regel-Builder
 * Description: Flexible Travel-Set-Regeln für mehrere WooCommerce-Produkte. Variantenpreise bleiben vollständig in WooCommerce; das Plugin verwaltet nur Auswahlregeln und Kategorien.
 * Author: GK
 * Version: 4.0.1
 * Text Domain: gk-travelset
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class GK_TravelSet_Rule_Builder {
    const VERSION           = '4.0.1';
    const OPTION_KEY        = 'gkts_settings_v4';
    const LEGACY_OPTION_KEY = 'gkts_settings_v3';
    const VERSION_KEY       = 'gkts_plugin_version';
    const ADMIN_NONCE       = 'gkts_admin_save';
    const AJAX_NONCE        = 'gkts_frontend_nonce';

    private $settings = null;

    public function __construct() {
        add_action( 'init', [ $this, 'maybe_migrate' ], 20 );
        add_action( 'admin_menu', [ $this, 'admin_menu' ], 60 );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        add_action( 'admin_post_gkts_save_settings', [ $this, 'save_settings' ] );

        add_action( 'wp_ajax_gkts_admin_variations', [ $this, 'ajax_admin_variations' ] );
        add_action( 'wp_ajax_gkts_frontend_lists', [ $this, 'ajax_frontend_lists' ] );
        add_action( 'wp_ajax_nopriv_gkts_frontend_lists', [ $this, 'ajax_frontend_lists' ] );

        add_action( 'wp', [ $this, 'frontend_hooks' ] );

        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate' ], 10, 6 );
        add_filter( 'woocommerce_add_cart_item_data', [ $this, 'attach' ], 10, 4 );
        add_filter( 'woocommerce_get_item_data', [ $this, 'display' ], 10, 2 );
        add_filter( 'woocommerce_dropdown_variation_attribute_options_args', [ $this, 'sort_variation_dropdown_args' ], 20 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'order_item_meta' ], 10, 4 );
    }

    /* ---------------------------------------------------------------------
     * Settings / Migration
     * ------------------------------------------------------------------ */

    private function defaults(): array {
        return [
            'products' => [],
        ];
    }

    private function get_settings(): array {
        if ( is_array( $this->settings ) ) {
            return $this->settings;
        }

        $saved = get_option( self::OPTION_KEY, [] );
        $saved = is_array( $saved ) ? $saved : [];
        $this->settings = wp_parse_args( $saved, $this->defaults() );
        if ( ! isset( $this->settings['products'] ) || ! is_array( $this->settings['products'] ) ) {
            $this->settings['products'] = [];
        }
        return $this->settings;
    }

    private function set_settings( array $settings ): void {
        $this->settings = $settings;
        update_option( self::OPTION_KEY, $settings, false );
    }

    /**
     * Migriert die komplette v3-Konfiguration in die neue Multi-Produkt-Struktur.
     * Vorhandene Regeln, Kategorien und Varianten-Zuordnungen bleiben erhalten.
     * Das frühere Regel-Preisfeld wird absichtlich nicht übernommen – Preise gehören ab v4 zu WooCommerce.
     */
    public function maybe_migrate(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $installed = (string) get_option( self::VERSION_KEY, '' );
        $current   = get_option( self::OPTION_KEY, null );

        if ( is_array( $current ) && isset( $current['products'] ) ) {
            if ( version_compare( $installed, self::VERSION, '<' ) ) {
                $current = $this->normalize_settings( $current );
                update_option( self::OPTION_KEY, $current, false );
                update_option( self::VERSION_KEY, self::VERSION, false );
                $this->settings = $current;
            }
            return;
        }

        $legacy = get_option( self::LEGACY_OPTION_KEY, [] );
        $new    = $this->defaults();

        if ( is_array( $legacy ) && ! empty( $legacy['product_id'] ) ) {
            $product_id = absint( $legacy['product_id'] );
            $rules      = [];

            foreach ( (array) ( $legacy['rules'] ?? [] ) as $index => $rule ) {
                if ( ! is_array( $rule ) ) {
                    continue;
                }
                $groups = [];
                foreach ( (array) ( $rule['groups'] ?? [] ) as $group ) {
                    if ( ! is_array( $group ) ) {
                        continue;
                    }
                    $groups[] = [
                        'label'        => sanitize_text_field( $group['label'] ?? '' ),
                        'count'        => max( 1, min( 50, absint( $group['count'] ?? 1 ) ) ),
                        'category_ids' => array_values( array_filter( array_unique( array_map( 'absint', (array) ( $group['category_ids'] ?? [] ) ) ) ) ),
                    ];
                }

                $rules[] = [
                    'id'           => sanitize_key( $rule['id'] ?? 'rule_' . $index ),
                    'name'         => sanitize_text_field( $rule['name'] ?? '' ),
                    'variation_id' => absint( $rule['variation_id'] ?? 0 ),
                    'enabled'      => ! empty( $rule['enabled'] ) ? 1 : 0,
                    'groups'       => $groups,
                ];
            }

            $new['products'][] = [
                'id'                     => 'set_' . $product_id,
                'product_id'             => $product_id,
                'auto_create_variations' => ! empty( $legacy['auto_create_variations'] ) ? 1 : 0,
                'rules'                  => $rules,
            ];
        }

        $new = $this->normalize_settings( $new );
        update_option( self::OPTION_KEY, $new, false );
        update_option( self::VERSION_KEY, self::VERSION, false );
        $this->settings = $new;
    }

    private function normalize_settings( array $settings ): array {
        $out = $this->defaults();
        $seen_products = [];

        foreach ( (array) ( $settings['products'] ?? [] ) as $pi => $config ) {
            if ( ! is_array( $config ) ) {
                continue;
            }
            $product_id = absint( $config['product_id'] ?? 0 );
            if ( ! $product_id || isset( $seen_products[ $product_id ] ) ) {
                continue;
            }
            $seen_products[ $product_id ] = true;

            $rules = [];
            foreach ( (array) ( $config['rules'] ?? [] ) as $ri => $rule ) {
                if ( ! is_array( $rule ) ) {
                    continue;
                }
                $name = sanitize_text_field( $rule['name'] ?? '' );
                if ( $name === '' ) {
                    continue;
                }

                $groups = [];
                foreach ( (array) ( $rule['groups'] ?? [] ) as $group ) {
                    if ( ! is_array( $group ) ) {
                        continue;
                    }
                    $label = sanitize_text_field( $group['label'] ?? '' );
                    if ( $label === '' ) {
                        continue;
                    }
                    $groups[] = [
                        'label'        => $label,
                        'count'        => max( 1, min( 50, absint( $group['count'] ?? 1 ) ) ),
                        'category_ids' => array_values( array_filter( array_unique( array_map( 'absint', (array) ( $group['category_ids'] ?? [] ) ) ) ) ),
                    ];
                }
                if ( ! $groups ) {
                    continue;
                }

                $rules[] = [
                    'id'           => sanitize_key( $rule['id'] ?? 'rule_' . $ri ),
                    'name'         => $name,
                    'variation_id' => absint( $rule['variation_id'] ?? 0 ),
                    'enabled'      => ! empty( $rule['enabled'] ) ? 1 : 0,
                    'groups'       => $groups,
                ];
            }

            $out['products'][] = [
                'id'                     => sanitize_key( $config['id'] ?? 'set_' . $product_id ),
                'product_id'             => $product_id,
                'auto_create_variations' => ! empty( $config['auto_create_variations'] ) ? 1 : 0,
                'rules'                  => $rules,
            ];
        }

        return $out;
    }

    private function get_product_config( int $product_id ): ?array {
        if ( ! $product_id ) {
            return null;
        }
        foreach ( (array) $this->get_settings()['products'] as $config ) {
            if ( absint( $config['product_id'] ?? 0 ) === $product_id ) {
                return $config;
            }
        }
        return null;
    }

    /* ---------------------------------------------------------------------
     * Admin
     * ------------------------------------------------------------------ */

    public function admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Travel-Set', 'gk-travelset' ),
            __( 'Travel-Set', 'gk-travelset' ),
            'manage_woocommerce',
            'gk-travelset',
            [ $this, 'admin_page' ]
        );
    }

    public function admin_assets( string $hook ): void {
        if ( $hook !== 'woocommerce_page_gk-travelset' ) {
            return;
        }

        wp_enqueue_style( 'woocommerce_admin_styles' );
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_enqueue_script( 'jquery-ui-sortable' );
        wp_enqueue_script(
            'gkts-admin',
            plugins_url( 'travelset-admin.js', __FILE__ ),
            [ 'jquery', 'wc-enhanced-select', 'jquery-ui-sortable' ],
            self::VERSION,
            true
        );

        wp_localize_script( 'gkts-admin', 'GKTS_ADMIN', [
            'ajax'            => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'gkts_admin_ajax' ),
            'variationAction' => 'gkts_admin_variations',
            'texts'           => [
                'chooseVariation' => __( 'Vorhandene Variante auswählen …', 'gk-travelset' ),
                'noVariations'    => __( 'Keine Varianten gefunden', 'gk-travelset' ),
                'newProduct'      => __( 'Neues Travel-Set Produkt', 'gk-travelset' ),
                'confirmProduct'  => __( 'Nur die Travel-Set-Konfiguration entfernen? Das WooCommerce-Produkt selbst wird nicht gelöscht.', 'gk-travelset' ),
            ],
        ] );
    }

    public function admin_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'gk-travelset' ) );
        }

        $settings   = $this->get_settings();
        $categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );

        if ( isset( $_GET['gkts_saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Travel-Set gespeichert.</strong> Die Regeln wurden gespeichert. Variantenpreise wurden nicht verändert.</p></div>';
        }
        if ( isset( $_GET['gkts_warning'] ) ) {
            echo '<div class="notice notice-warning"><p>' . esc_html( wp_unslash( $_GET['gkts_warning'] ) ) . '</p></div>';
        }
        ?>
        <div class="wrap gkts-admin-wrap">
            <h1><?php esc_html_e( 'Travel-Set Regel-Builder', 'gk-travelset' ); ?></h1>
            <p class="description">Verwalte mehrere Travel-Set-Produkte. Jedes Produkt besitzt seine eigenen Regeln, Varianten-Zuordnungen und Produktkategorien.</p>
            <div class="notice notice-info inline gkts-price-note"><p><strong>Preise:</strong> Variantenpreise werden ausschließlich unter <em>Produkte → Produkt bearbeiten → Varianten</em> gepflegt. Dieses Plugin liest den Preis nur zur Anzeige und überschreibt ihn niemals.</p></div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="gkts-settings-form">
                <input type="hidden" name="action" value="gkts_save_settings">
                <?php wp_nonce_field( self::ADMIN_NONCE, '_gkts_nonce' ); ?>

                <div class="gkts-toolbar">
                    <button type="button" class="button button-primary" id="gkts-add-product">+ Travel-Set Produkt hinzufügen</button>
                    <span class="description">Produkte, Regeln und Produktgruppen kannst du am Griff ☰ verschieben.</span>
                </div>

                <div id="gkts-products">
                    <?php
                    foreach ( (array) $settings['products'] as $pi => $config ) {
                        $this->render_product_admin( (string) $pi, $config, $categories, 0 === (int) $pi );
                    }
                    ?>
                </div>

                <div class="gkts-empty-products" <?php echo ! empty( $settings['products'] ) ? 'style="display:none"' : ''; ?>>
                    Noch kein Travel-Set-Produkt eingerichtet. Klicke oben auf „+ Travel-Set Produkt hinzufügen“.
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large">Änderungen speichern</button>
                </p>
            </form>
        </div>

        <?php $this->render_admin_templates( $categories ); ?>

        <style>
            .gkts-admin-wrap{max-width:1320px}
            .gkts-price-note{max-width:1000px;margin:14px 0 18px!important}
            .gkts-toolbar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:18px 0}
            .gkts-product-config{background:#fff;border:1px solid #c3c4c7;border-radius:10px;margin:16px 0;overflow:hidden;box-shadow:0 1px 1px rgba(0,0,0,.03)}
            .gkts-product-head{display:flex;align-items:center;gap:10px;padding:14px 16px;background:#f6f7f7;border-bottom:1px solid #dcdcde}
            .gkts-product-title{font-size:15px;font-weight:700;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .gkts-drag{cursor:grab;color:#646970;font-size:18px;line-height:1;user-select:none}
            .gkts-drag:active{cursor:grabbing}
            .gkts-collapse{border:0;background:transparent;cursor:pointer;font-size:18px;padding:2px 6px;color:#50575e}
            .gkts-product-body{padding:18px}
            .gkts-product-main{display:grid;grid-template-columns:180px minmax(280px,560px);gap:10px 18px;align-items:center}
            .gkts-product-main .description{grid-column:2}
            .gkts-check{display:block;margin:14px 0 4px}
            .gkts-rules-panel{border-top:1px solid #e5e5e5;margin-top:18px;padding-top:16px}
            .gkts-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}
            .gkts-panel-head h3{margin:0 0 4px}
            .gkts-rule{border:1px solid #dcdcde;border-radius:8px;margin:14px 0;background:#fcfcfc}
            .gkts-rule-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 13px;border-bottom:1px solid #e7e7e7;background:#f6f7f7;border-radius:8px 8px 0 0}
            .gkts-rule-head-left{display:flex;align-items:center;gap:9px;min-width:0}
            .gkts-rule-head strong{font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .gkts-rule-body{padding:14px}
            .gkts-rule-grid{display:grid;grid-template-columns:minmax(220px,1.25fr) minmax(300px,1.65fr) 100px;gap:12px;align-items:end}
            .gkts-field label{display:block;font-weight:600;margin:0 0 5px}
            .gkts-field input[type=text],.gkts-field input[type=number],.gkts-field select{width:100%}
            .gkts-groups{margin-top:15px;border-top:1px solid #e4e4e4;padding-top:13px}
            .gkts-group{display:grid;grid-template-columns:24px minmax(150px,.8fr) 100px minmax(260px,2fr) auto;gap:10px;align-items:end;margin:8px 0}
            .gkts-group .select2-container{width:100%!important}
            .gkts-group-drag{align-self:center;margin-top:22px}
            .gkts-rule-footer{display:flex;align-items:center;justify-content:space-between;margin-top:12px;gap:12px}
            .gkts-total{font-weight:600}
            .gkts-empty,.gkts-empty-products{padding:22px;text-align:center;border:1px dashed #b7b7b7;border-radius:8px;color:#646970;background:#fff}
            .gkts-remove-rule,.gkts-remove-product{color:#b32d2e!important}
            .gkts-sort-placeholder{height:58px;border:2px dashed #8c8f94;border-radius:8px;background:#f0f6fc;margin:10px 0}
            .gkts-product-placeholder{height:76px;border:2px dashed #2271b1;border-radius:10px;background:#f0f6fc;margin:16px 0}
            @media(max-width:920px){.gkts-rule-grid,.gkts-group,.gkts-product-main{grid-template-columns:1fr}.gkts-product-main .description{grid-column:1}.gkts-group-drag{margin:0}.gkts-panel-head{flex-direction:column}}
        </style>
        <?php
    }

    private function render_product_admin( string $pi, array $config, $categories, bool $expanded = false ): void {
        $config = wp_parse_args( $config, [
            'id'                     => 'set_' . $pi,
            'product_id'             => 0,
            'auto_create_variations' => 0,
            'rules'                  => [],
        ] );

        $product_id = absint( $config['product_id'] );
        $product    = $product_id ? wc_get_product( $product_id ) : false;
        $title      = $product ? $product->get_formatted_name() : 'Neues Travel-Set Produkt';
        $variations = $this->get_variation_options( $product_id );
        ?>
        <div class="gkts-product-config" data-product-index="<?php echo esc_attr( $pi ); ?>">
            <div class="gkts-product-head">
                <span class="gkts-drag gkts-product-drag" title="Produkt verschieben">☰</span>
                <span class="gkts-product-title"><?php echo esc_html( $title ); ?></span>
                <button type="button" class="button-link gkts-remove-product">Konfiguration entfernen</button>
                <button type="button" class="gkts-collapse" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" title="Auf-/Zuklappen"><?php echo $expanded ? '▴' : '▾'; ?></button>
            </div>
            <div class="gkts-product-body" <?php echo $expanded ? '' : 'style="display:none"'; ?>>
                <input type="hidden" class="gkts-product-order" name="products[<?php echo esc_attr( $pi ); ?>][sort_order]" value="<?php echo esc_attr( (int) $pi ); ?>">
                <input type="hidden" name="products[<?php echo esc_attr( $pi ); ?>][id]" value="<?php echo esc_attr( $config['id'] ); ?>">
                <div class="gkts-product-main">
                    <label><strong>Variables Produkt</strong></label>
                    <select class="wc-product-search gkts-product-select" style="width:100%;" name="products[<?php echo esc_attr( $pi ); ?>][product_id]"
                            data-placeholder="Produkt suchen …" data-action="woocommerce_json_search_products" data-allow_clear="true">
                        <?php if ( $product ) : ?>
                            <option value="<?php echo esc_attr( $product_id ); ?>" selected><?php echo esc_html( $product->get_formatted_name() ); ?></option>
                        <?php endif; ?>
                    </select>
                    <span></span>
                    <p class="description">Dieses Produkt besitzt seine eigene Regel-Liste. Das Entfernen hier löscht niemals das eigentliche WooCommerce-Produkt.</p>
                </div>
                <label class="gkts-check"><input type="checkbox" name="products[<?php echo esc_attr( $pi ); ?>][auto_create_variations]" value="1" <?php checked( ! empty( $config['auto_create_variations'] ) ); ?>> Nicht zugeordnete Varianten beim Speichern automatisch anlegen (nur bei genau einem Varianten-Attribut). Neue Varianten werden <strong>ohne Preis</strong> erstellt.</label>

                <div class="gkts-rules-panel">
                    <div class="gkts-panel-head">
                        <div>
                            <h3>Regeln</h3>
                            <p class="description">Jede Regel wird einer vorhandenen WooCommerce-Variante zugeordnet. Die Reihenfolge kannst du mit ☰ ändern.</p>
                        </div>
                        <button type="button" class="button button-primary gkts-add-rule">+ Regel hinzufügen</button>
                    </div>

                    <div class="gkts-rules">
                        <?php foreach ( (array) $config['rules'] as $ri => $rule ) :
                            $this->render_rule_admin( $pi, (string) $ri, $rule, $categories, $variations );
                        endforeach; ?>
                    </div>
                    <div class="gkts-empty" <?php echo ! empty( $config['rules'] ) ? 'style="display:none"' : ''; ?>>Noch keine Regel für dieses Produkt.</div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_rule_admin( string $pi, string $ri, array $rule, $categories, array $variations ): void {
        $rule = wp_parse_args( $rule, [
            'id'           => 'rule_' . $ri,
            'name'         => '',
            'variation_id' => 0,
            'enabled'      => 1,
            'groups'       => [],
        ] );
        $groups = is_array( $rule['groups'] ) ? $rule['groups'] : [];
        $total  = 0;
        foreach ( $groups as $g ) {
            $total += max( 0, (int) ( $g['count'] ?? 0 ) );
        }
        ?>
        <div class="gkts-rule" data-rule-index="<?php echo esc_attr( $ri ); ?>">
            <div class="gkts-rule-head">
                <div class="gkts-rule-head-left">
                    <span class="gkts-drag gkts-rule-drag" title="Regel verschieben">☰</span>
                    <strong class="gkts-rule-title"><?php echo esc_html( $rule['name'] ?: 'Neue Regel' ); ?></strong>
                </div>
                <div>
                    <label style="margin-right:10px"><input type="checkbox" name="products[<?php echo esc_attr( $pi ); ?>][rules][<?php echo esc_attr( $ri ); ?>][enabled]" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?>> aktiv</label>
                    <button type="button" class="button-link gkts-remove-rule">Regel entfernen</button>
                </div>
            </div>
            <div class="gkts-rule-body">
                <input type="hidden" class="gkts-rule-order" name="products[<?php echo esc_attr( $pi ); ?>][rules][<?php echo esc_attr( $ri ); ?>][sort_order]" value="<?php echo esc_attr( (int) $ri ); ?>">
                <input type="hidden" name="products[<?php echo esc_attr( $pi ); ?>][rules][<?php echo esc_attr( $ri ); ?>][id]" value="<?php echo esc_attr( $rule['id'] ); ?>">
                <div class="gkts-rule-grid">
                    <div class="gkts-field">
                        <label>Regelname</label>
                        <input type="text" class="gkts-rule-name" name="products[<?php echo esc_attr( $pi ); ?>][rules][<?php echo esc_attr( $ri ); ?>][name]" value="<?php echo esc_attr( $rule['name'] ); ?>" placeholder="z. B. 3× Designer + 3× Nische" required>
                    </div>
                    <div class="gkts-field">
                        <label>Passende WooCommerce-Variante</label>
                        <select class="gkts-variation-select" name="products[<?php echo esc_attr( $pi ); ?>][rules][<?php echo esc_attr( $ri ); ?>][variation_id]">
                            <option value="">Vorhandene Variante auswählen …</option>
                            <?php foreach ( $variations as $variation_id => $label ) : ?>
                                <option value="<?php echo esc_attr( $variation_id ); ?>" <?php selected( (int) $rule['variation_id'], (int) $variation_id ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="gkts-field">
                        <label>Anzahl</label>
                        <input type="number" class="gkts-total-field" value="<?php echo esc_attr( $total ); ?>" readonly>
                    </div>
                </div>

                <div class="gkts-groups">
                    <strong>Produktauswahl / Kategorien</strong>
                    <div class="gkts-group-list">
                        <?php foreach ( $groups as $gi => $group ) :
                            $this->render_group_admin( $pi, $ri, (string) $gi, $group, $categories );
                        endforeach; ?>
                    </div>
                    <div class="gkts-rule-footer">
                        <button type="button" class="button gkts-add-group">+ Produktgruppe hinzufügen</button>
                        <span class="gkts-total">Gesamt: <span><?php echo esc_html( $total ); ?></span> Produkte</span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_group_admin( string $pi, string $ri, string $gi, array $group, $categories ): void {
        $label = sanitize_text_field( $group['label'] ?? '' );
        $count = max( 1, (int) ( $group['count'] ?? 1 ) );
        $selected_ids = array_map( 'absint', (array) ( $group['category_ids'] ?? [] ) );
        ?>
        <div class="gkts-group" data-group-index="<?php echo esc_attr( $gi ); ?>">
            <input type="hidden" class="gkts-group-order" name="products[<?php echo esc_attr( $pi ); ?>][rules][<?php echo esc_attr( $ri ); ?>][groups][<?php echo esc_attr( $gi ); ?>][sort_order]" value="<?php echo esc_attr( (int) $gi ); ?>">
            <span class="gkts-drag gkts-group-drag" title="Produktgruppe verschieben">☰</span>
            <div class="gkts-field">
                <label>Bezeichnung</label>
                <input type="text" name="products[<?php echo esc_attr( $pi ); ?>][rules][<?php echo esc_attr( $ri ); ?>][groups][<?php echo esc_attr( $gi ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="Designer / Nische / …" required>
            </div>
            <div class="gkts-field">
                <label>Anzahl</label>
                <input type="number" min="1" max="50" class="gkts-group-count" name="products[<?php echo esc_attr( $pi ); ?>][rules][<?php echo esc_attr( $ri ); ?>][groups][<?php echo esc_attr( $gi ); ?>][count]" value="<?php echo esc_attr( $count ); ?>" required>
            </div>
            <div class="gkts-field">
                <label>WooCommerce-Kategorie(n)</label>
                <select class="gkts-category-select" name="products[<?php echo esc_attr( $pi ); ?>][rules][<?php echo esc_attr( $ri ); ?>][groups][<?php echo esc_attr( $gi ); ?>][category_ids][]" multiple data-placeholder="Kategorie auswählen …">
                    <?php if ( ! is_wp_error( $categories ) ) : foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( (int) $cat->term_id, $selected_ids, true ) ); ?>><?php echo esc_html( $cat->name ); ?></option>
                    <?php endforeach; endif; ?>
                </select>
            </div>
            <div><button type="button" class="button-link-delete gkts-remove-group">Entfernen</button></div>
        </div>
        <?php
    }

    private function render_admin_templates( $categories ): void {
        $cat_options = '';
        if ( ! is_wp_error( $categories ) ) {
            foreach ( $categories as $cat ) {
                $cat_options .= '<option value="' . esc_attr( $cat->term_id ) . '">' . esc_html( $cat->name ) . '</option>';
            }
        }
        ?>
        <script type="text/html" id="gkts-product-template">
            <div class="gkts-product-config" data-product-index="__PRODUCT__">
                <div class="gkts-product-head">
                    <span class="gkts-drag gkts-product-drag" title="Produkt verschieben">☰</span>
                    <span class="gkts-product-title">Neues Travel-Set Produkt</span>
                    <button type="button" class="button-link gkts-remove-product">Konfiguration entfernen</button>
                    <button type="button" class="gkts-collapse" aria-expanded="true" title="Auf-/Zuklappen">▴</button>
                </div>
                <div class="gkts-product-body">
                    <input type="hidden" class="gkts-product-order" name="products[__PRODUCT__][sort_order]" value="0">
                    <input type="hidden" name="products[__PRODUCT__][id]" value="set___PRODUCT__">
                    <div class="gkts-product-main">
                        <label><strong>Variables Produkt</strong></label>
                        <select class="wc-product-search gkts-product-select" style="width:100%;" name="products[__PRODUCT__][product_id]" data-placeholder="Produkt suchen …" data-action="woocommerce_json_search_products" data-allow_clear="true"></select>
                        <span></span><p class="description">Dieses Produkt besitzt seine eigene Regel-Liste. Das Entfernen hier löscht niemals das eigentliche WooCommerce-Produkt.</p>
                    </div>
                    <label class="gkts-check"><input type="checkbox" name="products[__PRODUCT__][auto_create_variations]" value="1"> Nicht zugeordnete Varianten beim Speichern automatisch anlegen (nur bei genau einem Varianten-Attribut). Neue Varianten werden <strong>ohne Preis</strong> erstellt.</label>
                    <div class="gkts-rules-panel">
                        <div class="gkts-panel-head"><div><h3>Regeln</h3><p class="description">Jede Regel wird einer WooCommerce-Variante zugeordnet. Die Reihenfolge kannst du mit ☰ ändern.</p></div><button type="button" class="button button-primary gkts-add-rule">+ Regel hinzufügen</button></div>
                        <div class="gkts-rules"></div>
                        <div class="gkts-empty">Noch keine Regel für dieses Produkt.</div>
                    </div>
                </div>
            </div>
        </script>

        <script type="text/html" id="gkts-rule-template">
            <div class="gkts-rule" data-rule-index="__RULE__">
                <div class="gkts-rule-head">
                    <div class="gkts-rule-head-left"><span class="gkts-drag gkts-rule-drag" title="Regel verschieben">☰</span><strong class="gkts-rule-title">Neue Regel</strong></div>
                    <div><label style="margin-right:10px"><input type="checkbox" name="products[__PRODUCT__][rules][__RULE__][enabled]" value="1" checked> aktiv</label><button type="button" class="button-link gkts-remove-rule">Regel entfernen</button></div>
                </div>
                <div class="gkts-rule-body">
                    <input type="hidden" class="gkts-rule-order" name="products[__PRODUCT__][rules][__RULE__][sort_order]" value="0">
                    <input type="hidden" name="products[__PRODUCT__][rules][__RULE__][id]" value="rule___RULE__">
                    <div class="gkts-rule-grid">
                        <div class="gkts-field"><label>Regelname</label><input type="text" class="gkts-rule-name" name="products[__PRODUCT__][rules][__RULE__][name]" placeholder="z. B. 3× Designer + 3× Nische" required></div>
                        <div class="gkts-field"><label>Passende WooCommerce-Variante</label><select class="gkts-variation-select" name="products[__PRODUCT__][rules][__RULE__][variation_id]"><option value="">Vorhandene Variante auswählen …</option></select></div>
                        <div class="gkts-field"><label>Anzahl</label><input type="number" class="gkts-total-field" value="0" readonly></div>
                    </div>
                    <div class="gkts-groups"><strong>Produktauswahl / Kategorien</strong><div class="gkts-group-list"></div><div class="gkts-rule-footer"><button type="button" class="button gkts-add-group">+ Produktgruppe hinzufügen</button><span class="gkts-total">Gesamt: <span>0</span> Produkte</span></div></div>
                </div>
            </div>
        </script>

        <script type="text/html" id="gkts-group-template">
            <div class="gkts-group" data-group-index="__GROUP__">
                <input type="hidden" class="gkts-group-order" name="products[__PRODUCT__][rules][__RULE__][groups][__GROUP__][sort_order]" value="0">
                <span class="gkts-drag gkts-group-drag" title="Produktgruppe verschieben">☰</span>
                <div class="gkts-field"><label>Bezeichnung</label><input type="text" name="products[__PRODUCT__][rules][__RULE__][groups][__GROUP__][label]" placeholder="Designer / Nische / …" required></div>
                <div class="gkts-field"><label>Anzahl</label><input type="number" min="1" max="50" class="gkts-group-count" name="products[__PRODUCT__][rules][__RULE__][groups][__GROUP__][count]" value="1" required></div>
                <div class="gkts-field"><label>WooCommerce-Kategorie(n)</label><select class="gkts-category-select" name="products[__PRODUCT__][rules][__RULE__][groups][__GROUP__][category_ids][]" multiple data-placeholder="Kategorie auswählen …"><?php echo $cat_options; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></div>
                <div><button type="button" class="button-link-delete gkts-remove-group">Entfernen</button></div>
            </div>
        </script>
        <?php
    }

    private function sort_posted_items( array $items ): array {
        $decorated = [];
        $position  = 0;
        foreach ( $items as $key => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $decorated[] = [
                'key'      => $key,
                'item'     => $item,
                'order'    => isset( $item['sort_order'] ) ? (int) $item['sort_order'] : $position,
                'position' => $position,
            ];
            $position++;
        }
        usort( $decorated, static function( $a, $b ) {
            if ( $a['order'] === $b['order'] ) {
                return $a['position'] <=> $b['position'];
            }
            return $a['order'] <=> $b['order'];
        } );
        return array_map( static function( $row ) { return $row['item']; }, $decorated );
    }

    public function save_settings(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Keine Berechtigung.', 'gk-travelset' ) );
        }
        check_admin_referer( self::ADMIN_NONCE, '_gkts_nonce' );

        $raw_products = isset( $_POST['products'] ) && is_array( $_POST['products'] ) ? wp_unslash( $_POST['products'] ) : [];
        $raw_products = $this->sort_posted_items( $raw_products );
        $products     = [];
        $seen         = [];
        $skipped      = 0;

        foreach ( $raw_products as $pi => $raw_config ) {
            if ( ! is_array( $raw_config ) ) {
                continue;
            }

            $product_id = absint( $raw_config['product_id'] ?? 0 );
            $product    = $product_id ? wc_get_product( $product_id ) : false;
            if ( ! $product || ! $product->is_type( 'variable' ) || isset( $seen[ $product_id ] ) ) {
                $skipped++;
                continue;
            }
            $seen[ $product_id ] = true;

            $rules = [];
            $raw_rules = $this->sort_posted_items( (array) ( $raw_config['rules'] ?? [] ) );
            foreach ( $raw_rules as $ri => $raw_rule ) {
                if ( ! is_array( $raw_rule ) ) {
                    continue;
                }
                $name = sanitize_text_field( $raw_rule['name'] ?? '' );
                if ( $name === '' ) {
                    continue;
                }

                $groups = [];
                $raw_groups = $this->sort_posted_items( (array) ( $raw_rule['groups'] ?? [] ) );
                foreach ( $raw_groups as $raw_group ) {
                    if ( ! is_array( $raw_group ) ) {
                        continue;
                    }
                    $label = sanitize_text_field( $raw_group['label'] ?? '' );
                    $count = max( 1, min( 50, absint( $raw_group['count'] ?? 1 ) ) );
                    $category_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) ( $raw_group['category_ids'] ?? [] ) ) ) ) );
                    if ( $label === '' ) {
                        continue;
                    }
                    $groups[] = [
                        'label'        => $label,
                        'count'        => $count,
                        'category_ids' => $category_ids,
                    ];
                }
                if ( ! $groups ) {
                    continue;
                }

                $rules[] = [
                    'id'           => sanitize_key( $raw_rule['id'] ?? 'rule_' . $ri ),
                    'name'         => $name,
                    'variation_id' => absint( $raw_rule['variation_id'] ?? 0 ),
                    'enabled'      => ! empty( $raw_rule['enabled'] ) ? 1 : 0,
                    'groups'       => $groups,
                ];
            }

            $config = [
                'id'                     => sanitize_key( $raw_config['id'] ?? 'set_' . $product_id ),
                'product_id'             => $product_id,
                'auto_create_variations' => ! empty( $raw_config['auto_create_variations'] ) ? 1 : 0,
                'rules'                  => $rules,
            ];

            $config = $this->sync_rule_variations( $config, ! empty( $config['auto_create_variations'] ) );
            $this->sync_variation_menu_order( $config );
            $products[] = $config;
        }

        $settings = [ 'products' => $products ];
        $this->set_settings( $settings );
        update_option( self::VERSION_KEY, self::VERSION, false );

        $args = [ 'page' => 'gk-travelset', 'gkts_saved' => 1 ];
        if ( $skipped ) {
            $args['gkts_warning'] = rawurlencode( 'Mindestens eine Konfiguration wurde übersprungen, weil kein gültiges variables WooCommerce-Produkt ausgewählt war oder dasselbe Produkt doppelt vorkam.' );
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public function ajax_admin_variations(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Keine Berechtigung.' ], 403 );
        }
        check_ajax_referer( 'gkts_admin_ajax', 'nonce' );
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $items = [];
        foreach ( $this->get_variation_options( $product_id ) as $id => $label ) {
            $items[] = [
                'id'   => (int) $id,
                'text' => $label,
            ];
        }
        wp_send_json_success( [ 'variations' => $items ] );
    }

    private function get_variation_options( int $product_id ): array {
        $product = $product_id ? wc_get_product( $product_id ) : false;
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return [];
        }

        $out = [];
        foreach ( $product->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
                continue;
            }
            $label       = $this->variation_short_label( $variation );
            $price       = $variation->get_price();
            $price_label = $price !== '' ? wp_strip_all_tags( wc_price( $price ) ) : __( 'kein Preis gesetzt', 'gk-travelset' );
            $out[ (int) $variation_id ] = '#' . (int) $variation_id . ' — ' . $label . ' (' . $price_label . ')';
        }
        return $out;
    }

    private function variation_short_label( WC_Product_Variation $variation ): string {
        $parts = [];
        foreach ( $variation->get_attributes() as $name => $value ) {
            if ( $value === '' ) {
                continue;
            }
            if ( taxonomy_exists( $name ) ) {
                $term = get_term_by( 'slug', $value, $name );
                $parts[] = $term && ! is_wp_error( $term ) ? $term->name : $value;
            } else {
                $parts[] = $value;
            }
        }
        return $parts ? implode( ' / ', $parts ) : sprintf( __( 'Variante #%d', 'gk-travelset' ), $variation->get_id() );
    }

    private function get_variation_label_map( int $product_id ): array {
        $map     = [];
        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return $map;
        }
        foreach ( $product->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
                continue;
            }
            $map[ $this->normalize_label( $this->variation_short_label( $variation ) ) ] = (int) $variation_id;
        }
        return $map;
    }

    private function normalize_label( string $text ): string {
        $text = html_entity_decode( wp_strip_all_tags( $text ) );
        $text = remove_accents( $text );
        $text = strtolower( $text );
        $text = str_replace( [ '×', 'x', 'dufte', 'düfte', 'parfums', 'parfum' ], '', $text );
        $text = preg_replace( '/[^a-z0-9]+/u', '', $text );
        return (string) $text;
    }

    /**
     * Ordnet Regeln zu Varianten zu und kann fehlende Varianten anlegen.
     * WICHTIG: Diese Methode verändert niemals Preise bestehender oder neuer Varianten.
     */
    private function sync_rule_variations( array $config, bool $allow_create ): array {
        $product_id = absint( $config['product_id'] ?? 0 );
        $product    = $product_id ? wc_get_product( $product_id ) : false;
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return $config;
        }

        $label_map = $this->get_variation_label_map( $product_id );
        foreach ( (array) $config['rules'] as $i => $rule ) {
            $variation_id = absint( $rule['variation_id'] ?? 0 );
            $variation    = $variation_id ? wc_get_product( $variation_id ) : false;
            if ( ! $variation || ! $variation->is_type( 'variation' ) || (int) $variation->get_parent_id() !== $product_id ) {
                $variation_id = 0;
                $normalized   = $this->normalize_label( (string) ( $rule['name'] ?? '' ) );
                if ( isset( $label_map[ $normalized ] ) ) {
                    $variation_id = (int) $label_map[ $normalized ];
                } elseif ( $allow_create ) {
                    $variation_id = $this->create_variation_for_rule( $product, (string) ( $rule['name'] ?? '' ) );
                    if ( $variation_id ) {
                        $label_map[ $normalized ] = $variation_id;
                    }
                }
                $config['rules'][ $i ]['variation_id'] = $variation_id;
            }
        }

        WC_Product_Variable::sync( $product_id );
        wc_delete_product_transients( $product_id );
        return $config;
    }

    private function create_variation_for_rule( WC_Product_Variable $product, string $rule_name ): int {
        if ( $rule_name === '' ) {
            return 0;
        }

        $variation_attributes = [];
        foreach ( $product->get_attributes() as $attribute_key => $attribute ) {
            if ( $attribute instanceof WC_Product_Attribute && $attribute->get_variation() ) {
                $variation_attributes[] = [
                    'key'       => (string) $attribute_key,
                    'attribute' => $attribute,
                ];
            }
        }
        if ( count( $variation_attributes ) !== 1 ) {
            return 0;
        }

        /** @var WC_Product_Attribute $attribute */
        $attribute       = $variation_attributes[0]['attribute'];
        $attribute_key   = $variation_attributes[0]['key'];
        $attribute_name  = $attribute->get_name();
        $variation_value = '';

        if ( $attribute->is_taxonomy() ) {
            $term = get_term_by( 'name', $rule_name, $attribute_name );
            if ( ! $term || is_wp_error( $term ) ) {
                $inserted = wp_insert_term( $rule_name, $attribute_name );
                if ( is_wp_error( $inserted ) ) {
                    return 0;
                }
                $term = get_term( (int) $inserted['term_id'], $attribute_name );
            }
            if ( ! $term || is_wp_error( $term ) ) {
                return 0;
            }

            $options = array_map( 'intval', (array) $attribute->get_options() );
            if ( ! in_array( (int) $term->term_id, $options, true ) ) {
                $options[] = (int) $term->term_id;
                $attribute->set_options( array_values( array_unique( $options ) ) );
                $attrs = $product->get_attributes();
                $attrs[ $attribute_key ] = $attribute;
                $product->set_attributes( $attrs );
                $product->save();
            }
            $variation_value = $term->slug;
        } else {
            $options = (array) $attribute->get_options();
            $found   = false;
            foreach ( $options as $option ) {
                if ( $this->normalize_label( (string) $option ) === $this->normalize_label( $rule_name ) ) {
                    $rule_name = (string) $option;
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                $options[] = $rule_name;
                $attribute->set_options( array_values( array_unique( $options ) ) );
                $attrs = $product->get_attributes();
                $attrs[ $attribute_key ] = $attribute;
                $product->set_attributes( $attrs );
                $product->save();
            }
            $variation_value = $rule_name;
        }

        foreach ( $product->get_children() as $child_id ) {
            $existing = wc_get_product( $child_id );
            if ( ! $existing || ! $existing->is_type( 'variation' ) ) {
                continue;
            }
            $attrs = $existing->get_attributes();
            if ( isset( $attrs[ $attribute_key ] ) && $this->normalize_label( (string) $attrs[ $attribute_key ] ) === $this->normalize_label( $variation_value ) ) {
                return (int) $child_id;
            }
        }

        $variation = new WC_Product_Variation();
        $variation->set_parent_id( $product->get_id() );
        $variation->set_attributes( [ $attribute_key => $variation_value ] );
        $variation->set_status( 'publish' );
        $variation->set_manage_stock( false );
        // Absichtlich kein set_regular_price()/set_price(): Preis wird ausschließlich in WooCommerce gepflegt.
        $variation_id = $variation->save();

        return $variation_id ? (int) $variation_id : 0;
    }

    /**
     * Synchronisiert die WooCommerce-Varianten-Reihenfolge mit der Reihenfolge
     * der Travel-Set-Regeln. Preise und sonstige Variantendaten bleiben unberührt.
     */
    private function sync_variation_menu_order( array $config ): void {
        $product_id = absint( $config['product_id'] ?? 0 );
        $product    = $product_id ? wc_get_product( $product_id ) : false;
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            return;
        }

        $ordered_ids = [];
        foreach ( (array) ( $config['rules'] ?? [] ) as $rule ) {
            $variation_id = absint( $rule['variation_id'] ?? 0 );
            if ( ! $variation_id || in_array( $variation_id, $ordered_ids, true ) ) {
                continue;
            }
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation->is_type( 'variation' ) || (int) $variation->get_parent_id() !== $product_id ) {
                continue;
            }
            $ordered_ids[] = $variation_id;
        }

        foreach ( (array) $product->get_children() as $variation_id ) {
            $variation_id = absint( $variation_id );
            if ( $variation_id && ! in_array( $variation_id, $ordered_ids, true ) ) {
                $ordered_ids[] = $variation_id;
            }
        }

        foreach ( $ordered_ids as $menu_order => $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
                continue;
            }
            if ( (int) $variation->get_menu_order() !== (int) $menu_order ) {
                $variation->set_menu_order( (int) $menu_order );
                $variation->save();
            }
        }

        if ( class_exists( 'WC_Product_Variable' ) ) {
            WC_Product_Variable::sync( $product_id );
        }
        wc_delete_product_transients( $product_id );
    }

    /**
     * Erzwingt auch im nativen WooCommerce-Varianten-Dropdown die Reihenfolge
     * des Regel-Builders. Das ist unabhängig von Theme/Cache robuster als nur menu_order.
     */
    public function sort_variation_dropdown_args( $args ) {
        if ( ! is_array( $args ) ) {
            return $args;
        }

        $product = $args['product'] ?? null;
        if ( ! $product instanceof WC_Product_Variable ) {
            return $args;
        }

        $product_id = (int) $product->get_id();
        $config     = $this->get_product_config( $product_id );
        if ( ! $config ) {
            return $args;
        }

        $attribute = (string) ( $args['attribute'] ?? '' );
        if ( $attribute === '' ) {
            return $args;
        }

        $options = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : [];
        if ( ! $options ) {
            $variation_attributes = $product->get_variation_attributes();
            if ( isset( $variation_attributes[ $attribute ] ) && is_array( $variation_attributes[ $attribute ] ) ) {
                $options = $variation_attributes[ $attribute ];
            } else {
                foreach ( $variation_attributes as $attr_name => $attr_options ) {
                    if ( sanitize_title( (string) $attr_name ) === sanitize_title( $attribute ) && is_array( $attr_options ) ) {
                        $options = $attr_options;
                        break;
                    }
                }
            }
        }
        if ( ! $options ) {
            return $args;
        }

        $desired = [];
        foreach ( (array) ( $config['rules'] ?? [] ) as $rule ) {
            $variation_id = absint( $rule['variation_id'] ?? 0 );
            $variation    = $variation_id ? wc_get_product( $variation_id ) : false;
            if ( ! $variation || ! $variation->is_type( 'variation' ) || (int) $variation->get_parent_id() !== $product_id ) {
                continue;
            }
            foreach ( (array) $variation->get_attributes() as $attr_name => $value ) {
                if ( sanitize_title( (string) $attr_name ) !== sanitize_title( $attribute ) || $value === '' ) {
                    continue;
                }
                $key = sanitize_title( html_entity_decode( (string) $value ) );
                if ( $key !== '' && ! isset( $desired[ $key ] ) ) {
                    $desired[ $key ] = count( $desired );
                }
                break;
            }
        }
        if ( ! $desired ) {
            return $args;
        }
        $decorated = [];
        foreach ( array_values( $options ) as $position => $value ) {
            $key = sanitize_title( html_entity_decode( (string) $value ) );
            $decorated[] = [
                'value'    => $value,
                'rank'     => array_key_exists( $key, $desired ) ? $desired[ $key ] : PHP_INT_MAX,
                'position' => $position,
            ];
        }
        usort( $decorated, static function( $a, $b ) {
            if ( $a['rank'] === $b['rank'] ) {
                return $a['position'] <=> $b['position'];
            }
            return $a['rank'] <=> $b['rank'];
        } );
        $args['options'] = array_map( static function( $row ) { return $row['value']; }, $decorated );

        return $args;
    }

    /* ---------------------------------------------------------------------
     * Frontend
     * ------------------------------------------------------------------ */

    public function frontend_hooks(): void {
        if ( ! function_exists( 'is_product' ) || ! is_product() || ! $this->is_target_product_page() ) {
            return;
        }
        add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'render_frontend' ], 8 );
        add_action( 'wp_enqueue_scripts', [ $this, 'frontend_assets' ] );
    }

    private function current_target_product_id(): int {
        $id = (int) get_queried_object_id();
        return $this->get_product_config( $id ) ? $id : 0;
    }

    private function is_target_product_page(): bool {
        return $this->current_target_product_id() > 0;
    }

    private function frontend_rule_map( int $product_id ): array {
        $config = $this->get_product_config( $product_id );
        if ( ! $config ) {
            return [];
        }

        $map = [];
        foreach ( (array) $config['rules'] as $rule ) {
            if ( empty( $rule['enabled'] ) ) {
                continue;
            }
            $variation_id = absint( $rule['variation_id'] ?? 0 );
            if ( ! $variation_id ) {
                continue;
            }
            $groups = [];
            $total  = 0;
            foreach ( (array) ( $rule['groups'] ?? [] ) as $group ) {
                $count = max( 1, absint( $group['count'] ?? 1 ) );
                $cats  = array_values( array_filter( array_unique( array_map( 'absint', (array) ( $group['category_ids'] ?? [] ) ) ) ) );
                $groups[] = [
                    'label'       => sanitize_text_field( $group['label'] ?? '' ),
                    'count'       => $count,
                    'categoryIds' => $cats,
                ];
                $total += $count;
            }
            $map[ (string) $variation_id ] = [
                'id'     => sanitize_key( $rule['id'] ?? '' ),
                'name'   => sanitize_text_field( $rule['name'] ?? '' ),
                'total'  => $total,
                'groups' => $groups,
            ];
        }
        return $map;
    }

    private function frontend_rule_order( int $product_id ): array {
        $config = $this->get_product_config( $product_id );
        if ( ! $config ) {
            return [];
        }
        $order = [];
        foreach ( (array) ( $config['rules'] ?? [] ) as $rule ) {
            if ( empty( $rule['enabled'] ) ) {
                continue;
            }
            $variation_id = absint( $rule['variation_id'] ?? 0 );
            if ( $variation_id && ! in_array( $variation_id, $order, true ) ) {
                $order[] = $variation_id;
            }
        }
        return $order;
    }

    public function frontend_assets(): void {
        $product_id = $this->current_target_product_id();
        if ( ! $product_id ) {
            return;
        }

        wp_enqueue_script(
            'gkts-frontend',
            plugins_url( 'travelset-frontend.js', __FILE__ ),
            [ 'jquery' ],
            self::VERSION,
            true
        );
        wp_localize_script( 'gkts-frontend', 'GK_TRAVELSET', [
            'ajax'      => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( self::AJAX_NONCE ),
            'productId' => $product_id,
            'rules'     => $this->frontend_rule_map( $product_id ),
            'ruleOrder' => $this->frontend_rule_order( $product_id ),
            'texts'     => [
                'headline'           => 'Wähle deine %d Parfums',
                'chooseVariantFirst' => 'Bitte zuerst die Variante wählen …',
                'loading'            => 'Lade Optionen …',
                'placeholder'        => 'Bitte auswählen …',
                'noResults'          => 'Keine Optionen gefunden',
                'notConfigured'      => 'Für diese Variante ist noch keine Travel-Set-Regel hinterlegt.',
            ],
        ] );
    }

    public function render_frontend(): void {
        if ( ! $this->is_target_product_page() ) {
            return;
        }
        ?>
        <div class="gkts-travelset" data-gkts="1">
            <div class="gkts-headline">Wähle deine Parfums</div>
            <div class="gkts-hint">Bitte zuerst die Variante wählen …</div>
            <div class="gkts-grid"></div>
        </div>
        <style>
            .gkts-travelset{margin:16px 0 18px}
            .gkts-headline{font-weight:600;margin-bottom:7px}
            .gkts-hint{margin:2px 0 10px;font-size:.95em;color:#6b7280}
            .gkts-grid{display:grid;grid-template-columns:1fr;gap:12px}
            .gkts-cell{position:relative;width:100%}
            .gkts-slot-label{font-size:.86em;font-weight:600;margin:0 0 5px;color:#4b5563}
            .gkts-dd{position:relative;width:100%}
            .gkts-dd-toggle{display:flex;justify-content:space-between;align-items:center;width:100%;border:1px solid #e1e1e1;background:#fff;border-radius:8px;height:46px;padding:0 12px;font:inherit;cursor:pointer}
            .gkts-dd-current{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
            .gkts-dd-caret{opacity:.7;margin-left:8px}
            .gkts-dd-menu{display:none;position:absolute;left:0;top:calc(100% + 6px);width:100%;border:1px solid #e1e1e1;border-radius:8px;background:#fff;max-height:260px;overflow:auto;box-shadow:0 18px 40px rgba(0,0,0,.12);z-index:999}
            .gkts-dd-item{padding:10px 12px;cursor:pointer}
            .gkts-dd-item:hover{background:#f6f6f6}
            .gkts-dd-muted{color:#808080;padding:10px 12px}
        </style>
        <?php
    }

    public function ajax_frontend_lists(): void {
        check_ajax_referer( self::AJAX_NONCE, 'nonce' );

        $target_product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        if ( ! $target_product_id || ! $this->get_product_config( $target_product_id ) ) {
            wp_send_json_error( [ 'message' => 'Ungültiges Travel-Set-Produkt.' ], 400 );
        }

        $sets  = isset( $_POST['sets'] ) && is_array( $_POST['sets'] ) ? wp_unslash( $_POST['sets'] ) : [];
        $lists = [];
        foreach ( $sets as $set ) {
            $category_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) $set ) ) ) );
            $lists[] = $this->get_products_by_categories( $category_ids, $target_product_id );
        }
        wp_send_json_success( [ 'lists' => $lists ] );
    }

    private function get_products_by_categories( array $category_ids, int $exclude_product_id ): array {
        if ( ! $category_ids ) {
            return [];
        }

        $cache_key = 'gkts_products_' . md5( implode( ',', $category_ids ) . '|' . $exclude_product_id );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $q = new WP_Query( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'post__not_in'   => $exclude_product_id ? [ $exclude_product_id ] : [],
            'tax_query'      => [
                [
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => $category_ids,
                    'operator'         => 'IN',
                    'include_children' => true,
                ],
            ],
        ] );

        $items = [];
        foreach ( (array) $q->posts as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_purchasable() ) {
                continue;
            }
            $items[] = [
                'id'   => (int) $product_id,
                'text' => html_entity_decode( wp_strip_all_tags( $product->get_name() ) ),
            ];
        }
        set_transient( $cache_key, $items, HOUR_IN_SECONDS );
        return $items;
    }

    private function rule_for_variation( int $product_id, int $variation_id ): ?array {
        if ( ! $product_id || ! $variation_id ) {
            return null;
        }
        $config = $this->get_product_config( $product_id );
        if ( ! $config ) {
            return null;
        }
        foreach ( (array) $config['rules'] as $rule ) {
            if ( ! empty( $rule['enabled'] ) && absint( $rule['variation_id'] ?? 0 ) === $variation_id ) {
                return $rule;
            }
        }
        return null;
    }

    private function expanded_slots_for_rule( array $rule ): array {
        $slots = [];
        foreach ( (array) ( $rule['groups'] ?? [] ) as $group ) {
            $count = max( 1, absint( $group['count'] ?? 1 ) );
            $label = sanitize_text_field( $group['label'] ?? '' );
            $cats  = array_values( array_filter( array_unique( array_map( 'absint', (array) ( $group['category_ids'] ?? [] ) ) ) ) );
            for ( $i = 1; $i <= $count; $i++ ) {
                $slots[] = [
                    'label'        => $label,
                    'category_ids' => $cats,
                ];
            }
        }
        return $slots;
    }

    public function validate( $passed, $product_id, $quantity, $variation_id = 0, $variations = [], $cart_item_data = [] ) {
        $product_id = absint( $product_id );
        if ( ! $this->get_product_config( $product_id ) ) {
            return $passed;
        }

        $variation_id = absint( $variation_id ?: ( $_POST['variation_id'] ?? 0 ) );
        $rule = $this->rule_for_variation( $product_id, $variation_id );
        if ( ! $rule ) {
            wc_add_notice( 'Für die gewählte Variante ist noch keine Travel-Set-Regel hinterlegt.', 'error' );
            return false;
        }

        $slots    = $this->expanded_slots_for_rule( $rule );
        $selected = isset( $_POST['gk_parfum'] ) && is_array( $_POST['gk_parfum'] ) ? array_map( 'absint', wp_unslash( $_POST['gk_parfum'] ) ) : [];

        if ( count( $selected ) !== count( $slots ) ) {
            wc_add_notice( sprintf( 'Bitte wähle alle %d Parfums aus.', count( $slots ) ), 'error' );
            return false;
        }

        foreach ( $slots as $index => $slot ) {
            $selected_id = absint( $selected[ $index ] ?? 0 );
            if ( ! $selected_id ) {
                wc_add_notice( sprintf( 'Bitte wähle Parfum %d.', $index + 1 ), 'error' );
                return false;
            }
            $selected_product = wc_get_product( $selected_id );
            if ( ! $selected_product || ! $selected_product->is_purchasable() ) {
                wc_add_notice( sprintf( 'Die Auswahl bei Parfum %d ist nicht verfügbar.', $index + 1 ), 'error' );
                return false;
            }
            if ( ! $this->product_matches_categories( $selected_id, $slot['category_ids'] ) ) {
                wc_add_notice( sprintf( 'Parfum %d passt nicht zur vorgesehenen Kategorie.', $index + 1 ), 'error' );
                return false;
            }
        }

        return $passed;
    }

    private function product_matches_categories( int $product_id, array $allowed_category_ids ): bool {
        if ( ! $allowed_category_ids ) {
            return false;
        }
        $term_ids = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( is_wp_error( $term_ids ) ) {
            return false;
        }
        $expanded = array_map( 'intval', (array) $term_ids );
        foreach ( (array) $term_ids as $term_id ) {
            $expanded = array_merge( $expanded, array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
        }
        return (bool) array_intersect( array_unique( $expanded ), array_map( 'intval', $allowed_category_ids ) );
    }

    public function attach( $cart_item_data, $product_id, $variation_id, $quantity = 1 ) {
        $product_id = absint( $product_id );
        if ( ! $this->get_product_config( $product_id ) ) {
            return $cart_item_data;
        }

        $rule = $this->rule_for_variation( $product_id, absint( $variation_id ) );
        if ( ! $rule ) {
            return $cart_item_data;
        }

        $slots    = $this->expanded_slots_for_rule( $rule );
        $selected = isset( $_POST['gk_parfum'] ) && is_array( $_POST['gk_parfum'] ) ? array_map( 'absint', wp_unslash( $_POST['gk_parfum'] ) ) : [];
        $choices  = [];
        foreach ( $slots as $index => $slot ) {
            $product_choice_id = absint( $selected[ $index ] ?? 0 );
            if ( $product_choice_id ) {
                $choices[] = [
                    'product_id' => $product_choice_id,
                    'label'      => $slot['label'],
                ];
            }
        }

        if ( $choices ) {
            $cart_item_data['gk_travelset'] = [
                'product_id'   => $product_id,
                'rule_name'    => sanitize_text_field( $rule['name'] ?? '' ),
                'variation_id' => absint( $variation_id ),
                'choices'      => $choices,
            ];
            $cart_item_data['unique_key'] = md5( microtime( true ) . wp_json_encode( $cart_item_data['gk_travelset'] ) );
        }
        return $cart_item_data;
    }

    public function display( $item_data, $cart_item ) {
        if ( empty( $cart_item['gk_travelset'] ) ) {
            return $item_data;
        }

        $data = $cart_item['gk_travelset'];
        if ( is_array( $data ) && isset( $data['choices'] ) ) {
            if ( ! empty( $data['rule_name'] ) ) {
                $item_data[] = [ 'name' => 'Travel-Set', 'value' => esc_html( $data['rule_name'] ) ];
            }
            $names = [];
            foreach ( (array) $data['choices'] as $choice ) {
                $p = wc_get_product( absint( $choice['product_id'] ?? 0 ) );
                if ( $p ) {
                    $names[] = $p->get_name();
                }
            }
            if ( $names ) {
                $item_data[] = [ 'name' => __( 'Auswahl', 'gk-travelset' ), 'value' => esc_html( implode( ', ', $names ) ) ];
            }
            return $item_data;
        }

        // Rückwärtskompatibilität mit v2.x.
        $names = [];
        foreach ( (array) $data as $pid ) {
            $p = wc_get_product( absint( $pid ) );
            if ( $p ) {
                $names[] = $p->get_name();
            }
        }
        if ( $names ) {
            $item_data[] = [ 'name' => __( 'Auswahl', 'gk-travelset' ), 'value' => esc_html( implode( ', ', $names ) ) ];
        }
        return $item_data;
    }

    public function order_item_meta( $item, $cart_item_key, $values, $order ): void {
        if ( empty( $values['gk_travelset'] ) ) {
            return;
        }

        $data = $values['gk_travelset'];
        if ( is_array( $data ) && isset( $data['choices'] ) ) {
            if ( ! empty( $data['rule_name'] ) ) {
                $item->add_meta_data( 'Travel-Set', sanitize_text_field( $data['rule_name'] ), true );
            }
            $names = [];
            $ids   = [];
            foreach ( (array) $data['choices'] as $choice ) {
                $pid = absint( $choice['product_id'] ?? 0 );
                $p   = wc_get_product( $pid );
                if ( $p ) {
                    $names[] = $p->get_name();
                    $ids[]   = $pid;
                }
            }
            if ( $names ) {
                $item->add_meta_data( __( 'Auswahl', 'gk-travelset' ), implode( ', ', $names ), true );
                $item->add_meta_data( '_gk_travelset_ids', implode( ',', $ids ), true );
            }
            return;
        }

        $names = [];
        $ids   = [];
        foreach ( (array) $data as $pid ) {
            $p = wc_get_product( absint( $pid ) );
            if ( $p ) {
                $names[] = $p->get_name();
                $ids[]   = absint( $pid );
            }
        }
        if ( $names ) {
            $item->add_meta_data( __( 'Auswahl', 'gk-travelset' ), implode( ', ', $names ), true );
            $item->add_meta_data( '_gk_travelset_ids', implode( ',', $ids ), true );
        }
    }
}

new GK_TravelSet_Rule_Builder();