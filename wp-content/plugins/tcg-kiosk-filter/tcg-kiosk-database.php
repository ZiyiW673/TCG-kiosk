<?php
/**
 * Database helper that reads card definitions from bundled JSON files.
 */

if ( ! class_exists( 'TCG_Kiosk_Database' ) ) {
    class TCG_Kiosk_Database {
        /**
         * Absolute path to the database folder.
         *
         * @var string
         */
        protected $database_path;

        /**
         * Cache of parsed data.
         *
         * @var array|null
         */
        protected $cache = null;

        /**
         * Cache of set metadata grouped by game slug.
         *
         * @var array
         */
        protected $set_cache = array();

        /**
         * Cached lookup of card identifiers that exist as WooCommerce products.
         *
         * @var array|null
         */
        protected $product_card_lookup = null;

        /**
         * Whether the WooCommerce product lookup has been initialised.
         *
         * @var bool
         */
        protected $product_card_lookup_ready = false;

        /**
         * TCG_Kiosk_Database constructor.
         *
         * @param string $database_path Absolute path to the database directory.
         */
        public function __construct( $database_path ) {
            $path                = trailingslashit( $database_path );
            $this->database_path = is_dir( $path ) ? $path : '';
        }

        /**
         * Load and return TCG data grouped by game.
         *
         * @return array
         */
        public function get_tcg_data() {
            if ( null !== $this->cache ) {
                return $this->cache;
            }

            $data = array(
                'cards'        => array(),
                'lastModified' => $this->get_last_modified_timestamp(),
            );

            $directories = $this->database_path ? glob( $this->database_path . '*', GLOB_ONLYDIR ) : array();

            if ( empty( $directories ) ) {
                $this->cache = $data;
                return $data;
            }

            foreach ( $directories as $directory ) {
                $type_slug = basename( $directory );
                $config    = $this->get_type_filter_config( $type_slug );
                $cards     = $this->collect_cards_from_directory( $directory, $config, $type_slug );

                if ( empty( $cards ) ) {
                    continue;
                }

                $context = $this->get_set_context( $type_slug, $directory );

                $data['cards'][] = array(
                    'slug'                   => $type_slug,
                    'label'                  => $this->humanize_label( $type_slug ),
                    'typeLabel'              => $config['label'],
                    'typeOptions'            => $config['options'],
                    'typeIncludeAllOption'   => array_key_exists( 'include_all_option', $config ) ? (bool) $config['include_all_option'] : true,
                    'typeMatchMode'          => $config['match_mode'],
                    'typeCaseInsensitive'    => $config['case_insensitive'],
                    'overlayImage'           => $this->get_overlay_image_url( $type_slug ),
                    'setOrder'               => isset( $context['order'] ) && is_array( $context['order'] ) ? array_values( $context['order'] ) : array(),
                    'cards'                  => $cards,
                );
            }

            $this->cache = $data;

            return $data;
        }

        /**
         * Collect all cards for a given directory.
         *
         * @param string $directory Directory containing the card JSON files.
         *
         * @return array
         */
        protected function collect_cards_from_directory( $directory, array $config, $type_slug ) {
            $cards_directory = trailingslashit( $directory ) . 'cards';

            if ( ! is_dir( $cards_directory ) ) {
                return array();
            }

            $cards       = array();
            $game        = $this->humanize_label( $type_slug );
            $set_context = $this->get_set_context( $type_slug, $directory );
            $allowed_sets = isset( $set_context['allowed'] ) ? $set_context['allowed'] : null;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $cards_directory,
                    FilesystemIterator::SKIP_DOTS
                )
            );

            foreach ( $iterator as $file ) {
                if ( 'json' !== strtolower( $file->getExtension() ) ) {
                    continue;
                }

                $set_basename = $file->getBasename( '.json' );
                $set_id       = $this->to_lower( $set_basename );

                if ( is_array( $allowed_sets ) ) {
                    if ( empty( $allowed_sets ) || ! in_array( $set_id, $allowed_sets, true ) ) {
                        continue;
                    }
                }

                $json = file_get_contents( $file->getPathname() );

                if ( false === $json ) {
                    continue;
                }

                $decoded = json_decode( $json, true );

                if ( empty( $decoded ) || ! is_array( $decoded ) ) {
                    continue;
                }

                $set_name = $this->resolve_set_label( $type_slug, $set_id, $set_basename );

                foreach ( $decoded as $card ) {
                    if ( empty( $card['images'] ) || ! is_array( $card['images'] ) ) {
                        continue;
                    }

                    $product_matches = $this->get_card_product_matches( $card, $type_slug );

                    if ( ! $this->should_include_card( $card, $type_slug, $product_matches ) ) {
                        continue;
                    }

                    $image_sources = $this->prepare_image_sources( $card['images'] );

                    if ( empty( $image_sources['primary'] ) ) {
                        continue;
                    }

                    $cards[] = array(
                        'id'           => isset( $card['id'] ) ? (string) $card['id'] : '',
                        'name'         => isset( $card['name'] ) ? (string) $card['name'] : '',
                        'game'         => $game,
                        'set'          => $set_name,
                        'imageUrl'     => $image_sources['primary'],
                        'imageFullUrl' => $image_sources['full'],
                        'imageSrcset'  => $image_sources['srcset'],
                        'imageSizes'   => $image_sources['sizes'],
                        'typeValues'   => $this->extract_type_values( $card, $config ),
                        'details'      => $this->prepare_card_details( $card, $set_name, $game, $type_slug ),
                        'products'     => is_array( $product_matches ) ? array_values( $product_matches ) : array(),
                    );
                }
            }

            return $cards;
        }

        /**
         * Determine whether the given card should be included based on WooCommerce products.
         *
         * @param array  $card      Raw card payload.
         * @param string $type_slug Current game slug.
         *
         * @return bool
         */
        protected function should_include_card( array $card, $type_slug, $matches = null ) {
            $lookup = $this->get_product_card_lookup();

            if ( null === $lookup ) {
                return true;
            }

            if ( empty( $lookup ) ) {
                return apply_filters( 'tcg_kiosk_should_include_card', false, $card, $type_slug, $lookup, array() );
            }

            if ( null === $matches ) {
                $matches = $this->get_card_product_matches( $card, $type_slug );
            }

            if ( ! is_array( $matches ) ) {
                $matches = array();
            }

            $include = ! empty( $matches );

            return apply_filters( 'tcg_kiosk_should_include_card', $include, $card, $type_slug, $lookup, $matches );
        }

        /**
         * Retrieve the WooCommerce product matches for a given card.
         *
         * @param array  $card      Raw card payload.
         * @param string $type_slug Current game slug.
         *
         * @return array|null
         */
        protected function get_card_product_matches( array $card, $type_slug ) {
            $lookup = $this->get_product_card_lookup();

            if ( null === $lookup ) {
                return null;
            }

            if ( empty( $lookup ) ) {
                return array();
            }

            $candidates = $this->get_card_identifier_candidates( $card, $type_slug );

            if ( empty( $candidates ) ) {
                return array();
            }

            $matches = array();
            $seen    = array();

            foreach ( $candidates as $candidate ) {
                $normalized = $this->normalize_card_identifier( $candidate );

                if ( '' === $normalized || ! isset( $lookup[ $normalized ] ) || empty( $lookup[ $normalized ] ) ) {
                    continue;
                }

                foreach ( $lookup[ $normalized ] as $entry ) {
                    if ( empty( $entry ) || ! is_array( $entry ) ) {
                        continue;
                    }

                    if ( ! $this->entry_matches_game_category( $entry, $type_slug ) ) {
                        continue;
                    }

                    $product_id   = isset( $entry['productId'] ) ? (int) $entry['productId'] : 0;
                    $variation_id = isset( $entry['variationId'] ) ? (int) $entry['variationId'] : 0;
                    $unique_key   = $product_id . '|' . $variation_id;

                    if ( isset( $seen[ $unique_key ] ) ) {
                        $index = $seen[ $unique_key ];

                        if ( isset( $matches[ $index ]['matchedIdentifiers'] ) && is_array( $matches[ $index ]['matchedIdentifiers'] ) ) {
                            $matches[ $index ]['matchedIdentifiers'][] = $candidate;
                            $matches[ $index ]['matchedIdentifiers']   = array_values( array_unique( array_filter( $matches[ $index ]['matchedIdentifiers'] ) ) );
                        }

                        continue;
                    }

                    $entry['matchedIdentifiers'] = isset( $entry['matchedIdentifiers'] ) && is_array( $entry['matchedIdentifiers'] )
                        ? array_values( array_unique( array_filter( $entry['matchedIdentifiers'] ) ) )
                        : array();

                    $entry['matchedIdentifiers'][] = $candidate;
                    $entry['matchedIdentifiers']   = array_values( array_unique( array_filter( $entry['matchedIdentifiers'] ) ) );

                    $matches[]           = $entry;
                    $seen[ $unique_key ] = count( $matches ) - 1;
                }
            }

            return apply_filters( 'tcg_kiosk_card_product_matches', $matches, $card, $type_slug, $lookup, $candidates );
        }

        /**
         * Retrieve a lookup of product-backed card identifiers.
         *
         * @return array|null
         */
        protected function get_product_card_lookup() {
            if ( $this->product_card_lookup_ready ) {
                return $this->product_card_lookup;
            }

            $this->product_card_lookup       = $this->build_product_card_lookup();
            $this->product_card_lookup_ready = true;

            return $this->product_card_lookup;
        }

        /**
         * Build the WooCommerce-backed lookup of card identifiers.
         *
         * @return array|null
         */
        protected function build_product_card_lookup() {
            if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_products' ) ) {
                return null;
            }

            $query_args = apply_filters(
                'tcg_kiosk_product_query_args',
                array(
                    'limit'  => -1,
                    'return' => 'ids',
                    'status' => array( 'publish' ),
                    'type'   => array( 'simple', 'variable', 'grouped', 'external' ),
                )
            );

            $product_ids = wc_get_products( $query_args );

            if ( empty( $product_ids ) ) {
                return array();
            }

            $records = array();

            foreach ( $product_ids as $product_id ) {
                $product_records = $this->extract_identifiers_from_product( $product_id );

                if ( empty( $product_records ) ) {
                    continue;
                }

                $records = array_merge( $records, $product_records );
            }

            if ( empty( $records ) ) {
                return array();
            }

            $identifiers = array();

            foreach ( $records as $record ) {
                if ( isset( $record['identifier'] ) ) {
                    $identifiers[] = $record['identifier'];
                }
            }

            $identifiers = apply_filters( 'tcg_kiosk_product_card_identifiers', $identifiers, $product_ids, $records );

            $lookup = array();
            $seen   = array();

            foreach ( $records as $record ) {
                if ( empty( $record['identifier'] ) || empty( $record['data'] ) || ! is_array( $record['data'] ) ) {
                    continue;
                }

                $normalized = $this->normalize_card_identifier( $record['identifier'] );

                if ( '' === $normalized ) {
                    continue;
                }

                $product_id   = isset( $record['data']['productId'] ) ? (int) $record['data']['productId'] : ( isset( $record['product_id'] ) ? (int) $record['product_id'] : 0 );
                $variation_id = isset( $record['data']['variationId'] ) ? (int) $record['data']['variationId'] : ( isset( $record['variation_id'] ) ? (int) $record['variation_id'] : 0 );

                if ( ! isset( $record['data']['productId'] ) ) {
                    $record['data']['productId'] = $product_id;
                }

                if ( ! isset( $record['data']['variationId'] ) ) {
                    $record['data']['variationId'] = $variation_id;
                }

                if ( ! $product_id ) {
                    continue;
                }

                if ( ! isset( $lookup[ $normalized ] ) ) {
                    $lookup[ $normalized ] = array();
                    $seen[ $normalized ]   = array();
                }

                $unique_key = $product_id . '|' . $variation_id;

                if ( isset( $seen[ $normalized ][ $unique_key ] ) ) {
                    $index = $seen[ $normalized ][ $unique_key ];

                    if ( isset( $lookup[ $normalized ][ $index ]['matchedIdentifiers'] ) && is_array( $lookup[ $normalized ][ $index ]['matchedIdentifiers'] ) ) {
                        $lookup[ $normalized ][ $index ]['matchedIdentifiers'][] = $record['identifier'];
                        $lookup[ $normalized ][ $index ]['matchedIdentifiers']   = array_values( array_unique( array_filter( $lookup[ $normalized ][ $index ]['matchedIdentifiers'] ) ) );
                    }

                    continue;
                }

                $record['data']['matchedIdentifiers'] = array( $record['identifier'] );

                $lookup[ $normalized ][]             = $record['data'];
                $seen[ $normalized ][ $unique_key ] = count( $lookup[ $normalized ] ) - 1;
            }

            return apply_filters( 'tcg_kiosk_product_card_lookup', $lookup, $identifiers, $product_ids, $records );
        }

        /**
         * Extract candidate identifiers from a WooCommerce product.
         *
         * @param int $product_id Product ID.
         *
         * @return array
         */
        protected function extract_identifiers_from_product( $product_id ) {
            $records     = array();
            $identifiers = array( $product_id );
            $meta_keys   = apply_filters(
                'tcg_kiosk_product_card_meta_keys',
                array(
                    '_tcg_card_id',
                    '_tcg_card_ids',
                    'tcg_card_id',
                    'tcg_card_ids',
                ),
                $product_id
            );

            foreach ( $meta_keys as $meta_key ) {
                $value = get_post_meta( $product_id, $meta_key, true );

                if ( empty( $value ) && '0' !== $value ) {
                    continue;
                }

                $identifiers = array_merge( $identifiers, $this->extract_identifier_values( $value ) );
            }

            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

            if ( $product ) {
                $sku = $product->get_sku();

                if ( $sku ) {
                    $identifiers[] = $sku;
                }

                $payload = $this->format_product_payload( $product );
            } else {
                $payload = $this->format_basic_product_payload( $product_id );
            }

            $identifiers = array_values( array_unique( array_filter( array_map( array( $this, 'sanitize_identifier_value' ), $identifiers ) ) ) );

            foreach ( $identifiers as $identifier ) {
                $records[] = array(
                    'identifier'   => $identifier,
                    'product_id'   => (int) $product_id,
                    'variation_id' => 0,
                    'data'         => $payload,
                );
            }

            if ( $product && $product->is_type( 'variable' ) ) {
                foreach ( $product->get_children() as $variation_id ) {
                    $variation_records = $this->extract_identifiers_from_variation( $variation_id, $product );

                    if ( ! empty( $variation_records ) ) {
                        $records = array_merge( $records, $variation_records );
                    }
                }
            }

            return $records;
        }

        /**
         * Extract candidate identifiers from a product variation.
         *
         * @param int $variation_id Variation post ID.
         *
         * @return array
         */
        protected function extract_identifiers_from_variation( $variation_id, $parent_product = null ) {
            $records     = array();
            $identifiers = array( $variation_id );
            $meta_keys   = apply_filters(
                'tcg_kiosk_variation_card_meta_keys',
                array(
                    '_tcg_card_id',
                    '_tcg_card_ids',
                    'tcg_card_id',
                    'tcg_card_ids',
                ),
                $variation_id
            );

            foreach ( $meta_keys as $meta_key ) {
                $value = get_post_meta( $variation_id, $meta_key, true );

                if ( empty( $value ) && '0' !== $value ) {
                    continue;
                }

                $identifiers = array_merge( $identifiers, $this->extract_identifier_values( $value ) );
            }

            $variation = function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ) : null;

            if ( ! $parent_product && $variation && method_exists( $variation, 'get_parent_id' ) ) {
                $parent_id = $variation->get_parent_id();

                if ( $parent_id && function_exists( 'wc_get_product' ) ) {
                    $parent_product = wc_get_product( $parent_id );
                }
            }

            $parent_identifiers = array();

            if ( $parent_product ) {
                $parent_id = (int) $parent_product->get_id();

                if ( $parent_id ) {
                    $parent_identifiers[] = $parent_id;
                }

                $parent_sku = $parent_product->get_sku();

                if ( $parent_sku ) {
                    $parent_identifiers[] = $parent_sku;
                }

                $parent_meta_keys = apply_filters(
                    'tcg_kiosk_product_card_meta_keys',
                    array(
                        '_tcg_card_id',
                        '_tcg_card_ids',
                        'tcg_card_id',
                        'tcg_card_ids',
                    ),
                    $parent_id
                );

                foreach ( $parent_meta_keys as $meta_key ) {
                    $value = get_post_meta( $parent_id, $meta_key, true );

                    if ( empty( $value ) && '0' !== $value ) {
                        continue;
                    }

                    $parent_identifiers = array_merge( $parent_identifiers, $this->extract_identifier_values( $value ) );
                }
            }

            if ( $variation ) {
                $sku = $variation->get_sku();

                if ( $sku ) {
                    $identifiers[] = $sku;
                }

                $payload = $this->format_variation_payload( $variation, $parent_product );
            } else {
                $payload = $this->format_basic_variation_payload( $variation_id, $parent_product );
            }

            if ( ! empty( $parent_identifiers ) ) {
                $identifiers = array_merge( $identifiers, $parent_identifiers );
            }

            $identifiers = array_values( array_unique( array_filter( array_map( array( $this, 'sanitize_identifier_value' ), $identifiers ) ) ) );

            $product_id = isset( $payload['productId'] ) ? (int) $payload['productId'] : ( $parent_product ? (int) $parent_product->get_id() : 0 );

            foreach ( $identifiers as $identifier ) {
                $records[] = array(
                    'identifier'   => $identifier,
                    'product_id'   => $product_id,
                    'variation_id' => (int) $variation_id,
                    'data'         => $payload,
                );
            }

            return $records;
        }

        /**
         * Flatten identifier values retrieved from post meta.
         *
         * @param mixed $value Raw value stored in post meta.
         *
         * @return array
         */
        protected function extract_identifier_values( $value ) {
            if ( empty( $value ) && '0' !== $value ) {
                return array();
            }

            if ( is_array( $value ) ) {
                $values = array();

                foreach ( $value as $entry ) {
                    $values = array_merge( $values, $this->extract_identifier_values( $entry ) );
                }

                return $values;
            }

            if ( is_object( $value ) ) {
                return $this->extract_identifier_values( (array) $value );
            }

            $string = trim( (string) $value );

            if ( '' === $string ) {
                return array();
            }

            $parts = preg_split( '/[\r\n,]+/', $string );

            if ( false === $parts ) {
                $parts = array( $string );
            }

            $results = array();

            foreach ( $parts as $part ) {
                $part = trim( $part );

                if ( '' === $part ) {
                    continue;
                }

                $results[] = $part;
            }

            return $results;
        }

        /**
         * Prepare a product payload for the front-end consumer.
         *
         * @param WC_Product $product Product instance.
         *
         * @return array
         */
        protected function format_product_payload( $product ) {
            $product_id = $product ? (int) $product->get_id() : 0;

            return array(
                'productId'        => $product_id,
                'variationId'      => 0,
                'parentId'         => $product_id,
                'type'             => $this->sanitize_text_value( $product ? $product->get_type() : 'simple' ),
                'name'             => $this->sanitize_text_value( $product ? $product->get_name() : '' ),
                'sku'              => $this->sanitize_text_value( $product ? $product->get_sku() : '' ),
                'priceHtml'        => $this->prepare_price_html( $product ? $product->get_price_html() : '' ),
                'price'            => $this->prepare_price_value( $product ? $product->get_price() : '' ),
                'regularPrice'     => $this->prepare_price_value( $product ? $product->get_regular_price() : '' ),
                'salePrice'        => $this->prepare_price_value( $product ? $product->get_sale_price() : '' ),
                'currencySymbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
                'isPurchasable'    => $product ? (bool) $product->is_purchasable() : false,
                'isInStock'        => $product ? (bool) $product->is_in_stock() : false,
                'stockStatus'      => $this->sanitize_text_value( $product ? $product->get_stock_status() : '' ),
                'stockQuantity'    => $product && null !== $product->get_stock_quantity() ? (int) $product->get_stock_quantity() : null,
                'backordersAllowed'=> $product ? (bool) $product->backorders_allowed() : false,
                'permalink'        => $this->sanitize_url_value( $product ? $product->get_permalink() : '' ),
                'productCategories'=> $this->get_product_category_slugs( $product_id, $product ),
                'productCategoryNames' => $this->get_product_category_names( $product_id, $product ),
                'attributes'       => array(),
                'attributeSummary' => '',
            );
        }

        /**
         * Prepare a fallback payload when a product instance is unavailable.
         *
         * @param int $product_id Product ID.
         *
         * @return array
         */
        protected function format_basic_product_payload( $product_id ) {
            $name      = function_exists( 'get_the_title' ) ? get_the_title( $product_id ) : '';
            $permalink = function_exists( 'get_permalink' ) ? get_permalink( $product_id ) : '';

            return array(
                'productId'        => (int) $product_id,
                'variationId'      => 0,
                'parentId'         => (int) $product_id,
                'type'             => 'simple',
                'name'             => $this->sanitize_text_value( $name ),
                'sku'              => '',
                'priceHtml'        => '',
                'price'            => '',
                'regularPrice'     => '',
                'salePrice'        => '',
                'currencySymbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
                'isPurchasable'    => false,
                'isInStock'        => false,
                'stockStatus'      => '',
                'stockQuantity'    => null,
                'backordersAllowed'=> false,
                'permalink'        => $this->sanitize_url_value( $permalink ),
                'productCategories'=> $this->get_product_category_slugs( $product_id ),
                'productCategoryNames' => $this->get_product_category_names( $product_id ),
                'attributes'       => array(),
                'attributeSummary' => '',
            );
        }

        /**
         * Prepare a variation payload for the front-end consumer.
         *
         * @param WC_Product_Variation $variation      Variation instance.
         * @param WC_Product           $parent_product Parent product instance.
         *
         * @return array
         */
        protected function format_variation_payload( $variation, $parent_product = null ) {
            $parent_id         = $variation ? (int) $variation->get_parent_id() : ( $parent_product ? (int) $parent_product->get_id() : 0 );
            $attribute_summary = '';

            if ( $variation ) {
                if ( method_exists( $variation, 'get_attribute_summary' ) ) {
                    $attribute_summary = $this->sanitize_text_value( $variation->get_attribute_summary() );
                }

                if ( '' === $attribute_summary && function_exists( 'wc_get_formatted_variation' ) ) {
                    $formatted = wc_get_formatted_variation( $variation, true );

                    if ( is_array( $formatted ) ) {
                        $formatted = implode( ', ', $formatted );
                    }

                    if ( is_string( $formatted ) ) {
                        $attribute_summary = $this->sanitize_text_value( $formatted );
                    }
                }
            }

            return array(
                'productId'        => $parent_id,
                'variationId'      => $variation ? (int) $variation->get_id() : 0,
                'parentId'         => $parent_id,
                'type'             => 'variation',
                'name'             => $this->sanitize_text_value( $variation ? $variation->get_name() : '' ),
                'sku'              => $this->sanitize_text_value( $variation ? $variation->get_sku() : '' ),
                'priceHtml'        => $this->prepare_price_html( $variation ? $variation->get_price_html() : '' ),
                'price'            => $this->prepare_price_value( $variation ? $variation->get_price() : '' ),
                'regularPrice'     => $this->prepare_price_value( $variation ? $variation->get_regular_price() : '' ),
                'salePrice'        => $this->prepare_price_value( $variation ? $variation->get_sale_price() : '' ),
                'currencySymbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
                'isPurchasable'    => $variation ? (bool) $variation->is_purchasable() : false,
                'isInStock'        => $variation ? (bool) $variation->is_in_stock() : false,
                'stockStatus'      => $this->sanitize_text_value( $variation ? $variation->get_stock_status() : '' ),
                'stockQuantity'    => $variation && null !== $variation->get_stock_quantity() ? (int) $variation->get_stock_quantity() : null,
                'backordersAllowed'=> $variation ? (bool) $variation->backorders_allowed() : false,
                'permalink'        => $this->sanitize_url_value(
                    $variation ? $variation->get_permalink() : ( $parent_product ? $parent_product->get_permalink() : '' )
                ),
                'productCategories'=> $this->get_product_category_slugs( $parent_id, $parent_product ),
                'productCategoryNames' => $this->get_product_category_names( $parent_id, $parent_product ),
                'attributes'       => $variation ? $this->prepare_variation_attributes( $variation->get_attributes() ) : array(),
                'attributeSummary' => $attribute_summary,
            );
        }

        /**
         * Prepare a fallback payload when a variation instance is unavailable.
         *
         * @param int        $variation_id   Variation ID.
         * @param WC_Product $parent_product Optional parent product instance.
         *
         * @return array
         */
        protected function format_basic_variation_payload( $variation_id, $parent_product = null ) {
            $parent_id = $parent_product ? (int) $parent_product->get_id() : 0;

            return array(
                'productId'        => $parent_id,
                'variationId'      => (int) $variation_id,
                'parentId'         => $parent_id,
                'type'             => 'variation',
                'name'             => '',
                'sku'              => '',
                'priceHtml'        => '',
                'price'            => '',
                'regularPrice'     => '',
                'salePrice'        => '',
                'currencySymbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
                'isPurchasable'    => false,
                'isInStock'        => false,
                'stockStatus'      => '',
                'stockQuantity'    => null,
                'backordersAllowed'=> false,
                'permalink'        => $this->sanitize_url_value( $parent_product ? $parent_product->get_permalink() : '' ),
                'productCategories'=> $this->get_product_category_slugs( $parent_id, $parent_product ),
                'productCategoryNames' => $this->get_product_category_names( $parent_id, $parent_product ),
                'attributes'       => array(),
                'attributeSummary' => '',
            );
        }

        /**
         * Retrieve normalized product category slugs for a product.
         *
         * @param int        $product_id Product ID.
         * @param WC_Product $product    Optional product instance.
         *
         * @return array
         */
        protected function get_product_category_slugs( $product_id, $product = null ) {
            $terms = $this->get_product_category_terms( $product_id, $product );

            if ( empty( $terms ) ) {
                return array();
            }

            $slugs = array();

            foreach ( $terms as $term ) {
                if ( ! $term || empty( $term->slug ) ) {
                    continue;
                }

                $slug = sanitize_title( $term->slug );

                if ( '' !== $slug ) {
                    $slugs[] = $slug;
                }
            }

            return array_values( array_unique( $slugs ) );
        }

        /**
         * Retrieve product category display names for a product.
         *
         * @param int        $product_id Product ID.
         * @param WC_Product $product    Optional product instance.
         *
         * @return array
         */
        protected function get_product_category_names( $product_id, $product = null ) {
            $terms = $this->get_product_category_terms( $product_id, $product );

            if ( empty( $terms ) ) {
                return array();
            }

            $names = array();

            foreach ( $terms as $term ) {
                if ( ! $term || empty( $term->name ) ) {
                    continue;
                }

                $name = trim( (string) $term->name );

                if ( '' !== $name ) {
                    $names[] = $name;
                }
            }

            return array_values( array_unique( $names ) );
        }

        /**
         * Retrieve product category terms for a product.
         *
         * @param int        $product_id Product ID.
         * @param WC_Product $product    Optional product instance.
         *
         * @return array
         */
        protected function get_product_category_terms( $product_id, $product = null ) {
            $category_ids = array();

            if ( $product && method_exists( $product, 'get_category_ids' ) ) {
                $category_ids = $product->get_category_ids();
            } elseif ( function_exists( 'wc_get_product' ) ) {
                $loaded = wc_get_product( $product_id );

                if ( $loaded && method_exists( $loaded, 'get_category_ids' ) ) {
                    $category_ids = $loaded->get_category_ids();
                }
            }

            if ( empty( $category_ids ) ) {
                return array();
            }

            $terms = get_terms(
                array(
                    'taxonomy'   => 'product_cat',
                    'include'    => array_map( 'absint', $category_ids ),
                    'hide_empty' => false,
                )
            );

            if ( empty( $terms ) || is_wp_error( $terms ) ) {
                return array();
            }

            return $terms;
        }

        /**
         * Retrieve expected category names for a given game slug.
         *
         * @param string $type_slug Game/type slug.
         *
         * @return array
         */
        protected function get_game_category_names( $type_slug ) {
            $game_key = $this->normalize_game_key( $type_slug );

            if ( '' === $game_key ) {
                return array();
            }

            $map = array(
                'one-piece' => array( 'One Piece TCG' ),
                'gundam'    => array( 'Gundam TCG' ),
                'riftbound' => array( 'Riftbound TCG' ),
                'pokemon'   => array( 'Pokemon TCG' ),
            );

            return isset( $map[ $game_key ] ) ? $map[ $game_key ] : array();
        }

        /**
         * Normalize a game key from a type slug.
         *
         * @param string $type_slug Game/type slug.
         *
         * @return string
         */
        protected function normalize_game_key( $type_slug ) {
            $normalized = sanitize_title( $type_slug );

            if ( '' === $normalized ) {
                return '';
            }

            $known = array( 'one-piece', 'gundam', 'riftbound', 'pokemon' );

            foreach ( $known as $key ) {
                if ( false !== strpos( $normalized, $key ) || 'onepiece' === str_replace( '-', '', $normalized ) ) {
                    return $key;
                }
            }

            return $normalized;
        }

        /**
         * Determine whether a product entry should match the current game slug.
         *
         * @param array  $entry     Product entry payload.
         * @param string $type_slug Game/type slug.
         *
         * @return bool
         */
        protected function entry_matches_game_category( array $entry, $type_slug ) {
            if ( ! $type_slug ) {
                return true;
            }

            $normalized_type = $this->normalize_game_key( $type_slug );

            if ( '' === $normalized_type ) {
                return true;
            }

            $expected_names = $this->get_game_category_names( $type_slug );

            if ( ! empty( $expected_names ) && ! empty( $entry['productCategoryNames'] ) && is_array( $entry['productCategoryNames'] ) ) {
                $expected = array_map( 'sanitize_title', $expected_names );

                foreach ( $entry['productCategoryNames'] as $category_name ) {
                    if ( in_array( sanitize_title( $category_name ), $expected, true ) ) {
                        return true;
                    }
                }
            }

            if ( empty( $entry['productCategories'] ) || ! is_array( $entry['productCategories'] ) ) {
                return false;
            }

            foreach ( $entry['productCategories'] as $category_slug ) {
                if ( $this->normalize_game_key( $category_slug ) === $normalized_type ) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Sanitise the HTML string returned by WooCommerce pricing helpers.
         *
         * @param string $price_html Raw price HTML.
         *
         * @return string
         */
        protected function prepare_price_html( $price_html ) {
            if ( empty( $price_html ) || ! is_string( $price_html ) ) {
                return '';
            }

            if ( function_exists( 'wp_kses_post' ) ) {
                return wp_kses_post( $price_html );
            }

            return $price_html;
        }

        /**
         * Normalise a numeric price value for JSON transport.
         *
         * @param mixed $price Raw price value.
         *
         * @return string
         */
        protected function prepare_price_value( $price ) {
            if ( null === $price || '' === $price ) {
                return '';
            }

            if ( is_numeric( $price ) ) {
                if ( function_exists( 'wc_format_decimal' ) ) {
                    return wc_format_decimal( $price );
                }

                return (string) ( $price + 0 );
            }

            return trim( (string) $price );
        }

        /**
         * Prepare variation attributes for JSON transport.
         *
         * @param array $attributes Raw variation attributes.
         *
         * @return array
         */
        protected function prepare_variation_attributes( $attributes ) {
            if ( empty( $attributes ) || ! is_array( $attributes ) ) {
                return array();
            }

            $prepared = array();

            foreach ( $attributes as $key => $value ) {
                if ( ! is_string( $key ) && ! is_numeric( $key ) ) {
                    continue;
                }

                $attribute_key = trim( (string) $key );

                if ( '' === $attribute_key ) {
                    continue;
                }

                if ( 0 !== strpos( $attribute_key, 'attribute_' ) ) {
                    $normalized_key = ltrim( $attribute_key, '_' );

                    if ( 0 === strpos( $normalized_key, 'attribute_' ) ) {
                        $normalized_key = substr( $normalized_key, strlen( 'attribute_' ) );
                    }

                    if ( '' === $normalized_key ) {
                        continue;
                    }

                    $attribute_key = 'attribute_' . $normalized_key;
                }

                if ( is_array( $value ) ) {
                    $value = reset( $value );
                }

                $attribute_value = is_scalar( $value ) ? (string) $value : '';

                if ( function_exists( 'wc_clean' ) ) {
                    $attribute_value = wc_clean( $attribute_value );
                } elseif ( function_exists( 'sanitize_text_field' ) ) {
                    $attribute_value = sanitize_text_field( $attribute_value );
                } else {
                    $attribute_value = $this->sanitize_text_value( $attribute_value );
                }

                if ( 0 === strpos( $attribute_key, 'attribute_pa_' ) && function_exists( 'sanitize_title' ) ) {
                    $attribute_value = sanitize_title( $attribute_value );
                }

                $prepared[ $attribute_key ] = $attribute_value;
            }

            return $prepared;
        }

        /**
         * Sanitise a text value for inclusion in the JSON payload.
         *
         * @param mixed $value Raw value.
         *
         * @return string
         */
        protected function sanitize_text_value( $value ) {
            if ( null === $value ) {
                return '';
            }

            $string = (string) $value;

            if ( function_exists( 'wp_strip_all_tags' ) ) {
                $string = wp_strip_all_tags( $string );
            } else {
                $string = strip_tags( $string );
            }

            return trim( $string );
        }

        /**
         * Sanitise an identifier value before lookup storage.
         *
         * @param mixed $value Raw identifier value.
         *
         * @return string
         */
        protected function sanitize_identifier_value( $value ) {
            if ( is_numeric( $value ) || is_string( $value ) ) {
                $string = trim( (string) $value );

                if ( '' === $string ) {
                    return '';
                }

                return $string;
            }

            return '';
        }

        /**
         * Sanitise a URL value for inclusion in the payload.
         *
         * @param string $url Raw URL.
         *
         * @return string
         */
        protected function sanitize_url_value( $url ) {
            if ( empty( $url ) ) {
                return '';
            }

            $string = (string) $url;

            if ( function_exists( 'esc_url_raw' ) ) {
                return esc_url_raw( $string );
            }

            return $string;
        }

        /**
         * Produce the set of identifier candidates for a given card.
         *
         * @param array  $card      Raw card payload.
         * @param string $type_slug Current game slug.
         *
         * @return array
         */
        protected function get_card_identifier_candidates( array $card, $type_slug ) {
            $candidates = array();
            $is_one_piece = false !== strpos( strtolower( (string) $type_slug ), 'one-piece' );
            $raw_id = isset( $card['id'] ) ? (string) $card['id'] : '';
            $is_one_piece_alt = $is_one_piece && $raw_id && preg_match( '/_p1$/i', $raw_id );

            if ( isset( $card['id'] ) ) {
                $candidates[] = $card['id'];
            }

            if ( ! $is_one_piece_alt && isset( $card['number'] ) ) {
                $number = trim( (string) $card['number'] );

                if ( '' !== $number ) {
                    if ( isset( $card['set'] ) && is_array( $card['set'] ) ) {
                        if ( ! empty( $card['set']['id'] ) ) {
                            $set_id = trim( (string) $card['set']['id'] );

                            if ( '' !== $set_id ) {
                                $candidates[] = $set_id . '-' . $number;
                                $candidates[] = $set_id . $number;

                                if ( $is_one_piece ) {
                                    $normalized_set = strtolower( $set_id );
                                    $short_set = preg_replace( '/^op[-_]?/i', '', $normalized_set );
                                    $short_set = $short_set ? $short_set : $normalized_set;

                                    $candidates[] = 'op-' . $normalized_set . '-' . $number;
                                    $candidates[] = 'op-' . $normalized_set . '-' . $number . '-' . $short_set;

                                    if ( $short_set !== $normalized_set ) {
                                        $candidates[] = 'op-' . $short_set . '-' . $number;
                                        $candidates[] = 'op-' . $short_set . '-' . $number . '-' . $short_set;
                                    }
                                }
                            }
                        }

                        if ( ! empty( $card['set']['ptcgoCode'] ) ) {
                            $ptcgo = trim( (string) $card['set']['ptcgoCode'] );

                            if ( '' !== $ptcgo ) {
                                $candidates[] = $ptcgo . '-' . $number;
                                $candidates[] = $ptcgo . $number;
                            }
                        }
                    }
                }
            }

            if ( $is_one_piece && '' !== $raw_id ) {
                $base_id = preg_replace( '/_p\d+$/i', '', $raw_id );

                if ( $base_id && preg_match( '/^([a-z]+[0-9]+)-?([0-9]+)$/i', $base_id, $matches ) ) {
                    $set_code = strtolower( $matches[1] );
                    $number = $matches[2];
                    $number_trim = ltrim( $number, '0' );
                    $number_trim = '' !== $number_trim ? $number_trim : $number;

                    if ( $is_one_piece_alt ) {
                        $candidates[] = $set_code . '-' . $number . '-p1';
                        $candidates[] = $set_code . $number . 'p1';
                        $candidates[] = 'op-' . $set_code . '-' . $number . '-p1';
                        $candidates[] = 'op-' . $set_code . '-' . $number . '-' . $set_code . '-p1';

                        if ( $number_trim !== $number ) {
                            $candidates[] = $set_code . '-' . $number_trim . '-p1';
                            $candidates[] = $set_code . $number_trim . 'p1';
                            $candidates[] = 'op-' . $set_code . '-' . $number_trim . '-p1';
                            $candidates[] = 'op-' . $set_code . '-' . $number_trim . '-' . $set_code . '-p1';
                        }
                    } else {
                        $candidates[] = $set_code . '-' . $number;
                        $candidates[] = $set_code . $number;
                        $candidates[] = 'op-' . $set_code . '-' . $number;
                        $candidates[] = 'op-' . $set_code . '-' . $number . '-' . $set_code;

                        if ( $number_trim !== $number ) {
                            $candidates[] = $set_code . '-' . $number_trim;
                            $candidates[] = $set_code . $number_trim;
                            $candidates[] = 'op-' . $set_code . '-' . $number_trim;
                            $candidates[] = 'op-' . $set_code . '-' . $number_trim . '-' . $set_code;
                        }
                    }
                }
            }

            $candidates = array_values( array_unique( array_filter( $candidates ) ) );

            return apply_filters( 'tcg_kiosk_card_identifier_candidates', $candidates, $card, $type_slug );
        }

        /**
         * Normalise an identifier for comparison.
         *
         * @param mixed $value Identifier candidate.
         *
         * @return string
         */
        protected function normalize_card_identifier( $value ) {
            if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
                return '';
            }

            $string = trim( (string) $value );

            if ( '' === $string ) {
                return '';
            }

            $string = preg_replace( '/\s+/', '', $string );

            if ( null === $string ) {
                $string = trim( (string) $value );
            }

            return $this->to_lower( $string );
        }

        /**
         * Derive the configuration for the type filter based on the game slug.
         *
         * @param string $type_slug Slug for the game directory.
         *
         * @return array
         */
        protected function get_type_filter_config( $type_slug ) {
            $slug = strtolower( (string) $type_slug );

            if ( false !== strpos( $slug, 'pokemon' ) ) {
                return array(
                    'label'              => __( 'Type', 'tcg-kiosk-filter' ),
                    'field'              => 'types',
                    'options'            => array(
                        array(
                            'value' => 'Colorless',
                            'label' => __( 'Colorless', 'tcg-kiosk-filter' ),
                            'row'   => 'primary',
                        ),
                        array(
                            'value' => 'Darkness',
                            'label' => __( 'Darkness', 'tcg-kiosk-filter' ),
                            'row'   => 'primary',
                        ),
                        array(
                            'value' => 'Dragon',
                            'label' => __( 'Dragon', 'tcg-kiosk-filter' ),
                            'row'   => 'primary',
                        ),
                        array(
                            'value' => 'Fairy',
                            'label' => __( 'Fairy', 'tcg-kiosk-filter' ),
                            'row'   => 'primary',
                        ),
                        array(
                            'value' => 'Fighting',
                            'label' => __( 'Fighting', 'tcg-kiosk-filter' ),
                            'row'   => 'primary',
                        ),
                        array(
                            'value' => 'Fire',
                            'label' => __( 'Fire', 'tcg-kiosk-filter' ),
                            'row'   => 'primary',
                        ),
                        array(
                            'value' => 'Grass',
                            'label' => __( 'Grass', 'tcg-kiosk-filter' ),
                            'row'   => 'primary',
                        ),
                        array(
                            'value' => 'Lightning',
                            'label' => __( 'Lightning', 'tcg-kiosk-filter' ),
                            'row'   => 'primary',
                        ),
                        array(
                            'value' => 'Metal',
                            'label' => __( 'Metal', 'tcg-kiosk-filter' ),
                            'row'   => 'primary',
                        ),
                        array(
                            'value' => 'Psychic',
                            'label' => __( 'Psychic', 'tcg-kiosk-filter' ),
                            'row'   => 'primary',
                        ),
                        array(
                            'value' => 'Water',
                            'label' => __( 'Water', 'tcg-kiosk-filter' ),
                            'row'   => 'primary',
                        ),
                        array(
                            'value' => 'Pokémon Tool',
                            'label' => __( 'Pokémon Tool', 'tcg-kiosk-filter' ),
                            'row'   => 'trainer',
                        ),
                        array(
                            'value' => 'Stadium',
                            'label' => __( 'Stadium', 'tcg-kiosk-filter' ),
                            'row'   => 'trainer',
                        ),
                        array(
                            'value' => 'Supporter',
                            'label' => __( 'Supporter', 'tcg-kiosk-filter' ),
                            'row'   => 'trainer',
                        ),
                        array(
                            'value' => 'Item',
                            'label' => __( 'Item', 'tcg-kiosk-filter' ),
                            'row'   => 'trainer',
                        ),
                    ),
                    'include_all_option' => false,
                    'match_mode'         => 'exact',
                    'case_insensitive'   => false,
                    'trainer_subtypes'   => array(
                        'supporter'    => 'Supporter',
                        'stadium'      => 'Stadium',
                        'pokémon tool' => 'Pokémon Tool',
                        'pokemon tool' => 'Pokémon Tool',
                        'item'         => 'Item',
                    ),
                );
            }

            if ( false !== strpos( $slug, 'one-piece' ) ) {
                return array(
                    'label'              => __( 'Color', 'tcg-kiosk-filter' ),
                    'field'              => array(
                        'color',
                        array(
                            'name' => 'type',
                            'map'  => array(
                                'leader'    => __( 'Leader', 'tcg-kiosk-filter' ),
                                'character' => __( 'Character', 'tcg-kiosk-filter' ),
                                'event'     => __( 'Event', 'tcg-kiosk-filter' ),
                                'stage'     => __( 'Stage', 'tcg-kiosk-filter' ),
                            ),
                        ),
                    ),
                    'options'            => array(
                        array(
                            'value' => 'Black',
                            'label' => __( 'Black', 'tcg-kiosk-filter' ),
                            'row'   => 'colors',
                        ),
                        array(
                            'value' => 'Blue',
                            'label' => __( 'Blue', 'tcg-kiosk-filter' ),
                            'row'   => 'colors',
                        ),
                        array(
                            'value' => 'Green',
                            'label' => __( 'Green', 'tcg-kiosk-filter' ),
                            'row'   => 'colors',
                        ),
                        array(
                            'value' => 'Purple',
                            'label' => __( 'Purple', 'tcg-kiosk-filter' ),
                            'row'   => 'colors',
                        ),
                        array(
                            'value' => 'Red',
                            'label' => __( 'Red', 'tcg-kiosk-filter' ),
                            'row'   => 'colors',
                        ),
                        array(
                            'value' => 'Yellow',
                            'label' => __( 'Yellow', 'tcg-kiosk-filter' ),
                            'row'   => 'colors',
                        ),
                        array(
                            'value' => 'Leader',
                            'label' => __( 'Leader', 'tcg-kiosk-filter' ),
                            'row'   => 'types',
                        ),
                        array(
                            'value' => 'Character',
                            'label' => __( 'Character', 'tcg-kiosk-filter' ),
                            'row'   => 'types',
                        ),
                        array(
                            'value' => 'Event',
                            'label' => __( 'Event', 'tcg-kiosk-filter' ),
                            'row'   => 'types',
                        ),
                        array(
                            'value' => 'Stage',
                            'label' => __( 'Stage', 'tcg-kiosk-filter' ),
                            'row'   => 'types',
                        ),
                    ),
                    'include_all_option' => false,
                    'match_mode'         => 'contains',
                    'case_insensitive'   => true,
                );
            }

            if ( false !== strpos( $slug, 'riftbound' ) ) {
                return array(
                    'label'            => __( 'Domain', 'tcg-kiosk-filter' ),
                    'field'            => 'domain',
                    'options'          => array(
                        array(
                            'value' => 'body',
                            'label' => __( 'Body', 'tcg-kiosk-filter' ),
                        ),
                        array(
                            'value' => 'calm',
                            'label' => __( 'Calm', 'tcg-kiosk-filter' ),
                        ),
                        array(
                            'value' => 'chaos',
                            'label' => __( 'Chaos', 'tcg-kiosk-filter' ),
                        ),
                        array(
                            'value' => 'fury',
                            'label' => __( 'Fury', 'tcg-kiosk-filter' ),
                        ),
                        array(
                            'value' => 'mind',
                            'label' => __( 'Mind', 'tcg-kiosk-filter' ),
                        ),
                        array(
                            'value' => 'order',
                            'label' => __( 'Order', 'tcg-kiosk-filter' ),
                        ),
                        array(
                            'value' => 'none',
                            'label' => __( 'None', 'tcg-kiosk-filter' ),
                        ),
                    ),
                    'match_mode'       => 'contains',
                    'case_insensitive' => true,
                );
            }

            return array(
                'label'            => __( 'Type', 'tcg-kiosk-filter' ),
                'field'            => '',
                'options'          => array(),
                'match_mode'       => 'exact',
                'case_insensitive' => false,
            );
        }

        /**
         * Retrieve the overlay image URL for the given game slug.
         *
         * @param string $type_slug Game directory slug.
         *
         * @return string
         */
        protected function get_overlay_image_url( $type_slug ) {
            $slug = strtolower( (string) $type_slug );

            if ( false !== strpos( $slug, 'pokemon' ) ) {
                return plugins_url( 'assets/overlay/pokemon-card-back.png', __FILE__ );
            }

            if ( false !== strpos( $slug, 'one-piece' ) ) {
                return plugins_url( 'assets/overlay/one-piece-card-back.png', __FILE__ );
            }

            if ( false !== strpos( $slug, 'riftbound' ) || false !== strpos( $slug, 'league-of-legends' ) ) {
                return plugins_url( 'assets/overlay/riftbound-card-back.png', __FILE__ );
            }

            return '';
        }

        /**
         * Extract the relevant type values for a card based on the configuration.
         *
         * @param array $card   Raw card data.
         * @param array $config Type filter configuration.
         *
         * @return array
         */
        protected function extract_type_values( array $card, array $config ) {
            $values      = array();
            $field_specs = array();

            if ( isset( $config['field'] ) && is_array( $config['field'] ) ) {
                foreach ( $config['field'] as $field_entry ) {
                    if ( is_array( $field_entry ) ) {
                        $raw_name = isset( $field_entry['name'] ) ? $field_entry['name'] : '';

                        if ( is_array( $raw_name ) || ( ! is_string( $raw_name ) && ! is_numeric( $raw_name ) ) ) {
                            continue;
                        }

                        $field_name = trim( preg_replace( '/\s+/', ' ', (string) $raw_name ) );

                        if ( '' === $field_name ) {
                            continue;
                        }

                        $map = array();

                        if ( ! empty( $field_entry['map'] ) && is_array( $field_entry['map'] ) ) {
                            foreach ( $field_entry['map'] as $map_key => $map_value ) {
                                $raw_key = is_int( $map_key ) ? $map_value : $map_key;

                                if ( is_array( $raw_key ) || ( ! is_string( $raw_key ) && ! is_numeric( $raw_key ) ) ) {
                                    continue;
                                }

                                $clean_key = trim( preg_replace( '/\s+/', ' ', (string) $raw_key ) );

                                if ( '' === $clean_key ) {
                                    continue;
                                }

                                if ( is_array( $map_value ) ) {
                                    continue;
                                }

                                $clean_value = ( is_string( $map_value ) || is_numeric( $map_value ) )
                                    ? trim( preg_replace( '/\s+/', ' ', (string) $map_value ) )
                                    : '';

                                if ( '' === $clean_value ) {
                                    $clean_value = $clean_key;
                                }

                                $map[ $this->to_lower( $clean_key ) ] = $clean_value;
                            }
                        }

                        $field_specs[] = array(
                            'name' => $field_name,
                            'map'  => $map,
                        );
                    } elseif ( is_string( $field_entry ) || is_numeric( $field_entry ) ) {
                        $field_name = trim( preg_replace( '/\s+/', ' ', (string) $field_entry ) );

                        if ( '' === $field_name ) {
                            continue;
                        }

                        $field_specs[] = array(
                            'name' => $field_name,
                            'map'  => array(),
                        );
                    }
                }
            } elseif ( isset( $config['field'] ) && ( is_string( $config['field'] ) || is_numeric( $config['field'] ) ) ) {
                $field_name = trim( preg_replace( '/\s+/', ' ', (string) $config['field'] ) );

                if ( '' !== $field_name ) {
                    $field_specs[] = array(
                        'name' => $field_name,
                        'map'  => array(),
                    );
                }
            }

            foreach ( $field_specs as $field_spec ) {
                $field_name = $field_spec['name'];

                if ( '' === $field_name || ! array_key_exists( $field_name, $card ) ) {
                    continue;
                }

                $raw_value = $card[ $field_name ];

                if ( is_array( $raw_value ) ) {
                    $candidates = $raw_value;
                } elseif ( null !== $raw_value ) {
                    $candidates = array( $raw_value );
                } else {
                    $candidates = array();
                }

                foreach ( $candidates as $candidate ) {
                    if ( ! is_string( $candidate ) && ! is_numeric( $candidate ) ) {
                        continue;
                    }

                    $clean = trim( preg_replace( '/\s+/', ' ', (string) $candidate ) );

                    if ( '' === $clean ) {
                        continue;
                    }

                    if ( ! empty( $field_spec['map'] ) ) {
                        $lookup = $this->to_lower( $clean );

                        if ( isset( $field_spec['map'][ $lookup ] ) ) {
                            $values[] = $field_spec['map'][ $lookup ];
                            continue;
                        }
                    }

                    $values[] = $clean;
                }
            }

            $normalized = array();

            if ( ! empty( $config['trainer_subtypes'] ) && ! empty( $card['subtypes'] ) && is_array( $card['subtypes'] ) ) {
                $allowed_subtypes = array();

                foreach ( $config['trainer_subtypes'] as $configured_key => $configured_label ) {
                    $raw_key   = is_int( $configured_key ) ? $configured_label : $configured_key;
                    $raw_label = $configured_label;

                    if ( is_array( $raw_key ) || ( ! is_string( $raw_key ) && ! is_numeric( $raw_key ) ) ) {
                        continue;
                    }

                    $clean_key = trim( preg_replace( '/\s+/', ' ', (string) $raw_key ) );

                    if ( '' === $clean_key ) {
                        continue;
                    }

                    if ( is_array( $raw_label ) ) {
                        continue;
                    }

                    $clean_label = ( is_string( $raw_label ) || is_numeric( $raw_label ) )
                        ? trim( preg_replace( '/\s+/', ' ', (string) $raw_label ) )
                        : '';

                    if ( '' === $clean_label ) {
                        $clean_label = $clean_key;
                    }

                    $allowed_subtypes[ $this->to_lower( $clean_key ) ] = $clean_label;
                }

                if ( ! empty( $allowed_subtypes ) ) {
                    foreach ( $card['subtypes'] as $subtype ) {
                        if ( ! is_string( $subtype ) && ! is_numeric( $subtype ) ) {
                            continue;
                        }

                        $clean_subtype = trim( preg_replace( '/\s+/', ' ', (string) $subtype ) );

                        if ( '' === $clean_subtype ) {
                            continue;
                        }

                        $key = $this->to_lower( $clean_subtype );

                        if ( isset( $allowed_subtypes[ $key ] ) ) {
                            $normalized[] = $allowed_subtypes[ $key ];
                        }
                    }
                }
            }

            foreach ( $values as $value ) {
                if ( is_string( $value ) || is_numeric( $value ) ) {
                    $clean = trim( preg_replace( '/\s+/', ' ', (string) $value ) );

                    if ( '' !== $clean ) {
                        $normalized[] = $clean;
                    }
                }
            }

            if ( empty( $normalized ) ) {
                return array();
            }

            $case_insensitive = ! empty( $config['case_insensitive'] );
            $unique           = array();

            foreach ( $normalized as $value ) {
                $key = $case_insensitive ? $this->to_lower( $value ) : $value;

                if ( '' === $key ) {
                    continue;
                }

                if ( ! isset( $unique[ $key ] ) ) {
                    $unique[ $key ] = $case_insensitive ? $key : $value;
                }
            }

            if ( empty( $unique ) ) {
                return array();
            }

            return array_values( $unique );
        }

        /**
         * Build a list of human readable details for the provided card.
         *
         * @param array  $card     Raw card payload.
         * @param string $set_name Derived set name from the file path.
         * @param string $game     Human readable game name.
         * @param string $type_slug Game directory slug.
         *
         * @return array
         */
        protected function prepare_card_details( array $card, $set_name, $game, $type_slug ) {
            $details = array();
            $fields  = $this->get_detail_field_definitions( $type_slug );

            foreach ( $fields as $definition ) {
                if ( empty( $definition['label'] ) ) {
                    continue;
                }

                $value = $this->resolve_detail_value( $card, $set_name, $game, $definition, $type_slug );

                $this->append_detail( $details, $definition['label'], $value );
            }

            return $details;
        }

        /**
         * Determine the detail field definitions for a given game directory.
         *
         * @param string $type_slug Game directory slug.
         *
         * @return array
         */
        protected function get_detail_field_definitions( $type_slug ) {
            $slug = strtolower( (string) $type_slug );

            if ( false !== strpos( $slug, 'pokemon' ) ) {
                return array(
                    array(
                        'source' => 'card',
                        'key'    => 'name',
                        'label'  => __( 'Name', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'set_name',
                        'label'  => __( 'Source Set', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card',
                        'key'    => 'id',
                        'label'  => __( 'ID', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card',
                        'key'    => 'supertype',
                        'label'  => __( 'Supertype', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card',
                        'key'    => 'types',
                        'label'  => __( 'Types', 'tcg-kiosk-filter' ),
                        'format' => 'list',
                    ),
                );
            }

            if ( false !== strpos( $slug, 'one-piece' ) ) {
                return array(
                    array(
                        'source' => 'card',
                        'key'    => 'name',
                        'label'  => __( 'Name', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card_set_name',
                        'label'  => __( 'Source Set', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'set_name',
                        'label'  => __( 'Source Set', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card',
                        'key'    => 'code',
                        'label'  => __( 'Code', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card',
                        'key'    => 'rarity',
                        'label'  => __( 'Rarity', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card',
                        'key'    => 'type',
                        'label'  => __( 'Type', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card',
                        'key'    => 'color',
                        'label'  => __( 'Color', 'tcg-kiosk-filter' ),
                    ),
                );
            }

            if ( false !== strpos( $slug, 'riftbound' ) ) {
                return array(
                    array(
                        'source' => 'card',
                        'key'    => 'name',
                        'label'  => __( 'Name', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card_set_name',
                        'label'  => __( 'Source Set', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card',
                        'key'    => 'number',
                        'label'  => __( 'Number', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card',
                        'key'    => 'rarity',
                        'label'  => __( 'Rarity', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card',
                        'key'    => 'cardType',
                        'label'  => __( 'Card Type', 'tcg-kiosk-filter' ),
                    ),
                    array(
                        'source' => 'card',
                        'key'    => 'domain',
                        'label'  => __( 'Domain', 'tcg-kiosk-filter' ),
                    ),
                );
            }

            return array(
                array(
                    'source' => 'card',
                    'key'    => 'name',
                    'label'  => __( 'Name', 'tcg-kiosk-filter' ),
                ),
                array(
                    'source' => 'set_name',
                    'label'  => __( 'Source Set', 'tcg-kiosk-filter' ),
                ),
                array(
                    'source' => 'card',
                    'key'    => 'id',
                    'label'  => __( 'ID', 'tcg-kiosk-filter' ),
                ),
            );
        }

        /**
         * Resolve a field definition to a displayable value.
         *
         * @param array  $card       Raw card payload.
         * @param string $set_name   Derived set name.
         * @param string $game       Human readable game name.
         * @param array  $definition Field definition array.
         * @param string $type_slug  Game directory slug.
         *
         * @return string
         */
        protected function resolve_detail_value( array $card, $set_name, $game, array $definition, $type_slug ) {
            $source = isset( $definition['source'] ) ? $definition['source'] : 'card';

            if ( 'game' === $source ) {
                return is_string( $game ) ? trim( $game ) : '';
            }

            if ( 'set_name' === $source ) {
                return is_string( $set_name ) ? trim( $set_name ) : '';
            }

            if ( 'card_set_name' === $source ) {
                if ( isset( $card['set'] ) && is_array( $card['set'] ) ) {
                    if ( isset( $card['set']['name'] ) ) {
                        $name = $this->normalize_detail_value( $card['set']['name'] );

                        if ( '' !== $name ) {
                            return $name;
                        }
                    }

                    if ( isset( $card['set']['id'] ) ) {
                        $label = $this->resolve_set_label( $type_slug, $card['set']['id'], $card['set']['id'] );
                        $name  = $this->normalize_detail_value( $label );

                        if ( '' !== $name ) {
                            return $name;
                        }
                    }
                }

                return '';
            }

            if ( 'card' !== $source ) {
                return '';
            }

            $key = isset( $definition['key'] ) ? (string) $definition['key'] : '';

            if ( '' === $key ) {
                return '';
            }

            $value = null;

            if ( array_key_exists( $key, $card ) ) {
                $value = $card[ $key ];
            } else {
                $target = strtolower( $key );

                foreach ( $card as $card_key => $card_value ) {
                    if ( strtolower( (string) $card_key ) === $target ) {
                        $value = $card_value;
                        break;
                    }
                }
            }

            if ( null === $value ) {
                return '';
            }

            $is_pokemon = false !== strpos( $this->to_lower( (string) $type_slug ), 'pokemon' );

            if ( $is_pokemon && 'id' === $key ) {
                $formatted_id = $this->format_pokemon_card_identifier( $value, $card, $type_slug );

                if ( '' !== $formatted_id ) {
                    return $formatted_id;
                }
            }

            if ( isset( $definition['format'] ) && 'list' === $definition['format'] ) {
                if ( is_array( $value ) ) {
                    $parts = array();

                    foreach ( $value as $item ) {
                        $normalized = $this->normalize_detail_value( $item );

                        if ( '' === $normalized ) {
                            continue;
                        }

                        $parts[] = $normalized;
                    }

                    return empty( $parts ) ? '' : implode( ', ', $parts );
                }

                $normalized = $this->normalize_detail_value( $value );

                return '' === $normalized ? '' : $normalized;
            }

            return $this->normalize_detail_value( $value );
        }

        /**
         * Format a Pokémon card identifier using the set's PTCGO code and card number.
         *
         * @param mixed  $value     Raw identifier value.
         * @param array  $card      Original card payload.
         * @param string $type_slug Game directory slug.
         *
         * @return string
         */
        protected function format_pokemon_card_identifier( $value, array $card, $type_slug ) {
            if ( is_array( $value ) || is_object( $value ) ) {
                return '';
            }

            $raw = trim( (string) $value );

            if ( '' === $raw ) {
                return '';
            }

            $set_identifier = '';
            $number         = '';

            if ( false !== strpos( $raw, '-' ) ) {
                $parts = explode( '-', $raw, 2 );

                if ( ! empty( $parts ) ) {
                    $set_identifier = (string) $parts[0];

                    if ( isset( $parts[1] ) && '' === $number ) {
                        $number = trim( (string) $parts[1] );
                    }
                }
            }

            if ( isset( $card['number'] ) ) {
                $card_number = trim( (string) $card['number'] );

                if ( '' !== $card_number ) {
                    $number = $card_number;
                }
            }

            if ( '' === $set_identifier && isset( $card['set'] ) && is_array( $card['set'] ) && isset( $card['set']['id'] ) ) {
                $set_identifier = (string) $card['set']['id'];
            }

            if ( '' === $set_identifier ) {
                return '' === $number ? $raw : $number;
            }

            $code = $this->resolve_set_code( $type_slug, $set_identifier );

            if ( '' === $code ) {
                return '' === $number ? strtoupper( $set_identifier ) : strtoupper( $set_identifier ) . '-' . $number;
            }

            if ( '' === $number ) {
                return $code;
            }

            return $code . '-' . $number;
        }

        /**
         * Append a formatted detail entry to the list.
         *
         * @param array  $details Reference to the detail list.
         * @param string $label   Detail label.
         * @param string $value   Detail value.
         */
        protected function append_detail( array &$details, $label, $value ) {
            $label = is_string( $label ) ? trim( $label ) : '';
            $value = is_string( $value ) ? trim( $value ) : '';

            if ( '' === $label || '' === $value ) {
                return;
            }

            $details[] = array(
                'label' => $label,
                'value' => $value,
            );
        }

        /**
         * Convert a raw card value into a human readable string.
         *
         * @param mixed $value Raw value.
         *
         * @return string
         */
        protected function normalize_detail_value( $value ) {
            if ( null === $value ) {
                return '';
            }

            if ( is_bool( $value ) ) {
                return $value ? __( 'Yes', 'tcg-kiosk-filter' ) : __( 'No', 'tcg-kiosk-filter' );
            }

            if ( is_scalar( $value ) ) {
                $string = trim( (string) $value );

                return $string;
            }

            if ( is_array( $value ) ) {
                if ( empty( $value ) ) {
                    return '';
                }

                if ( $this->is_associative_array( $value ) ) {
                    $parts = array();

                    foreach ( $value as $key => $item ) {
                        $normalized = $this->normalize_detail_value( $item );

                        if ( '' === $normalized ) {
                            continue;
                        }

                        $label = is_string( $key ) ? $this->humanize_label( $key ) : '';

                        if ( '' !== $label ) {
                            $parts[] = $label . ': ' . $normalized;
                        } else {
                            $parts[] = $normalized;
                        }
                    }

                    if ( empty( $parts ) ) {
                        return '';
                    }

                    return implode( '; ', $parts );
                }

                $parts = array();

                foreach ( $value as $item ) {
                    $normalized = $this->normalize_detail_value( $item );

                    if ( '' === $normalized ) {
                        continue;
                    }

                    $parts[] = $normalized;
                }

                if ( empty( $parts ) ) {
                    return '';
                }

                return implode( "\n", $parts );
            }

            return '';
        }

        /**
         * Determine if an array is associative.
         *
         * @param array $array Input array.
         *
         * @return bool
         */
        protected function is_associative_array( array $array ) {
            if ( array() === $array ) {
                return false;
            }

            return array_keys( $array ) !== range( 0, count( $array ) - 1 );
        }

        /**
         * Normalize a value to lowercase, supporting multibyte strings when possible.
         *
         * @param string $value Input value.
         *
         * @return string
         */
        protected function to_lower( $value ) {
            if ( function_exists( 'mb_strtolower' ) ) {
                return mb_strtolower( $value, 'UTF-8' );
            }

            return strtolower( $value );
        }

        /**
         * Extract the most appropriate image URL from the provided data.
         *
         * @param array $images List of images indexed by size.
         *
         * @return string
         */
        protected function prepare_image_sources( array $images ) {
            $sources = array(
                'primary' => '',
                'full'    => '',
                'srcset'  => '',
                'sizes'   => '',
            );

            if ( empty( $images ) ) {
                return $sources;
            }

            $map      = array(
                'small'  => array(
                    'descriptor' => '1x',
                    'priority'   => 10,
                ),
                'normal' => array(
                    'descriptor' => '1.5x',
                    'priority'   => 20,
                ),
                'large'  => array(
                    'descriptor' => '2x',
                    'priority'   => 30,
                ),
                'image'  => array(
                    'descriptor' => '3x',
                    'priority'   => 40,
                ),
            );
            $entries  = array();

            foreach ( $map as $key => $meta ) {
                if ( empty( $images[ $key ] ) || ! is_string( $images[ $key ] ) ) {
                    continue;
                }

                $url = trim( $images[ $key ] );

                if ( '' === $url ) {
                    continue;
                }

                if ( '' === $sources['primary'] ) {
                    $sources['primary'] = $url;
                }

                if ( in_array( $key, array( 'large', 'image' ), true ) ) {
                    $sources['full'] = $url;
                }

                $entries[] = array(
                    'priority'   => $meta['priority'],
                    'descriptor' => $meta['descriptor'],
                    'url'        => $url,
                );
            }

            if ( '' === $sources['primary'] ) {
                foreach ( $images as $image_url ) {
                    if ( is_string( $image_url ) && '' !== trim( $image_url ) ) {
                        $sources['primary'] = trim( $image_url );
                        break;
                    }
                }
            }

            if ( '' === $sources['full'] ) {
                $sources['full'] = $sources['primary'];
            }

            if ( ! empty( $entries ) ) {
                usort(
                    $entries,
                    static function ( $a, $b ) {
                        return $a['priority'] <=> $b['priority'];
                    }
                );

                $seen  = array();
                $parts = array();

                foreach ( $entries as $entry ) {
                    if ( isset( $seen[ $entry['descriptor'] ] ) ) {
                        continue;
                    }

                    $sanitized_url = esc_url_raw( $entry['url'] );

                    if ( '' === $sanitized_url ) {
                        continue;
                    }

                    $seen[ $entry['descriptor'] ] = true;
                    $parts[]                       = $sanitized_url . ' ' . $entry['descriptor'];
                }

                if ( ! empty( $parts ) ) {
                    $sources['srcset'] = implode( ', ', $parts );
                    $sources['sizes']  = '(max-width: 600px) 80vw, (max-width: 900px) 40vw, 220px';
                }
            }

            $sources['primary'] = esc_url_raw( $sources['primary'] );
            $sources['full']    = esc_url_raw( $sources['full'] );

            if ( '' === $sources['srcset'] ) {
                $sources['sizes'] = '';
            }

            return $sources;
        }

        /**
         * Retrieve cached set metadata for the provided game directory.
         *
         * @param string $type_slug Game directory slug.
         * @param string $directory Absolute path to the game directory.
         *
         * @return array
         */
        protected function get_set_context( $type_slug, $directory ) {
            $slug = $this->to_lower( (string) $type_slug );

            if ( isset( $this->set_cache[ $slug ] ) ) {
                return $this->set_cache[ $slug ];
            }

            $context = array(
                'names'   => array(),
                'codes'   => array(),
                'allowed' => null,
                'order'   => array(),
            );

            if ( false === strpos( $slug, 'pokemon' ) ) {
                $this->set_cache[ $slug ] = $context;

                return $context;
            }

            $sets_file = trailingslashit( $directory ) . 'sets/en.json';

            if ( ! is_readable( $sets_file ) ) {
                $this->set_cache[ $slug ] = $context;

                return $context;
            }

            $json = file_get_contents( $sets_file );

            if ( false === $json ) {
                $this->set_cache[ $slug ] = $context;

                return $context;
            }

            $decoded = json_decode( $json, true );

            if ( empty( $decoded ) || ! is_array( $decoded ) ) {
                $this->set_cache[ $slug ] = $context;

                return $context;
            }

            $threshold_index = null;

            foreach ( $decoded as $index => $set ) {
                if ( ! is_array( $set ) ) {
                    continue;
                }

                if ( ! isset( $set['id'] ) ) {
                    continue;
                }

                $id = $this->to_lower( (string) $set['id'] );

                if ( '' === $id ) {
                    continue;
                }

                $name = '';

                if ( isset( $set['name'] ) ) {
                    $name = $this->normalize_detail_value( $set['name'] );
                }

                if ( '' === $name ) {
                    $name = $this->derive_set_name( $id );
                }

                $context['names'][ $id ] = $name;

                if ( isset( $set['ptcgoCode'] ) ) {
                    $code = $this->normalize_detail_value( $set['ptcgoCode'] );

                    if ( '' !== $code ) {
                        $context['codes'][ $id ] = strtoupper( $code );
                    }
                }

                if ( 'swshp' === $id ) {
                    $threshold_index = $index;
                }
            }

            if ( null !== $threshold_index ) {
                $allowed = array();

                foreach ( $decoded as $index => $set ) {
                    if ( ! is_array( $set ) ) {
                        continue;
                    }

                    if ( ! isset( $set['id'] ) ) {
                        continue;
                    }

                    $id = $this->to_lower( (string) $set['id'] );

                    if ( '' === $id ) {
                        continue;
                    }

                    if ( $index > $threshold_index ) {
                        $allowed[] = $id;
                    }
                }

                $context['allowed'] = $allowed;

                if ( ! empty( $allowed ) ) {
                    foreach ( $allowed as $allowed_id ) {
                        if ( isset( $context['names'][ $allowed_id ] ) ) {
                            $context['order'][] = $context['names'][ $allowed_id ];
                            continue;
                        }

                        $context['order'][] = $this->derive_set_name( $allowed_id );
                    }
                }
            }

            $this->set_cache[ $slug ] = $context;

            return $context;
        }

        /**
         * Resolve a set identifier to a human readable label using cached metadata.
         *
         * @param string $type_slug Game directory slug.
         * @param string $set_id    Raw set identifier.
         * @param string $fallback  Fallback label.
         *
         * @return string
         */
        protected function resolve_set_label( $type_slug, $set_id, $fallback = '' ) {
            $slug = $this->to_lower( (string) $type_slug );
            $id   = $this->to_lower( (string) $set_id );

            if ( isset( $this->set_cache[ $slug ] ) && isset( $this->set_cache[ $slug ]['names'][ $id ] ) ) {
                return $this->set_cache[ $slug ]['names'][ $id ];
            }

            if ( '' !== $fallback ) {
                return $this->derive_set_name( $fallback );
            }

            return $this->derive_set_name( $id );
        }

        /**
         * Derive a human readable set name from the file name.
         *
         * @param string $filename File name without extension.
         *
         * @return string
         */
        protected function derive_set_name( $filename ) {
            if ( empty( $filename ) ) {
                return '';
            }

            $name = str_replace( array( '-', '_' ), ' ', $filename );
            $name = preg_replace( '/\s+/', ' ', $name );

            return ucwords( trim( $name ) );
        }

        /**
         * Convert a slug into a human readable label.
         *
         * @param string $slug Slug string.
         *
         * @return string
         */
        protected function humanize_label( $slug ) {
            if ( empty( $slug ) ) {
                return '';
            }

            $label = str_replace( array( '-', '_' ), ' ', $slug );
            $label = preg_replace( '/\s+/', ' ', $label );

            return ucwords( trim( $label ) );
        }

        /**
         * Resolve a set identifier to its configured PTCGO code.
         *
         * @param string $type_slug Game directory slug.
         * @param string $set_id    Raw set identifier.
         *
         * @return string
         */
        protected function resolve_set_code( $type_slug, $set_id ) {
            $slug = $this->to_lower( (string) $type_slug );
            $id   = $this->to_lower( (string) $set_id );

            if ( isset( $this->set_cache[ $slug ] ) && isset( $this->set_cache[ $slug ]['codes'][ $id ] ) ) {
                return $this->set_cache[ $slug ]['codes'][ $id ];
            }

            return '';
        }

        /**
         * Determine the most recent modification timestamp for the database files.
         *
         * @return int
         */
        protected function get_last_modified_timestamp() {
            if ( empty( $this->database_path ) ) {
                return 0;
            }

            $latest = 0;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $this->database_path,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $file ) {
                $mtime = $file->getMTime();

                if ( $mtime > $latest ) {
                    $latest = $mtime;
                }
            }

            return $latest;
        }
    }
}
