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
                    'slug'                => $type_slug,
                    'label'               => $this->humanize_label( $type_slug ),
                    'typeLabel'           => $config['label'],
                    'typeOptions'         => $config['options'],
                    'typeMatchMode'       => $config['match_mode'],
                    'typeCaseInsensitive' => $config['case_insensitive'],
                    'overlayImage'        => $this->get_overlay_image_url( $type_slug ),
                    'setOrder'            => isset( $context['order'] ) && is_array( $context['order'] ) ? array_values( $context['order'] ) : array(),
                    'cards'               => $cards,
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
                    );
                }
            }

            return $cards;
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
                    'label'            => __( 'Type', 'tcg-kiosk-filter' ),
                    'field'            => 'types',
                    'options'          => array(),
                    'match_mode'       => 'exact',
                    'case_insensitive' => false,
                );
            }

            if ( false !== strpos( $slug, 'one-piece' ) ) {
                return array(
                    'label'            => __( 'Color', 'tcg-kiosk-filter' ),
                    'field'            => 'color',
                    'options'          => array(
                        array(
                            'value' => 'black',
                            'label' => __( 'Black', 'tcg-kiosk-filter' ),
                        ),
                        array(
                            'value' => 'blue',
                            'label' => __( 'Blue', 'tcg-kiosk-filter' ),
                        ),
                        array(
                            'value' => 'green',
                            'label' => __( 'Green', 'tcg-kiosk-filter' ),
                        ),
                        array(
                            'value' => 'purple',
                            'label' => __( 'Purple', 'tcg-kiosk-filter' ),
                        ),
                        array(
                            'value' => 'red',
                            'label' => __( 'Red', 'tcg-kiosk-filter' ),
                        ),
                        array(
                            'value' => 'yellow',
                            'label' => __( 'Yellow', 'tcg-kiosk-filter' ),
                        ),
                    ),
                    'match_mode'       => 'contains',
                    'case_insensitive' => true,
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
            $values = array();

            switch ( $config['field'] ) {
                case 'types':
                    if ( ! empty( $card['types'] ) && is_array( $card['types'] ) ) {
                        $values = $card['types'];
                    }
                    break;
                case 'color':
                    if ( ! empty( $card['color'] ) ) {
                        if ( is_array( $card['color'] ) ) {
                            $values = $card['color'];
                        } else {
                            $values = array( $card['color'] );
                        }
                    }
                    break;
                case 'domain':
                    if ( ! empty( $card['domain'] ) ) {
                        $values = array( $card['domain'] );
                    }
                    break;
            }

            if ( empty( $values ) ) {
                return array();
            }

            $normalized = array();

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
